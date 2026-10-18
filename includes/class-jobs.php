<?php
/**
 * Background job runner for long-running tool calls (plugin installs/updates,
 * WP-CLI, heavy PHP). Jobs survive HTTP timeouts: the request only queues the
 * work, a cron/poll pump executes it, and clients poll job_status until it
 * finishes. The token and its permissions are re-validated at execution time,
 * never trusted from queue time alone.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job queue: start, pump, status, cancel.
 */
final class Jobs {

	/**
	 * Cron hook that pumps the queue.
	 */
	const PUMP_HOOK = 'ai_connect_jobs_pump';

	/**
	 * Tools allowed to run as jobs (the genuinely slow ones).
	 *
	 * @return array
	 */
	public static function allowed_tools() {
		return array(
			'install_plugin',
			'update_plugin',
			'run_wp_cli',
			'run_php',
		);
	}

	/**
	 * Seconds a running job may go without an update before it is failed.
	 */
	const STALE_RUNNING = 900;

	/**
	 * Seconds a queued job may sit before a poll request pumps it inline.
	 */
	const PUMP_MIN_IDLE = 5;

	/**
	 * Concurrent queued/running jobs allowed per token.
	 */
	const MAX_ACTIVE_PER_TOKEN = 5;

	/**
	 * Maximum stored result size (bytes); longer payloads are truncated.
	 */
	const MAX_RESULT_BYTES = 262144;

