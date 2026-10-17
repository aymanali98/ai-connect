<?php
/**
 * Sandbox management: the wp-content/sitewright-sandbox directory where agent
 * written PHP is loaded with crash recovery.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sandbox directory helpers and PHP write policy enforcement.
 */
final class Sandbox {

	/**
	 * Sandbox directory name under wp-content.
	 */
	const DIRNAME = 'sitewright-sandbox';

	/**
	 * Absolute sandbox directory.
	 *
	 * @return string
	 */
	public static function dir() {
		return rtrim( Util::norm_path( WP_CONTENT_DIR ), '/' ) . '/' . self::DIRNAME;
	}

	/**
	 * Marker written when a sandbox file crashed PHP.
	 *
	 * @return string
	 */
	public static function crashed_file() {
		return self::dir() . '/.crashed';
	}

	/**
	 * Create the sandbox directory when missing.
	 *
	 * @return bool
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( is_dir( $dir ) ) {
			return true;
		}
		if ( ! function_exists( 'wp_mkdir_p' ) ) {
			return @mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return wp_mkdir_p( $dir );
	}

	/**
	 * Whether a path is inside the sandbox directory.
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public static function contains( $abs ) {
		return Util::path_starts_with( $abs, self::dir() );
	}

	/**
	 * Crash details or null.
	 *
	 * @return array|null
	 */
	public static function crash_info() {
		$file = self::crashed_file();
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw  = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$info = json_decode( $raw, true );
		return is_array( $info ) ? $info : array( 'message' => 'Unknown crash.' );
	}

	/**
	 * Whether safe mode is active (a crash marker exists).
	 *
	 * @return bool
	 */
	public static function is_safe_mode() {
		return is_file( self::crashed_file() );
	}

	/**
	 * Remove the crash marker and leave safe mode.
	 *
	 * @return bool
	 */
	public static function clear_crash() {
		if ( ! is_file( self::crashed_file() ) ) {
			return false;
		}
		return @unlink( self::crashed_file() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * List sandbox PHP files.
	 *
	 * @return array
	 */
	public static function files() {
		$files = glob( self::dir() . '/*.php' );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * Enforce the PHP file write policy for a resolved target path.
	 *
	 * @param string $abs    Absolute, guard-resolved path.
	 * @param string $action Verb for the message (write/edit/rename/delete).
	 * @return void
	 * @throws Tool_Error When policy forbids the operation.
	 */
	public static function assert_php_allowed( $abs, $action = 'write' ) {
		if ( 'sandbox' !== Settings::get( 'php_file_writes' ) ) {
			return; // Policy "anywhere": administrator opted in.
		}
		if ( ! Path_Guard::is_php_path( $abs ) ) {
			return; // Only executable PHP is sandboxed.
		}
		if ( self::contains( $abs ) ) {
			return;
		}
		throw new Tool_Error(
			sprintf( 'Refusing to %s an executable PHP file outside the sandbox.', $action ),
			'Target: ' . Path_Guard::relative( $abs ),
			'Write it to wp-content/' . self::DIRNAME . '/ instead. PHP there is loaded with crash recovery. '
				. 'An administrator can allow PHP writes anywhere via Ai Connect -> Settings ("PHP file writes: anywhere").'
		);
	}

	/**
	 * Status summary for diagnostics and the admin UI.
	 *
	 * @return array
	 */
	public static function status() {
		$dir = self::dir();
		return array(
			'dir'        => $dir,
			'exists'     => is_dir( $dir ),
			'writable'   => is_dir( $dir ) && is_writable( $dir ),
			'safe_mode'  => self::is_safe_mode(),
			'crash'      => self::crash_info(),
			'policy'     => Settings::get( 'php_file_writes' ),
			'files'      => array_map( 'basename', self::files() ),
		);
	}
}
