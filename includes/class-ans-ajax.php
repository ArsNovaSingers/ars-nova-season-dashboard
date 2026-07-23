<?php
/**
 * Authenticated admin-ajax endpoints.
 *
 * Every endpoint requires a logged-in user and the shared nonce. There are
 * deliberately no wp_ajax_nopriv_* hooks — anonymous requests get WP's
 * default "0"/400 response and never touch the Sheet.
 *
 * Actions:
 *  - ans_dash_fetch        Read the tracker (optionally cache-busting).
 *  - ans_dash_save_prefs   Persist the user's default view (user meta).
 *  - ans_dash_set_owner    Map a WP user to a tracker Owner (self or admin).
 *  - ans_dash_update_task  Write-back: change an editable field (feature-flagged).
 *  - ans_dash_create_task  Write-back: append a new task row (feature-flagged).
 *  - ans_dash_save_order   Persist the user's checklist drag-order (user meta;
 *                          not a sheet write, so it works with write-back off).
 *  - ans_dash_add_tag      Persist a custom tag name (site option; not a sheet
 *                          write, so it works with write-back off).
 *
 * @package ars-nova-season-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ANS_Ajax {

	/**
	 * Hook the endpoints.
	 */
	public static function init() {
		add_action( 'wp_ajax_ans_dash_fetch', array( __CLASS__, 'fetch' ) );
		add_action( 'wp_ajax_ans_dash_save_prefs', array( __CLASS__, 'save_prefs' ) );
		add_action( 'wp_ajax_ans_dash_set_owner', array( __CLASS__, 'set_owner' ) );
		add_action( 'wp_ajax_ans_dash_update_task', array( __CLASS__, 'update_task' ) );
		add_action( 'wp_ajax_ans_dash_create_task', array( __CLASS__, 'create_task' ) );
		add_action( 'wp_ajax_ans_dash_save_order', array( __CLASS__, 'save_order' ) );
		add_action( 'wp_ajax_ans_dash_add_tag', array( __CLASS__, 'add_tag' ) );
	}

	/**
	 * Common guard: nonce + login. Dies with a JSON error on failure.
	 */
	private static function guard() {
		check_ajax_referer( ANS_Dashboard::NONCE_NAME, 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'ars-nova-season-dashboard' ) ), 401 );
		}
	}

	/**
	 * Send a WP_Error as a JSON error response.
	 *
	 * @param WP_Error $error Error to relay.
	 */
	private static function send_error( WP_Error $error ) {
		wp_send_json_error( array( 'message' => $error->get_error_message() ), 500 );
	}

	/* ---------------------------------------------------------------------
	 * Read
	 * ------------------------------------------------------------------- */

	/**
	 * Fetch tasks (cached ~60s; refresh=1 busts the cache).
	 */
	public static function fetch() {
		self::guard();

		$refresh = ! empty( $_POST['refresh'] );
		$sheets  = ans_dash_sheets();

		if ( ! $sheets->is_configured() ) {
			wp_send_json_error(
				array( 'message' => __( 'The dashboard is not connected to Google yet. An administrator must add the OAuth Client ID/Secret and click “Connect Google account” under Season Dashboard → Settings.', 'ars-nova-season-dashboard' ) ),
				500
			);
		}

		$result = $sheets->get_tasks( $refresh );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'tasks'        => $result['tasks'],
				'fetchedAt'    => (int) $result['fetched_at'],
				'writeEnabled' => ans_dash_write_enabled(),
				'today'        => current_time( 'Y-m-d' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Preferences
	 * ------------------------------------------------------------------- */

	/**
	 * Save the current user's default-view preferences.
	 * Expects POST 'prefs' = JSON string; every field is whitelisted.
	 */
	public static function save_prefs() {
		self::guard();

		$raw = isset( $_POST['prefs'] ) ? wp_unslash( $_POST['prefs'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, sanitized field-by-field below.
		$in  = json_decode( (string) $raw, true );
		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid preferences payload.', 'ars-nova-season-dashboard' ) ), 400 );
		}

		$owners     = ans_dash_owners();
		$priorities = ans_dash_priorities();
		$allowed_fields = array( 'bucket', 'event', 'due', 'owner', 'priority', 'notes' );

		$prefs = array(
			'owner'    => ( isset( $in['owner'] ) && in_array( $in['owner'], $owners, true ) ) ? $in['owner'] : '',
			'bucket'   => isset( $in['bucket'] ) ? sanitize_text_field( (string) $in['bucket'] ) : '',
			'priority' => ( isset( $in['priority'] ) && in_array( $in['priority'], $priorities, true ) ) ? $in['priority'] : '',
			'layout'   => ( isset( $in['layout'] ) && in_array( $in['layout'], ans_dash_layouts(), true ) ) ? $in['layout'] : 'board',
			'my_tasks' => ! empty( $in['my_tasks'] ),
			'hide_done' => ! empty( $in['hide_done'] ),
			'hidden_fields'     => array(),
			'collapsed_buckets' => array(),
		);

		if ( isset( $in['hidden_fields'] ) && is_array( $in['hidden_fields'] ) ) {
			foreach ( $in['hidden_fields'] as $field ) {
				$field = sanitize_key( (string) $field );
				if ( in_array( $field, $allowed_fields, true ) ) {
					$prefs['hidden_fields'][] = $field;
				}
			}
		}

		if ( isset( $in['collapsed_buckets'] ) && is_array( $in['collapsed_buckets'] ) ) {
			foreach ( array_slice( (array) $in['collapsed_buckets'], 0, 50 ) as $bucket ) {
				$bucket = sanitize_text_field( (string) $bucket );
				if ( '' !== $bucket ) {
					$prefs['collapsed_buckets'][] = $bucket;
				}
			}
		}

		update_user_meta( get_current_user_id(), 'ans_dash_prefs', $prefs );

		wp_send_json_success( array( 'prefs' => $prefs ) );
	}

	/**
	 * Map a WP user to a tracker Owner.
	 * Users may set their own mapping (first-load self-select); only admins
	 * may set someone else's.
	 */
	public static function set_owner() {
		self::guard();

		$owner   = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();

		if ( '' !== $owner && ! in_array( $owner, ans_dash_owners(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown owner.', 'ars-nova-season-dashboard' ) ), 400 );
		}

		if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You can only set your own tracker name.', 'ars-nova-season-dashboard' ) ), 403 );
		}

		if ( '' === $owner ) {
			delete_user_meta( $user_id, 'ans_owner' );
		} else {
			update_user_meta( $user_id, 'ans_owner', $owner );
		}

		wp_send_json_success( array( 'owner' => $owner ) );
	}

	/* ---------------------------------------------------------------------
	 * Write-back (feature-flagged)
	 * ------------------------------------------------------------------- */

	/**
	 * Change one editable field on a task: Status, Owner, Priority, Bucket
	 * (shown in the UI as "Tag"), Color, Parent Task ID, Notes or Links.
	 * Guarded by the ARS_NOVA_DASH_WRITE flag; validates field + value against
	 * the fixed vocabularies; resolves the sheet row server-side by Task ID.
	 */
	public static function update_task() {
		self::guard();

		if ( ! ans_dash_write_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Write-back is disabled. Define ARS_NOVA_DASH_WRITE as true to enable it.', 'ars-nova-season-dashboard' ) ), 403 );
		}

		$task_id   = isset( $_POST['task_id'] ) ? sanitize_text_field( wp_unslash( $_POST['task_id'] ) ) : '';
		$field     = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
		$raw_value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per-field below.

		if ( '' === $task_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing Task ID.', 'ars-nova-season-dashboard' ) ), 400 );
		}

		// Validate + sanitize the value per field.
		switch ( $field ) {
			case 'Status':
				$value = sanitize_text_field( (string) $raw_value );
				if ( ! in_array( $value, ans_dash_statuses(), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid status.', 'ars-nova-season-dashboard' ) ), 400 );
				}
				break;

			case 'Owner':
				$value = sanitize_text_field( (string) $raw_value );
				if ( '' !== $value && ! in_array( $value, ans_dash_owners(), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid owner.', 'ars-nova-season-dashboard' ) ), 400 );
				}
				break;

			case 'Priority':
				$value = sanitize_text_field( (string) $raw_value );
				if ( '' !== $value && ! in_array( $value, ans_dash_priorities(), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid priority.', 'ars-nova-season-dashboard' ) ), 400 );
				}
				break;

			case 'Color':
				$value  = strtolower( sanitize_text_field( (string) $raw_value ) );
				$colors = ans_dash_colors();
				if ( '' !== $value && ! isset( $colors[ $value ] ) && ! in_array( $value, array_map( 'strtolower', array_values( $colors ) ), true ) ) {
					wp_send_json_error( array( 'message' => __( 'Invalid color.', 'ars-nova-season-dashboard' ) ), 400 );
				}
				break;

			case 'Bucket':
				$value = sanitize_text_field( (string) $raw_value );
				if ( mb_strlen( $value ) > 100 ) {
					wp_send_json_error( array( 'message' => __( 'Tag is too long (100 characters max).', 'ars-nova-season-dashboard' ) ), 400 );
				}
				break;

			case 'Notes':
				$value = sanitize_textarea_field( (string) $raw_value );
				if ( mb_strlen( $value ) > 5000 ) {
					wp_send_json_error( array( 'message' => __( 'Notes are too long (5000 characters max).', 'ars-nova-season-dashboard' ) ), 400 );
				}
				break;

			case 'Links':
				$value = sanitize_textarea_field( (string) $raw_value );
				if ( mb_strlen( $value ) > 2000 ) {
					wp_send_json_error( array( 'message' => __( 'Links are too long (2000 characters max).', 'ars-nova-season-dashboard' ) ), 400 );
				}
				// One URL per line; invalid lines are dropped.
				$lines = array();
				foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$url = esc_url_raw( $line );
					if ( '' !== $url ) {
						$lines[] = $url;
					}
				}
				$value = implode( "\n", $lines );
				break;

			case 'Parent Task ID':
				$value = strtoupper( sanitize_text_field( (string) $raw_value ) );
				if ( '' !== $value ) {
					if ( ! preg_match( '/^TSK-\d+$/i', $value ) ) {
						wp_send_json_error( array( 'message' => __( 'Invalid parent task ID.', 'ars-nova-season-dashboard' ) ), 400 );
					}
					if ( 0 === strcasecmp( $value, $task_id ) ) {
						wp_send_json_error( array( 'message' => __( 'A task cannot be its own parent.', 'ars-nova-season-dashboard' ) ), 400 );
					}
				}
				break;

			default:
				wp_send_json_error( array( 'message' => __( 'That field cannot be edited from the dashboard.', 'ars-nova-season-dashboard' ) ), 400 );
		}

		$actor  = wp_get_current_user()->display_name;
		$result = ans_dash_sheets()->update_task_field( $task_id, $field, $value, $actor );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'task'  => $result['task'],
				'today' => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Create a new task from the "+ New Task" form. Guarded by the write flag;
	 * every field is sanitized and validated against the fixed vocabularies.
	 * The server assigns the Task ID (and Status/Source/Created/Updated) —
	 * client-provided IDs are never accepted.
	 */
	public static function create_task() {
		self::guard();

		if ( ! ans_dash_write_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Write-back is disabled. Define ARS_NOVA_DASH_WRITE as true to enable it.', 'ars-nova-season-dashboard' ) ), 403 );
		}

		$task_text = isset( $_POST['task'] ) ? sanitize_text_field( wp_unslash( $_POST['task'] ) ) : '';
		$owner     = isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( $_POST['owner'] ) ) : '';
		$priority  = isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( $_POST['priority'] ) ) : '';
		$due       = isset( $_POST['due'] ) ? sanitize_text_field( wp_unslash( $_POST['due'] ) ) : '';
		$bucket    = isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '';
		$event     = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';

		if ( '' === $task_text ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the task text.', 'ars-nova-season-dashboard' ) ), 400 );
		}
		if ( mb_strlen( $task_text ) > 300 ) {
			wp_send_json_error( array( 'message' => __( 'Task text is too long (300 characters max).', 'ars-nova-season-dashboard' ) ), 400 );
		}
		if ( '' !== $owner && ! in_array( $owner, ans_dash_owners(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid owner.', 'ars-nova-season-dashboard' ) ), 400 );
		}
		if ( '' !== $priority && ! in_array( $priority, ans_dash_priorities(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid priority.', 'ars-nova-season-dashboard' ) ), 400 );
		}
		if ( '' !== $due && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) {
			wp_send_json_error( array( 'message' => __( 'Due date must be YYYY-MM-DD (or blank).', 'ars-nova-season-dashboard' ) ), 400 );
		}
		if ( mb_strlen( $bucket ) > 100 || mb_strlen( $event ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Bucket / Concert-Event is too long (100 characters max).', 'ars-nova-season-dashboard' ) ), 400 );
		}

		$actor  = wp_get_current_user()->display_name;
		$result = ans_dash_sheets()->create_task(
			array(
				'Task'          => $task_text,
				'Bucket'        => $bucket,
				'Owner'         => $owner,
				'Priority'      => $priority,
				'Due'           => $due,
				'Concert/Event' => $event,
			),
			$actor
		);
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'task'  => $result['task'],
				'today' => current_time( 'Y-m-d' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * User-local ordering + site-wide custom tags (no sheet writes — these
	 * work even when write-back is off, but still require login + nonce).
	 * ------------------------------------------------------------------- */

	/**
	 * Persist the current user's checklist drag-order.
	 * Expects POST 'order' = JSON array of Task IDs. Stored in user meta
	 * 'ans_dash_order', capped at 500 IDs, each validated as TSK-####.
	 */
	public static function save_order() {
		self::guard();

		$raw = isset( $_POST['order'] ) ? wp_unslash( $_POST['order'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, validated item-by-item below.
		$in  = json_decode( (string) $raw, true );
		if ( ! is_array( $in ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order payload.', 'ars-nova-season-dashboard' ) ), 400 );
		}

		$order = array();
		foreach ( array_slice( $in, 0, 500 ) as $id ) {
			$id = sanitize_text_field( (string) $id );
			if ( preg_match( '/^TSK-\d+$/i', $id ) && ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		update_user_meta( get_current_user_id(), 'ans_dash_order', $order );

		wp_send_json_success( array( 'order' => $order ) );
	}

	/**
	 * Persist a new custom tag name (site-wide) so it appears in the tag
	 * pickers before any task uses it. Appends to the
	 * 'ans_dash_custom_buckets' option (array, capped at 200 entries).
	 */
	public static function add_tag() {
		self::guard();

		$tag = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '';
		if ( '' === $tag ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a tag name.', 'ars-nova-season-dashboard' ) ), 400 );
		}
		if ( mb_strlen( $tag ) > 100 ) {
			wp_send_json_error( array( 'message' => __( 'Tag is too long (100 characters max).', 'ars-nova-season-dashboard' ) ), 400 );
		}

		$tags = ans_dash_custom_buckets();
		if ( ! in_array( $tag, $tags, true ) ) {
			if ( count( $tags ) >= 200 ) {
				wp_send_json_error( array( 'message' => __( 'Too many custom tags (200 max). Remove some from the ans_dash_custom_buckets option first.', 'ars-nova-season-dashboard' ) ), 400 );
			}
			$tags[] = $tag;
			update_option( 'ans_dash_custom_buckets', $tags, false );
		}

		wp_send_json_success( array( 'tags' => $tags, 'tag' => $tag ) );
	}
}
