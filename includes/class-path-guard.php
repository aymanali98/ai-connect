<?php
/**
 * Filesystem path guard: containment inside the configured jail, no traversal,
 * symlink-escape checks, protected-path rules. Throws Tool_Error on violation.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and vets filesystem paths for every filesystem tool.
 */
final class Path_Guard {

	/**
	 * Normalized allowed base directory (no trailing slash).
	 *
	 * @return string
	 */
	public static function base() {
		return rtrim( Util::norm_path( Settings::fs_base() ), '/' );
	}

	/**
	 * Real path of the base directory when possible.
	 *
	 * @return string
	 */
	public static function real_base() {
		$real = realpath( self::base() );
		return rtrim( Util::norm_path( false !== $real ? $real : self::base() ), '/' );
	}

	/**
	 * Whether a path is absolute (POSIX or Windows drive).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_absolute( $path ) {
		$path = (string) $path;
		return 0 === strpos( $path, '/' ) || 1 === preg_match( '#^[A-Za-z]:/#', $path );
	}

	/**
	 * Resolve a user-supplied path against the jail and enforce containment.
	 *
	 * @param string $path       Raw path from the agent.
	 * @param bool   $must_exist Whether the path must already exist.
	 * @return string Absolute, verified path.
	 * @throws Tool_Error When the path is invalid or escapes the jail.
	 */
	public static function resolve( $path, $must_exist = false ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			throw new Tool_Error( 'A path is required.' );
		}
		if ( false !== strpos( $path, "\0" ) ) {
			throw new Tool_Error( 'The path contains a null byte.' );
		}

		$path = Util::norm_path( $path );

		foreach ( explode( '/', $path ) as $index => $segment ) {
			if ( '..' === $segment ) {
				throw new Tool_Error(
					'Path traversal ("..") is not allowed.',
					'Paths are resolved from ' . self::base() . '; use an absolute path under the allowed base if you need another location.'
				);
			}
			// Windows silently strips trailing dots and spaces from a segment,
			// so ".. " is "..", and "wp-config.php. " is "wp-config.php".
			// Reject every such spelling to keep containment and
			// protected-path checks meaningful on Windows.
			$segment_win = rtrim( $segment, " .\t" );
			if ( $segment_win !== $segment ) {
				throw new Tool_Error(
					'Path segments cannot end with dots or spaces.',
					'Windows would silently strip them, which can turn a harmless name into ".." or into a protected file like wp-config.php.'
				);
			}
			// NTFS alternate data streams: a colon is only legal in a Windows
			// drive prefix ("C:"), which can only be the first segment.
			$is_drive = ( 0 === $index && 1 === preg_match( '/^[A-Za-z]:$/', $segment ) );
			if ( ! $is_drive && false !== strpos( $segment, ':' ) ) {
				throw new Tool_Error(
					'Colon characters are not allowed in path segments.',
					'Colons can address NTFS alternate data streams on Windows, which would bypass path checks.'
				);
			}
		}

		$base = self::base();
		if ( ! self::is_absolute( $path ) ) {
			$path = $base . '/' . $path;
		}
		$path = Util::norm_path( $path );

		if ( ! Util::path_starts_with( $path, $base ) ) {
			throw new Tool_Error(
				'The path is outside the allowed base directory.',
				'Resolved to: ' . $path . ' — allowed base: ' . $base
			);
		}

		// Symlink-aware verification: resolve the closest existing ancestor and
		// re-check containment on the real path.
		$probe  = $path;
		$suffix = '';
		$guard  = 0;
		while ( ! file_exists( $probe ) && $guard < 100 ) {
			$parent = dirname( $probe );
			if ( '' === $parent || '.' === $parent || $parent === $probe ) {
				break;
			}
			$suffix = '/' . basename( $probe ) . $suffix;
			$probe  = $parent;
			$guard++;
		}

		$real = realpath( $probe );
		if ( false !== $real ) {
			$real_norm = Util::norm_path( $real );
			if ( ! Util::path_starts_with( $real_norm, self::real_base() ) ) {
				throw new Tool_Error(
					'The path escapes the allowed base directory through a symbolic link.',
					'Real location: ' . $real_norm
				);
			}
			$path = '' === $suffix ? $real_norm : $real_norm . $suffix;
		}

		if ( $must_exist && ! file_exists( $path ) ) {
			throw new Tool_Error( 'File or directory not found: ' . $path );
		}

		return $path;
	}

	/**
	 * Path relative to ABSPATH, for display and protected-path matching.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	public static function relative( $abs ) {
		$abs  = Util::norm_path( $abs );
		$root = rtrim( Util::norm_path( ABSPATH ), '/' );
		if ( Util::path_starts_with( $abs, $root ) ) {
			return ltrim( substr( $abs, strlen( $root ) ), '/' );
		}
		return $abs;
	}

	/**
	 * Reject write/delete/rename operations on protected files.
	 *
	 * @param string $abs Absolute path.
	 * @param string $op  Operation label for the message.
	 * @return void
	 * @throws Tool_Error When the path is protected.
	 */
	public static function check_writable( $abs, $op = 'modify' ) {
		$rel      = self::relative( $abs );
		$rel_norm = ltrim( Util::norm_path( $rel ), '/' );

		foreach ( (array) Settings::get( 'protected_paths' ) as $protected ) {
			$protected = ltrim( Util::norm_path( (string) $protected ), '/' );
			if ( '' === $protected ) {
				continue;
			}
			$is_match = Util::path_starts_with( $rel_norm, $protected );
			if ( $is_match ) {
				throw new Tool_Error(
					sprintf( 'Refusing to %s the protected path "%s".', $op, $rel_norm ),
					'Protected paths are configured in Ai Connect -> Settings.',
					sprintf( 'Remove "%s" from the protected list only if you genuinely need to modify it.', $protected )
				);
			}
		}
	}

	/**
	 * Whether a filename suggests PHP that could execute.
	 *
	 * @param string $path Path or filename.
	 * @return bool
	 */
	public static function is_php_path( $path ) {
		$name = strtolower( basename( Util::norm_path( $path ) ) );
		if ( false !== strpos( $name, '.php' ) ) {
			// Plain text/data siblings such as notes.md are fine; anything whose
			// name contains ".php" anywhere is treated as PHP.
			return true;
		}
		if ( 'php' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
			return true;
		}
		return in_array( pathinfo( $name, PATHINFO_EXTENSION ), array( 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps' ), true );
	}
}
