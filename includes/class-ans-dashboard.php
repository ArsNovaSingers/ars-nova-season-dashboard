<?php
/**
 * Dashboard rendering: [ans_season_dashboard] shortcode + wp-admin menu page.
 *
 * The PHP side renders an empty shell plus a JSON config blob; the board
 * itself is drawn client-side from data fetched over admin-ajax (so the
 * shortcode page and the admin page share one code path, and Refresh works
 * without a full page load).
 *
 * @package ars-nova-season-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ANS_Dashboard {

	const SHORTCODE  = 'ans_season_dashboard';
	const MENU_SLUG  = 'ans-season-dashboard';
	const NONCE_NAME = 'ans_dash_nonce';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) CSS/JS so either context can pull them in.
	 */
	public static function register_assets() {
		if ( wp_style_is( 'ans-dash', 'registered' ) ) {
			return;
		}
		wp_register_style( 'ans-dash', ANS_DASH_URL . 'assets/dashboard.css', array(), ANS_DASH_VERSION );
		wp_register_script( 'ans-dash', ANS_DASH_URL . 'assets/dashboard.js', array(), ANS_DASH_VERSION, true );
	}

	/**
	 * Admin menu page (visible to any logged-in user; 'read' capability).
	 */
	public static function register_admin_page() {
		add_menu_page(
			__( 'Season Dashboard', 'ars-nova-season-dashboard' ),
			__( 'Season Dashboard', 'ars-nova-season-dashboard' ),
			'read',
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-schedule',
			3
		);
	}

	/**
	 * Admin page callback.
	 */
	public static function render_admin_page() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ars Nova Season Dashboard', 'ars-nova-season-dashboard' ) . '</h1>';
		echo self::render_dashboard(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaped parts below.
		echo '</div>';
	}

	/**
	 * Shortcode callback.
	 *
	 * @return string
	 */
	public static function render_shortcode() {
		return self::render_dashboard();
	}

	/**
	 * Current user's saved dashboard preferences (with defaults applied).
	 *
	 * @param int $user_id WP user ID.
	 * @return array
	 */
	public static function get_user_prefs( $user_id ) {
		$defaults = array(
			'owner'             => '',      // Default Owner filter ('' = All).
			'bucket'            => '',      // Default Bucket filter ('' = All).
			'priority'          => '',      // Default Priority filter ('' = All).
			'layout'            => 'board', // 'board' | 'list' | 'checklist'.
			'my_tasks'          => false,   // "My tasks" toggle on by default?
			'hide_done'         => false,   // Legacy (pre-Open|Done checklist); kept so stored prefs stay valid.
			'hidden_fields'     => array(), // Card/list fields hidden: bucket,event,due,owner,priority.
			'collapsed_buckets' => array(), // Bucket names collapsed in list view.
		);
		$saved = get_user_meta( $user_id, 'ans_dash_prefs', true );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$prefs = array_merge( $defaults, $saved );
		$prefs['hidden_fields']     = array_values( array_map( 'strval', (array) $prefs['hidden_fields'] ) );
		$prefs['collapsed_buckets'] = array_values( array_map( 'strval', (array) $prefs['collapsed_buckets'] ) );
		$prefs['my_tasks']          = (bool) $prefs['my_tasks'];
		$prefs['hide_done']         = (bool) $prefs['hide_done'];
		if ( ! in_array( $prefs['layout'], ans_dash_layouts(), true ) ) {
			$prefs['layout'] = 'board';
		}
		return $prefs;
	}

	/**
	 * Shared shell renderer for both contexts.
	 *
	 * @return string
	 */
	public static function render_dashboard() {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="ans-dash-login-required"><p>%s</p><p><a class="ans-dash-login-link" href="%s">%s</a></p></div>',
				esc_html__( 'This dashboard is for the Ars Nova team. Please log in to view it.', 'ars-nova-season-dashboard' ),
				esc_url( wp_login_url( self::current_url() ) ),
				esc_html__( 'Log in', 'ars-nova-season-dashboard' )
			);
		}

		wp_enqueue_style( 'ans-dash' );
		wp_enqueue_script( 'ans-dash' );

		$user  = wp_get_current_user();
		$owner = (string) get_user_meta( $user->ID, 'ans_owner', true );

		$order = get_user_meta( $user->ID, 'ans_dash_order', true );
		if ( ! is_array( $order ) ) {
			$order = array();
		}
		$order = array_values( array_map( 'strval', $order ) );

		$config = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::NONCE_NAME ),
			'writeEnabled'  => ans_dash_write_enabled(),
			'statuses'      => ans_dash_statuses(),
			'priorities'    => ans_dash_priorities(),
			'owners'        => ans_dash_owners(),
			'colors'        => ans_dash_colors(),
			'customBuckets' => ans_dash_custom_buckets(),
			'order'         => $order,
			'currentOwner'  => in_array( $owner, ans_dash_owners(), true ) ? $owner : '',
			'projectName'   => 'Ars Nova',
			'skillName'     => 'ans-task-runner',
			'displayName'   => $user->display_name,
			'prefs'         => self::get_user_prefs( $user->ID ),
			'canManage'     => current_user_can( 'manage_options' ),
			'i18n'          => array(
				'loading'       => __( 'Loading the Season Tracker…', 'ars-nova-season-dashboard' ),
				'loadError'     => __( 'Could not load the tracker.', 'ars-nova-season-dashboard' ),
				'saveError'     => __( 'Save failed.', 'ars-nova-season-dashboard' ),
				'saved'         => __( 'Saved.', 'ars-nova-season-dashboard' ),
				'empty'         => __( 'No tasks match the current filters.', 'ars-nova-season-dashboard' ),
				'openEmpty'     => __( 'Nothing open — all caught up.', 'ars-nova-season-dashboard' ),
				'doneEmpty'     => __( 'Nothing marked Done yet.', 'ars-nova-season-dashboard' ),
				'refreshed'     => __( 'Tracker refreshed.', 'ars-nova-season-dashboard' ),
				'pickOwnerLead' => __( 'Who are you on the tracker? Pick your name to enable “My tasks”.', 'ars-nova-season-dashboard' ),
				'taskCreated'   => __( 'Task created.', 'ars-nova-season-dashboard' ),
				'taskRequired'  => __( 'Please enter the task text.', 'ars-nova-season-dashboard' ),
				'writeOff'      => __( 'Write-back is disabled, so checkboxes are read-only. An administrator can enable it (ARS_NOVA_DASH_WRITE or the Settings checkbox).', 'ars-nova-season-dashboard' ),
				'undo'          => __( 'Undo', 'ars-nova-season-dashboard' ),
				/* translators: %s: task title */
				'markedDone'    => __( 'Marked "%s" as Done', 'ars-nova-season-dashboard' ),
				/* translators: %s: task title */
				'markedOpen'    => __( 'Marked "%s" as not done', 'ars-nova-season-dashboard' ),
				'undone'        => __( 'Change undone.', 'ars-nova-season-dashboard' ),
				'orderSaved'    => __( 'Order saved.', 'ars-nova-season-dashboard' ),
				'tagAdded'      => __( 'Tag added.', 'ars-nova-season-dashboard' ),
				'tagRequired'   => __( 'Please enter a tag name.', 'ars-nova-season-dashboard' ),
				'noParent'      => __( '— none —', 'ars-nova-season-dashboard' ),
			),
		);

		wp_localize_script( 'ans-dash', 'ansDashConfig', $config );

		ob_start();
		?>
		<div id="ans-dash-app" class="ans-dash" data-write="<?php echo esc_attr( ans_dash_write_enabled() ? '1' : '0' ); ?>">
			<div class="ans-dash-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Dashboard controls', 'ars-nova-season-dashboard' ); ?>"></div>
			<div class="ans-dash-summary" aria-live="polite"></div>
			<div class="ans-dash-owner-banner" hidden></div>
			<div class="ans-dash-notice" role="status" aria-live="polite" hidden></div>
			<div class="ans-dash-body">
				<p class="ans-dash-loading"><?php esc_html_e( 'Loading the Season Tracker…', 'ars-nova-season-dashboard' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Best-effort current URL for the login redirect.
	 *
	 * @return string
	 */
	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}
}
