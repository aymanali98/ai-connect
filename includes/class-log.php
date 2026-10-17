<?php
/**
 * Bounded activity log for tool calls (no arguments captured, no secrets).
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads the ai_connect_log table (legacy rows from the
 * pre-1.1.0 sitewright_log table are migrated on upgrade).
 */
final class Log {

	/**
	 * Record one tool call.
	 *
	 * @param string $tool        Tool name.
	 * @param string $risk        read|write|dangerous.
	 * @param string $status      ok|error|blocked.
	 * @param string $detail      Short human summary (no secrets).
	 * @param int    $duration_ms Duration in milliseconds.
	 * @param int    $user_id     Acting user.
	 * @param string $token_prefix Token display prefix.
	 * @return void
	 */
	public static function add( $tool, $risk, $status, $detail, $duration_ms, $user_id, $token_prefix ) {
		global $wpdb;

		$wpdb->insert(
			DB::log_table(),
			array(
				'created_at'   => Util::now(),
				'user_id'      => (int) $user_id,
				'token_prefix' => Util::truncate( (string) $token_prefix, 16 ),
				'tool'         => Util::truncate( (string) $tool, 64 ),
				'risk'         => Util::truncate( (string) $risk, 12 ),
				'status'       => Util::truncate( (string) $status, 16 ),
				'detail'       => Util::truncate( (string) $detail, 500 ),
				'duration_ms'  => max( 0, (int) $duration_ms ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		self::prune();
	}

	/**
	 * Most recent entries, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$table = DB::log_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT {$limit}", ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total row count.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = DB::log_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete all rows.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = DB::log_table();
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Keep only the newest N rows (N = log_retention setting).
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;
		$retention = max( 50, (int) Settings::get( 'log_retention' ) );
		$table     = DB::log_table();
		$cutoff    = $wpdb->get_var( 'SELECT id FROM ' . $table . ' ORDER BY id DESC LIMIT 1 OFFSET ' . ( $retention - 1 ) );
		if ( null === $cutoff || false === $cutoff ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", (int) $cutoff ) );
	}
}
