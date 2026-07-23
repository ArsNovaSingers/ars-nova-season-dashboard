<?php
/**
 * Uninstall cleanup for Ars Nova Season Dashboard.
 *
 * Removes the plugin option, cached transients, and per-user meta
 * (owner mapping + dashboard preferences). The Google Sheet itself is
 * never touched.
 *
 * @package ars-nova-season-dashboard
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options (settings — including any legacy sa_json — plus OAuth tokens).
delete_option( 'ans_dash_settings' );
delete_option( 'ans_oauth_refresh_token' );
delete_option( 'ans_oauth_account' );
delete_option( 'ans_dash_custom_buckets' );

// Transients (token + tasks cache).
delete_transient( 'ans_dash_google_token' );
delete_transient( 'ans_dash_tasks_cache' );

// Per-user meta for all users.
delete_metadata( 'user', 0, 'ans_owner', '', true );
delete_metadata( 'user', 0, 'ans_dash_prefs', '', true );
delete_metadata( 'user', 0, 'ans_dash_order', '', true );
