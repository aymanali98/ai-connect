<?php
/**
 * SQL statement guard: classifies statements and detects multi-statement input
 * so db tools can enforce read/write/ddl boundaries. No WordPress dependency.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static SQL analysis helpers.
 */
final class Sql_Guard {

	/**
	 * Classify a SQL statement.
	 *
	 * @param string $sql Raw statement.
	 * @return array{kind: string, label: string, reason: string} kind: read|write|ddl|unknown
	 */
	public static function classify( $sql ) {
		$clean = self::strip_literals_and_comments( (string) $sql );
		$trim  = trim( $clean );

		if ( '' === $trim ) {
			return self::result( 'unknown', 'Empty statement' );
		}
		if ( self::is_multi_statement( $sql ) ) {
			return self::result( 'unknown', 'Multiple statements in one call' );
		}

		if ( ! preg_match( '/^([A-Za-z]+)/', $trim, $matches ) ) {
			return self::result( 'unknown', 'Unrecognized statement' );
		}
		$verb = strtoupper( $matches[1] );

		if ( 'WITH' === $verb ) {
			if ( preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $trim ) ) {
				return self::result( 'write', 'WITH ... write query' );
			}
			if ( preg_match( '/\bSELECT\b/i', $trim ) ) {
				return self::result( 'read', 'WITH ... SELECT query' );
			}
			return self::result( 'unknown', 'WITH without a recognized main statement' );
		}

		$read = array( 'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );
		if ( in_array( $verb, $read, true ) ) {
			return self::result( 'read', $verb . ' query' );
		}

		$write = array( 'INSERT', 'UPDATE', 'DELETE', 'REPLACE' );
		if ( in_array( $verb, $write, true ) ) {
			return self::result( 'write', $verb . ' query' );
		}

		$ddl = array( 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE', 'SET', 'USE', 'CALL', 'LOCK', 'UNLOCK', 'FLUSH', 'ANALYZE', 'OPTIMIZE', 'REPAIR', 'KILL', 'HANDLER', 'LOAD' );
		if ( in_array( $verb, $ddl, true ) ) {
			return self::result( 'ddl', $verb . ' statement' );
		}

		return self::result( 'unknown', 'Unsupported statement type: ' . $verb );
	}

	/**
	 * Whether parsing sees more than one statement.
	 *
	 * @param string $sql Raw statement.
	 * @return bool
	 */
	public static function is_multi_statement( $sql ) {
		$clean = self::strip_literals_and_comments( (string) $sql );
		return 1 === preg_match( '/;\s*\S/', $clean );
	}

	/**
	 * Whether the statement references a system schema with a dotted name.
	 *
	 * @param string $sql Raw statement.
	 * @return bool
	 */
	public static function touches_system_schema( $sql ) {
		$clean = self::strip_literals_and_comments( (string) $sql );
		return 1 === preg_match( '/(^|[^A-Za-z0-9_])(information_schema|performance_schema|mysql|sys)\s*`?\s*\./i', $clean );
	}

	/**
	 * Whether the statement performs server-side file IO: INTO OUTFILE /
	 * DUMPFILE, LOAD_FILE(), or LOAD DATA. These are blocked in the SQL tools
	 * because they read or write files on the database server outside the
	 * filesystem jail. (A deliberate limitation; file work goes through the
	 * filesystem tools where Path_Guard applies.)
	 *
	 * @param string $sql Raw statement.
	 * @return bool
	 */
	public static function touches_filesystem( $sql ) {
		$clean = self::strip_literals_and_comments( (string) $sql );
		return 1 === preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b|\bLOAD_FILE\s*\(|\bLOAD\s+DATA\b/i', $clean );
	}

	/**
	 * Replace the {prefix} placeholder with the real table prefix.
	 *
	 * @param string $sql    Raw statement.
	 * @param string $prefix Table prefix.
	 * @return string
	 */
	public static function substitute_prefix( $sql, $prefix ) {
		return str_replace( array( '{{prefix}}', '{prefix}' ), (string) $prefix, (string) $sql );
	}

	/**
	 * Build a classification result.
	 *
	 * @param string $kind   Kind.
	 * @param string $label  Label.
	 * @return array
	 */
	private static function result( $kind, $label ) {
		return array(
			'kind'   => $kind,
			'label'  => $label,
			'reason' => $label,
		);
	}

	/**
	 * Replace the contents of string literals (single/double quotes) and
	 * comments with blanks so the remaining text can be analyzed safely.
	 * Backtick-quoted identifiers stay in the output so system-schema and
	 * table checks can still see them. Keeps quotes as delimiters.
	 *
	 * @param string $sql Raw SQL.
	 * @return string
	 */
	public static function strip_literals_and_comments( $sql ) {
		$out   = '';
		$len   = strlen( $sql );
		$i     = 0;
		$state = 'code';
		$quote = '';

		while ( $i < $len ) {
			$ch   = $sql[ $i ];
			$next = ( $i + 1 ) < $len ? $sql[ $i + 1 ] : '';

			if ( 'code' === $state ) {
				if ( "'" === $ch || '"' === $ch ) {
					$state = 'string';
					$quote = $ch;
					$out  .= $ch;
				} elseif ( '-' === $ch && '-' === $next ) {
					$state = 'line_comment';
					$i++;
					$out .= ' ';
				} elseif ( '#' === $ch ) {
					$state = 'line_comment';
					$out  .= ' ';
				} elseif ( '/' === $ch && '*' === $next ) {
					$state = 'block_comment';
					$i++;
					$out .= ' ';
				} else {
					$out .= $ch;
				}
			} elseif ( 'string' === $state ) {
				if ( '\\' === $ch ) {
					$i++; // Skip the escaped character entirely.
				} elseif ( $ch === $quote ) {
					if ( $next === $quote ) {
						$i++; // Doubled quote inside the literal.
					} else {
						$state = 'code';
						$out  .= $ch;
					}
				}
			} elseif ( 'line_comment' === $state ) {
				if ( "\n" === $ch ) {
					$state = 'code';
					$out  .= "\n";
				}
			} elseif ( 'block_comment' === $state ) {
				if ( '*' === $ch && '/' === $next ) {
					$state = 'code';
					$i++;
					$out .= ' ';
				} elseif ( "\n" === $ch ) {
					$out .= "\n";
				}
			}

			$i++;
		}

		return $out;
	}
}
