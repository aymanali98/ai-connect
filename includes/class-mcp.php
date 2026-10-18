<?php
/**
 * MCP (Model Context Protocol) server over HTTP: JSON-RPC 2.0 dispatch for
 * initialize, ping, tools/list, tools/call and notifications.
 *
 * Transport: stateless Streamable HTTP (single JSON responses). Authorization:
 * bearer connection tokens; identity = the WordPress user who created the token.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-RPC handler for the /mcp endpoint.
 */
final class Mcp {

	/**
	 * Protocol revisions this server can speak, newest first.
	 */
	const SUPPORTED_PROTOCOLS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * Serve one HTTP request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function serve( $request ) {
		$method = strtoupper( (string) $request->get_method() );
		if ( 'POST' !== $method ) {
			$response = new \WP_REST_Response( null, 405 );
			$response->header( 'Allow', 'POST' );
			return $response;
		}

		// --- Master switch --------------------------------------------------
		// Answered before authentication: a switched-off site reveals nothing.
		if ( ! Settings::enabled() ) {
			return new \WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32003,
						'message' => 'Ai Connect is turned off for this site. An administrator can re-enable it on the Connect AI page of Ai Connect.',
					),
				),
				403
			);
		}

		// --- Authentication -------------------------------------------------
		if ( self::auth_failures() >= self::AUTH_FAIL_LIMIT ) {
			$response = new \WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32002,
						'message' => 'Too many failed authentication attempts from this address. Try again later.',
					),
				),
				429
			);
			$response->header( 'Retry-After', (string) self::AUTH_FAIL_WINDOW );
			return $response;
		}

		$header = (string) $request->get_header( 'authorization' );
		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $matches ) ) {
			self::note_auth_failure();
			return self::unauthorized( 'Missing bearer token. Send: Authorization: Bearer <token>' );
		}
		$token = Tokens::authenticate( $matches[1] );
		if ( null === $token ) {
			self::note_auth_failure();
			return self::unauthorized( 'This token is invalid, revoked, or expired. Create a new one in Ai Connect -> Connect AI.' );
		}

		wp_set_current_user( $token['user_id'] );
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! user_can( $user, AI_CONNECT_CAP ) ) {
			return self::unauthorized( 'The WordPress user behind this token no longer has administrator access. Create a new token in Ai Connect -> Connect AI.' );
		}

		// --- Parse the JSON-RPC envelope -------------------------------------
		$raw      = (string) $request->get_body();
		$decoded  = null;
		$is_array = null;

		if ( '' === trim( $raw ) ) {
			return self::rpc_error( null, -32700, 'Empty request body.' );
		}
		$decoded  = json_decode( $raw, true );
		$is_array = is_array( $decoded );

		if ( ! $is_array && JSON_ERROR_NONE !== json_last_error() ) {
			return self::rpc_error( null, -32700, 'Parse error: the body is not valid JSON.' );
		}
		if ( ! $is_array ) {
			return self::rpc_error( null, -32600, 'Invalid request: a JSON-RPC object is required.' );
		}
		if ( self::is_list( $decoded ) ) {
			return self::rpc_error( null, -32600, 'Batch requests are not supported.' );
		}

		$id     = array_key_exists( 'id', $decoded ) ? $decoded['id'] : null;
		$method = isset( $decoded['method'] ) ? $decoded['method'] : null;
		if ( ! is_string( $method ) || '' === $method ) {
			return self::rpc_error( self::public_id( $id ), -32600, 'Invalid request: "method" is required.' );
		}
		if ( null !== $id && ! is_string( $id ) && ! is_int( $id ) ) {
			return self::rpc_error( null, -32600, 'Invalid request: "id" must be a string or number.' );
		}

		$params = isset( $decoded['params'] ) && is_array( $decoded['params'] ) ? $decoded['params'] : array();

		// --- Notifications ----------------------------------------------------
		// JSON-RPC: messages without an id are notifications and get no reply.
		// MCP additionally names them "notifications/..."; "initialized" is the
		// legacy 2024-11-05 spelling. All are accepted with a bare 202.
		$is_notification = ( null === $id ) || ( 0 === strpos( $method, 'notifications/' ) ) || ( 'initialized' === $method );
		if ( $is_notification ) {
			Log::add( self::notification_label( $method ), 'read', 'ok', 'notification', 0, $token['user_id'], $token['token_prefix'] );
			return new \WP_REST_Response( null, 202 );
		}

		// --- Methods -----------------------------------------------------------
		try {
			switch ( $method ) {
				case 'initialize':
					return self::rpc( $id, self::initialize_result( $params ) );

				case 'ping':
					return self::rpc( $id, new \stdClass() );

				case 'tools/list':
					$tools = array();
					foreach ( Registry::enabled() as $def ) {
						$tools[] = Registry::mcp_shape( $def );
					}
					return self::rpc( $id, array( 'tools' => $tools ) );

				case 'tools/call':
					return self::handle_tools_call( $id, $params, $token );

				case 'resources/list':
					return self::rpc( $id, array( 'resources' => array() ) );

				case 'prompts/list':
					return self::rpc( $id, array( 'prompts' => array() ) );

				default:
					return self::rpc_error( $id, -32601, 'Method not found: ' . $method );
			}
		} catch ( \Throwable $error ) {
			Log::add( $method, 'read', 'error', $error->getMessage(), 0, $token['user_id'], $token['token_prefix'] );
			return self::rpc_error( $id, -32603, 'Internal error: ' . $error->getMessage() );
		}
	}

	/**
	 * Execute tools/call.
	 *
	 * @param mixed $id    Request id.
	 * @param array $params Params.
	 * @param array $token Token row.
	 * @return \WP_REST_Response
	 */
	private static function handle_tools_call( $id, array $params, array $token ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		if ( '' === $name ) {
			return self::rpc_error( $id, -32602, 'Invalid params: "name" is required.' );
		}
		$def = Registry::find( $name );
		if ( null === $def ) {
			return self::rpc_error( $id, -32602, 'Unknown tool: ' . $name . '. Call tools/list for the current catalog.' );
		}

		$args  = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$start = microtime( true );

		// Permission gate.
		$reason = Registry::gate( $def, $token );
		if ( null !== $reason ) {
			Log::add( $name, $def['risk'], 'blocked', $reason, self::ms_since( $start ), $token['user_id'], $token['token_prefix'] );
			return self::rpc( $id, self::tool_result_payload( array( 'ok' => false, 'error' => array( 'message' => $reason ) ), true ) );
		}

		// Schema validation.
		$validated = Registry::validate( $def, $args );
		$clean     = $validated[0];
		$errors    = $validated[1];
		if ( ! empty( $errors ) ) {
			$detail = implode( ' ', $errors );
			Log::add( $name, $def['risk'], 'error', $detail, self::ms_since( $start ), $token['user_id'], $token['token_prefix'] );
			return self::rpc( $id, self::tool_result_payload( array( 'ok' => false, 'error' => array( 'message' => 'Invalid parameters.', 'detail' => $detail ) ), true ) );
		}

		// Execute.
		try {
			Registry::set_context_token( $token );
			$data = Registry::call( $def, $clean );
			if ( ! array_key_exists( 'ok', $data ) ) {
				$data = array_merge( array( 'ok' => true ), $data );
			}
			Log::add( $name, $def['risk'], 'ok', self::summarize( $clean ), self::ms_since( $start ), $token['user_id'], $token['token_prefix'] );
			return self::rpc( $id, self::tool_result_payload( $data, false ) );
		} catch ( Tool_Error $error ) {
			Log::add( $name, $def['risk'], 'error', $error->getMessage(), self::ms_since( $start ), $token['user_id'], $token['token_prefix'] );
			return self::rpc( $id, self::tool_result_payload( array( 'ok' => false, 'error' => $error->to_array() ), true ) );
		} catch ( \Throwable $error ) {
			$message = 'The tool failed: ' . $error->getMessage();
			Log::add( $name, $def['risk'], 'error', $message, self::ms_since( $start ), $token['user_id'], $token['token_prefix'] );
			return self::rpc( $id, self::tool_result_payload( array( 'ok' => false, 'error' => array( 'message' => $message ) ), true ) );
		}
	}

	/**
	 * Wrap a payload as an MCP tools/call result.
	 *
	 * @param array $payload Data (serialized as pretty JSON text).
	 * @param bool  $is_error Error flag.
	 * @return array
	 */
	private static function tool_result_payload( array $payload, $is_error ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => Util::json( $payload ),
				),
			),
			'isError' => (bool) $is_error,
		);
	}

	/**
	 * initialize result.
	 *
	 * @param array $params Client params.
	 * @return array
	 */
	private static function initialize_result( array $params ) {
		$client_protocol = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$negotiated      = in_array( $client_protocol, self::SUPPORTED_PROTOCOLS, true ) ? $client_protocol : self::SUPPORTED_PROTOCOLS[0];

		return array(
			'protocolVersion' => $negotiated,
			'capabilities'    => array(
				'tools' => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'ai-connect',
				'title'   => 'Ai Connect — WordPress Agent Toolkit',
				'version' => AI_CONNECT_VERSION,
			),
			'instructions'    => self::instructions(),
		);
	}

	/**
	 * Server instructions shown to the agent after initialize.
	 *
	 * @return string
	 */
	public static function instructions() {
		$site_name = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
		$site_url  = function_exists( 'home_url' ) ? home_url() : '';
		$wp        = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';

		$tools    = Registry::enabled();
		$read     = 0;
		$write    = 0;
		$danger   = 0;
		foreach ( $tools as $def ) {
			if ( 'read' === $def['risk'] ) {
				$read++;
			} elseif ( 'write' === $def['risk'] ) {
				$write++;
			} else {
				$danger++;
			}
		}

		$lines   = array();
		$lines[] = sprintf( 'You are connected to the live WordPress site "%s" (%s), WordPress %s.', '' !== $site_name ? $site_name : $site_url, $site_url, $wp );
		$lines[] = sprintf( '%d tools are enabled: %d read, %d write, %d dangerous.', count( $tools ), $read, $write, $danger );
		$lines[] = sprintf( 'Read-only mode is %s. Dangerous tools are %s.', Settings::is_read_only() ? 'ON — only read tools will run' : 'off', Settings::dangerous_allowed() ? 'enabled' : 'turned off' );
		$lines[] = '';
		$lines[] = 'Working rules:';
		$lines[] = '- Start with agent_context or site_info to learn the environment before making changes.';
		$lines[] = '- Prefer read tools first (list_directory, read_file, list_plugins...) before writing anything.';
		$lines[] = '- New and edited PHP files must live in wp-content/sitewright-sandbox/ (crash-safe loading). Never write executable PHP elsewhere.';
		$lines[] = '- run_php executes in the WordPress runtime. Prefer return $value; for inspection. Do not call exit() or create loops.';
		$lines[] = '- run_wp_cli runs real WP-CLI commands; long commands should be avoided (30s+ timeouts).';
		$lines[] = '- design_get returns the site design system. Follow it when generating markup, CSS, or content.';
		$lines[] = '- Destructive tools (delete_file, db_execute, run_php...) are for explicit user requests. When unsure, ask the user.';
		$lines[] = '- This is a development tool for development/staging sites. The site owner reviews your actions in Ai Connect -> Security (activity log).';

		if ( Settings::is_read_only() ) {
			$lines[] = '- READ-ONLY MODE: you can inspect but not change the site. Explain what you would change instead of attempting it.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Failed authentications allowed per client address per window.
	 */
	const AUTH_FAIL_LIMIT = 100;

	/**
	 * Auth-failure throttle window, seconds.
	 */
	const AUTH_FAIL_WINDOW = 300;

	/**
	 * Client address used for the auth-failure throttle.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' !== $ip ? $ip : 'unknown';
	}

	/**
	 * Transient key for the current client's failure counter.
	 *
	 * @return string
	 */
	private static function auth_fail_key() {
		return 'ai_connect_auth_fail_' . md5( self::client_ip() );
	}

	/**
	 * Current failure count for this client.
	 *
	 * @return int
	 */
	private static function auth_failures() {
		if ( ! function_exists( 'get_transient' ) ) {
			return 0;
		}
		return (int) get_transient( self::auth_fail_key() );
	}

	/**
	 * Record one failed authentication attempt.
	 *
	 * @return void
	 */
	private static function note_auth_failure() {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$key   = self::auth_fail_key();
		$count = (int) get_transient( $key );
		if ( $count < 1000 ) {
			$count++;
		}
		set_transient( $key, $count, self::AUTH_FAIL_WINDOW );
	}

	/**
	 * 401 response with a JSON-RPC shaped error.
	 *
	 * @param string $message Message.
	 * @return \WP_REST_Response
	 */
	private static function unauthorized( $message ) {
		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32001,
					'message' => $message,
				),
			),
			401
		);
		$response->header( 'WWW-Authenticate', 'Bearer realm="ai-connect"' );
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}

	/**
	 * JSON-RPC success response.
	 *
	 * @param mixed $id     Id.
	 * @param mixed $result Result.
	 * @return \WP_REST_Response
	 */
	private static function rpc( $id, $result ) {
		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}

	/**
	 * JSON-RPC error response.
	 *
	 * @param mixed  $id      Id.
	 * @param int    $code    Error code.
	 * @param string $message Message.
	 * @return \WP_REST_Response
	 */
	private static function rpc_error( $id, $code, $message ) {
		$response = new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => (int) $code,
					'message' => $message,
				),
			),
			200
		);
		$response->header( 'Content-Type', 'application/json; charset=UTF-8' );
		return $response;
	}

	/**
	 * Sanitize an id for public output.
	 *
	 * @param mixed $id Raw id.
	 * @return mixed
	 */
	private static function public_id( $id ) {
		if ( null === $id || is_int( $id ) || is_string( $id ) ) {
			return $id;
		}
		return null;
	}

	/**
	 * Whether an array is a JSON list (sequential keys).
	 *
	 * @param array $value Value.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$index = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}
			$index++;
		}
		return true;
	}

	/**
	 * Short label for notification logging.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	private static function notification_label( $method ) {
		return Util::truncate( (string) $method, 64 );
	}

	/**
	 * Elapsed milliseconds since a microtime(true) mark.
	 *
	 * @param float $start Start mark.
	 * @return int
	 */
	private static function ms_since( $start ) {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}

	/**
	 * One-line argument summary for the activity log (never content bodies).
	 *
	 * @param array $args Clean args.
	 * @return string
	 */
	private static function summarize( array $args ) {
		$preferred = array( 'path', 'table', 'post_id', 'id', 'slug', 'name', 'plugin', 'theme', 'option', 'query' );
		foreach ( $preferred as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				return Util::truncate( $key . '=' . (string) $args[ $key ], 200 );
			}
		}
		if ( isset( $args['args'] ) && is_array( $args['args'] ) ) {
			return Util::truncate( 'wp-cli: ' . implode( ' ', array_map( 'strval', array_slice( $args['args'], 0, 4 ) ) ), 200 );
		}
		return '';
	}
}
