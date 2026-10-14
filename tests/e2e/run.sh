#!/usr/bin/env bash
#
# End-to-end test for RunWP Migrate.
#
# Builds one ddev project with two WordPress sites, /alpha and /beta, with
# different table prefixes and paths, then pulls, pushes, restores and
# attacks between them. Needs ddev and Docker.
#
#   tests/e2e/run.sh            temporary project, deleted afterwards
#   KEEP=1 tests/e2e/run.sh     keep it for poking around
#   PHP=8.3 WP=6.8 tests/e2e/run.sh
#
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d "${TMPDIR:-/tmp}/rwm-e2e.XXXXXX")
NAME=rwm-e2e
URL="https://$NAME.ddev.site"

cleanup() {
	if [ "${KEEP:-0}" != "1" ]; then
		(cd "$WORK" && ddev delete --omit-snapshot --yes >/dev/null 2>&1) || true
		rm -rf "$WORK"
	else
		echo "Kept: $WORK ($URL/alpha, $URL/beta)"
	fi
}
trap cleanup EXIT

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
in_site() { local site=$1; shift; ddev exec "cd web/$site && $*"; }

step "Project in $WORK"
mkdir -p "$WORK/web"
cp -R "$ROOT/tests/e2e" "$WORK/e2e"
cd "$WORK"
ddev config --project-type=php --docroot=web --project-name="$NAME" --php-version="${PHP:-7.4}" --database=mariadb:10.11 --performance-mode=none >/dev/null
ddev start >/dev/null
ddev mysql -uroot -proot -e "CREATE DATABASE alpha; CREATE DATABASE beta; GRANT ALL ON alpha.* TO 'db'@'%'; GRANT ALL ON beta.* TO 'db'@'%';"

PASS=$(openssl rand -hex 12)
for site in alpha beta; do
	prefix="${site:0:2}_"
	title=$([ "$site" = alpha ] && echo Alpha || echo Beta)
	ddev exec "wp core download --path=web/$site --version=${WP:-6.6} --locale=en_US --quiet \
		&& wp config create --path=web/$site --dbname=$site --dbuser=db --dbpass=db --dbhost=db --dbprefix=$prefix --quiet \
		&& wp core install --path=web/$site --url=$URL/$site --title=$title --admin_user=admin --admin_password=$PASS --admin_email=admin@$site.test --skip-email --quiet"
	rsync -a --exclude .git --exclude tests "$ROOT/" "web/$site/wp-content/plugins/runwp-migrate/"
	in_site "$site" "wp plugin activate runwp-migrate --quiet"
done

step "Seed alpha"
in_site alpha "wp eval-file ../../e2e/seed.php \
	&& wp post generate --count=600 --quiet \
	&& wp media import wp-includes/images/w-logo-blue.png wp-admin/images/wordpress-logo.png --quiet \
	&& mkdir -p wp-content/uploads/files \
	&& head -c 5300000 /dev/urandom > wp-content/uploads/files/big.pdf \
	&& cp wp-content/uploads/files/big.pdf /var/www/html/e2e-big.pdf \
	&& printf '<?php' > wp-content/uploads/files/evil.php \
	&& touch wp-content/uploads/files/.hidden \
	&& wp runwp-migrate allow pull && wp runwp-migrate allow push"
in_site beta "wp post create --post_title='Beta only' --post_name=beta-only --post_status=publish --quiet"
KEY=$(in_site alpha "wp runwp-migrate info" | awk '/^Key:/ {print $2}' | tr -d '\r')

step "Pull alpha into beta"
in_site beta "wp runwp-migrate connect alpha $URL/alpha $KEY && wp runwp-migrate pull alpha --yes"
in_site beta "wp eval-file ../../e2e/verify-pull.php"

step "Pull three more times (backups and constraint names must not collide)"
for i in 1 2 3; do in_site beta "wp runwp-migrate pull alpha --db --yes" | tail -1; done

step "Push beta to alpha"
in_site beta "wp post create --post_title='Pushed from beta' --post_name=pushed-from-beta --post_status=publish --post_content='<a href=\"$URL/beta/from-beta\">x</a>' --quiet \
	&& echo note > wp-content/uploads/files/beta-note.txt \
	&& wp runwp-migrate push alpha --yes"
in_site alpha "RWM_ALPHA_KEY=$KEY wp eval-file ../../e2e/verify-push.php"

step "Undo the push from beta"
in_site beta "wp runwp-migrate restore alpha --yes"
in_site alpha "RWM_ALPHA_KEY=$KEY wp eval-file ../../e2e/verify-restore.php"

step "Requests alpha must refuse"
in_site beta "wp eval-file ../../e2e/attack.php"

step "Access switches"
in_site alpha "wp runwp-migrate allow push --off"
if in_site beta "wp runwp-migrate push alpha --yes" 2>/dev/null; then echo "FAIL push went through although switched off"; exit 1; else echo "PASS push refused while switched off"; fi
in_site alpha "wp runwp-migrate allow push"

step "Media comparison and cancelling"
in_site beta "wp eval-file ../../e2e/resume.php"

step "Uninstall removes key, connections and tables"
in_site beta "wp plugin uninstall runwp-migrate --deactivate --quiet"
LEFT=$(in_site beta "wp db query \"SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '\\\\_rwb\\\\_%' OR TABLE_NAME LIKE '\\\\_rwt\\\\_%')) + (SELECT COUNT(*) FROM be_options WHERE option_name LIKE 'runwp\\\\_migrate\\\\_%')\" --skip-column-names" | tr -d '\r')
if [ "$LEFT" = "0" ]; then echo "PASS nothing left behind"; else echo "FAIL $LEFT tables or options left behind"; exit 1; fi

printf '\n\033[32mAll end-to-end checks passed.\033[0m\n'
