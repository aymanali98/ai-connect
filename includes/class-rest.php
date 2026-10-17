<?php
/**
 * REST routes: the public /mcp endpoint and admin-only helper endpoints
 * (connection test, diagnostics) used by the admin UI over AJAX.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route registration and admin endpoint handlers.
 */
final class Rest {

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public static function register() {
		// Serve /mcp before WordPress validates the request body: malformed JSON
		// must produce a JSON-RPC -32700 error, not a rest_invalid_json 400.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'handle_mcp_early' ), 5, 3 );

		foreach ( self::namespaces() as $namespace ) {
			register_rest_route(
				$namespace,
				'/mcp',
				array(
					array(
						'methods'             => 'POST',
						'callback'            => array( Mcp::class, 'serve' ),
						'permission_callback' => '__return_true', // Auth happens inside (bearer token).
					),
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'method_not_allowed' ),
						'permission_callback' => '__return_true',
					),
					array(
						'methods'             => 'DELETE',
						'callback'            => array( __CLASS__, 'method_not_allowed' ),
						'permission_callback' => '__return_true',
					),
				)
			);

			register_rest_route(
				$namespace,
				'/admin/test-connection',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'test_connection' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
				)
			);

			register_rest_route(
				$namespace,
				'/admin/diagnostics',
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'diagnostics' ),
					'permission_callback' => array( __CLASS__, 'admin_permission' ),
				)
			);
		}
	}

	/**
	 * REST namespaces served: the canonical Ai Connect namespace plus the
	 * legacy sitewright namespace so existing client configuration URLs keep
	 * working after the rebrand.
	 *
	 * @return array
	 */
	public static function namespaces() {
		$namespaces = array( AI_CONNECT_REST_NS );
		$legacy     = defined( 'SITEWRIGHT_REST_NS' ) ? SITEWRIGHT_REST_NS : 'sitewright/v1';
		if ( ! in_array( $legacy, $namespaces, true ) ) {
			$namespaces[] = $legacy;
		}
		return $namespaces;
	}

	/**
	 * Whether a REST route is one of our MCP endpoints.
	 *
	 * @param string $route Route.
	 * @return bool
	 */
	public static function is_mcp_route( $route ) {
		$route = trim( (string) $route, '/' );
		foreach ( self::namespaces() as $namespace ) {
			if ( $namespace . '/mcp' === $route ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hijack /mcp requests before the REST server parses or validates them.
	 *
	 * This keeps the MCP endpoint's error contract intact: invalid JSON gets a
	 * JSON-RPC -32700 response instead of WordPress's rest_invalid_json 400.
	 *
	 * @param mixed            $result  Pre-calculated result (null by default).
	 * @param \WP_REST_Server  $server  Server (unused).
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function handle_mcp_early( $result, $server, $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) ) {
			return $result;
		}
		if ( ! self::is_mcp_route( $request->get_route() ) ) {
			return $result;
		}
		return Mcp::serve( $request );
	}

	/**
	 * Admin capability check for helper endpoints.
	 *
	 * @return bool
	 */
	public static function admin_permission() {
		return current_user_can( AI_CONNECT_CAP );
	}

	/**
	 * 405 for non-POST traffic on /mcp.
	 *
	 * @return \WP_REST_Response
	 */
	public static function method_not_allowed() {
		$response = new \WP_REST_Response( null, 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * Real end-to-end connection test: the server calls its own /mcp endpoint
	 * over HTTP with the given token, runs initialize + tools/list, and reports
	 * what a client would see. Runs as a loopback request inside WordPress.
	 *
	 * @param \WP_REST_Request $request Request with 'token'.
	 * @return \WP_REST_Response
	 */
	public static function test_connection( $request ) {
		if ( ! Settings::enabled() ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'Ai Connect is turned off for this site. Re-enable it on the Connect AI page, then run the test again.' ), 200 );
		}
		$token = trim( (string) $request->get_param( 'token' ) );
		if ( '' === $token ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'No token provided.' ), 400 );
		}
		if ( ! preg_match( '/^swt_[0-9a-f]{8}_[0-9a-f]{48}$/', $token ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => 'That does not look like an Ai Connect token (expected swt_xxxxxxxx_...).' ), 400 );
		}

		$url = rest_url( AI_CONNECT_REST_NS . '/mcp' );

		$init = self::mcp_roundtrip(
			$url,
			$token,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => Mcp::SUPPORTED_PROTOCOLS[0],
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'ai-connect-connection-test',
						'version' => AI_CONNECT_VERSION,
					),
				),
			)
		);

		if ( ! $init['ok'] ) {
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $init['error'] ), 200 );
		}
		$init_data = $init['data'];
		if ( isset( $init_data['error'] ) ) {
			$message = isset( $init_data['error']['message'] ) ? $init_data['error']['message'] : 'initialize failed.';
			$status  = 401 === $init['status'] ? 'The token was rejected (revoked, expired, or invalid). Create a new token and check that the site can reach itself.' : $message;
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $status ), 200 );
		}

		$list = self::mcp_roundtrip(
			$url,
			$token,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => new \stdClass(),
			)
		);

		$tool_count = 0;
		if ( $list['ok'] && isset( $list['data']['result']['tools'] ) && is_array( $list['data']['result']['tools'] ) ) {
			$tool_count = count( $list['data']['result']['tools'] );
		}

		$server = isset( $init_data['result']['serverInfo'] ) ? $init_data['result']['serverInfo'] : array();
		$proto  = isset( $init_data['result']['protocolVersion'] ) ? $init_data['result']['protocolVersion'] : '';

		return new \WP_REST_Response(
			array(
				'ok'               => true,
				'protocol_version' => $proto,
				'server'           => array(
					'name'    => isset( $server['name'] ) ? $server['name'] : 'ai-connect',
					'version' => isset( $server['version'] ) ? $server['version'] : '',
				),
				'tools'            => $tool_count,
				'latency_ms'       => $init['ms'] + $list['ms'],
				'endpoint'         => $url,
			),
			200
		);
	}

	/**
	 * One loopback JSON-RPC round trip to the site's own MCP endpoint.
	 *
	 * @param string $url   Endpoint.
	 * @param string $token Bearer token.
	 * @param array  $body  JSON-RPC payload.
	 * @return array{ok: bool, status?: int, data?: array, ms: int, error?: string}
	 */
	private static function mcp_roundtrip( $url, $token, array $body ) {
		$start    = microtime( true );
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json, text/event-stream',
				),
				'body'    => Util::json_compact( $body ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'ms'    => $ms,
				'error' => 'The site could not reach its own REST endpoint (' . $response->get_error_message() . '). This usually means a firewall, maintenance plugin, or DNS issue blocks loopback requests.',
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		return array(
			'ok'     => true,
			'status' => $status,
			'data'   => is_array( $data ) ? $data : array(),
			'ms'     => $ms,
		);
	}

	/**
	 * Run diagnostics (admin UI).
	 *
	 * @return \WP_REST_Response
	 */
	public static function diagnostics() {
		return new \WP_REST_Response(
			array(
				'ok'     => true,
				'checks' => Diagnostics::run(),
			),
			200
		);
	}
}
