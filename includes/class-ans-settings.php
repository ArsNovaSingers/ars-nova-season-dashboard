<?php
/**
 * Admin settings: Google OAuth client (ID/secret + one-time connect flow),
 * sheet ID, write flag, cache TTL, and the WP-user -> tracker-Owner mapping.
 *
 * @package ars-nova-season-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ANS_Settings {

	const OPTION             = 'ans_dash_settings';
	const PAGE_SLUG          = 'ans-season-dashboard-settings';
	const MAP_ACTION         = 'ans_dash_save_owner_map';
	const CONNECT_ACTION     = 'ans_oauth_connect';
	const CALLBACK_ACTION    = 'ans_oauth_callback';
	const DISCONNECT_ACTION  = 'ans_oauth_disconnect';
	const STATE_NONCE_ACTION = 'ans_oauth_state';
	const AUTH_URL           = 'https://accounts.google.com/o/oauth2/v2/auth';
	const USERINFO_URL       = 'https://www.googleapis.com/oauth2/v3/userinfo';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_' . self::MAP_ACTION, array( __CLASS__, 'save_owner_map' ) );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_' . self::CALLBACK_ACTION, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * The exact redirect URI Google sends the admin back to. This precise
	 * string (query included) must be registered under “Authorized redirect
	 * URIs” on the OAuth client in Google Cloud.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php' ) . '?action=' . self::CALLBACK_ACTION;
	}

	/**
	 * Submenu under the dashboard menu (admins only).
	 */
	public static function register_page() {
		add_submenu_page(
			ANS_Dashboard::MENU_SLUG,
			__( 'Season Dashboard Settings', 'ars-nova-season-dashboard' ),
			__( 'Settings', 'ars-nova-season-dashboard' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the option + sanitizer with the Settings API.
	 */
	public static function register_settings() {
		register_setting(
			'ans_dash_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize the settings array. The Client Secret field keeps its previous
	 * value when submitted blank, so admins are never forced to re-paste it.
	 *
	 * @param array $input Raw POSTed option value.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		$out = array(
			'sheet_id'            => isset( $input['sheet_id'] ) ? sanitize_text_field( $input['sheet_id'] ) : '',
			'oauth_client_id'     => isset( $input['oauth_client_id'] ) ? sanitize_text_field( $input['oauth_client_id'] ) : '',
			'oauth_client_secret' => isset( $existing['oauth_client_secret'] ) ? (string) $existing['oauth_client_secret'] : '',
			'write_enabled'       => empty( $input['write_enabled'] ) ? 0 : 1,
			'cache_ttl'           => isset( $input['cache_ttl'] ) ? max( 15, absint( $input['cache_ttl'] ) ) : 60,
		);

		if ( '' === $out['sheet_id'] ) {
			$out['sheet_id'] = ANS_DASH_DEFAULT_SHEET_ID;
		}

		// New secret pasted? Blank means "keep the stored one".
		$raw_secret = isset( $input['oauth_client_secret'] ) ? trim( (string) wp_unslash( $input['oauth_client_secret'] ) ) : '';
		if ( '' !== $raw_secret ) {
			$out['oauth_client_secret'] = sanitize_text_field( $raw_secret );
			add_settings_error(
				self::OPTION,
				'ans_oauth_secret_saved',
				__( 'Client Secret saved. It is stored server-side only and never sent to the browser.', 'ars-nova-season-dashboard' ),
				'success'
			);
		}

		// Settings changed — clear the cached token + tasks so they rebuild.
		delete_transient( ANS_Sheets::TOKEN_TRANSIENT );
		delete_transient( ANS_Sheets::TASKS_TRANSIENT );

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * OAuth connect / callback / disconnect (admin-only, one-time)
	 * ------------------------------------------------------------------- */

	/**
	 * Redirect back to the settings page with a status flag.
	 *
	 * @param string $flag  Query flag name (e.g. 'ans_oauth_ok').
	 * @param string $value Flag value.
	 */
	private static function back_to_settings( $flag = '', $value = '1' ) {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $flag ) {
			$args[ $flag ] = $value;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin-post: build the Google authorization URL and send the admin there.
	 */
	public static function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ars-nova-season-dashboard' ) );
		}
		check_admin_referer( self::CONNECT_ACTION );

		$client_id = ans_dash_oauth_client_id();
		if ( '' === $client_id || '' === ans_dash_oauth_client_secret() ) {
			self::back_to_settings( 'ans_oauth_error', 'missing_client' );
		}

		$auth_url = add_query_arg(
			array(
				'response_type'          => 'code',
				'client_id'              => rawurlencode( $client_id ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'scope'                  => rawurlencode( ANS_Sheets::SCOPE ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => rawurlencode( wp_create_nonce( self::STATE_NONCE_ACTION ) ),
			),
			self::AUTH_URL
		);

		wp_redirect( $auth_url ); // phpcs:ignore WordPress.Security.SafeRedirect -- intentional external redirect to Google.
		exit;
	}

	/**
	 * admin-post: Google redirects back here with ?code & ?state. Exchange the
	 * code for tokens server-side and store the refresh token.
	 */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ars-nova-season-dashboard' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! wp_verify_nonce( $state, self::STATE_NONCE_ACTION ) ) {
			self::back_to_settings( 'ans_oauth_error', 'bad_state' );
		}

		if ( isset( $_GET['error'] ) ) {
			self::back_to_settings( 'ans_oauth_error', sanitize_key( wp_unslash( $_GET['error'] ) ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			self::back_to_settings( 'ans_oauth_error', 'no_code' );
		}

		$response = wp_remote_post(
			ANS_Sheets::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => ans_dash_oauth_client_id(),
					'client_secret' => ans_dash_oauth_client_secret(),
					'redirect_uri'  => self::redirect_uri(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::back_to_settings( 'ans_oauth_error', 'http_error' );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $http || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			self::back_to_settings( 'ans_oauth_error', 'exchange_failed' );
		}

		// Only overwrite the stored refresh token when Google returned a new,
		// non-empty one (prompt=consent should always produce one).
		if ( ! empty( $body['refresh_token'] ) && is_string( $body['refresh_token'] ) ) {
			update_option( ANS_Sheets::REFRESH_OPTION, $body['refresh_token'], false );
		} elseif ( '' === trim( (string) get_option( ANS_Sheets::REFRESH_OPTION, '' ) ) ) {
			self::back_to_settings( 'ans_oauth_error', 'no_refresh_token' );
		}

		// Best-effort: fetch the connected account's email for display.
		$account  = '';
		$userinfo = wp_remote_get(
			self::USERINFO_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $body['access_token'] ),
			)
		);
		if ( ! is_wp_error( $userinfo ) && 200 === (int) wp_remote_retrieve_response_code( $userinfo ) ) {
			$info = json_decode( wp_remote_retrieve_body( $userinfo ), true );
			if ( is_array( $info ) && ! empty( $info['email'] ) ) {
				$account = sanitize_email( (string) $info['email'] );
			}
		}
		update_option( ANS_Sheets::ACCOUNT_OPTION, $account, false );

		// Fresh connection — rebuild tokens and caches.
		delete_transient( ANS_Sheets::TOKEN_TRANSIENT );
		delete_transient( ANS_Sheets::TASKS_TRANSIENT );

		self::back_to_settings( 'ans_oauth_ok' );
	}

	/**
	 * admin-post: forget the stored refresh token + connected account.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ars-nova-season-dashboard' ) );
		}
		check_admin_referer( self::DISCONNECT_ACTION );

		delete_option( ANS_Sheets::REFRESH_OPTION );
		delete_option( ANS_Sheets::ACCOUNT_OPTION );
		delete_transient( ANS_Sheets::TOKEN_TRANSIENT );
		delete_transient( ANS_Sheets::TASKS_TRANSIENT );

		self::back_to_settings( 'ans_oauth_disconnected' );
	}

	/**
	 * Human-readable message for an OAuth error flag.
	 *
	 * @param string $flag Error flag from the callback redirect.
	 * @return string
	 */
	private static function oauth_error_message( $flag ) {
		$messages = array(
			'missing_client'   => __( 'Enter (and save) the Client ID and Client Secret before connecting.', 'ars-nova-season-dashboard' ),
			'bad_state'        => __( 'The connection attempt could not be verified (state mismatch). Please try again.', 'ars-nova-season-dashboard' ),
			'no_code'          => __( 'Google did not return an authorization code.', 'ars-nova-season-dashboard' ),
			'http_error'       => __( 'Could not reach Google to exchange the authorization code.', 'ars-nova-season-dashboard' ),
			'exchange_failed'  => __( 'Exchanging the authorization code failed. Check that the Client ID/Secret are correct and that the redirect URI shown below is registered on the OAuth client.', 'ars-nova-season-dashboard' ),
			'no_refresh_token' => __( 'Google did not return a refresh token. Remove the app’s access at myaccount.google.com/permissions and connect again.', 'ars-nova-season-dashboard' ),
			'access_denied'    => __( 'Consent was declined on the Google screen. Nothing was changed.', 'ars-nova-season-dashboard' ),
		);
		if ( isset( $messages[ $flag ] ) ) {
			return $messages[ $flag ];
		}
		/* translators: %s: error code returned by Google */
		return sprintf( __( 'Google returned an error: %s', 'ars-nova-season-dashboard' ), $flag );
	}

	/**
	 * admin-post handler for the owner-mapping form (separate from Settings API).
	 */
	public static function save_owner_map() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'ars-nova-season-dashboard' ) );
		}
		check_admin_referer( self::MAP_ACTION );

		$owners = ans_dash_owners();
		$map    = isset( $_POST['ans_owner_map'] ) && is_array( $_POST['ans_owner_map'] ) ? wp_unslash( $_POST['ans_owner_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per entry below.

		foreach ( $map as $user_id => $owner ) {
			$user_id = absint( $user_id );
			$owner   = sanitize_text_field( (string) $owner );
			if ( $user_id < 1 || false === get_userdata( $user_id ) ) {
				continue;
			}
			if ( '' === $owner ) {
				delete_user_meta( $user_id, 'ans_owner' );
			} elseif ( in_array( $owner, $owners, true ) ) {
				update_user_meta( $user_id, 'ans_owner', $owner );
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'ans_map_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings      = ans_dash_get_settings();
		$const_id      = defined( 'ARS_NOVA_OAUTH_CLIENT_ID' ) && '' !== trim( (string) ARS_NOVA_OAUTH_CLIENT_ID );
		$const_secret  = defined( 'ARS_NOVA_OAUTH_CLIENT_SECRET' ) && '' !== trim( (string) ARS_NOVA_OAUTH_CLIENT_SECRET );
		$const_write   = defined( 'ARS_NOVA_DASH_WRITE' );
		$has_secret    = '' !== ans_dash_oauth_client_secret();
		$refresh_token = trim( (string) get_option( ANS_Sheets::REFRESH_OPTION, '' ) );
		$connected     = '' !== $refresh_token;
		$account       = (string) get_option( ANS_Sheets::ACCOUNT_OPTION, '' );
		$redirect_uri  = self::redirect_uri();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Season Dashboard Settings', 'ars-nova-season-dashboard' ); ?></h1>

			<?php if ( isset( $_GET['ans_map_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Owner mapping saved.', 'ars-nova-season-dashboard' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ans_oauth_ok'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account connected. The dashboard can now read the tracker sheet.', 'ars-nova-season-dashboard' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ans_oauth_disconnected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google account disconnected. The stored refresh token was deleted.', 'ars-nova-season-dashboard' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ans_oauth_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( self::oauth_error_message( sanitize_key( wp_unslash( $_GET['ans_oauth_error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ans_dash_settings_group' ); ?>

				<h2><?php esc_html_e( 'Google connection', 'ars-nova-season-dashboard' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ans-sheet-id"><?php esc_html_e( 'Spreadsheet ID', 'ars-nova-season-dashboard' ); ?></label></th>
						<td>
							<input type="text" id="ans-sheet-id" class="regular-text code"
								name="<?php echo esc_attr( self::OPTION ); ?>[sheet_id]"
								value="<?php echo esc_attr( $settings['sheet_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The long ID from the tracker URL (docs.google.com/spreadsheets/d/…/edit). Must contain the “Tasks” and “Change Log” tabs.', 'ars-nova-season-dashboard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ans-oauth-client-id"><?php esc_html_e( 'OAuth Client ID', 'ars-nova-season-dashboard' ); ?></label></th>
						<td>
							<?php if ( $const_id ) : ?>
								<p><strong><?php esc_html_e( 'Using the ARS_NOVA_OAUTH_CLIENT_ID constant from wp-config.php — this field is ignored.', 'ars-nova-season-dashboard' ); ?></strong></p>
							<?php else : ?>
								<input type="text" id="ans-oauth-client-id" class="large-text code"
									name="<?php echo esc_attr( self::OPTION ); ?>[oauth_client_id]"
									value="<?php echo esc_attr( $settings['oauth_client_id'] ); ?>" />
								<p class="description"><?php esc_html_e( 'From Google Cloud → APIs & Services → Credentials → OAuth 2.0 Client ID (Web application). Looks like ….apps.googleusercontent.com.', 'ars-nova-season-dashboard' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ans-oauth-client-secret"><?php esc_html_e( 'OAuth Client Secret', 'ars-nova-season-dashboard' ); ?></label></th>
						<td>
							<?php if ( $const_secret ) : ?>
								<p><strong><?php esc_html_e( 'Using the ARS_NOVA_OAUTH_CLIENT_SECRET constant from wp-config.php — this field is ignored.', 'ars-nova-season-dashboard' ); ?></strong></p>
							<?php else : ?>
								<input type="password" id="ans-oauth-client-secret" class="regular-text code"
									name="<?php echo esc_attr( self::OPTION ); ?>[oauth_client_secret]"
									value="" autocomplete="new-password"
									placeholder="<?php echo esc_attr( $has_secret ? __( 'A secret is stored. Enter a new one only to replace it.', 'ars-nova-season-dashboard' ) : 'GOCSPX-…' ); ?>" />
								<p class="description"><?php esc_html_e( 'Stored server-side only and never sent to the browser. Leave blank to keep the current secret. For extra safety you can instead define ARS_NOVA_OAUTH_CLIENT_SECRET in wp-config.php.', 'ars-nova-season-dashboard' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ans-oauth-redirect-uri"><?php esc_html_e( 'Authorized redirect URI', 'ars-nova-season-dashboard' ); ?></label></th>
						<td>
							<input type="text" id="ans-oauth-redirect-uri" class="large-text code" readonly
								onfocus="this.select();"
								value="<?php echo esc_attr( $redirect_uri ); ?>" />
							<p class="description"><?php esc_html_e( 'Copy this exact URL into the OAuth client’s “Authorized redirect URIs” list in Google Cloud before connecting.', 'ars-nova-season-dashboard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Google account', 'ars-nova-season-dashboard' ); ?></th>
						<td>
							<?php if ( $connected ) : ?>
								<p>
									<?php if ( '' !== $account ) : ?>
										<?php
										printf(
											/* translators: %s: connected Google account email */
											esc_html__( 'Connected as %s.', 'ars-nova-season-dashboard' ),
											'<code>' . esc_html( $account ) . '</code>'
										);
										?>
									<?php else : ?>
										<?php esc_html_e( 'Connected (account email unknown).', 'ars-nova-season-dashboard' ); ?>
									<?php endif; ?>
									<br />
									<span class="description">
										<?php
										printf(
											/* translators: %s: granted OAuth scope */
											esc_html__( 'Granted scope: %s', 'ars-nova-season-dashboard' ),
											'<code>' . esc_html( ANS_Sheets::SCOPE ) . '</code>'
										);
										?>
									</span>
								</p>
							<?php else : ?>
								<p><?php esc_html_e( 'Not connected. Save the Client ID and Secret above, register the redirect URI in Google Cloud, then connect.', 'ars-nova-season-dashboard' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ans-cache-ttl"><?php esc_html_e( 'Read cache (seconds)', 'ars-nova-season-dashboard' ); ?></label></th>
						<td>
							<input type="number" id="ans-cache-ttl" min="15" step="1" class="small-text"
								name="<?php echo esc_attr( self::OPTION ); ?>[cache_ttl]"
								value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How long sheet reads are cached before hitting Google again. Default 60.', 'ars-nova-season-dashboard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Write-back', 'ars-nova-season-dashboard' ); ?></th>
						<td>
							<?php if ( $const_write ) : ?>
								<p><strong>
									<?php
									echo esc_html(
										ans_dash_write_enabled()
											? __( 'ENABLED via the ARS_NOVA_DASH_WRITE constant in wp-config.php (this checkbox is ignored).', 'ars-nova-season-dashboard' )
											: __( 'DISABLED via the ARS_NOVA_DASH_WRITE constant in wp-config.php (this checkbox is ignored).', 'ars-nova-season-dashboard' )
									);
									?>
								</strong></p>
							<?php else : ?>
								<label for="ans-write-enabled">
									<input type="checkbox" id="ans-write-enabled" value="1"
										name="<?php echo esc_attr( self::OPTION ); ?>[write_enabled]"
										<?php checked( ! empty( $settings['write_enabled'] ) ); ?> />
									<?php esc_html_e( 'Allow changing Status (drag) and Owner (dropdown) from the board, with Change Log entries.', 'ars-nova-season-dashboard' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Requires the connected Google account to have Editor access on the sheet. You can also hard-set this with define( \'ARS_NOVA_DASH_WRITE\', true ) in wp-config.php, which overrides this checkbox.', 'ars-nova-season-dashboard' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'ars-nova-season-dashboard' ) ); ?>
			</form>

			<?php if ( $connected ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::DISCONNECT_ACTION ); ?>" />
					<?php wp_nonce_field( self::DISCONNECT_ACTION ); ?>
					<?php submit_button( __( 'Disconnect', 'ars-nova-season-dashboard' ), 'delete', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Deletes the stored refresh token and connected-account record. The dashboard stops working until an account is connected again.', 'ars-nova-season-dashboard' ); ?></p>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CONNECT_ACTION ); ?>" />
					<?php wp_nonce_field( self::CONNECT_ACTION ); ?>
					<?php submit_button( __( 'Connect Google account', 'ars-nova-season-dashboard' ), 'primary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Sends you to Google to consent as an account that can open the tracker sheet (Editor access if write-back will be enabled). The refresh token is stored server-side only.', 'ars-nova-season-dashboard' ); ?></p>
				</form>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'Owner mapping (WP user → tracker name)', 'ars-nova-season-dashboard' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Controls each person’s “My tasks” view. Users can also pick their own name the first time they open the dashboard.', 'ars-nova-season-dashboard' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::MAP_ACTION ); ?>" />
				<?php wp_nonce_field( self::MAP_ACTION ); ?>
				<table class="widefat striped" style="max-width:640px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WordPress user', 'ars-nova-season-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Tracker owner', 'ars-nova-season-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( get_users( array( 'orderby' => 'display_name', 'number' => 200 ) ) as $user ) : ?>
							<?php $current = (string) get_user_meta( $user->ID, 'ans_owner', true ); ?>
							<tr>
								<td><?php echo esc_html( $user->display_name ); ?> <span class="description">(<?php echo esc_html( $user->user_login ); ?>)</span></td>
								<td>
									<select name="ans_owner_map[<?php echo esc_attr( (string) $user->ID ); ?>]">
										<option value=""><?php esc_html_e( '— none —', 'ars-nova-season-dashboard' ); ?></option>
										<?php foreach ( ans_dash_owners() as $owner ) : ?>
											<option value="<?php echo esc_attr( $owner ); ?>" <?php selected( $current, $owner ); ?>><?php echo esc_html( $owner ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save owner mapping', 'ars-nova-season-dashboard' ) ); ?>
			</form>
		</div>
		<?php
	}
}
