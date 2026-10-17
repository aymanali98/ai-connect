<?php
/**
 * Environment diagnostics: dependency, transport, filesystem, sandbox, WP-CLI,
 * and configuration checks with actionable fixes.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces the diagnostics report used by the admin page and the MCP tool.
 */
final class Diagnostics {

	/**
	 * Run all checks.
	 *
	 * @param bool $full Include slow checks (loopback request, WP-CLI detection).
	 * @return array{checks: array, summary: array}
	 */
	public static function run( $full = true ) {
		$checks = array();

		// --- Runtime versions -------------------------------------------------
		$php_status = version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'fail';
		if ( 'ok' === $php_status && version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$php_status = 'warn';
		}
		self::add(
			$checks,
			'php_version',
			'PHP version',
			$php_status,
			'Running PHP ' . PHP_VERSION . '.',
			'PHP 8.0+ is recommended for WordPress 6.9 features; 7.4 is the minimum for Ai Connect.'
		);

		$wp_version = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
		$wp_status  = version_compare( $wp_version, '6.0', '>=' ) ? 'ok' : 'fail';
		self::add(
			$checks,
			'wp_version',
			'WordPress version',
			$wp_status,
			'Running WordPress ' . $wp_version . '.',
			'WordPress 6.0+ required. 6.9+ enables the Abilities API bridge (discover_abilities, run_ability).'
		);

		// --- Extensions -------------------------------------------------------
		$loaded  = array_map( 'strtolower', get_loaded_extensions() );
		$missing = array();
		foreach ( array( 'json' ) as $required ) {
			if ( ! in_array( $required, $loaded, true ) ) {
				$missing[] = $required;
			}
		}
		$recommended_missing = array();
		foreach ( array( 'mbstring', 'openssl', 'curl', 'fileinfo' ) as $recommended ) {
			if ( ! in_array( $recommended, $loaded, true ) ) {
				$recommended_missing[] = $recommended;
			}
		}
		$ext_status = 'ok';
		$ext_detail = 'Required: json (present).';
		if ( $missing ) {
			$ext_status = 'fail';
			$ext_detail = 'Missing required extensions: ' . implode( ', ', $missing ) . '.';
		} elseif ( $recommended_missing ) {
			$ext_status = 'warn';
			$ext_detail = 'Missing recommended extensions: ' . implode( ', ', $recommended_missing ) . '.';
		}
		self::add( $checks, 'php_extensions', 'PHP extensions', $ext_status, $ext_detail, $missing || $recommended_missing ? 'Ask the host to enable the missing PHP extensions.' : '' );

		// --- Environment type --------------------------------------------------
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		self::add(
			$checks,
			'environment_type',
			'WordPress environment',
			'production' === $env ? 'warn' : 'ok',
			'wp_get_environment_type() reports "' . $env . '".',
			'production' === $env ? 'Ai Connect grants agents deep access. Use it on staging/development sites, or set WP_ENVIRONMENT_TYPE.' : ''
		);

		// --- HTTPS -------------------------------------------------------------
		$is_ssl = is_ssl();
		self::add(
			$checks,
			'https',
			'HTTPS',
			$is_ssl ? 'ok' : 'warn',
			$is_ssl ? 'The site is served over HTTPS.' : 'The site is served over plain HTTP.',
			$is_ssl ? '' : 'Connection tokens travel in the Authorization header. Use HTTPS, or keep the site on a trusted local network only.'
		);

		// --- REST loopback ------------------------------------------------------
		if ( $full ) {
			$probe = wp_remote_post(
				rest_url( AI_CONNECT_REST_NS . '/mcp' ),
				array(
					'timeout' => 5,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => '{"jsonrpc":"2.0","id":1,"method":"ping"}',
				)
			);
			if ( is_wp_error( $probe ) ) {
				self::add(
					$checks,
					'rest_loopback',
					'REST endpoint reachability',
					'warn',
					'The site could not reach its own MCP endpoint: ' . $probe->get_error_message(),
					'Loopback requests are often blocked by firewalls or maintenance plugins. Remote MCP clients may still connect from outside.'
				);
			} else {
				$code = (int) wp_remote_retrieve_response_code( $probe );
				if ( 401 === $code ) {
					self::add( $checks, 'rest_loopback', 'REST endpoint reachability', 'ok', 'Endpoint answers and rejects unauthenticated requests (HTTP 401).' );
				} elseif ( 200 === $code ) {
					self::add( $checks, 'rest_loopback', 'REST endpoint reachability', 'fail', 'The endpoint answered 200 to an unauthenticated request. This should never happen.', 'Investigate immediately: something may be intercepting requests to /ai-connect/v1/mcp.' );
				} else {
					self::add( $checks, 'rest_loopback', 'REST endpoint reachability', 'warn', 'Unexpected response code: ' . $code . '.', 'A security plugin or server rule may be intercepting REST requests.' );
				}
			}
		}

		// --- Tables -------------------------------------------------------------
		global $wpdb;
		$tables_ok = DB::tables_exist();
		self::add(
			$checks,
			'db_tables',
			'Database tables',
			$tables_ok ? 'ok' : 'fail',
			$tables_ok ? 'Token and log tables exist.' : 'One or more Ai Connect tables are missing.',
			$tables_ok ? '' : 'Deactivate and reactivate the plugin to recreate the tables.'
		);

		// --- Abilities API -------------------------------------------------------
		$abilities_available = function_exists( 'wp_get_abilities' );
		self::add(
			$checks,
			'abilities_api',
			'WordPress Abilities API',
			$abilities_available ? 'ok' : 'warn',
			$abilities_available ? 'Abilities API detected; discover_abilities and run_ability are live.' : 'Abilities API not detected (WordPress 6.9+ ships it).',
			$abilities_available ? '' : 'Upgrade WordPress to use the abilities integration. All other Ai Connect tools work without it.'
		);

		// --- Filesystem ----------------------------------------------------------
		$wp_content_writable = is_writable( WP_CONTENT_DIR );
		$uploads             = wp_upload_dir();
		$uploads_writable    = empty( $uploads['error'] ) && is_writable( $uploads['basedir'] );
		$fs_status           = ( $wp_content_writable && $uploads_writable ) ? 'ok' : 'warn';
		self::add(
			$checks,
			'filesystem',
			'Filesystem write access',
			$fs_status,
			sprintf( 'wp-content writable: %s; uploads writable: %s.', $wp_content_writable ? 'yes' : 'no', $uploads_writable ? 'yes' : 'no' ),
			'ok' === $fs_status ? '' : 'Filesystem tools and media uploads need write access to wp-content.'
		);

		// --- Sandbox --------------------------------------------------------------
		$sandbox = Sandbox::status();
		$flags   = array();
		if ( '' !== (string) $wpdb->last_error ) {
			$flags[] = 'Database warning: ' . $wpdb->last_error;
		}
		if ( $sandbox['safe_mode'] ) {
			$sandbox_status = 'fail';
			$crash          = is_array( $sandbox['crash'] ) ? $sandbox['crash'] : array();
			$message        = isset( $crash['message'] ) ? (string) $crash['message'] : 'Unknown error.';
			$file           = isset( $crash['sandbox_file'] ) ? basename( (string) $crash['sandbox_file'] ) : 'unknown file.';
			$detail         = sprintf( 'SAFE MODE: "%s" crashed PHP while loading (%s).', $file, Util::truncate( $message, 200 ) );
			$fix            = 'Inspect or delete the crashing file, then use "Exit safe mode" below.';
		} elseif ( ! $sandbox['exists'] ) {
			$sandbox_status = 'warn';
			$detail         = 'Sandbox directory does not exist yet.';
			$fix            = 'It is created automatically on first use, or click "Repair sandbox" below.';
		} elseif ( ! $sandbox['writable'] ) {
			$sandbox_status = 'fail';
			$detail         = 'Sandbox directory is not writable: ' . $sandbox['dir'];
			$fix            = 'Fix wp-content permissions so PHP sandbox writes work.';
		} else {
			$sandbox_status = 'ok';
			$detail         = sprintf( 'Ready with %d file(s); PHP write policy: %s.', count( $sandbox['files'] ), (string) $sandbox['policy'] );
			$fix            = '';
		}
		self::add( $checks, 'sandbox', 'PHP sandbox', $sandbox_status, $detail, $fix );

		// --- WP-CLI ---------------------------------------------------------------
		if ( $full && class_exists( 'AiConnect\\Tools\\Dev' ) ) {
			$cli = Tools\Dev::find_wp_cli();
			self::add(
				$checks,
				'wp_cli',
				'WP-CLI',
				! empty( $cli['found'] ) ? 'ok' : 'warn',
				! empty( $cli['found'] ) ? 'WP-CLI available: ' . (string) $cli['version'] . ' (' . (string) $cli['path'] . ').' : (string) $cli['reason'],
				! empty( $cli['found'] ) ? '' : 'Set the WP-CLI binary path in Ai Connect -> Settings if it is installed somewhere unusual.'
			);
		} else {
			self::add( $checks, 'wp_cli', 'WP-CLI', 'warn', 'Not checked (quick mode).' );
		}

		// --- File mods ------------------------------------------------------------
		$file_mods      = ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		$file_edit      = ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
		$mods_status    = $file_mods ? 'ok' : 'warn';
		$mods_detail    = sprintf( 'Plugin install/update allowed: %s. Theme/plugin editor allowed: %s.', $file_mods ? 'yes' : 'no (DISALLOW_FILE_MODS)', $file_edit ? 'yes' : 'no (DISALLOW_FILE_EDIT)' );
		self::add( $checks, 'file_mods', 'File modification constants', $mods_status, $mods_detail, $file_mods ? '' : 'install_plugin and update_plugin cannot work while DISALLOW_FILE_MODS is set.' );

		// --- Debug flags ------------------------------------------------------------
		$debug_bits = array();
		foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_ENVIRONMENT_TYPE' ) as $const ) {
			if ( defined( $const ) ) {
				$value        = constant( $const );
				$debug_bits[] = $const . '=' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			}
		}
		self::add( $checks, 'debug_flags', 'Debug constants', 'ok', $debug_bits ? implode( ', ', $debug_bits ) : 'No debug constants defined.' );

