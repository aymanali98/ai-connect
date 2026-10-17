<?php
/**
 * Tool execution error. Tools throw this for actionable, agent-readable failures.
 * The MCP layer converts it into an isError result (not a protocol error).
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A failure the agent can act on (bad path, denied SQL, missing plugin, ...).
 */
class Tool_Error extends \Exception {

	/**
	 * Additional actionable context.
	 *
	 * @var string
	 */
	public $detail;

	/**
	 * Optional hint with a concrete next step.
	 *
	 * @var string
	 */
	public $hint;

	/**
	 * Constructor.
	 *
	 * @param string $message Human/agent readable message.
	 * @param string $detail  Extra context.
	 * @param string $hint    Suggested next step.
	 */
	public function __construct( $message, $detail = '', $hint = '' ) {
		parent::__construct( (string) $message );
		$this->detail = (string) $detail;
		$this->hint   = (string) $hint;
	}

	/**
	 * Structured payload for the MCP error result.
	 *
	 * @return array
	 */
	public function to_array() {
		$error = array( 'message' => $this->getMessage() );
		if ( '' !== $this->detail ) {
			$error['detail'] = $this->detail;
		}
		if ( '' !== $this->hint ) {
			$error['hint'] = $this->hint;
		}
		return $error;
	}
}
