<?php
/**
 * Tool registry: definitions, JSON-schema subset validation, permission gating.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds every tool the MCP server exposes.
 */
final class Registry {

	/**
	 * Registered tools by name.
	 *
	 * @var array
	 */
	private static $tools = array();

	/**
	 * Authenticated token row for the tool call in flight. Set by the invoker
	 * (MCP request handler or the jobs worker) right before Registry::call().
	 *
	 * @var array|null
	 */
	private static $context_token = null;

	/**
	 * Record the token row that the current tool call runs as.
	 *
	 * @param array|null $token_row Token row.
	 * @return void
	 */
	public static function set_context_token( $token_row ) {
		self::$context_token = is_array( $token_row ) ? $token_row : null;
	}

	/**
	 * Token row for the tool call in flight (null outside a call).
	 *
	 * @return array|null
	 */
	public static function context_token() {
		return self::$context_token;
	}

	/**
	 * Register a tool.
	 *
	 * @param string $name Tool name ([a-z0-9_]).
	 * @param array  $def  {
	 *     @type string   $title       Human title.
	 *     @type string   $description Agent-facing description.
	 *     @type string   $risk        read|write|dangerous.
	 *     @type string   $group       Group key.
	 *     @type array    $schema      JSON-schema subset.
	 *     @type callable $callback    Executes with validated args, returns array.
	 * }
	 * @return void
	 */
	public static function add( $name, array $def ) {
		$name = strtolower( (string) $name );
		if ( ! preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return;
		}
		$risk = isset( $def['risk'] ) ? (string) $def['risk'] : 'read';
		if ( ! in_array( $risk, array( 'read', 'write', 'dangerous' ), true ) ) {
			$risk = 'read';
		}
		self::$tools[ $name ] = array(
			'name'        => $name,
			'title'       => isset( $def['title'] ) ? (string) $def['title'] : $name,
			'description' => isset( $def['description'] ) ? (string) $def['description'] : '',
			'risk'        => $risk,
			'group'       => isset( $def['group'] ) ? (string) $def['group'] : 'general',
			'schema'      => isset( $def['schema'] ) && is_array( $def['schema'] ) ? $def['schema'] : array(
				'type'       => 'object',
				'properties' => array(),
			),
			'callback'    => isset( $def['callback'] ) ? $def['callback'] : null,
		);
	}

	/**
	 * All registered tools.
	 *
	 * @return array
	 */
	public static function all() {
		return self::$tools;
	}

	/**
	 * Find one tool.
	 *
	 * @param string $name Tool name.
	 * @return array|null
	 */
	public static function find( $name ) {
		$name = strtolower( (string) $name );
		return isset( self::$tools[ $name ] ) ? self::$tools[ $name ] : null;
	}

	/**
	 * Whether the administrator disabled a tool.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public static function is_disabled( $name ) {
		return in_array( strtolower( (string) $name ), Settings::disabled_tools(), true );
	}

	/**
	 * Tools enabled right now (admin toggles applied).
	 *
	 * @return array
	 */
	public static function enabled() {
		$out = array();
		foreach ( self::$tools as $name => $def ) {
			if ( ! self::is_disabled( $name ) ) {
				$out[ $name ] = $def;
			}
		}
		return $out;
	}

	/**
	 * Permission gate for a tool call. Returns a refusal reason or null.
	 *
	 * @param array $def       Tool definition.
	 * @param array $token_row Authenticated token row.
	 * @return string|null
	 */
	public static function gate( array $def, array $token_row ) {
		if ( self::is_disabled( $def['name'] ) ) {
			return 'This tool is disabled by the site administrator (Ai Connect -> Tools).';
		}

		$risk      = $def['risk'];
		$read_only = Settings::is_read_only() || ! empty( $token_row['read_only'] );

		if ( 'read' !== $risk && $read_only ) {
			return 'Read-only mode is active, so only read tools can run. An administrator can turn it off in Ai Connect -> Security, or connect with a token that is not read-only.';
		}
		if ( 'dangerous' === $risk && ! Settings::dangerous_allowed() ) {
			return 'Dangerous tools (PHP execution, SQL writes, WP-CLI) are turned off in Ai Connect -> Security.';
		}
		return null;
	}

