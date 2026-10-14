<?php
/**
 * Tools → RunWP Migrate.
 *
 * @package RunWP_Migrate
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen with four tabs: run a migration, manage connections, this
 * site's access, and the backup. Built from WordPress's own admin markup;
 * the migration itself runs through admin-ajax, one step per request.
 */
class RunWP_Migrate_Admin {

	const SLUG = 'runwp-migrate';

	/**
	 * Singleton instance.
	 *
	 * @var RunWP_Migrate_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the shared instance.
	 *
	 * @return RunWP_Migrate_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into wp-admin.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RUNWP_MIGRATE_FILE ), array( $this, 'action_links' ) );

		foreach ( array( 'save_access', 'regenerate_key', 'add_connection', 'remove_connection', 'restore' ) as $action ) {
			add_action( 'admin_post_runwp_migrate_' . $action, array( $this, 'handle_' . $action ) );
		}

		foreach ( array( 'plan', 'start', 'step', 'cancel' ) as $action ) {
			add_action( 'wp_ajax_runwp_migrate_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Register the screen.
	 */
	public function add_menu() {
		add_management_page(
			__( 'RunWP Migrate', 'runwp-migrate' ),
			__( 'RunWP Migrate', 'runwp-migrate' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Migrate', 'runwp-migrate' ) . '</a>' );
		return $links;
	}

	/**
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::SLUG ) );
	}

	/**
	 * @param string $hook Current screen.
	 */
	public function enqueue( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script( 'runwp-migrate-admin', plugins_url( 'assets/admin.js', RUNWP_MIGRATE_FILE ), array(), RUNWP_MIGRATE_VERSION, true );
		wp_localize_script(
			'runwp-migrate-admin',
			'runwpMigrate',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'runwp_migrate' ),
				'loginUrl' => wp_login_url( self::url() ),
				'running'  => null !== RunWP_Migrate_Migration::current(),
				'i18n'     => array(
					'loading'      => __( 'Asking the other site …', 'runwp-migrate' ),
					'source'       => __( 'From', 'runwp-migrate' ),
					'target'       => __( 'To', 'runwp-migrate' ),
					'address'      => __( 'Address', 'runwp-migrate' ),
					'prefix'       => __( 'Table prefix', 'runwp-migrate' ),
					'tables'       => __( 'Tables', 'runwp-migrate' ),
					'noPairs'      => __( 'Both sites use the same addresses and paths; nothing is replaced.', 'runwp-migrate' ),
					'rows'         => __( 'rows', 'runwp-migrate' ),
					'warnPull'     => __( 'The database of this site is replaced with the one of %s, including users: you will have to log in again with an account from there. The current tables are kept as a backup.', 'runwp-migrate' ),
					'warnPush'     => __( 'The database of %s is replaced with the one of this site, including users and every order or comment that came in there since. Its current tables are kept as a backup.', 'runwp-migrate' ),
					'warnMedia'    => __( 'Media files that are missing or differ are copied. No file is deleted.', 'runwp-migrate' ),
					'confirm'      => __( 'I understand what will be overwritten.', 'runwp-migrate' ),
					'running'      => __( 'Migration running …', 'runwp-migrate' ),
					'failed'       => __( 'The migration stopped: %s', 'runwp-migrate' ),
					'retryHint'    => __( 'Fix the cause and try again; the migration continues where it stopped.', 'runwp-migrate' ),
					'cancelAsk'    => __( 'Cancel the migration? Nothing has been swapped yet; the partial copy is discarded.', 'runwp-migrate' ),
					'cancelled'    => __( 'Migration cancelled. Nothing was changed.', 'runwp-migrate' ),
					'doneDbPull'   => __( 'Done. This site now runs on the database of %s. Its users replaced the ones here, so log in again with an account from there.', 'runwp-migrate' ),
					'doneDbPush'   => __( 'Done. %s now runs on the database of this site.', 'runwp-migrate' ),
					'doneMedia'    => __( 'Done. The media files are copied.', 'runwp-migrate' ),
					'logIn'        => __( 'Log in', 'runwp-migrate' ),
					'open'         => __( 'Open site', 'runwp-migrate' ),
					'copied'       => __( 'Copied', 'runwp-migrate' ),
					'none'         => __( 'Choose the database, the media, or both.', 'runwp-migrate' ),
					'networkError' => __( 'The request did not get through.', 'runwp-migrate' ),
				),
			)
		);
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'migrate'     => __( 'Migrate', 'runwp-migrate' ),
			'connections' => __( 'Connections', 'runwp-migrate' ),
			'site'        => __( 'This site', 'runwp-migrate' ),
			'backup'      => __( 'Backup', 'runwp-migrate' ),
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'migrate';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'migrate';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'RunWP Migrate', 'runwp-migrate' ) . '</h1>';

