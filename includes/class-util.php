<?php
/**
 * Small shared helpers. Kept dependency-free so unit tests can load it without WordPress.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility helpers.
 */
final class Util {

	/**
	 * Pretty JSON, safe for protocol output (never throws).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json( $value ) {
		$encoded = json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			$encoded = '"<unencodable value: ' . esc_html_stub( gettype( $value ) ) . '>"';
		}
		return $encoded;
	}

	/**
	 * Compact JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json_compact( $value ) {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? 'null' : $encoded;
	}

	/**
	 * Normalize slashes in a path without touching the filesystem.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function norm_path( $path ) {
		$path = str_replace( "\0", '', (string) $path );
		$path = str_replace( '\\', '/', $path );
		$path = (string) preg_replace( '#/+#', '/', $path );
		return $path;
	}

	/**
	 * Whether the platform is Windows.
	 *
	 * @return bool
	 */
	public static function is_windows() {
		return 0 === stripos( PHP_OS_FAMILY, 'Windows' );
	}

	/**
	 * Case-aware path prefix comparison.
	 *
	 * @param string $path   Path to test.
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	public static function path_starts_with( $path, $prefix ) {
		$path   = self::norm_path( $path );
		$prefix = rtrim( self::norm_path( $prefix ), '/' );
		if ( self::is_windows() ) {
			$path   = strtolower( $path );
			$prefix = strtolower( $prefix );
		}
		return $path === $prefix || 0 === strpos( $path . '/', $prefix . '/' );
	}

	/**
	 * UTC timestamp in MySQL format.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Convert a MySQL UTC timestamp to a unix timestamp.
	 *
	 * @param string $mysql Datetime.
	 * @return int
	 */
	public static function to_unix( $mysql ) {
		if ( empty( $mysql ) || '0000-00-00 00:00:00' === $mysql ) {
			return 0;
		}
		$time = strtotime( $mysql . ' UTC' );
		return false === $time ? 0 : (int) $time;
	}

	/**
	 * Truncate a string safely.
	 *
	 * @param string $value String.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	public static function truncate( $value, $max ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, max( 0, $max - 3 ) ) . '...';
	}

	/**
	 * Human-readable byte size.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function bytes( $bytes ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$index = 0;
		while ( $bytes >= 1024 && $index < count( $units ) - 1 ) {
			$bytes /= 1024;
			$index++;
		}
		return ( $index < 2 ? (string) (int) $bytes : number_format_i18n_stub( $bytes, 1 ) ) . ' ' . $units[ $index ];
	}

	/**
	 * Coerce a value to boolean.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return (bool) $value;
	}
}

/**
 * Escaping stand-in used only in Util::json fallback, where the value is a type
 * name produced by PHP itself. Defined in this file so Util stays dependency-free.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html_stub( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Number formatting stand-in for dependency-free contexts.
 *
 * @param float $number Number.
 * @param int   $decimals Decimals.
 * @return string
 */
function number_format_i18n_stub( $number, $decimals = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
	return number_format( (float) $number, (int) $decimals );
}
