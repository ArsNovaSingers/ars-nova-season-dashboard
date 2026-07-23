<?php
/**
 * Google Sheets REST client using an OAuth 2.0 web-application client and a
 * stored refresh token (refresh-token grant).
 *
 * Deliberately dependency-free: no google/apiclient. Access tokens are minted
 * server-side by POSTing the stored refresh token to Google's token endpoint
 * via wp_remote_post; all Sheets calls use wp_remote_request. The client
 * secret and refresh token never reach the browser.
 *
 * @package ars-nova-season-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ANS_Sheets {

	const TOKEN_URL        = 'https://oauth2.googleapis.com/token';
	const SHEETS_BASE      = 'https://sheets.googleapis.com/v4/spreadsheets/';
	const SCOPE            = 'https://www.googleapis.com/auth/spreadsheets';
	const TOKEN_TRANSIENT  = 'ans_dash_google_token';
	const REFRESH_OPTION   = 'ans_oauth_refresh_token';
	const ACCOUNT_OPTION   = 'ans_oauth_account';
	const TASKS_TRANSIENT  = 'ans_dash_tasks_cache';
	const TASKS_TAB        = 'Tasks';
	const CHANGELOG_TAB    = 'Change Log';
	const TASKS_RANGE      = 'Tasks!A1:S';
	const FIRST_DATA_ROW   = 2; // Row 1 is the header row.

	/**
	 * Column letters by tracker field. Keep in sync with the sheet layout.
	 *
	 * @var array<string,string>
	 */
	const COLUMNS = array(
		'Task ID'       => 'A',
		'Bucket'        => 'B',
		'Task'          => 'C',
		'Owner'         => 'D',
		'Status'        => 'E',
		'Priority'      => 'F',
		'Due'           => 'G',
		'Concert/Event' => 'H',
		'Source'        => 'I',
		'Notes'         => 'J',
		'Created'       => 'K',
		'Updated'       => 'L',
		'Done At'       => 'M',
		'Added By'      => 'N',
		'Color'         => 'O',
		'Parent Task ID' => 'P',
		'Links'         => 'Q',
		'Progress %'    => 'R',
		'Steps'         => 'S',
	);

	/** @var string */
	private $spreadsheet_id;

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/** @var int */
	private $cache_ttl;

	/**
	 * @param string $spreadsheet_id Google spreadsheet ID.
	 * @param string $client_id      OAuth 2.0 web-application Client ID.
	 * @param string $client_secret  OAuth 2.0 web-application Client Secret.
	 * @param int    $cache_ttl      Read-cache TTL in seconds.
	 */
	public function __construct( $spreadsheet_id, $client_id, $client_secret, $cache_ttl = 60 ) {
		$this->spreadsheet_id = $spreadsheet_id;
		$this->client_id      = trim( (string) $client_id );
		$this->client_secret  = trim( (string) $client_secret );
		$this->cache_ttl      = max( 15, (int) $cache_ttl );
	}

	/**
	 * Whether credentials look present (does not validate them against Google):
	 * client ID + client secret + a stored refresh token.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->client_id
			&& '' !== $this->client_secret
			&& '' !== $this->get_refresh_token();
	}

	/* ---------------------------------------------------------------------
	 * Auth: stored refresh token -> access token (refresh-token grant)
	 * ------------------------------------------------------------------- */

	/**
	 * The stored refresh token (obtained via the one-time admin connect flow).
	 *
	 * @return string
	 */
	private function get_refresh_token() {
		return trim( (string) get_option( self::REFRESH_OPTION, '' ) );
	}

	/**
	 * Get (and cache) an OAuth access token via the refresh-token grant.
	 * Cached in a transient until ~60 seconds before it expires.
	 *
	 * @return string|WP_Error
	 */
	private function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( '' === $this->client_id || '' === $this->client_secret ) {
			return new WP_Error( 'ans_no_credentials', __( 'No Google OAuth client configured. An administrator must add the Client ID and Client Secret in Season Dashboard → Settings (or define ARS_NOVA_OAUTH_CLIENT_ID / ARS_NOVA_OAUTH_CLIENT_SECRET in wp-config.php).', 'ars-nova-season-dashboard' ) );
		}

		$refresh_token = $this->get_refresh_token();
		if ( '' === $refresh_token ) {
			return new WP_Error( 'ans_not_connected', __( 'No Google account is connected. An administrator must click “Connect Google account” in Season Dashboard → Settings.', 'ars-nova-season-dashboard' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'refresh_token' => $refresh_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$detail = is_array( $body ) && ! empty( $body['error_description'] ) ? $body['error_description'] : 'HTTP ' . $code;
			return new WP_Error( 'ans_token_failed', sprintf(
				/* translators: %s: error detail from Google */
				__( 'Google auth failed: %s', 'ars-nova-season-dashboard' ),
				$detail
			) );
		}

		$token      = (string) $body['access_token'];
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $expires_in - 60 ) );

		return $token;
	}

	/* ---------------------------------------------------------------------
	 * Low-level Sheets REST calls
	 * ------------------------------------------------------------------- */

	/**
	 * Perform an authenticated Sheets API request.
	 *
	 * @param string     $method 'GET' or 'POST' or 'PUT'.
	 * @param string     $path   Path appended to the spreadsheet URL (already encoded).
	 * @param array|null $json   Optional JSON body.
	 * @return array|WP_Error Decoded response body.
	 */
	private function request( $method, $path, $json = null ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url  = self::SHEETS_BASE . rawurlencode( $this->spreadsheet_id ) . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $json ) {
			$args['body'] = wp_json_encode( $json );
		}

		// Use wp_remote_request so the HTTP method in $args ('GET'/'POST'/'PUT')
		// is honoured. wp_remote_post() would force POST, breaking values.update (PUT).
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'ans_sheets_error', sprintf(
				/* translators: %s: error detail from the Google Sheets API */
				__( 'Google Sheets API error: %s', 'ars-nova-season-dashboard' ),
				$detail
			) );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * spreadsheets.values.get
	 *
	 * @param string $range A1 range, e.g. "Tasks!A1:L".
	 * @return array|WP_Error Rows (array of arrays), possibly empty.
	 */
	public function get_values( $range ) {
		$result = $this->request( 'GET', '/values/' . rawurlencode( $range ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['values'] ) && is_array( $result['values'] ) ? $result['values'] : array();
	}

	/**
	 * spreadsheets.values.update (valueInputOption=USER_ENTERED)
	 *
	 * @param string $range A1 range for the write.
	 * @param array  $rows  Array of row arrays.
	 * @return array|WP_Error
	 */
	public function update_values( $range, array $rows ) {
		return $this->request(
			'PUT',
			'/values/' . rawurlencode( $range ) . '?valueInputOption=USER_ENTERED',
			array(
				'range'  => $range,
				'values' => $rows,
			)
		);
	}

	/**
	 * spreadsheets.values.append (valueInputOption=USER_ENTERED)
	 *
	 * @param string $range Table range to append to, e.g. "Change Log!A1:E".
	 * @param array  $rows  Array of row arrays.
	 * @return array|WP_Error
	 */
	public function append_values( $range, array $rows ) {
		return $this->request(
			'POST',
			'/values/' . rawurlencode( $range ) . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
			array( 'values' => $rows )
		);
	}

	/* ---------------------------------------------------------------------
	 * Tracker-shaped reads
	 * ------------------------------------------------------------------- */

	/**
	 * Convert a raw sheet row into a task array keyed by field name.
	 *
	 * @param array $row Raw row values (0-indexed A..S).
	 * @return array<string,string>
	 */
	private static function row_to_task( array $row ) {
		$fields = array_keys( self::COLUMNS );
		$task   = array();
		foreach ( $fields as $i => $field ) {
			$task[ $field ] = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
		}
		return $task;
	}

	/**
	 * Fetch all tasks (cached ~60s). Rows without a Task ID are skipped.
	 *
	 * @param bool $force_refresh Bypass and rebuild the cache.
	 * @return array|WP_Error { tasks: array[], fetched_at: int }
	 */
	public function get_tasks( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TASKS_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['tasks'] ) ) {
				return $cached;
			}
		}

		$rows = $this->get_values( self::TASKS_RANGE );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$tasks = array();
		foreach ( $rows as $i => $row ) {
			if ( 0 === $i ) {
				continue; // Header row.
			}
			$task = self::row_to_task( (array) $row );
			if ( '' === $task['Task ID'] ) {
				continue;
			}
			$tasks[] = $task;
		}

		$payload = array(
			'tasks'      => $tasks,
			'fetched_at' => time(),
		);
		set_transient( self::TASKS_TRANSIENT, $payload, $this->cache_ttl );

		return $payload;
	}

	/**
	 * Bust the tasks read-cache (after writes / manual refresh).
	 */
	public function flush_cache() {
		delete_transient( self::TASKS_TRANSIENT );
	}

	/**
	 * Resolve a Task ID to its 1-based sheet row, reading the sheet fresh.
	 * Client-supplied row numbers are never trusted — this is the only path.
	 *
	 * @param string $task_id Task ID (column A value).
	 * @return array|WP_Error { row: int, task: array<string,string> }
	 */
	public function find_task_row( $task_id ) {
		$task_id = trim( (string) $task_id );
		if ( '' === $task_id ) {
			return new WP_Error( 'ans_no_task_id', __( 'Missing Task ID.', 'ars-nova-season-dashboard' ) );
		}

		$rows = $this->get_values( self::TASKS_RANGE );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		foreach ( $rows as $i => $row ) {
			if ( 0 === $i ) {
				continue;
			}
			$candidate = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			if ( $candidate === $task_id ) {
				return array(
					'row'  => $i + 1, // Sheet rows are 1-based; $i is 0-based over the same list.
					'task' => self::row_to_task( (array) $row ),
				);
			}
		}

		return new WP_Error( 'ans_task_not_found', sprintf(
			/* translators: %s: task ID */
			__( 'Task %s was not found in the tracker. Try refreshing the board.', 'ars-nova-season-dashboard' ),
			$task_id
		) );
	}

	/* ---------------------------------------------------------------------
	 * Task ID allocation
	 * ------------------------------------------------------------------- */

	/**
	 * Compute the next free Task ID by scanning column A for TSK-#### IDs and
	 * incrementing the highest numeric suffix. Reads the sheet fresh (never
	 * the cache) so two quick creates don't collide on a stale max.
	 *
	 * @return string|WP_Error e.g. "TSK-0050" ("TSK-0001" on an empty sheet).
	 */
	public function next_task_id() {
		$rows = $this->get_values( self::TASKS_TAB . '!A1:A' );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$max = 0;
		foreach ( $rows as $i => $row ) {
			if ( 0 === $i ) {
				continue; // Header row.
			}
			$candidate = isset( $row[0] ) ? trim( (string) $row[0] ) : '';
			if ( preg_match( '/^TSK-(\d+)$/i', $candidate, $m ) ) {
				$n = (int) $m[1];
				if ( $n > $max ) {
					$max = $n;
				}
			}
		}

		return sprintf( 'TSK-%04d', $max + 1 );
	}

	/* ---------------------------------------------------------------------
	 * Write-back
	 * ------------------------------------------------------------------- */

	/**
	 * Update one editable field on a task, stamp the Updated column, and append
	 * a Change Log row. When the field is Status, the Done At column (M) is
	 * stamped with the current date-time on a move to Done and cleared when the
	 * task leaves Done. Field/value validation happens in the AJAX layer; this
	 * method still refuses unknown columns as a second guard.
	 *
	 * @param string $task_id Task ID (column A value).
	 * @param string $field   Field name (Status, Owner, Priority, Bucket,
	 *                        Color, Parent Task ID, Notes or Links).
	 * @param string $value   New value (already validated/sanitized).
	 * @param string $actor   WP display name for the Change Log.
	 * @return array|WP_Error { task: array<string,string> } The updated task.
	 */
	public function update_task_field( $task_id, $field, $value, $actor ) {
		$editable = array( 'Status', 'Owner', 'Priority', 'Bucket', 'Color', 'Parent Task ID', 'Notes', 'Links' );
		if ( ! isset( self::COLUMNS[ $field ] ) || ! in_array( $field, $editable, true ) ) {
			return new WP_Error( 'ans_bad_field', __( 'That field cannot be edited from the dashboard.', 'ars-nova-season-dashboard' ) );
		}

		$located = $this->find_task_row( $task_id );
		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$row       = (int) $located['row'];
		$task      = $located['task'];
		$old_value = $task[ $field ];
		$today     = current_time( 'Y-m-d' ); // Site-timezone date.

		// 1) Write the changed cell.
		$col    = self::COLUMNS[ $field ];
		$result = $this->update_values( self::TASKS_TAB . '!' . $col . $row, array( array( $value ) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// 2) Stamp Updated (column L).
		$stamp = $this->update_values( self::TASKS_TAB . '!' . self::COLUMNS['Updated'] . $row, array( array( $today ) ) );
		if ( is_wp_error( $stamp ) ) {
			return $stamp; // Cell write succeeded but the stamp failed — surface it.
		}

		// 2b) Done At (column M) side-effect: stamp on move to Done, clear otherwise.
		$done_at = isset( $task['Done At'] ) ? $task['Done At'] : '';
		if ( 'Status' === $field ) {
			$done_at = ( 'Done' === $value ) ? current_time( 'Y-m-d H:i:s' ) : '';
			$done    = $this->update_values( self::TASKS_TAB . '!' . self::COLUMNS['Done At'] . $row, array( array( $done_at ) ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}

		// 3) Append the Change Log row (Timestamp, Actor, Action, Entity, Detail).
		$old_short = mb_strlen( $old_value ) > 120 ? mb_substr( $old_value, 0, 120 ) . '…' : $old_value;
		$new_short = mb_strlen( $value ) > 120 ? mb_substr( $value, 0, 120 ) . '…' : $value;
		$detail    = sprintf( '%s: "%s" -> "%s" (%s)', $field, $old_short, $new_short, $task['Task'] );
		$this->append_change_log( $actor, 'Update ' . $field, $task_id, $detail );

		// 4) Bust the read-cache so the next fetch reflects the change.
		$this->flush_cache();

		$task[ $field ]    = $value;
		$task['Updated']   = $today;
		$task['Done At']   = $done_at;

		return array( 'task' => $task );
	}

	/**
	 * Create a new task: allocate the next Task ID, append the row to the
	 * Tasks tab in the exact A–S column order, log it to the Change Log, and
	 * bust the read-cache. Field values must already be sanitized/validated
	 * by the AJAX layer; the server always assigns Task ID, Status, Source,
	 * Created and Updated — client values for those are ignored.
	 *
	 * @param array  $fields Sanitized fields: Task (required), Bucket, Owner,
	 *                       Priority, Due, Concert/Event (all optional).
	 * @param string $actor  WP display name for the Change Log.
	 * @return array|WP_Error { task: array<string,string> } The created task.
	 */
	public function create_task( array $fields, $actor ) {
		$task_id = $this->next_task_id();
		if ( is_wp_error( $task_id ) ) {
			return $task_id;
		}

		$today = current_time( 'Y-m-d' ); // Site-timezone date.

		$task = array(
			'Task ID'       => $task_id,
			'Bucket'        => isset( $fields['Bucket'] ) ? (string) $fields['Bucket'] : '',
			'Task'          => isset( $fields['Task'] ) ? (string) $fields['Task'] : '',
			'Owner'         => isset( $fields['Owner'] ) ? (string) $fields['Owner'] : '',
			'Status'        => 'To Do',
			'Priority'      => isset( $fields['Priority'] ) ? (string) $fields['Priority'] : '',
			'Due'           => isset( $fields['Due'] ) ? (string) $fields['Due'] : '',
			'Concert/Event' => isset( $fields['Concert/Event'] ) ? (string) $fields['Concert/Event'] : '',
			'Source'        => 'dashboard',
			'Notes'         => '',
			'Created'       => $today,
			'Updated'       => $today,
			'Done At'       => '',
			'Added By'      => (string) $actor,
			'Color'         => '',
			'Parent Task ID' => '',
			'Links'         => '',
			'Progress %'    => '',
			'Steps'         => '',
		);

		// Build the row in the exact COLUMNS order (A..S).
		$row = array();
		foreach ( array_keys( self::COLUMNS ) as $field ) {
			$row[] = $task[ $field ];
		}

		$result = $this->append_values( self::TASKS_RANGE, array( $row ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Change Log row; a logging failure is not fatal to the create.
		$this->append_change_log( $actor, 'Create task', $task_id, $task['Task'] );

		// Bust the read-cache so the next fetch includes the new row.
		$this->flush_cache();

		return array( 'task' => $task );
	}

	/**
	 * Append one row to the Change Log tab.
	 * A logging failure is not fatal to the main write.
	 *
	 * @param string $actor  Actor display name.
	 * @param string $action Action label.
	 * @param string $entity Entity (usually the Task ID).
	 * @param string $detail Human-readable detail.
	 * @return array|WP_Error
	 */
	public function append_change_log( $actor, $action, $entity, $detail ) {
		return $this->append_values(
			self::CHANGELOG_TAB . '!A1:E',
			array(
				array(
					current_time( 'Y-m-d H:i:s' ),
					(string) $actor,
					(string) $action,
					(string) $entity,
					(string) $detail,
				),
			)
		);
	}
}
