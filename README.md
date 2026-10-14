# RunWP Migrate

Pull a WordPress site's database and media library into another, or push
them the other way. Addresses and paths are rewritten on the way, inside
serialized data too, and the site being overwritten keeps running until the
very last moment, with its old tables kept as a one-click backup.

It does the everyday job of WP Migrate DB Pro: live to local, local to
staging. It does it without an account, a licence or a service in between.
Two sites with the plugin installed talk to each other directly.

## Install

Copy the `runwp-migrate` folder into `wp-content/plugins` on **both** sites,
or zip it and upload it under *Plugins → Add New → Upload Plugin*. Activate
it. Requires WordPress 6.0+ and PHP 7.4+. Multisite is not supported.

## Connect two sites

On the site you want to pull from or push to, usually live:

1. *Tools → RunWP Migrate → This site*: copy the **connection info** (its
   address and a key).
2. Under **Access**, switch on what the other site may do: **Pull** (read
   this site) and/or **Push** (overwrite it). Both are off by default.

On the site you work from, usually local:

3. *Tools → RunWP Migrate → Connections*: give the connection a name (`live`)
   and paste the connection info. It is tested before it is saved.

Requests always go from the site you work on to the other one, so a local
site behind a router can pull from and push to a public one.

## Migrate

*Tools → RunWP Migrate → Migrate*: choose the connection, **Pull** or
**Push**, and whether to move the database, the media, or both. **Preview**
asks the other site and shows both addresses, every replacement that will be
made, and the tables, each of which can be left out. Confirm, start, and the
progress bar runs until done.

After a **pull** of the database, this site has the other site's users: log
in again with an account from there.

Or from the command line:

```bash
wp runwp-migrate pull live                     # database and media
wp runwp-migrate pull live --db                # database only
wp runwp-migrate push staging --media          # media only
wp runwp-migrate pull live --exclude=wp_actionscheduler_logs,wp_wc_sessions
wp runwp-migrate restore                       # undo the last migration into this site
wp runwp-migrate restore staging               # undo the last push to staging
```

Setting up from the command line:

```bash
# On the other site
wp runwp-migrate info                          # address and key
wp runwp-migrate allow pull                    # and/or: allow push
# Here
wp runwp-migrate connect live https://example.com <key>
wp runwp-migrate connections
```

## What moves, and what does not

- **Database:** every table carrying the source site's table prefix. If the
  prefixes differ (`wp_` live, `dev_` local), tables are renamed and so are
  the prefixed keys inside them (`wp_user_roles`, `wp_capabilities`), so
  users keep their roles. Tables that exist only on the target are left
  alone.
- **Media:** the uploads folder, compared file by file on size and
  modification time. Only files that are missing or differ are copied, so
  the second run is quick. Nothing on the target is deleted. Caches, logs and
  other backup plugins' folders are skipped, and so are executable files
  (`.php`, `.phar`, `.htaccess` …) and dotfiles in either direction.
- **Not moved:** WordPress itself, themes and plugins. Deploy those as code.
- **Kept on the target:** this plugin's key, access switches and
  connections, and the plugin stays active, so the next migration can still
  reach the site.

**A push replaces the target's database.** Orders, customers and comments
that came in on the target since your last pull are gone afterwards (until
restored). Push to staging freely; push to a live shop only when that is
what you mean, or leave its order tables out.

## How it works

- **Chunks.** Rows travel in chunks of up to 2 MB (smaller if either server's
  `post_max_size` asks for it), paged by primary key, so a large table costs
  no more per request than a small one. Files travel in parts of the same
  size. Each admin request works for about eight seconds and hands back, so
  no PHP time limit or proxy timeout is ever reached; a failed step can
  simply be retried and the run continues where it stopped.
- **Temporary tables, one swap.** Rows go into copies named `_rwt_<table>`.
  The site keeps using its real tables throughout. At the end a single
  `RENAME TABLE` statement swaps all of them at once: the site sees the old
  data or the new, never a mix. Foreign keys are carried across the swap.
- **Backup.** The replaced tables stay as `_rwb_<table>` until the next
  migration into the site. *Backup* puts them back, on this site or (undo a
  push) on a connected one. It is a safety net for the last migration, not a
  backup strategy: keep real backups too.
- **Replacement.** The source's address is rewritten to the target's with
  and without scheme (`https://example.com`, `//example.com`), JSON-escaped
  (`https:\/\/example.com`) and URL-encoded, and its file system paths to the
  target's. Serialized values are unserialized, rewritten and serialized
  again, so their lengths stay right. Replacement runs on the sending side,
  so no site ever unserializes data it did not store itself. Post GUIDs are
  left unchanged on purpose.

## Security

- Every request is signed with HMAC-SHA256 over its method, route, time,
  a single-use nonce and a hash of its body, using the receiving site's key.
  A request cannot be forged without the key, changed on the way, or
  replayed; one older than five minutes is refused.
- Pull and push are separate switches, both off until switched on. On a live
  site, switch push on only for the moment you push to it.
- Connections to anything but local development hosts (`.test`, `.local`,
  `.ddev.site`, private addresses …) require HTTPS. The key itself is never
  sent.
- Table names are checked against the site's own tables and prefix, values
  are escaped, and the only statement a sender can have run is the
  `CREATE TABLE` for a temporary copy. File paths are resolved and must stay
  inside the uploads folder.
- *This site → Create new key* locks every connected site out at once.
- Uninstalling removes the key, the connections and the plugin's tables.

## Development

`tests/e2e/run.sh` builds a ddev project with two WordPress sites (different
table prefixes and paths), seeds one with the awkward cases (nested
serialized data, JSON-escaped URLs, binary blobs, foreign keys, a table
without primary key, a 5 MB file, a PHP file that must not travel), and then
pulls, pushes, restores, cancels and sends forged requests. It needs ddev
and Docker and takes about a minute.

```bash
tests/e2e/run.sh                     # PHP 7.4, WordPress 6.6
PHP=8.3 WP=7.0 tests/e2e/run.sh
KEEP=1 tests/e2e/run.sh              # keep the project to look around
```

Translations: English source strings, German in `languages/` (de_CH, de_DE).
After changing strings:

```bash
wp i18n make-pot . languages/runwp-migrate.pot --domain=runwp-migrate --exclude=tests
wp i18n make-mo languages
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
