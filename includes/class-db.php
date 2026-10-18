<?php
/**
 * Database schema: connection tokens and the activity log.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table management.
 */
final class DB {

	/**
	 * Current schema version.
	 */
	const SCHEMA_VERSION = '3';

	/**
	 * Tokens table name.
	 *
	 * @return string
	 */
	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_connect_tokens';
	}

	/**
	 * Legacy sitewright tokens table name (pre-1.1.0 installs).
	 *
	 * @return string
	 */
	public static function legacy_tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'sitewright_tokens';
	}

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_connect_log';
	}

	/**
	 * Background jobs table name (long-running operations).
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_connect_jobs';
	}

	/**
	 * Legacy sitewright log table name (pre-1.1.0 installs).
	 *
	 * @return string
	 */
	public static function legacy_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'sitewright_log';
	}

	/**
	 * Create or update tables on activation.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		if ( file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$tokens  = self::tokens_table();
		$log     = self::log_table();
		$jobs    = self::jobs_table();

		$sql_tokens = "CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_prefix varchar(16) NOT NULL DEFAULT '',
			token_hash char(64) NOT NULL DEFAULT '',
			name varchar(120) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			read_only tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			expires_at datetime DEFAULT NULL,
			last_used_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY token_prefix (token_prefix),
			KEY user_id (user_id)
		) {$charset};";

		$sql_log = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_prefix varchar(16) NOT NULL DEFAULT '',
			tool varchar(64) NOT NULL DEFAULT '',
			risk varchar(12) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT '',
			detail varchar(500) NOT NULL DEFAULT '',
			duration_ms int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY tool (tool)
		) {$charset};";

		$sql_jobs = "CREATE TABLE {$jobs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			status varchar(20) NOT NULL DEFAULT 'queued',
			tool varchar(64) NOT NULL DEFAULT '',
			args longtext,
			result longtext,
			message varchar(500) NOT NULL DEFAULT '',
			percent int(11) NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			token_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql_tokens );
			dbDelta( $sql_log );
			dbDelta( $sql_jobs );
		}

		// Backward-compatible migration from legacy sitewright tables if present.
		self::maybe_migrate_legacy_data();

		$db_opt = defined( 'AI_CONNECT_DB_VERSION_OPTION' ) ? AI_CONNECT_DB_VERSION_OPTION : 'ai_connect_db_version';
		update_option( $db_opt, self::SCHEMA_VERSION, false );
		update_option( 'sitewright_db_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Safely copy legacy sitewright rows into the new tables. Idempotent:
	 * INSERT IGNORE keeps existing ids and can be re-run at every upgrade.
	 *
	 * @return void
	 */
	public static function maybe_migrate_legacy_data() {
		global $wpdb;
		$legacy_tokens = self::legacy_tokens_table();
		$new_tokens    = self::tokens_table();
		$legacy_log    = self::legacy_log_table();
		$new_log       = self::log_table();

		if ( ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}

		if ( $legacy_tokens !== $new_tokens ) {
			$has_legacy = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_tokens ) );
			$has_new    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_tokens ) );
			if ( $legacy_tokens === $has_legacy && $new_tokens === $has_new ) {
				$wpdb->query(
					"INSERT IGNORE INTO {$new_tokens} (id, token_prefix, token_hash, name, user_id, read_only, created_at, expires_at, last_used_at, revoked_at)
					SELECT id, token_prefix, token_hash, name, user_id, read_only, created_at, expires_at, last_used_at, revoked_at FROM {$legacy_tokens}"
				);
			}
		}

		if ( $legacy_log !== $new_log ) {
			$has_legacy = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy_log ) );
			$has_new    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_log ) );
			if ( $legacy_log === $has_legacy && $new_log === $has_new ) {
				$wpdb->query(
					"INSERT IGNORE INTO {$new_log} (id, created_at, user_id, token_prefix, tool, risk, status, detail, duration_ms)
					SELECT id, created_at, user_id, token_prefix, tool, risk, status, detail, duration_ms FROM {$legacy_log}"
				);
			}
		}
	}

	/**
	 * Re-run install when the schema version changed (e.g. plugin updated by file copy).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$db_opt = defined( 'AI_CONNECT_DB_VERSION_OPTION' ) ? AI_CONNECT_DB_VERSION_OPTION : 'ai_connect_db_version';
		$ver    = get_option( $db_opt, null );
		if ( null === $ver ) {
			$ver = get_option( 'sitewright_db_version', null );
		}
		if ( self::SCHEMA_VERSION === $ver ) {
			return;
		}
		self::install();
	}

	/**
	 * Whether both tables exist.
	 *
	 * @return bool
	 */
	public static function tables_exist() {
		global $wpdb;
		if ( ! method_exists( $wpdb, 'get_var' ) ) {
			return true;
		}
		$tokens = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::tokens_table() ) );
		if ( self::tokens_table() !== $tokens ) {
			// Check legacy table for compatibility
			$legacy = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::legacy_tokens_table() ) );
			if ( self::legacy_tokens_table() !== $legacy ) {
				return false;
			}
		}
		$log = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::log_table() ) );
		if ( self::log_table() !== $log ) {
			$legacy_log = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::legacy_log_table() ) );
			if ( self::legacy_log_table() !== $legacy_log ) {
				return false;
			}
		}
		$jobs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::jobs_table() ) );
		if ( self::jobs_table() !== $jobs ) {
			return false;
		}
		return true;
	}
}

// Class aliases for backward compatibility with the Sitewright namespace.
if ( ! class_exists( 'Sitewright\\DB', false ) ) {
	class_alias( 'AiConnect\\DB', 'Sitewright\\DB' );
}