	/**
	 * Validate arguments against the tool schema subset. Unknown keys are dropped.
	 *
	 * @param array $def  Tool definition.
	 * @param mixed $args Raw arguments.
	 * @return array{0: array, 1: array} [clean args, error strings]
	 */
	public static function validate( array $def, $args ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		$schema = $def['schema'];
		if ( ! is_array( $schema ) || 'object' !== ( isset( $schema['type'] ) ? $schema['type'] : 'object' ) ) {
			$schema = array(
				'type'       => 'object',
				'properties' => array(),
			);
		}
		$result = self::validate_object( $schema, $args, '' );
		return array( $result[1], $result[2] );
	}

	/**
	 * Validate one object value.
	 *
	 * @param array  $schema Schema.
	 * @param array  $value  Value.
	 * @param string $path   Display path.
	 * @return array{0: bool, 1: array, 2: array} [ok, clean, errors]
	 */
	private static function validate_object( array $schema, array $value, $path ) {
		$props     = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required  = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$clean     = array();
		$errors    = array();

		foreach ( $required as $key ) {
			$present = array_key_exists( $key, $value ) && null !== $value[ $key ];
			$empty   = false;
			if ( $present && is_string( $value[ $key ] ) && '' === trim( $value[ $key ] ) ) {
				$empty = true;
			}
			if ( ! $present || $empty ) {
				$label    = '' === $path ? $key : $path . '.' . $key;
				$errors[] = sprintf( 'Missing required parameter "%s".', $label );
			}
		}

		foreach ( $props as $key => $spec ) {
			$label = '' === $path ? $key : $path . '.' . $key;
			if ( ! array_key_exists( $key, $value ) || null === $value[ $key ] ) {
				if ( is_array( $spec ) && array_key_exists( 'default', $spec ) ) {
					$clean[ $key ] = $spec['default'];
				}
				continue;
			}
			$result = self::validate_value( $spec, $value[ $key ], $label );
			if ( ! $result[0] ) {
				$errors[] = $result[1];
				continue;
			}
			$clean[ $key ] = $result[1];
		}

		return array( empty( $errors ), $clean, $errors );
	}

	/**
	 * Validate (and coerce) one value.
	 *
	 * @param mixed  $spec  Schema fragment.
	 * @param mixed  $value Raw value.
	 * @param string $label Display label.
	 * @return array{0: bool, 1: mixed} [ok, clean value or error string]
	 */
	private static function validate_value( $spec, $value, $label ) {
		if ( ! is_array( $spec ) ) {
			return array( true, $value );
		}
		if ( ! isset( $spec['type'] ) ) {
			return array( true, $value );
		}
		$type = (string) $spec['type'];

		switch ( $type ) {
			case 'string':
				if ( is_array( $value ) || is_object( $value ) ) {
					return array( false, sprintf( '"%s" must be a string.', $label ) );
				}
				$value = (string) $value;
				if ( isset( $spec['minLength'] ) && strlen( $value ) < (int) $spec['minLength'] ) {
					return array( false, sprintf( '"%s" must be at least %d characters.', $label, (int) $spec['minLength'] ) );
				}
				if ( isset( $spec['maxLength'] ) && strlen( $value ) > (int) $spec['maxLength'] ) {
					return array( false, sprintf( '"%s" exceeds the maximum length of %d characters.', $label, (int) $spec['maxLength'] ) );
				}
				if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
					return array( false, sprintf( '"%s" must be one of: %s.', $label, implode( ', ', $spec['enum'] ) ) );
				}
				return array( true, $value );

			case 'integer':
				if ( is_bool( $value ) || ! is_numeric( $value ) ) {
					return array( false, sprintf( '"%s" must be an integer.', $label ) );
				}
				$value = (int) $value;
				if ( isset( $spec['minimum'] ) && $value < (int) $spec['minimum'] ) {
					return array( false, sprintf( '"%s" must be >= %d.', $label, (int) $spec['minimum'] ) );
				}
				if ( isset( $spec['maximum'] ) && $value > (int) $spec['maximum'] ) {
					return array( false, sprintf( '"%s" must be <= %d.', $label, (int) $spec['maximum'] ) );
				}
				return array( true, $value );

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return array( false, sprintf( '"%s" must be a number.', $label ) );
				}
				$value = (float) $value;
				if ( isset( $spec['minimum'] ) && $value < (float) $spec['minimum'] ) {
					return array( false, sprintf( '"%s" must be >= %s.', $label, (string) $spec['minimum'] ) );
				}
				if ( isset( $spec['maximum'] ) && $value > (float) $spec['maximum'] ) {
					return array( false, sprintf( '"%s" must be <= %s.', $label, (string) $spec['maximum'] ) );
				}
				return array( true, $value );

