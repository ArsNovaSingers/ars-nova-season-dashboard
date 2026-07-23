<?php
/**
 * Plugin Name:       Ars Nova Season Dashboard
 * Plugin URI:        https://arsnovasingers.org/
 * Description:       Logged-in team dashboard that renders the Ars Nova Season Tracker (Google Sheet) as a per-person Kanban board with personalized views and optional write-back.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Ars Nova Singers
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ars-nova-season-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ANS_DASH_VERSION', '1.3.0' );
define( 'ANS_DASH_FILE', __FILE__ );
define( 'ANS_DASH_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANS_DASH_URL', plugin_dir_url( __FILE__ ) );

/**
 * Default spreadsheet (Season Tracker). Can be overridden on the settings page.
 */
define( 'ANS_DASH_DEFAULT_SHEET_ID', '1J0_F58MBUK1vmbpAAnA5EcwshqfiEZGHLr_ARCJhE_M' );

/*
 * -------------------------------------------------------------------------
 * Vocabulary helpers (single source of truth — tweak here if the tracker
 * columns, people, or colors ever change).
 * -------------------------------------------------------------------------
 */

/**
 * Fixed Status vocabulary, in Kanban column order.
 *
 * @return string[]
 */
function ans_dash_statuses() {
	return array( 'Backlog', 'To Do', 'In Progress', 'Blocked', 'Review', 'Done' );
}

/**
 * Priority vocabulary, highest first.
 *
 * @return string[]
 */
function ans_dash_priorities() {
	return array( 'P1', 'P2', 'P3', 'P4' );
}

/**
 * Dashboard layout vocabulary. First entry is the default.
 *
 * @return string[]
 */
function ans_dash_layouts() {
	return array( 'board', 'list', 'checklist' );
}

/**
 * Tracker owners.
 *
 * @return string[]
 */
function ans_dash_owners() {
	return array( 'Kim', 'Tom', 'Jonathan', 'Zahnay' );
}

/**
 * Allowed task colors (column O), ordered for the palette popover.
 * Keys are human names, values are the hex codes stored in the sheet.
 *
 * @return array<string,string>
 */
function ans_dash_colors() {
	return array(
		'red'    => '#e5484d',
		'orange' => '#f5a623',
		'yellow' => '#f2d600',
		'green'  => '#2e7d32',
		'blue'   => '#1565c0',
		'purple' => '#7b1fa2',
		'gray'   => '#9e9e9e',
	);
}

/**
 * Site-wide custom tag names (persisted "Bucket" values that may not be on
 * any task yet). Stored in the 'ans_dash_custom_buckets' option.
 *
 * @return string[]
 */
function ans_dash_custom_buckets() {
	$tags = get_option( 'ans_dash_custom_buckets', array() );
	if ( ! is_array( $tags ) ) {
		$tags = array();
	}
	return array_values( array_map( 'strval', $tags ) );
}

/**
 * Plugin settings with defaults applied.
 *
 * @return array{sheet_id:string,oauth_client_id:string,oauth_client_secret:string,write_enabled:int,cache_ttl:int}
 */
function ans_dash_get_settings() {
	$defaults = array(
		'sheet_id'            => ANS_DASH_DEFAULT_SHEET_ID,
		'oauth_client_id'     => '',
		'oauth_client_secret' => '',
		'write_enabled'       => 0,
		'cache_ttl'           => 60,
	);
	$saved = get_option( 'ans_dash_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings = array_merge( $defaults, $saved );
	if ( '' === trim( (string) $settings['sheet_id'] ) ) {
		$settings['sheet_id'] = ANS_DASH_DEFAULT_SHEET_ID;
	}
	$settings['cache_ttl'] = max( 15, (int) $settings['cache_ttl'] );
	return $settings;
}

/**
 * Is write-back enabled?
 *
 * The ARS_NOVA_DASH_WRITE constant (wp-config.php) always wins when defined;
 * otherwise the settings checkbox applies. Default: false (read-only v1).
 *
 * @return bool
 */
function ans_dash_write_enabled() {
	if ( defined( 'ARS_NOVA_DASH_WRITE' ) ) {
		return (bool) ARS_NOVA_DASH_WRITE;
	}
	$settings = ans_dash_get_settings();
	return ! empty( $settings['write_enabled'] );
}

/**
 * The Google OAuth 2.0 web-application Client ID. Prefers the
 * ARS_NOVA_OAUTH_CLIENT_ID constant (wp-config.php) over the stored option.
 *
 * @return string
 */
function ans_dash_oauth_client_id() {
	if ( defined( 'ARS_NOVA_OAUTH_CLIENT_ID' ) && is_string( ARS_NOVA_OAUTH_CLIENT_ID ) && '' !== trim( ARS_NOVA_OAUTH_CLIENT_ID ) ) {
		return trim( ARS_NOVA_OAUTH_CLIENT_ID );
	}
	$settings = ans_dash_get_settings();
	return trim( (string) $settings['oauth_client_id'] );
}

/**
 * The Google OAuth 2.0 web-application Client Secret. Prefers the
 * ARS_NOVA_OAUTH_CLIENT_SECRET constant (wp-config.php) over the stored
 * option. Never sent to the browser.
 *
 * @return string
 */
function ans_dash_oauth_client_secret() {
	if ( defined( 'ARS_NOVA_OAUTH_CLIENT_SECRET' ) && is_string( ARS_NOVA_OAUTH_CLIENT_SECRET ) && '' !== trim( ARS_NOVA_OAUTH_CLIENT_SECRET ) ) {
		return trim( ARS_NOVA_OAUTH_CLIENT_SECRET );
	}
	$settings = ans_dash_get_settings();
	return trim( (string) $settings['oauth_client_secret'] );
}

/*
 * -------------------------------------------------------------------------
 * Bootstrap
 * -------------------------------------------------------------------------
 */

require_once ANS_DASH_DIR . 'includes/class-ans-sheets.php';
require_once ANS_DASH_DIR . 'includes/class-ans-dashboard.php';
require_once ANS_DASH_DIR . 'includes/class-ans-ajax.php';
require_once ANS_DASH_DIR . 'includes/class-ans-settings.php';

/**
 * Shared Sheets client instance.
 *
 * @return ANS_Sheets
 */
function ans_dash_sheets() {
	static $client = null;
	if ( null === $client ) {
		$settings = ans_dash_get_settings();
		$client   = new ANS_Sheets( $settings['sheet_id'], ans_dash_oauth_client_id(), ans_dash_oauth_client_secret(), $settings['cache_ttl'] );
	}
	return $client;
}

add_action( 'plugins_loaded', function () {
	ANS_Dashboard::init();
	ANS_Ajax::init();
	ANS_Settings::init();
} );