		// --- Limits -------------------------------------------------------------------
		$memory_raw = ini_get( 'memory_limit' );
		$memory     = self::parse_bytes( $memory_raw );
		$max_exec   = (int) ini_get( 'max_execution_time' );
		$limits     = array(
			'memory_limit=' . $memory_raw,
			'max_execution_time=' . $max_exec . 's',
			'post_max_size=' . ini_get( 'post_max_size' ),
		);
		$limits_status = ( $memory >= 128 * 1024 * 1024 ) ? 'ok' : 'warn';
		self::add(
			$checks,
			'php_limits',
			'PHP limits',
			$limits_status,
			implode( ', ', $limits ),
			128 * 1024 * 1024 > $memory ? 'Low memory limit may break large file operations and media uploads.' : ''
		);

		// --- Disk ------------------------------------------------------------------------
		$free = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $free ) {
			self::add(
				$checks,
				'disk_space',
				'Disk space',
				$free < 500 * 1024 * 1024 ? 'warn' : 'ok',
				'Free space: ' . Util::bytes( $free ) . '.',
				$free < 500 * 1024 * 1024 ? 'Very little disk space can break file writes, uploads, and updates.' : ''
			);
		} else {
			self::add( $checks, 'disk_space', 'Disk space', 'ok', 'Could not determine free space on this host.' );
		}

		// --- Cron ---------------------------------------------------------------------------
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		self::add(
			$checks,
			'cron',
			'WP-Cron',
			$cron_disabled ? 'warn' : 'ok',
			$cron_disabled ? 'DISABLE_WP_CRON is set (a system cron must run wp-cron.php).' : 'WP-Cron is enabled.',
			$cron_disabled ? 'Make sure a system cron calls wp-cron.php, or daily cleanup tasks will not run.' : ''
		);

		// --- Tokens summary --------------------------------------------------------------------
		self::add( $checks, 'tokens', 'Connection tokens', 'ok', Tokens::active_count() . ' active token(s).' );

		$summary = array(
			'ok'     => 0,
			'warn'   => 0,
			'fail'   => 0,
			'ran_at' => Util::now(),
			'full'   => (bool) $full,
		);
		foreach ( $checks as $check ) {
			if ( isset( $summary[ $check['status'] ] ) ) {
				$summary[ $check['status'] ]++;
			}
		}

		return array(
			'checks'  => $checks,
			'summary' => $summary,
		);
	}

	/**
	 * Append a check.
	 *
	 * @param array  $checks Checks list (by reference).
	 * @param string $id     Machine id.
	 * @param string $label  Label.
	 * @param string $status ok|warn|fail.
	 * @param string $detail Detail line.
	 * @param string $fix    Actionable fix.
	 * @return void
	 */
	private static function add( array &$checks, $id, $label, $status, $detail, $fix = '' ) {
		$checks[] = array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => (string) $detail,
			'fix'    => (string) $fix,
		);
	}

	/**
	 * Parse a php.ini shorthand byte value.
	 *
	 * @param string $value Value like "256M".
	 * @return int
	 */
	private static function parse_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return PHP_INT_MAX;
		}
		$unit = strtolower( substr( $value, -1 ) );
		$num  = (int) $value;
		switch ( $unit ) {
			case 'g':
				return $num * 1024 * 1024 * 1024;
			case 'm':
				return $num * 1024 * 1024;
			case 'k':
				return $num * 1024;
			default:
				return (int) $value;
		}
	}
}