			case 'boolean':
				return array( true, Util::to_bool( $value ) );

			case 'array':
				if ( ! is_array( $value ) ) {
					return array( false, sprintf( '"%s" must be an array.', $label ) );
				}
				$value = array_values( $value );
				if ( isset( $spec['maxItems'] ) && count( $value ) > (int) $spec['maxItems'] ) {
					return array( false, sprintf( '"%s" accepts at most %d items.', $label, (int) $spec['maxItems'] ) );
				}
				if ( isset( $spec['items'] ) && is_array( $spec['items'] ) ) {
					$clean_items = array();
					foreach ( $value as $index => $item ) {
						$result = self::validate_value( $spec['items'], $item, $label . '[' . $index . ']' );
						if ( ! $result[0] ) {
							return $result;
						}
						$clean_items[] = $result[1];
					}
					return array( true, $clean_items );
				}
				return array( true, $value );

			case 'object':
				if ( ! is_array( $value ) ) {
					return array( false, sprintf( '"%s" must be an object.', $label ) );
				}
				if ( ! isset( $spec['properties'] ) ) {
					// Free-form object (post meta pairs, job arguments, ability
					// payloads): pass values through unchanged.
					return array( true, $value );
				}
				$result = self::validate_object( $spec, $value, $label );
				if ( ! $result[0] ) {
					return array( false, implode( ' ', $result[2] ) );
				}
				return array( true, $result[1] );
		}

		return array( true, $value );
	}

	/**
	 * MCP annotations for a tool.
	 *
	 * @param array $def Tool definition.
	 * @return array
	 */
	public static function annotations( array $def ) {
		switch ( $def['risk'] ) {
			case 'read':
				return array(
					'readOnlyHint'   => true,
					'destructiveHint' => false,
					'idempotentHint' => true,
				);
			case 'dangerous':
				return array(
					'readOnlyHint'   => false,
					'destructiveHint' => true,
					'idempotentHint' => false,
				);
			default:
				return array(
					'readOnlyHint'   => false,
					'destructiveHint' => false,
					'idempotentHint' => false,
				);
		}
	}

	/**
	 * Protocol shape for tools/list.
	 *
	 * @param array $def Tool definition.
	 * @return array
	 */
	public static function mcp_shape( array $def ) {
		return array(
			'name'        => $def['name'],
			'title'       => $def['title'],
			'description' => $def['description'],
			'inputSchema' => $def['schema'],
			'annotations' => self::annotations( $def ),
		);
	}

	/**
	 * Execute a validated tool call.
	 *
	 * @param array $def  Tool definition.
	 * @param array $args Clean arguments.
	 * @return array Result payload.
	 * @throws Tool_Error On tool failure.
	 */
	public static function call( array $def, array $args ) {
		if ( ! is_callable( $def['callback'] ) ) {
			throw new Tool_Error( 'This tool is not executable in the current context.' );
		}
		$result = call_user_func( $def['callback'], $args );
		if ( ! is_array( $result ) ) {
			$result = array( 'result' => $result );
		}
		return $result;
	}
}