		$this->notices();

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$tab === $key ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		call_user_func( array( $this, 'render_' . $tab ) );

		echo '</div>';
	}

	/**
	 * Run a migration.
	 */
	private function render_migrate() {
		$connections = RunWP_Migrate_Settings::connections();

		if ( ! $connections ) {
			echo '<div class="notice notice-info inline"><p>';
			printf(
				/* translators: %s: link to the Connections tab. */
				esc_html__( 'Connect another site first, under %s.', 'runwp-migrate' ),
				'<a href="' . esc_url( self::url( array( 'tab' => 'connections' ) ) ) . '">' . esc_html__( 'Connections', 'runwp-migrate' ) . '</a>'
			);
			echo '</p></div>';
			return;
		}
		?>
		<div id="runwp-migrate-setup">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="runwp-migrate-connection"><?php esc_html_e( 'Other site', 'runwp-migrate' ); ?></label></th>
					<td>
						<select id="runwp-migrate-connection">
							<?php foreach ( $connections as $name => $connection ) : ?>
								<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name . ' (' . $connection['url'] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Direction', 'runwp-migrate' ); ?></th>
					<td>
						<fieldset>
							<label><input type="radio" name="runwp_migrate_direction" value="pull" checked> <strong><?php esc_html_e( 'Pull', 'runwp-migrate' ); ?>:</strong> <?php esc_html_e( 'copy the other site into this one', 'runwp-migrate' ); ?></label><br>
							<label><input type="radio" name="runwp_migrate_direction" value="push"> <strong><?php esc_html_e( 'Push', 'runwp-migrate' ); ?>:</strong> <?php esc_html_e( 'copy this site into the other one', 'runwp-migrate' ); ?></label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'What', 'runwp-migrate' ); ?></th>
					<td>
						<fieldset>
							<label><input type="checkbox" id="runwp-migrate-db" checked> <?php esc_html_e( 'Database', 'runwp-migrate' ); ?></label><br>
							<label><input type="checkbox" id="runwp-migrate-media" checked> <?php esc_html_e( 'Media (uploads folder)', 'runwp-migrate' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</table>
			<p><button type="button" class="button" id="runwp-migrate-plan"><?php esc_html_e( 'Preview', 'runwp-migrate' ); ?></button> <span class="spinner"></span></p>
		</div>

		<div id="runwp-migrate-preview" hidden>
			<h2><?php esc_html_e( 'Preview', 'runwp-migrate' ); ?></h2>
			<table class="widefat striped" id="runwp-migrate-sites"></table>

			<h3><?php esc_html_e( 'Replacements', 'runwp-migrate' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Addresses and paths of the source are rewritten to those of the target, serialized data included.', 'runwp-migrate' ); ?></p>
			<table class="widefat striped" id="runwp-migrate-pairs"></table>

			<div id="runwp-migrate-tables-box">
				<h3><?php esc_html_e( 'Tables', 'runwp-migrate' ); ?></h3>
				<p><label><input type="checkbox" id="runwp-migrate-all" checked> <?php esc_html_e( 'All tables', 'runwp-migrate' ); ?></label></p>
				<div id="runwp-migrate-tables" style="max-height: 260px; overflow: auto; border: 1px solid #c3c4c7; background: #fff; padding: 8px 12px;"></div>
			</div>

			<div class="notice notice-warning inline"><p id="runwp-migrate-warning"></p></div>
			<p><label><input type="checkbox" id="runwp-migrate-confirm"> <span id="runwp-migrate-confirm-text"></span></label></p>
			<p><button type="button" class="button button-primary" id="runwp-migrate-start" disabled><?php esc_html_e( 'Start migration', 'runwp-migrate' ); ?></button></p>
		</div>

		<div id="runwp-migrate-progress" hidden>
			<h2><?php esc_html_e( 'Migration', 'runwp-migrate' ); ?></h2>
			<progress id="runwp-migrate-bar" max="100" value="0" style="width: 100%; max-width: 640px; height: 20px;"></progress>
			<p id="runwp-migrate-status"></p>
			<p>
				<button type="button" class="button" id="runwp-migrate-retry" hidden style="display: none;"><?php esc_html_e( 'Try again', 'runwp-migrate' ); ?></button>
				<button type="button" class="button" id="runwp-migrate-cancel"><?php esc_html_e( 'Cancel', 'runwp-migrate' ); ?></button>
			</p>
		</div>

		<div id="runwp-migrate-result"></div>
		<?php
	}

	/**
	 * Saved connections and the form to add one.
	 */
	private function render_connections() {
		$connections = RunWP_Migrate_Settings::connections();
		?>
		<h2><?php esc_html_e( 'Connected sites', 'runwp-migrate' ); ?></h2>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'runwp-migrate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Address', 'runwp-migrate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Added', 'runwp-migrate' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $connections ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No sites connected yet.', 'runwp-migrate' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $connections as $name => $connection ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $name ); ?></strong></td>
						<td><a href="<?php echo esc_url( $connection['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $connection['url'] ); ?></a></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $connection['added'] ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Remove this connection?', 'runwp-migrate' ) ) ); ?>)">
								<?php wp_nonce_field( 'runwp_migrate_remove_connection' ); ?>
								<input type="hidden" name="action" value="runwp_migrate_remove_connection">
								<input type="hidden" name="name" value="<?php echo esc_attr( $name ); ?>">
								<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Remove', 'runwp-migrate' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Connect a site', 'runwp-migrate' ); ?></h2>
		<p><?php esc_html_e( 'Install RunWP Migrate on the other site too, copy its connection info under Tools → RunWP Migrate → This site, and paste it here.', 'runwp-migrate' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'runwp_migrate_add_connection' ); ?>
			<input type="hidden" name="action" value="runwp_migrate_add_connection">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="runwp-migrate-name"><?php esc_html_e( 'Name', 'runwp-migrate' ); ?></label></th>
					<td>
						<input type="text" id="runwp-migrate-name" name="name" class="regular-text" placeholder="live" required>
						<p class="description"><?php esc_html_e( 'Short and without spaces, e.g. live or staging. WP-CLI uses it too: wp runwp-migrate pull live', 'runwp-migrate' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="runwp-migrate-info"><?php esc_html_e( 'Connection info', 'runwp-migrate' ); ?></label></th>
					<td><textarea id="runwp-migrate-info" name="info" class="large-text code" rows="3" required></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Test and save connection', 'runwp-migrate' ) ); ?>
		</form>
		<?php
	}

	/**
	 * This site's connection info and access switches.
	 */
	private function render_site() {
		?>
		<h2><?php esc_html_e( 'Connection info', 'runwp-migrate' ); ?></h2>
		<p><?php esc_html_e( 'Paste this on the other site to connect it to this one. Keep it to yourself: it gives access to everything you allow below.', 'runwp-migrate' ); ?></p>
		<textarea id="runwp-migrate-own-info" class="large-text code" rows="2" readonly><?php echo esc_textarea( RunWP_Migrate_Settings::connection_info() ); ?></textarea>
		<p><button type="button" class="button" data-runwp-migrate-copy="runwp-migrate-own-info"><?php esc_html_e( 'Copy', 'runwp-migrate' ); ?></button></p>

		<h2><?php esc_html_e( 'Access', 'runwp-migrate' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'runwp_migrate_save_access' ); ?>
			<input type="hidden" name="action" value="runwp_migrate_save_access">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Pull', 'runwp-migrate' ); ?></th>
					<td>
						<label><input type="checkbox" name="allow_pull" value="1" <?php checked( RunWP_Migrate_Settings::is_allowed( 'pull' ) ); ?>> <?php esc_html_e( 'Connected sites may read the database and media of this site', 'runwp-migrate' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Push', 'runwp-migrate' ); ?></th>
					<td>
						<label><input type="checkbox" name="allow_push" value="1" <?php checked( RunWP_Migrate_Settings::is_allowed( 'push' ) ); ?>> <?php esc_html_e( 'Connected sites may overwrite the database and media of this site', 'runwp-migrate' ); ?></label>
						<p class="description"><?php esc_html_e( 'On a live site, switch this on only for the moment you push to it.', 'runwp-migrate' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save', 'runwp-migrate' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Key', 'runwp-migrate' ); ?></h2>
		<p><?php esc_html_e( 'A new key locks out every site that is connected to this one. Connect them again with the new connection info.', 'runwp-migrate' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Create a new key? Connected sites lose access.', 'runwp-migrate' ) ) ); ?>)">
			<?php wp_nonce_field( 'runwp_migrate_regenerate_key' ); ?>
			<input type="hidden" name="action" value="runwp_migrate_regenerate_key">
			<?php submit_button( __( 'Create new key', 'runwp-migrate' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Restore this site, or undo a push on a connected one.
	 */
	private function render_backup() {
		$backup = RunWP_Migrate_Database::backup_info();
		?>
		<h2><?php esc_html_e( 'This site', 'runwp-migrate' ); ?></h2>
		<?php if ( $backup ) : ?>
			<p>
				<?php
				if ( $backup['time'] ) {
					printf(
						/* translators: 1: number of tables, 2: date and time, 3: origin address. */
						esc_html__( '%1$s tables were set aside on %2$s, before data from %3$s replaced them.', 'runwp-migrate' ),
						esc_html( number_format_i18n( (int) $backup['tables'] ) ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup['time'] ) ),
						'<code>' . esc_html( $backup['label'] ) . '</code>'
					);
				} else {
					/* translators: %s: number of tables. */
					printf( esc_html__( '%s tables from before the last migration are set aside.', 'runwp-migrate' ), esc_html( number_format_i18n( (int) $backup['tables'] ) ) );
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Put the old tables back? The data the migration brought is discarded, and you may have to log in again.', 'runwp-migrate' ) ) ); ?>)">
				<?php wp_nonce_field( 'runwp_migrate_restore' ); ?>
				<input type="hidden" name="action" value="runwp_migrate_restore">
				<?php submit_button( __( 'Restore this site', 'runwp-migrate' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p><?php esc_html_e( 'No backup yet. Every migration into this site keeps the tables it replaces until the next one.', 'runwp-migrate' ); ?></p>
		<?php endif; ?>

		<?php $connections = RunWP_Migrate_Settings::connections(); ?>
		<?php if ( $connections ) : ?>
			<h2><?php esc_html_e( 'Connected sites', 'runwp-migrate' ); ?></h2>
			<p><?php esc_html_e( 'Undo the last push to a connected site. That site has to allow pushing.', 'runwp-migrate' ); ?></p>
			<?php foreach ( $connections as $name => $connection ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin: 0 8px 8px 0;" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( /* translators: %s: site address. */ __( 'Put the tables of %s back as they were before the last push?', 'runwp-migrate' ), $connection['url'] ) ) ); ?>)">
					<?php wp_nonce_field( 'runwp_migrate_restore' ); ?>
					<input type="hidden" name="action" value="runwp_migrate_restore">
					<input type="hidden" name="connection" value="<?php echo esc_attr( $name ); ?>">
					<?php
					/* translators: %s: connection name. */
					submit_button( sprintf( __( 'Restore %s', 'runwp-migrate' ), $name ), 'secondary', 'submit', false );
					?>
				</form>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * The notice a handler left before redirecting here.
	 */
	private function notices() {
		$key    = 'runwp_migrate_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
	}

	/**
	 * Leave a notice and go back to a tab.
	 *
	 * @param string $tab     Tab to show.
	 * @param string $type    success, error, warning or info.
	 * @param string $message Text.
	 */
	private function back( $tab, $type, $message ) {
		set_transient(
			'runwp_migrate_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
		wp_safe_redirect( self::url( array( 'tab' => $tab ) ) );
		exit;
	}

	/**
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'runwp-migrate' ) );
		}
	}

	/**
	 * Save the access switches.
	 */
	public function handle_save_access() {
		$this->guard( 'runwp_migrate_save_access' );

		RunWP_Migrate_Settings::set_allowed( 'pull', ! empty( $_POST['allow_pull'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		RunWP_Migrate_Settings::set_allowed( 'push', ! empty( $_POST['allow_push'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$this->back( 'site', 'success', __( 'Access saved.', 'runwp-migrate' ) );
	}

	/**
	 * Replace the key.
	 */
	public function handle_regenerate_key() {
		$this->guard( 'runwp_migrate_regenerate_key' );
		RunWP_Migrate_Settings::regenerate_key();
		$this->back( 'site', 'success', __( 'New key created. Connect the other sites again.', 'runwp-migrate' ) );
	}

	/**
	 * Test and save a connection.
	 */
	public function handle_add_connection() {
		$this->guard( 'runwp_migrate_add_connection' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$name = RunWP_Migrate_Settings::sanitize_name( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
		$info = RunWP_Migrate_Settings::parse_connection_info( isset( $_POST['info'] ) ? sanitize_textarea_field( wp_unslash( $_POST['info'] ) ) : '' );
		// phpcs:enable

		if ( '' === $name ) {
			$this->back( 'connections', 'error', __( 'Give the connection a name.', 'runwp-migrate' ) );
		}
		if ( is_wp_error( $info ) ) {
			$this->back( 'connections', 'error', $info->get_error_message() );
		}

		$remote = ( new RunWP_Migrate_Remote( $info['url'], $info['key'] ) )->info();
		if ( is_wp_error( $remote ) ) {
			/* translators: %s: error message. */
			$this->back( 'connections', 'error', sprintf( __( 'Not saved: %s', 'runwp-migrate' ), $remote->get_error_message() ) );
		}

		RunWP_Migrate_Settings::add_connection( $name, $info['url'], $info['key'] );

		$this->back(
			'connections',
			'success',
			sprintf(
				/* translators: 1: connection name, 2: site name, 3: what the site allows. */
				__( 'Connected "%1$s" (%2$s). It allows: %3$s.', 'runwp-migrate' ),
				$name,
				$remote['name'],
				self::allowed_text( $remote )
			)
		);
	}

	/**
	 * @param array $info Remote info.
	 * @return string
	 */
	private static function allowed_text( array $info ) {
		$allowed = array();
		if ( ! empty( $info['allow_pull'] ) ) {
			$allowed[] = __( 'pull', 'runwp-migrate' );
		}
		if ( ! empty( $info['allow_push'] ) ) {
			$allowed[] = __( 'push', 'runwp-migrate' );
		}
		return $allowed ? implode( ', ', $allowed ) : __( 'nothing yet', 'runwp-migrate' );
	}

	/**
	 * Remove a connection.
	 */
	public function handle_remove_connection() {
		$this->guard( 'runwp_migrate_remove_connection' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		RunWP_Migrate_Settings::remove_connection( $name );

		$this->back( 'connections', 'success', __( 'Connection removed.', 'runwp-migrate' ) );
	}

	/**
	 * Restore this site or a connected one.
	 */
	public function handle_restore() {
		$this->guard( 'runwp_migrate_restore' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$connection = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( $_POST['connection'] ) ) : null;
		$result     = RunWP_Migrate_Migration::restore( $connection );

		if ( is_wp_error( $result ) ) {
			$this->back( 'backup', 'error', $result->get_error_message() );
		}

		/* translators: %s: number of tables. */
		$this->back( 'backup', 'success', sprintf( __( 'Restored %s tables.', 'runwp-migrate' ), number_format_i18n( (int) $result['tables'] ) ) );
	}

	/**
	 * AJAX guard.
	 */
	private function ajax_guard() {
		check_ajax_referer( 'runwp_migrate' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'runwp-migrate' ) ), 403 );
		}
	}

	/**
	 * @param array|WP_Error $result Result.
	 */
	private function ajax_respond( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Preview: what would be copied and replaced.
	 */
	public function ajax_plan() {
		$this->ajax_guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$direction  = isset( $_POST['direction'] ) && 'push' === $_POST['direction'] ? 'push' : 'pull';
		$connection = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( $_POST['connection'] ) ) : '';
		// phpcs:enable

		$plan = RunWP_Migrate_Migration::plan( $direction, $connection );
		if ( is_wp_error( $plan ) ) {
			$this->ajax_respond( $plan );
		}

		$tables = array();
		foreach ( $plan['tables'] as $table ) {
			$tables[] = array(
				'name' => $table['name'],
				'dest' => $table['dest'],
				'rows' => number_format_i18n( (int) $table['rows'] ),
				'size' => size_format( (int) $table['size'] ),
			);
		}

		$this->ajax_respond(
			array(
				'direction' => $direction,
				'from'      => array(
					'name'   => $plan['from']['name'],
					'home'   => $plan['from']['home'],
					'prefix' => $plan['from']['prefix'],
					'tables' => count( $plan['from']['tables'] ),
				),
				'to'        => array(
					'name'   => $plan['to']['name'],
					'home'   => $plan['to']['home'],
					'prefix' => $plan['to']['prefix'],
					'tables' => count( $plan['to']['tables'] ),
				),
				'pairs'     => array_map( null, array_keys( $plan['pairs'] ), array_values( $plan['pairs'] ) ),
				'tables'    => $tables,
			)
		);
	}

	/**
	 * Start.
	 */
	public function ajax_start() {
		$this->ajax_guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_guard().
		$direction  = isset( $_POST['direction'] ) && 'push' === $_POST['direction'] ? 'push' : 'pull';
		$connection = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( $_POST['connection'] ) ) : '';
		$db         = ! empty( $_POST['db'] );
		$media      = ! empty( $_POST['media'] );
		$tables     = isset( $_POST['tables'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tables'] ) ) : null;
		// phpcs:enable

		$state = RunWP_Migrate_Migration::start( $direction, $connection, $db, $media, $tables );
		$this->ajax_respond( is_wp_error( $state ) ? $state : self::progress( $state ) );
	}

	/**
	 * One step.
	 */
	public function ajax_step() {
		$this->ajax_guard();

		$state = RunWP_Migrate_Migration::step();
		$this->ajax_respond( is_wp_error( $state ) ? $state : self::progress( $state ) );
	}

	/**
	 * Cancel.
	 */
	public function ajax_cancel() {
		$this->ajax_guard();
		$this->ajax_respond( array( 'cancelled' => RunWP_Migrate_Migration::cancel() ) );
	}

	/**
	 * What the progress display needs from a state.
	 *
	 * @param array $state State.
	 * @return array
	 */
	private static function progress( array $state ) {
		$connection = RunWP_Migrate_Settings::connection( $state['connection'] );

		return array(
			'done'      => 'done' === $state['phase'],
			'percent'   => RunWP_Migrate_Migration::percent( $state ),
			'message'   => RunWP_Migrate_Migration::describe( $state ),
			'direction' => $state['direction'],
			'db'        => $state['db'],
			'media'     => $state['media'],
			'remote'    => $connection ? $connection['url'] : '',
		);
	}
}
