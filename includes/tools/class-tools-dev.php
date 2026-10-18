<?php
/**
 * Developer tools: filesystem, PHP execution, database, and WP-CLI.
 *
 * Agent-facing strings are intentionally plain English (not translated).
 *
 * @package AiConnect
 */

namespace AiConnect\Tools;

use AiConnect\Path_Guard;
use AiConnect\Registry;
use AiConnect\Sandbox;
use AiConnect\Settings;
use AiConnect\Sql_Guard;
use AiConnect\Tool_Error;
use AiConnect\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the filesystem/database/code tool group.
 */
final class Dev {

	/**
	 * Register this group.
	 *
	 * @return void
	 */
	public static function register() {
		Registry::add(
			'list_directory',
			array(
				'title'       => 'List directory',
				'description' => 'List files and directories under the WordPress root (or wp-content, depending on the filesystem jail setting). Supports recursion with a depth limit and an entry cap.',
				'risk'        => 'read',
				'group'       => 'files',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'path'          => array(
							'type'        => 'string',
							'description' => 'Directory path (relative to the base or absolute under it). Defaults to the base directory.',
							'default'     => '',
						),
						'recursive'     => array(
							'type'        => 'boolean',
							'description' => 'Recurse into subdirectories.',
							'default'     => false,
						),
						'depth'         => array(
							'type'        => 'integer',
							'description' => 'Maximum recursion depth (1-5).',
							'default'     => 1,
							'minimum'     => 1,
							'maximum'     => 5,
						),
						'include_hidden' => array(
							'type'        => 'boolean',
							'description' => 'Include dotfiles and dot-directories.',
							'default'     => false,
						),
						'limit'         => array(
							'type'        => 'integer',
							'description' => 'Maximum entries returned (1-500).',
							'default'     => 200,
							'minimum'     => 1,
							'maximum'     => 500,
						),
					),
				),
				'callback'    => array( __CLASS__, 'list_directory' ),
			)
		);

		Registry::add(
			'read_file',
			array(
				'title'       => 'Read file',
				'description' => 'Read a file under the WordPress root. Supports byte offsets for large files. Binary or invalid-UTF-8 content is returned base64-encoded. Size capped by the fs_read_max_bytes setting.',
				'risk'        => 'read',
				'group'       => 'files',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'path'      => array(
							'type'        => 'string',
							'description' => 'File path (relative to the base or absolute under it).',
							'minLength'   => 1,
						),
						'offset'    => array(
							'type'        => 'integer',
							'description' => 'Byte offset to start from.',
							'default'     => 0,
							'minimum'     => 0,
						),
						'max_bytes' => array(
							'type'        => 'integer',
							'description' => 'Maximum bytes to return (clamped to the fs_read_max_bytes setting).',
							'default'     => 0,
							'minimum'     => 0,
						),
					),
					'required'   => array( 'path' ),
				),
				'callback'    => array( __CLASS__, 'read_file' ),
			)
		);

		Registry::add(
			'write_file',
			array(
				'title'       => 'Write file',
				'description' => 'Write full UTF-8 text content to a file (creates parent directories; overwrites existing files). Executable PHP may only be written inside wp-content/sitewright-sandbox/ unless the admin changed the policy. Use edit_file for targeted changes.',
				'risk'        => 'write',
				'group'       => 'files',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'path'               => array(
							'type'        => 'string',
							'description' => 'Destination file path.',
							'minLength'   => 1,
						),
						'content'            => array(
							'type'        => 'string',
							'description' => 'Full file content (UTF-8).',
						),
						'create_directories' => array(
							'type'        => 'boolean',
							'description' => 'Create missing parent directories.',
							'default'     => true,
						),
					),
					'required'   => array( 'path', 'content' ),
				),
				'callback'    => array( __CLASS__, 'write_file' ),
			)
		);

		Registry::add(
			'edit_file',
			array(
				'title'       => 'Edit file',
				'description' => 'Replace an exact text fragment inside a file. Fails when the fragment is missing; refuses ambiguous multi-match edits unless replace_all is true. Prefer this over write_file for small changes.',
				'risk'        => 'write',
				'group'       => 'files',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'path'        => array(
							'type'        => 'string',
							'description' => 'File path to edit.',
							'minLength'   => 1,
						),
						'find'        => array(
							'type'        => 'string',
							'description' => 'Exact text to find (copy it from read_file output).',
							'minLength'   => 1,
						),
						'replace'     => array(
							'type'        => 'string',
							'description' => 'Replacement text.',
						),
						'replace_all' => array(
							'type'        => 'boolean',
							'description' => 'Replace every occurrence instead of requiring a unique match.',
							'default'     => false,
						),
					),
					'required'   => array( 'path', 'find', 'replace' ),
				),
				'callback'    => array( __CLASS__, 'edit_file' ),
			)
		);

		Registry::add(
			'delete_file',
			array(
				'title'       => 'Delete file',
				'description' => 'Delete one file, or one empty directory. Refuses WordPress core directories (wp-admin, wp-includes) and protected paths.',
				'risk'        => 'write',
				'group'       => 'files',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'File or empty directory path.',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'path' ),
				),
				'callback'    => array( __CLASS__, 'delete_file' ),
			)
		);

		Registry::add(
			'rename_file',
			array(
				'title'       => 'Rename or move file',
				'description' => 'Rename/move a file or directory. Screens WordPress core paths and protected paths. Renaming a .php file to *.php.disabled neutralizes its execution without deleting it (and back).',
				'risk'        => 'write',
				'group'       => 'files',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'from' => array(
							'type'        => 'string',
							'description' => 'Existing source path.',
							'minLength'   => 1,
						),
						'to'   => array(
							'type'        => 'string',
							'description' => 'Destination path (must not exist).',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'from', 'to' ),
				),
				'callback'    => array( __CLASS__, 'rename_file' ),
			)
		);

		Registry::add(
			'run_php',
			array(
				'title'       => 'Run PHP',
				'description' => 'Execute a PHP snippet inside the live WordPress runtime with the full environment available (global $wpdb, all WP functions, loaded plugins). Use "return $value;" to return data. Echo output, return value, and captured warnings are reported. Fatal errors kill the request and cannot be caught.',
				'risk'        => 'dangerous',
				'group'       => 'code',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'code' => array(
							'type'        => 'string',
							'description' => 'PHP code without <?php tags. Example: global $wpdb; return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");',
							'minLength'   => 1,
							'maxLength'   => 102400,
						),
					),
					'required'   => array( 'code' ),
				),
				'callback'    => array( __CLASS__, 'run_php' ),
			)
		);

		Registry::add(
			'db_query',
			array(
				'title'       => 'Database query (read)',
				'description' => 'Run a read-only SQL statement (SELECT, SHOW, DESCRIBE, EXPLAIN, WITH...SELECT) against the WordPress database. Use {prefix} for the table prefix. SELECTs without LIMIT get LIMIT 200 appended. Optional positional params are passed through prepare() when the statement contains placeholders.',
				'risk'        => 'read',
				'group'       => 'database',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'sql'    => array(
							'type'        => 'string',
							'description' => 'Read SQL statement. Example: SELECT ID, post_title FROM {prefix}posts WHERE post_status = %s LIMIT 10',
							'minLength'   => 1,
						),
						'params' => array(
							'type'        => 'array',
							'description' => 'Values for %s/%d placeholders (optional).',
							'items'       => array( 'type' => 'string' ),
							'default'     => array(),
						),
					),
					'required'   => array( 'sql' ),
				),
				'callback'    => array( __CLASS__, 'db_query' ),
			)
		);

		Registry::add(
			'db_execute',
			array(
				'title'       => 'Database execute (write)',
				'description' => 'Run a write or DDL SQL statement (INSERT, UPDATE, DELETE, REPLACE, CREATE, ALTER, DROP, ...). Refuses multi-statement input, MySQL system schemas (information_schema, performance_schema, mysql, sys), and server-level statements (GRANT, CREATE/DROP DATABASE, CREATE USER, SET GLOBAL).',
				'risk'        => 'dangerous',
				'group'       => 'database',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'sql'    => array(
							'type'        => 'string',
							'description' => 'Write/DDL SQL statement. Use {prefix} for the table prefix.',
							'minLength'   => 1,
						),
						'params' => array(
							'type'        => 'array',
							'description' => 'Values for %s/%d placeholders (optional).',
							'items'       => array( 'type' => 'string' ),
							'default'     => array(),
						),
					),
					'required'   => array( 'sql' ),
				),
				'callback'    => array( __CLASS__, 'db_execute' ),
			)
		);

		Registry::add(
			'db_tables',
			array(
				'title'       => 'Database tables',
				'description' => 'List all database tables with engine, row estimate, and size.',
				'risk'        => 'read',
				'group'       => 'database',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'callback'    => array( __CLASS__, 'db_tables' ),
			)
		);

		Registry::add(
			'db_describe',
			array(
				'title'       => 'Describe table',
				'description' => 'Show columns and indexes of one table.',
				'risk'        => 'read',
				'group'       => 'database',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'table' => array(
							'type'        => 'string',
							'description' => 'Full table name (see db_tables or use the {prefix} substitution mentally).',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'table' ),
				),
				'callback'    => array( __CLASS__, 'db_describe' ),
			)
		);

		Registry::add(
			'run_wp_cli',
			array(
				'title'       => 'Run WP-CLI',
				'description' => 'Run a real WP-CLI command (without the leading "wp"), e.g. ["plugin","list","--format=json"]. Requires a wp binary on the server. Commands run with --path pointing at this install and a hard timeout.',
				'risk'        => 'dangerous',
				'group'       => 'code',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'args' => array(
							'type'        => 'array',
							'description' => 'Command arguments, each as its own array element.',
							'items'       => array( 'type' => 'string' ),
							'minItems'    => 1,
							'maxItems'    => 40,
						),
					),
					'required'   => array( 'args' ),
				),
				'callback'    => array( __CLASS__, 'run_wp_cli' ),
			)
		);
	}

	/**
	 * list_directory tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the directory is invalid.
	 */
	public static function list_directory( array $args ) {
		$path = isset( $args['path'] ) ? (string) $args['path'] : '';
		$abs  = Path_Guard::resolve( '' === $path ? Path_Guard::base() : $path, true );

		if ( ! is_dir( $abs ) ) {
			throw new Tool_Error( 'Not a directory: ' . Path_Guard::relative( $abs ) );
		}

		$recursive = ! empty( $args['recursive'] );
		$depth     = $recursive ? (int) $args['depth'] : 1;
		$limit     = (int) $args['limit'];
		$hidden    = ! empty( $args['include_hidden'] );

		$items     = array();
		$truncated = false;
		self::walk( $abs, 1, $depth, $limit, $hidden, $items, $truncated );

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['type'] !== $b['type'] ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'path'      => Path_Guard::relative( $abs ),
			'count'     => count( $items ),
			'truncated' => $truncated,
			'items'     => $items,
		);
	}

	/**
	 * read_file tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the file cannot be read.
	 */
	public static function read_file( array $args ) {
		$abs = Path_Guard::resolve( (string) $args['path'], true );
		if ( ! is_file( $abs ) ) {
			throw new Tool_Error( 'Not a file: ' . Path_Guard::relative( $abs ) );
		}
		if ( ! is_readable( $abs ) ) {
			throw new Tool_Error( 'The file is not readable by PHP: ' . Path_Guard::relative( $abs ) );
		}

		$size    = (int) filesize( $abs );
		$setting = (int) Settings::get( 'fs_read_max_bytes' );
		$max     = min( $setting, 8 * 1024 * 1024 );
		$offset  = max( 0, (int) $args['offset'] );
		$want    = isset( $args['max_bytes'] ) && (int) $args['max_bytes'] > 0 ? (int) $args['max_bytes'] : $max;
		$length  = min( $want, $max );

		$handle = fopen( $abs, 'rb' );
		if ( false === $handle ) {
			throw new Tool_Error( 'Could not open the file for reading.' );
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}
		$data = fread( $handle, $length );
		fclose( $handle );

		if ( false === $data ) {
			$data = '';
		}

		$returned  = strlen( $data );
		$truncated = ( $offset + $returned ) < $size;

		$is_utf8 = function_exists( 'mb_check_encoding' )
			? mb_check_encoding( $data, 'UTF-8' )
			: (bool) preg_match( '//u', $data );
		$result  = array(
			'path'           => Path_Guard::relative( $abs ),
			'size'           => $size,
			'offset'         => $offset,
			'returned_bytes' => $returned,
			'truncated'      => $truncated,
			'encoding'       => $is_utf8 ? 'utf-8' : 'base64',
			'sha256_returned' => hash( 'sha256', $data ),
		);
		$result['content'] = $is_utf8 ? $data : base64_encode( $data );

		return $result;
	}

	/**
	 * write_file tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When writing is forbidden or fails.
	 */
	public static function write_file( array $args ) {
		$abs = Path_Guard::resolve( (string) $args['path'] );
		Path_Guard::check_writable( $abs, 'write' );
		Sandbox::assert_php_allowed( $abs, 'write' );

		$content = (string) $args['content'];
		$bytes   = strlen( $content );
		$max     = (int) Settings::get( 'fs_write_max_bytes' );
		if ( $bytes > $max ) {
			throw new Tool_Error( 'Content exceeds the write limit (' . Util::bytes( $bytes ) . ' > ' . Util::bytes( $max ) . ').' );
		}
		if ( is_dir( $abs ) ) {
			throw new Tool_Error( 'The target is a directory: ' . Path_Guard::relative( $abs ) );
		}

		$parent = dirname( $abs );
		if ( ! is_dir( $parent ) ) {
			$create = ! isset( $args['create_directories'] ) || $args['create_directories'];
			if ( ! $create ) {
				throw new Tool_Error( 'Parent directory does not exist: ' . Path_Guard::relative( $parent ) );
			}
			if ( ! function_exists( 'wp_mkdir_p' ) ? ! @mkdir( $parent, 0755, true ) : ! wp_mkdir_p( $parent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Tool_Error( 'Could not create the parent directory: ' . Path_Guard::relative( $parent ) );
			}
		}

		$existed = file_exists( $abs );
		$written = file_put_contents( $abs, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			throw new Tool_Error( 'Writing failed (permissions?).' );
		}

		if ( Sandbox::contains( $abs ) ) {
			Sandbox::ensure_dir();
		}

		return array(
			'path'          => Path_Guard::relative( $abs ),
			'bytes_written' => (int) $written,
			'created'       => ! $existed,
			'sha256'        => hash( 'sha256', $content ),
		);
	}

	/**
	 * edit_file tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the edit cannot be applied.
	 */
	public static function edit_file( array $args ) {
		$abs = Path_Guard::resolve( (string) $args['path'], true );
		if ( ! is_file( $abs ) ) {
			throw new Tool_Error( 'Not a file: ' . Path_Guard::relative( $abs ) );
		}
		Path_Guard::check_writable( $abs, 'edit' );
		Sandbox::assert_php_allowed( $abs, 'edit' );

		$size = (int) filesize( $abs );
		if ( $size > (int) Settings::get( 'fs_write_max_bytes' ) ) {
			throw new Tool_Error( 'The file is larger than the edit limit (' . Util::bytes( $size ) . ').' );
		}

		$content = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			throw new Tool_Error( 'Could not read the file.' );
		}

		$find    = (string) $args['find'];
		$replace = (string) $args['replace'];
		if ( '' === $find ) {
			throw new Tool_Error( 'The "find" text must not be empty.' );
		}

		$count = substr_count( $content, $find );
		if ( 0 === $count ) {
			throw new Tool_Error(
				'The search text was not found in the file.',
				'Searched for: ' . Util::truncate( str_replace( "\n", '\n', $find ), 120 ),
				'Read the file first and copy the exact text, including whitespace.'
			);
		}
		if ( $count > 1 && empty( $args['replace_all'] ) ) {
			throw new Tool_Error(
				'The search text appears ' . $count . ' times.',
				'',
				'Include more surrounding context to make it unique, or pass replace_all=true to replace every occurrence.'
			);
		}

		if ( ! empty( $args['replace_all'] ) ) {
			$new = str_replace( $find, $replace, $content );
		} else {
			$pos = strpos( $content, $find );
			$new = substr( $content, 0, $pos ) . $replace . substr( $content, $pos + strlen( $find ) );
		}

		$written = file_put_contents( $abs, $new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			throw new Tool_Error( 'Writing the edited file failed (permissions?).' );
		}

		return array(
			'path'         => Path_Guard::relative( $abs ),
			'replacements' => ! empty( $args['replace_all'] ) ? $count : 1,
			'bytes'        => (int) $written,
			'sha256'       => hash( 'sha256', $new ),
		);
	}

	/**
	 * delete_file tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When deletion is forbidden or fails.
	 */
	public static function delete_file( array $args ) {
		$abs = Path_Guard::resolve( (string) $args['path'], true );
		Path_Guard::check_writable( $abs, 'delete' );
		self::assert_not_core( $abs, 'delete' );

		if ( is_dir( $abs ) ) {
			$entries = scandir( $abs );
			if ( false === $entries ) {
				throw new Tool_Error( 'Could not read the directory.' );
			}
			$entries = array_diff( $entries, array( '.', '..' ) );
			if ( ! empty( $entries ) ) {
				throw new Tool_Error(
					'The directory is not empty (' . count( $entries ) . ' entries).',
					'',
					'Delete its contents first; recursive deletion is intentionally not supported.'
				);
			}
			if ( ! rmdir( $abs ) ) {
				throw new Tool_Error( 'Could not remove the empty directory.' );
			}
			return array(
				'path'    => Path_Guard::relative( $abs ),
				'deleted' => true,
				'type'    => 'directory',
			);
		}

		if ( ! is_file( $abs ) ) {
			throw new Tool_Error( 'File not found: ' . Path_Guard::relative( $abs ) );
		}
		if ( ! unlink( $abs ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new Tool_Error( 'Could not delete the file.' );
		}

		return array(
			'path'    => Path_Guard::relative( $abs ),
			'deleted' => true,
			'type'    => 'file',
		);
	}

	/**
	 * rename_file tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the move is forbidden or fails.
	 */
	public static function rename_file( array $args ) {
		$from = Path_Guard::resolve( (string) $args['from'], true );
		$to   = Path_Guard::resolve( (string) $args['to'] );

		Path_Guard::check_writable( $from, 'rename' );
		Path_Guard::check_writable( $to, 'rename' );
		self::assert_not_core( $from, 'rename' );
		self::assert_not_core( $to, 'rename' );
		$is_restoring_disabled = ( substr( $from, -13 ) === '.php.disabled' && substr( $to, -4 ) === '.php' && substr( $from, 0, -9 ) === $to );
		if ( ! $is_restoring_disabled ) {
			Sandbox::assert_php_allowed( $to, 'rename' );
		}

		if ( ! file_exists( $from ) ) {
			throw new Tool_Error( 'Source not found: ' . Path_Guard::relative( $from ) );
		}
		if ( file_exists( $to ) ) {
			throw new Tool_Error( 'The destination already exists: ' . Path_Guard::relative( $to ) );
		}

		$parent = dirname( $to );
		if ( ! is_dir( $parent ) ) {
			if ( ! wp_mkdir_p( $parent ) ) {
				throw new Tool_Error( 'Could not create the destination directory.' );
			}
		}

		if ( ! rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new Tool_Error( 'The rename failed (permissions or cross-device move?).' );
		}

		return array(
			'from'    => Path_Guard::relative( $from ),
			'to'      => Path_Guard::relative( $to ),
			'renamed' => true,
		);
	}

	/**
	 * run_php tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function run_php( array $args ) {
		$code = (string) $args['code'];
		$code = (string) preg_replace( '/^\s*<\?php/', '', $code );

		$errors = array();
		$handler = function ( $errno, $errstr, $errfile, $errline ) use ( &$errors ) {
			$errors[] = self::error_level_name( $errno ) . ': ' . $errstr . ' in ' . $errfile . ':' . $errline;
			return true; // Suppress default PHP error output; report through the tool result.
		};

		$start = microtime( true );
		set_error_handler( $handler ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		ob_start();

		$result = null;
		try {
			$runner = eval( 'return function () { ' . $code . "\n};" ); // phpcs:ignore Squiz.PHP.Eval -- Core feature: execute agent code in the WP runtime.
			if ( is_callable( $runner ) ) {
				$result = $runner();
			}
		} catch ( \Throwable $error ) {
			$errors[] = get_class( $error ) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine();
			$result   = null;
		}

		$output = (string) ob_get_clean();
		restore_error_handler();

		$output_truncated = false;
		if ( strlen( $output ) > 102400 ) {
			$output           = Util::truncate( $output, 102400 );
			$output_truncated = true;
		}

		return array(
			'return_value'     => self::dump_value( $result ),
			'output'           => $output,
			'output_truncated' => $output_truncated,
			'warnings'         => $errors,
			'time_ms'          => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);
	}

	/**
	 * db_query tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the statement is not allowed or fails.
	 */
	public static function db_query( array $args ) {
		global $wpdb;

		$sql       = Sql_Guard::substitute_prefix( (string) $args['sql'], $wpdb->prefix );
		$class     = Sql_Guard::classify( $sql );
		if ( 'read' !== $class['kind'] ) {
			throw new Tool_Error(
				'Only read statements are allowed in db_query.',
				'Detected: ' . $class['label'] . '.',
				'Use db_execute for writes/DDL.'
			);
		}
		if ( Sql_Guard::touches_filesystem( $sql ) ) {
			throw new Tool_Error(
				'Server-side file IO is not allowed in SQL tools (INTO OUTFILE/DUMPFILE, LOAD_FILE(), LOAD DATA).',
				'This guard keeps SQL tools inside the database; file access goes through the filesystem tools, which enforce the configured jail.'
			);
		}

		$note = '';
		if ( 1 === preg_match( '/^\s*SELECT\b/i', $sql ) && ! preg_match( '/\bLIMIT\b/i', $sql ) ) {
			$sql  = rtrim( rtrim( $sql ), ';' ) . ' LIMIT 200';
			$note = 'LIMIT 200 was appended because the SELECT had no LIMIT.';
		}

		$prepared = self::maybe_prepare( $sql, isset( $args['params'] ) ? $args['params'] : array() );
		$rows     = $wpdb->get_results( $prepared, ARRAY_A );

		if ( '' !== (string) $wpdb->last_error ) {
			throw new Tool_Error( 'SQL error: ' . $wpdb->last_error );
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$truncated = false;
		if ( count( $rows ) > 500 ) {
			$rows      = array_slice( $rows, 0, 500 );
			$truncated = true;
		}

		return array(
			'columns'   => ! empty( $rows ) ? array_keys( $rows[0] ) : array(),
			'row_count' => count( $rows ),
			'rows'      => $rows,
			'truncated' => $truncated,
			'note'      => $note,
		);
	}

	/**
	 * db_execute tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the statement is not allowed or fails.
	 */
	public static function db_execute( array $args ) {
		global $wpdb;

		$sql   = Sql_Guard::substitute_prefix( (string) $args['sql'], $wpdb->prefix );
		$class = Sql_Guard::classify( $sql );
		if ( ! in_array( $class['kind'], array( 'write', 'ddl' ), true ) ) {
			throw new Tool_Error(
				'Only write or DDL statements are allowed in db_execute.',
				'Detected: ' . $class['label'] . '.',
				'Use db_query for read statements.'
			);
		}
		if ( Sql_Guard::touches_system_schema( $sql ) ) {
			throw new Tool_Error( 'Refusing to write to MySQL system schemas (information_schema, performance_schema, mysql, sys).' );
		}
		if ( Sql_Guard::touches_filesystem( $sql ) ) {
			throw new Tool_Error(
				'Server-side file IO is not allowed in SQL tools (INTO OUTFILE/DUMPFILE, LOAD_FILE(), LOAD DATA).',
				'This guard keeps SQL tools inside the database; file access goes through the filesystem tools, which enforce the configured jail.'
			);
		}
		if ( 1 === preg_match( '/\b(DROP\s+(DATABASE|SCHEMA)|CREATE\s+(DATABASE|SCHEMA|USER)|ALTER\s+USER|DROP\s+USER|GRANT|REVOKE|SET\s+GLOBAL)\b/i', $sql ) ) {
			throw new Tool_Error( 'Refusing that statement: server-level operations are never executable through Ai Connect.' );
		}

		$prepared = self::maybe_prepare( $sql, isset( $args['params'] ) ? $args['params'] : array() );
		$result   = $wpdb->query( $prepared );

		if ( false === $result ) {
			throw new Tool_Error( 'SQL error: ' . $wpdb->last_error );
		}

		return array(
			'kind'          => $class['kind'],
			'affected_rows' => (int) $result,
			'insert_id'     => $wpdb->insert_id ? (int) $wpdb->insert_id : null,
		);
	}

	/**
	 * db_tables tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the listing fails.
	 */
	public static function db_tables( array $args ) {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new Tool_Error( 'SQL error: ' . $wpdb->last_error );
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$items = array();
		foreach ( array_slice( $rows, 0, 500 ) as $row ) {
			$items[] = array(
				'name'    => isset( $row['Name'] ) ? $row['Name'] : '',
				'engine'  => isset( $row['Engine'] ) ? $row['Engine'] : '',
				'rows'    => isset( $row['Rows'] ) ? (int) $row['Rows'] : null,
				'bytes'   => ( isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0 ) + ( isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0 ),
				'comment' => isset( $row['Comment'] ) ? Util::truncate( (string) $row['Comment'], 120 ) : '',
			);
		}

		return array(
			'count' => count( $items ),
			'items' => $items,
		);
	}

	/**
	 * db_describe tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the table is invalid.
	 */
	public static function db_describe( array $args ) {
		global $wpdb;

		$table = (string) $args['table'];
		if ( ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) {
			throw new Tool_Error( 'Invalid table name: ' . $table );
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			throw new Tool_Error( 'Table not found: ' . $table, '', 'Use db_tables to list existing tables.' );
		}

		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			throw new Tool_Error( 'SQL error: ' . $wpdb->last_error );
		}

		$column_items = array();
		foreach ( (array) $columns as $column ) {
			$column_items[] = array(
				'field'   => isset( $column['Field'] ) ? $column['Field'] : '',
				'type'    => isset( $column['Type'] ) ? $column['Type'] : '',
				'null'    => isset( $column['Null'] ) ? $column['Null'] : '',
				'key'     => isset( $column['Key'] ) ? $column['Key'] : '',
				'default' => isset( $column['Default'] ) ? $column['Default'] : null,
				'extra'   => isset( $column['Extra'] ) ? $column['Extra'] : '',
			);
		}

		$index_map = array();
		foreach ( (array) $indexes as $index ) {
			$name = isset( $index['Key_name'] ) ? (string) $index['Key_name'] : '';
			if ( ! isset( $index_map[ $name ] ) ) {
				$index_map[ $name ] = array(
					'name'    => $name,
					'unique'  => isset( $index['Non_unique'] ) && 0 === (int) $index['Non_unique'],
					'columns' => array(),
				);
			}
			$index_map[ $name ]['columns'][] = isset( $index['Column_name'] ) ? (string) $index['Column_name'] : '';
		}

		return array(
			'table'   => $table,
			'columns' => $column_items,
			'indexes' => array_values( $index_map ),
		);
	}

	/**
	 * run_wp_cli tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When WP-CLI is unavailable.
	 */
	public static function run_wp_cli( array $args ) {
		$cli = self::find_wp_cli();
		if ( empty( $cli['found'] ) ) {
			throw new Tool_Error(
				'WP-CLI is not available on this server.',
				(string) $cli['reason'],
				'Set the wp binary path in Ai Connect -> Settings, or use run_php for equivalent functionality.'
			);
		}

		$command_args = array();
		foreach ( (array) $args['args'] as $item ) {
			$command_args[] = (string) $item;
		}

		$has_path = false;
		foreach ( $command_args as $item ) {
			if ( 0 === strpos( $item, '--path' ) ) {
				$has_path = true;
				break;
			}
		}
		if ( ! $has_path ) {
			$command_args[] = '--path=' . Util::norm_path( ABSPATH );
		}

		$timeout  = max( 5, (int) Settings::get( 'wp_cli_timeout' ) );
		$exec     = self::exec_command( array_merge( array( (string) $cli['path'] ), $command_args ), $timeout );

		return array(
			'command'    => 'wp ' . implode( ' ', $command_args ),
			'exit_code'  => $exec['exit_code'],
			'stdout'     => $exec['stdout'],
			'stderr'     => $exec['stderr'],
			'timed_out'  => $exec['timed_out'],
			'time_ms'    => $exec['time_ms'],
		);
	}

	/**
	 * Detect the WP-CLI binary (cached for 5 minutes). Shared with Diagnostics.
	 *
	 * @return array{found: bool, path: string, version: string, reason: string}
	 */
	public static function find_wp_cli() {
		$cached = get_transient( 'ai_connect_wpcli_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = array(
			'found'   => false,
			'path'    => '',
			'version' => '',
			'reason'  => '',
		);

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( ! function_exists( 'proc_open' ) || in_array( 'proc_open', $disabled, true ) ) {
			$result['reason'] = 'PHP process execution (proc_open) is disabled on this server.';
			set_transient( 'ai_connect_wpcli_status', $result, 300 );
			return $result;
		}

		$binary = trim( (string) Settings::get( 'wp_cli_path' ) );
		if ( '' === $binary ) {
			$binary = 'wp';
		}

		$probe = self::exec_command( array( $binary, '--version' ), 10 );
		if ( 0 === $probe['exit_code'] && false !== stripos( $probe['stdout'], 'WP-CLI' ) ) {
			$version = '';
			if ( preg_match( '/WP-CLI\s+([0-9][0-9A-Za-z\.\-]*)/', $probe['stdout'], $matches ) ) {
				$version = $matches[1];
			}
			$result = array(
				'found'   => true,
				'path'    => $binary,
				'version' => $version,
				'reason'  => '',
			);
		} else {
			$result['reason'] = 'The "wp" binary was not found or failed to run.';
			$stderr           = trim( (string) $probe['stderr'] );
			if ( '' !== $stderr ) {
				$result['reason'] .= ' ' . Util::truncate( $stderr, 200 );
			}
			$result['reason'] .= ' Binary tried: ' . $binary;
		}

		set_transient( 'ai_connect_wpcli_status', $result, 300 );
		return $result;
	}

	/**
	 * Recursively collect directory entries (bounded).
	 *
	 * @param string $dir       Absolute directory.
	 * @param int    $current   Current depth (1-based).
	 * @param int    $max_depth Maximum depth.
	 * @param int    $limit     Entry cap.
	 * @param bool   $hidden    Include dotfiles.
	 * @param array  $items     Collected items (by reference).
	 * @param bool   $truncated Truncation flag (by reference).
	 * @return void
	 */
	private static function walk( $dir, $current, $max_depth, $limit, $hidden, array &$items, &$truncated ) {
		if ( count( $items ) >= $limit ) {
			$truncated = true;
			return;
		}
		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( count( $items ) >= $limit ) {
				$truncated = true;
				return;
			}
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! $hidden && 0 === strpos( $entry, '.' ) ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			$is_dir = is_dir( $path );
			$items[] = array(
				'name'     => $entry,
				'path'     => Path_Guard::relative( $path ),
				'type'     => $is_dir ? 'dir' : ( is_link( $path ) ? 'link' : 'file' ),
				'size'     => $is_dir ? null : (int) filesize( $path ),
				'modified' => gmdate( 'c', (int) filemtime( $path ) ),
			);
			if ( $is_dir && ! is_link( $path ) && $current < $max_depth ) {
				self::walk( $path, $current + 1, $max_depth, $limit, $hidden, $items, $truncated );
			}
		}
	}

	/**
	 * Prepare a statement when params are supplied.
	 *
	 * @param string $sql    Statement.
	 * @param array  $params Positional params.
	 * @return string
	 * @throws Tool_Error On params/placeholders mismatch.
	 */
	private static function maybe_prepare( $sql, $params ) {
		global $wpdb;

		$params = is_array( $params ) ? array_values( $params ) : array();
		if ( empty( $params ) ) {
			return $sql;
		}

		$clean_sql    = Sql_Guard::strip_literals_and_comments( $sql );
		$placeholders = preg_match_all( '/%[sdf]/', $clean_sql );
		if ( $placeholders !== count( $params ) ) {
			throw new Tool_Error(
				'Parameter count mismatch.',
				count( $params ) . ' parameter(s) supplied but the statement has ' . (int) $placeholders . ' placeholder(s) (%s, %d, %f).'
			);
		}

		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL -- Values are passed through prepare().
		if ( null === $prepared || false === $prepared ) {
			throw new Tool_Error( 'Could not prepare the SQL statement.' );
		}
		return $prepared;
	}

	/**
	 * Refuse operations on WordPress core directories.
	 *
	 * @param string $abs Absolute path.
	 * @param string $op  Verb.
	 * @return void
	 * @throws Tool_Error When the path is core.
	 */
	private static function assert_not_core( $abs, $op ) {
		$rel = strtolower( ltrim( Path_Guard::relative( $abs ), '/' ) );
		foreach ( array( 'wp-admin', 'wp-includes' ) as $core ) {
			if ( $rel === $core || 0 === strpos( $rel, $core . '/' ) ) {
				throw new Tool_Error(
					sprintf( 'Refusing to %s WordPress core files (%s).', $op, $core ),
					'Core files are updated by WordPress; modify them only with an explicit update workflow.',
					'Theme and plugin files are not affected by this restriction.'
				);
			}
		}
	}

	/**
	 * Execute a process with a timeout and captured output.
	 *
	 * @param array $argv    Command argv (each element escaped).
	 * @param int   $timeout Seconds.
	 * @return array{stdout: string, stderr: string, exit_code: int, timed_out: bool, time_ms: int}
	 */
	private static function exec_command( array $argv, $timeout ) {
		$start = microtime( true );
		$out   = array(
			'stdout'    => '',
			'stderr'    => '',
			'exit_code' => -1,
			'timed_out' => false,
			'time_ms'   => 0,
		);

		if ( ! function_exists( 'proc_open' ) ) {
			$out['stderr'] = 'proc_open() is not available.';
			return $out;
		}

		// Windows needs the executable quoted for cmd; escapeshellarg handles the argv spelling.
		$command = implode( ' ', array_map( 'escapeshellarg', $argv ) );

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes, Util::norm_path( ABSPATH ) );
		if ( ! is_resource( $process ) ) {
			$out['stderr'] = 'Could not start the process.';
			return $out;
		}

		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$cap  = 256 * 1024;
		$done = false;
		while ( ! $done ) {
			if ( Util::is_windows() ) {
				usleep( 20000 );
				$streams_to_read = array( $pipes[1], $pipes[2] );
			} else {
				$read   = array( $pipes[1], $pipes[2] );
				$write  = null;
				$except = null;

				$ready = @stream_select( $read, $write, $except, 0, 200000 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false === $ready ) {
					break;
				}
				$streams_to_read = $read;
			}

			foreach ( $streams_to_read as $stream ) {
				$chunk = fread( $stream, 8192 );
				if ( false !== $chunk && '' !== $chunk ) {
					if ( $stream === $pipes[1] ) {
						if ( strlen( $out['stdout'] ) < $cap ) {
							$out['stdout'] .= $chunk;
						}
					} elseif ( strlen( $out['stderr'] ) < $cap ) {
						$out['stderr'] .= $chunk;
					}
				}
			}

			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				// Drain whatever is still buffered.
				$rest = stream_get_contents( $pipes[1] );
				if ( false !== $rest && '' !== $rest && strlen( $out['stdout'] ) < $cap ) {
					$out['stdout'] .= $rest;
				}
				$rest = stream_get_contents( $pipes[2] );
				if ( false !== $rest && '' !== $rest && strlen( $out['stderr'] ) < $cap ) {
					$out['stderr'] .= $rest;
				}
				$out['exit_code'] = (int) $status['exitcode'];
				$done             = true;
			}

			if ( ! $done && ( microtime( true ) - $start ) > $timeout ) {
				proc_terminate( $process );
				$out['timed_out'] = true;
				$out['exit_code'] = -1;
				$done             = true;
			}
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( ! $out['timed_out'] ) {
			$close = proc_close( $process );
			if ( -1 === $out['exit_code'] && -1 !== $close ) {
				$out['exit_code'] = (int) $close;
			}
		} else {
			proc_close( $process );
		}

		$out['time_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
		return $out;
	}

	/**
	 * Human name for a PHP error level.
	 *
	 * @param int $errno Level.
	 * @return string
	 */
	private static function error_level_name( $errno ) {
		$names = array(
			E_ERROR             => 'Fatal error',
			E_WARNING           => 'Warning',
			E_PARSE             => 'Parse error',
			E_NOTICE            => 'Notice',
			E_CORE_ERROR        => 'Core error',
			E_CORE_WARNING      => 'Core warning',
			E_COMPILE_ERROR     => 'Compile error',
			E_COMPILE_WARNING   => 'Compile warning',
			E_USER_ERROR        => 'User error',
			E_USER_WARNING      => 'User warning',
			E_USER_NOTICE       => 'User notice',
			E_RECOVERABLE_ERROR => 'Recoverable error',
			E_DEPRECATED        => 'Deprecated',
			E_USER_DEPRECATED   => 'User deprecated',
		);
		return isset( $names[ $errno ] ) ? $names[ $errno ] : ( 'PHP error ' . $errno );
	}

	/**
	 * Convert a PHP value into something safely JSON-encodable for the tool result.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Recursion depth.
	 * @return mixed
	 */
	private static function dump_value( $value, $depth = 0 ) {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		if ( $depth >= 6 ) {
			return '[depth limit]';
		}
		if ( is_array( $value ) ) {
			$out   = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= 500 ) {
					$out['__ai_connect'] = 'Array truncated at 500 entries.';
					break;
				}
				$out[ $key ] = self::dump_value( $item, $depth + 1 );
				$count++;
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			if ( $value instanceof \JsonSerializable ) {
				return self::dump_value( $value->jsonSerialize(), $depth + 1 );
			}
			return array(
				'__class'    => get_class( $value ),
				'__toString' => method_exists( $value, '__toString' ) ? Util::truncate( (string) $value, 500 ) : null,
				'__properties' => self::dump_value( get_object_vars( $value ), $depth + 1 ),
			);
		}
		return '[' . gettype( $value ) . ']';
	}
}