	/**
	 * Queue a long-running tool call. Returns immediately with a job id.
	 *
	 * @param string $tool  Tool name.
	 * @param array  $args  Raw tool arguments.
	 * @param array $token  Authenticated, re-validatable token row.
	 * @return array
	 * @throws Tool_Error When the tool is not job-capable or the queue is full.
	 */
	public static function start( $tool, array $args, array $token ) {
		global $wpdb;

		$tool = strtolower( trim( (string) $tool ) );
		if ( ! in_array( $tool, self::allowed_tools(), true ) ) {
			throw new Tool_Error(
				'This tool cannot run as a background job: ' . ( '' !== $tool ? $tool : '(missing)' ) . '.',
				'Job-capable tools: ' . implode( ', ', self::allowed_tools() ) . '.',
				'Call every other tool directly; they are fast enough for a normal request.'
			);
		}

		$def = Registry::find( $tool );
		if ( null === $def ) {
			throw new Tool_Error( 'Unknown tool: ' . $tool );
		}

		$reason = Registry::gate( $def, $token );
		if ( null !== $reason ) {
			throw new Tool_Error( $reason );
		}

		$validated = Registry::validate( $def, $args );
		if ( ! empty( $validated[1] ) ) {
			throw new Tool_Error( 'Invalid parameters.', implode( ' ', $validated[1] ) );
		}

		$table  = DB::jobs_table();
		$active = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE token_id = %d AND status IN ('queued','running')",
				(int) $token['id']
			)
		);
		if ( $active >= self::MAX_ACTIVE_PER_TOKEN ) {
			throw new Tool_Error(
				'This token already has ' . $active . ' active jobs (limit ' . self::MAX_ACTIVE_PER_TOKEN . ').',
				'Wait for the running jobs to finish, or cancel queued ones with job_cancel.'
			);
		}

		$row = array(
			'created_at' => Util::now(),
			'updated_at' => Util::now(),
			'status'     => 'queued',
			'tool'       => $tool,
			'args'       => self::encode( $validated[0] ),
			'message'    => '',
			'percent'    => 0,
			'user_id'    => (int) $token['user_id'],
			'token_id'   => (int) $token['id'],
		);
		$wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' ) );
		$id = (int) $wpdb->insert_id;

		Log::add( $tool, $def['risk'], 'queued', 'job #' . $id . ' queued (background)', 0, (int) $token['user_id'], (string) $token['token_prefix'] );
		self::schedule_pump();

		return array(
			'job_id'  => $id,
			'tool'    => $tool,
			'status'  => 'queued',
			'note'    => 'Poll job_status with this job_id every ~3-5 seconds until status is no longer queued or running.',
		);
	}

	/**
	 * Execute queued jobs until the queue is empty. Safe to call often.
	 *
	 * @return int Number of jobs executed.
	 */
	public static function pump() {
		// The master switch stops agent work everywhere: queued jobs wait (and
		// age out through the stale-fail rule) until Ai Connect is turned back on.
		if ( ! Settings::enabled() ) {
			return 0;
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions -- Background jobs must outlive the default request limit.
		}

		$done = 0;
		$job  = self::claim_next();
		while ( null !== $job ) {
			self::run( $job );
			$done++;
			$job = self::claim_next();
		}
		return $done;
	}

	/**
	 * Run the queue inline when a poll observes the job has been sitting idle
	 * (covers hosts where WP-Cron never fires).
	 *
	 * @param int $id Job id.
	 * @return void
	 */
	public static function pump_if_stale( $id ) {
		global $wpdb;
		$table = DB::jobs_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, status, updated_at FROM {$table} WHERE id = %d LIMIT 1", (int) $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return;
		}
		if ( ! in_array( (string) $row['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		$idle = time() - Util::to_unix( (string) $row['updated_at'] );
		if ( $idle >= self::PUMP_MIN_IDLE ) {
			self::pump();
		}
	}

	/**
	 * Job status payload (scoped to the token that started it).
	 *
	 * @param int   $id       Job id.
	 * @param array $token    Token row of the caller.
	 * @return array
	 * @throws Tool_Error When the job does not exist or belongs to another token.
	 */
	public static function get_status( $id, array $token ) {
		$row = self::find( $id, $token );
		return self::shape( $row );
	}

	/**
	 * Cancel a queued job (best effort for running jobs, which PHP cannot
	 * interrupt from another request).
	 *
	 * @param int   $id    Job id.
	 * @param array $token Caller token row.
	 * @return array
	 * @throws Tool_Error When the job does not exist or belongs to another token.
	 */
	public static function cancel( $id, array $token ) {
		global $wpdb;

		$row = self::find( $id, $token );
		if ( ! in_array( (string) $row['status'], array( 'queued', 'running' ), true ) ) {
			return array(
				'job_id' => (int) $row['id'],
				'status' => (string) $row['status'],
				'note'   => 'The job already finished; nothing to cancel.',
			);
		}

		$table = DB::jobs_table();
		if ( 'queued' === (string) $row['status'] ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'cancelled', message = 'Cancelled before it started.', updated_at = %s WHERE id = %d AND status = 'queued'",
					Util::now(),
					(int) $id
				)
			);
			return array(
				'job_id' => (int) $id,
				'status' => 'cancelled',
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET message = 'Cancellation requested; the running job will finish and record its result.', updated_at = %s WHERE id = %d",
				Util::now(),
				(int) $id
			)
		);
		return array(
			'job_id' => (int) $id,
			'status' => 'running',
			'note'   => 'The job is already running. PHP cannot be interrupted from another request, so it will finish and record its result.',
		);
	}

	/**
	 * Delete finished jobs older than 7 days (daily cron).
	 *
	 * @return int Deleted rows.
	 */
	public static function cleanup() {
		global $wpdb;
		$table  = DB::jobs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 7 * 86400 ) );
		$count  = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE status NOT IN ('queued','running') AND updated_at < %s", $cutoff )
		);
		return max( 0, (int) $count );
	}

	/**
	 * Fail jobs whose worker died mid-run.
	 *
	 * @return int Failed rows.
	 */
	public static function fail_stale() {
		global $wpdb;
		$table  = DB::jobs_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_RUNNING );
		$count  = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'failed', message = 'The worker stopped responding; the job was marked failed.', updated_at = %s WHERE status = 'running' AND updated_at < %s",
				Util::now(),
				$cutoff
			)
		);
		return max( 0, (int) $count );
	}

	/**
	 * Ask WP-Cron to pump soon.
	 *
	 * @return void
	 */
	private static function schedule_pump() {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::PUMP_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::PUMP_HOOK );
		}
	}

	/**
	 * Atomically claim the oldest queued job.
	 *
	 * @return array|null Job row that was claimed, or null.
	 */
	private static function claim_next() {
		global $wpdb;

		self::fail_stale();

		$table = DB::jobs_table();
		$row   = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT 1",
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', updated_at = %s WHERE id = %d AND status = 'queued'",
				Util::now(),
				(int) $row['id']
			)
		);
		if ( ! $claimed ) {
			return null; // Another pump won the race.
		}
		$row['status'] = 'running';
		return $row;
	}

	/**
	 * Execute one claimed job with full re-validation.
	 *
	 * @param array $job Job row.
	 * @return void
	 */
	private static function run( array $job ) {
		$start = microtime( true );
		$tool  = (string) $job['tool'];
		$id    = (int) $job['id'];

		$def = Registry::find( $tool );
		if ( null === $def ) {
			self::finish( $id, 'error', null, 'Unknown tool: ' . $tool );
			return;
		}

		// Re-validate the token at execution time. The queue is not a bypass.
		$token = Tokens::get( (int) $job['token_id'] );
		if ( null === $token ) {
			self::finish( $id, 'blocked', null, 'The token that started this job no longer exists.' );
			return;
		}
		if ( ( ! empty( $token['revoked_at'] ) && '0000-00-00 00:00:00' !== $token['revoked_at'] ) || Tokens::is_expired( $token ) ) {
			self::finish( $id, 'blocked', null, 'The token that started this job was revoked or expired before it ran.' );
			return;
		}
		if ( function_exists( 'user_can' ) && ! user_can( (int) $token['user_id'], AI_CONNECT_CAP ) ) {
			self::finish( $id, 'blocked', null, 'The WordPress user behind this token no longer has administrator access.' );
			return;
		}
		$reason = Registry::gate( $def, $token );
		if ( null !== $reason ) {
			self::finish( $id, 'blocked', null, $reason );
			return;
		}

		$raw       = self::decode( (string) $job['args'] );
		$validated = Registry::validate( $def, $raw );
		if ( ! empty( $validated[1] ) ) {
			self::finish( $id, 'error', null, 'Invalid parameters: ' . implode( ' ', $validated[1] ) );
			return;
		}

		try {
			Registry::set_context_token( $token );
			$data = Registry::call( $def, $validated[0] );
			if ( ! array_key_exists( 'ok', $data ) ) {
				$data = array_merge( array( 'ok' => true ), $data );
			}
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			Log::add( $tool, $def['risk'], 'ok', 'job #' . $id . ' finished', $ms, (int) $token['user_id'], (string) $token['token_prefix'] );
			self::finish( $id, 'done', $data, 'Completed.' );
		} catch ( Tool_Error $error ) {
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			Log::add( $tool, $def['risk'], 'error', 'job #' . $id . ': ' . $error->getMessage(), $ms, (int) $token['user_id'], (string) $token['token_prefix'] );
			self::finish( $id, 'error', array( 'ok' => false, 'error' => $error->to_array() ), $error->getMessage() );
		} catch ( \Throwable $error ) {
			$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			Log::add( $tool, $def['risk'], 'error', 'job #' . $id . ': ' . $error->getMessage(), $ms, (int) $token['user_id'], (string) $token['token_prefix'] );
			self::finish( $id, 'error', array( 'ok' => false, 'error' => array( 'message' => 'The tool failed: ' . $error->getMessage() ) ), 'The tool failed.' );
		}
	}

	/**
	 * Look up a job that belongs to the given token.
	 *
	 * @param int   $id    Job id.
	 * @param array $token Caller token row.
	 * @return array Job row.
	 * @throws Tool_Error When missing or not owned by the token.
	 */
	private static function find( $id, array $token ) {
		global $wpdb;
		$table = DB::jobs_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) $row['token_id'] !== (int) $token['id'] ) {
			throw new Tool_Error(
				'Job not found: ' . (int) $id . '.',
				'Jobs are only visible to the token that started them.'
			);
		}
		return $row;
	}

	/**
	 * Shape a job row for tool output.
	 *
	 * @param array $row Job row.
	 * @return array
	 */
	private static function shape( array $row ) {
		$result = null;
		if ( isset( $row['result'] ) && null !== $row['result'] && '' !== (string) $row['result'] ) {
			$result = self::decode( (string) $row['result'] );
		}
		return array(
			'job_id'     => (int) $row['id'],
			'tool'       => (string) $row['tool'],
			'status'     => (string) $row['status'],
			'percent'    => (int) $row['percent'],
			'message'    => (string) $row['message'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
			'result'     => $result,
		);
	}

	/**
	 * Persist a terminal status for a job.
	 *
	 * @param int         $id      Job id.
	 * @param string      $status  done|error|blocked.
	 * @param array|null  $result  Result payload.
	 * @param string      $message Short human message.
	 * @return void
	 */
	private static function finish( $id, $status, $result, $message ) {
		global $wpdb;
		$encoded = null === $result ? null : self::encode( $result );
		$wpdb->update(
			DB::jobs_table(),
			array(
				'status'     => $status,
				'result'     => $encoded,
				'message'    => Util::truncate( (string) $message, 500 ),
				'percent'    => 'done' === $status ? 100 : 0,
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * JSON-encode a value for storage (truncated above MAX_RESULT_BYTES).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function encode( $value ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		if ( ! is_string( $json ) ) {
			$json = (string) $json;
		}
		if ( strlen( $json ) > self::MAX_RESULT_BYTES ) {
			$json = substr( $json, 0, self::MAX_RESULT_BYTES ) . '"__ai_connect_truncated__"';
		}
		return $json;
	}

	/**
	 * Decode stored JSON; invalid payloads come back as null.
	 *
	 * @param string $json Stored JSON.
	 * @return mixed
	 */
	private static function decode( $json ) {
		$value = json_decode( (string) $json, true );
		return $value;
	}
}
