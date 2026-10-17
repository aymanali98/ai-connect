<?php
/**
 * Settings storage with strict sanitization. One option row: sitw settings.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin settings option.
 */
final class Settings {

	/**
	 * Request-scoped settings cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Master switch (Connect AI page).
			'enabled'                  => true,
			// Security modes.
			'read_only'                => false,
			'allow_dangerous_tools'    => true,
			// Tokens & logging.
			'default_token_days'       => 90,
			'log_retention'            => 500,
			// Filesystem.
			'fs_jail'                  => 'root',
			'fs_read_max_bytes'        => 1048576,
			'fs_write_max_bytes'       => 2097152,
			'protected_paths'          => array( 'wp-config.php' ),
			'php_file_writes'          => 'sandbox',
			// WP-CLI.
			'wp_cli_path'              => '',
			'wp_cli_timeout'           => 60,
			// Housekeeping.
			'delete_data_on_uninstall' => false,
			// Disabled tools (list of tool names).
			'disabled_tools'           => array(),
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( AI_CONNECT_OPTION, null );
		if ( null === $stored ) {
			// One-time migration from the pre-1.1.0 option key. The legacy row is
			// kept untouched as a rollback backup; reads and writes use the new key.
			$legacy_key = defined( 'SITEWRIGHT_OPTION' ) ? SITEWRIGHT_OPTION : 'sitewright_settings';
			$legacy     = get_option( $legacy_key, null );
			if ( null !== $legacy ) {
				update_option( AI_CONNECT_OPTION, $legacy, false );
				$stored = $legacy;
			}
		}
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		self::$cache = array_merge( self::defaults(), $stored );
		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Merge a patch into the settings.
	 *
	 * @param array $patch Key => value patch.
	 * @return array The new full settings array.
	 */
	public static function update( array $patch ) {
		$merged   = array_merge( self::all(), $patch );
		$clean    = self::sanitize( $merged );
		update_option( AI_CONNECT_OPTION, $clean, false );
		self::flush_cache();
		return $clean;
	}

	/**
	 * Reset the memoized copy (tests and tools that update in-process).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( AI_CONNECT_OPTION, 'options' );
		}
	}

	/**
	 * Whether the plugin is switched on (the master switch on the Settings
	 * page). When off, the MCP endpoint refuses every request with a clear
	 * error before authentication, and background jobs do not run.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return Util::to_bool( self::get( 'enabled' ) );
	}

	/**
	 * Whether read-only mode is active.
	 *
	 * @return bool
	 */
	public static function is_read_only() {
		return Util::to_bool( self::get( 'read_only' ) );
	}

	/**
	 * Whether dangerous tools (PHP, SQL writes, WP-CLI) may run at all.
	 *
	 * @return bool
	 */
	public static function dangerous_allowed() {
		return Util::to_bool( self::get( 'allow_dangerous_tools' ) );
	}

	/**
	 * Names of tools disabled by the administrator.
	 *
	 * @return array
	 */
	public static function disabled_tools() {
		$value = self::get( 'disabled_tools' );
		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	/**
	 * The effective base directory for relative filesystem paths.
	 *
	 * @return string
	 */
	public static function fs_base() {
		return 'wp-content' === self::get( 'fs_jail' ) ? WP_CONTENT_DIR : ABSPATH;
	}

	/**
	 * Sanitize and clamp a full settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		$out['enabled']                  = Util::to_bool( isset( $input['enabled'] ) ? $input['enabled'] : $defaults['enabled'] );
		$out['read_only']                = Util::to_bool( isset( $input['read_only'] ) ? $input['read_only'] : $defaults['read_only'] );
		$out['allow_dangerous_tools']    = Util::to_bool( isset( $input['allow_dangerous_tools'] ) ? $input['allow_dangerous_tools'] : $defaults['allow_dangerous_tools'] );
		$out['delete_data_on_uninstall'] = Util::to_bool( isset( $input['delete_data_on_uninstall'] ) ? $input['delete_data_on_uninstall'] : $defaults['delete_data_on_uninstall'] );

		$out['default_token_days'] = self::clamp_int( isset( $input['default_token_days'] ) ? $input['default_token_days'] : $defaults['default_token_days'], 0, 3650, $defaults['default_token_days'] );
		$out['log_retention']      = self::clamp_int( isset( $input['log_retention'] ) ? $input['log_retention'] : $defaults['log_retention'], 50, 10000, $defaults['log_retention'] );
		$out['wp_cli_timeout']     = self::clamp_int( isset( $input['wp_cli_timeout'] ) ? $input['wp_cli_timeout'] : $defaults['wp_cli_timeout'], 5, 600, $defaults['wp_cli_timeout'] );

		$out['fs_read_max_bytes']  = self::clamp_int( isset( $input['fs_read_max_bytes'] ) ? $input['fs_read_max_bytes'] : $defaults['fs_read_max_bytes'], 1024, 104857600, $defaults['fs_read_max_bytes'] );
		$out['fs_write_max_bytes'] = self::clamp_int( isset( $input['fs_write_max_bytes'] ) ? $input['fs_write_max_bytes'] : $defaults['fs_write_max_bytes'], 1024, 104857600, $defaults['fs_write_max_bytes'] );

		$jail         = isset( $input['fs_jail'] ) ? (string) $input['fs_jail'] : $defaults['fs_jail'];
		$out['fs_jail'] = in_array( $jail, array( 'root', 'wp-content' ), true ) ? $jail : $defaults['fs_jail'];

		$php_policy             = isset( $input['php_file_writes'] ) ? (string) $input['php_file_writes'] : $defaults['php_file_writes'];
		$out['php_file_writes'] = in_array( $php_policy, array( 'sandbox', 'anywhere' ), true ) ? $php_policy : $defaults['php_file_writes'];

		$out['wp_cli_path'] = self::sanitize_line( isset( $input['wp_cli_path'] ) ? $input['wp_cli_path'] : '' );

		$protected = isset( $input['protected_paths'] ) ? $input['protected_paths'] : $defaults['protected_paths'];
		if ( is_string( $protected ) ) {
			$protected = preg_split( '/\r\n|\r|\n/', $protected );
		}
		$clean_protected = array();
		if ( is_array( $protected ) ) {
			foreach ( $protected as $entry ) {
				$entry = trim( Util::norm_path( self::sanitize_line( $entry ) ), '/' );
				$entry = ltrim( $entry, '/' );
				if ( '' !== $entry && false === strpos( $entry, '..' ) && ! in_array( $entry, $clean_protected, true ) ) {
					$clean_protected[] = $entry;
				}
			}
		}
		$out['protected_paths'] = $clean_protected;

		$disabled = isset( $input['disabled_tools'] ) ? $input['disabled_tools'] : array();
		if ( is_string( $disabled ) ) {
			$disabled = preg_split( '/[\r\n,]+/', $disabled );
		}
		$clean_disabled = array();
		if ( is_array( $disabled ) ) {
			foreach ( $disabled as $name ) {
				$name = strtolower( trim( (string) $name ) );
				$name = (string) preg_replace( '/[^a-z0-9_\-]/', '', $name );
				if ( '' !== $name && ! in_array( $name, $clean_disabled, true ) ) {
					$clean_disabled[] = $name;
				}
			}
		}
		$out['disabled_tools'] = $clean_disabled;

		return $out;
	}

	/**
	 * Clamp an integer into a range.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @param int   $fallback Fallback when not numeric.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $fallback;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * One-line string sanitizer that does not require WordPress (test-safe).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function sanitize_line( $value ) {
		$value = (string) $value;
		$value = (string) preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = strip_tags( $value );
		return trim( $value );
	}
}
