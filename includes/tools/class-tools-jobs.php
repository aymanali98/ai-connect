<?php
/**
 * Background job tools: job_start, job_status, job_cancel.
 *
 * These keep long operations (plugin installs/updates, WP-CLI, heavy PHP) from
 * dying on HTTP timeouts: the call queues work and clients poll for progress.
 *
 * @package AiConnect
 */

namespace AiConnect\Tools;

use AiConnect\Jobs;
use AiConnect\Registry;
use AiConnect\Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the background job tool group.
 */
final class Jobs_Tools {

	/**
	 * Register this group.
	 *
	 * @return void
	 */
	public static function register() {
		Registry::add(
			'job_start',
			array(
				'title'       => 'Start background job',
				'description' => 'Queue a long-running tool call (plugin install/update, WP-CLI, heavy PHP) and return a job_id immediately. The job runs in the background so it survives HTTP/proxy timeouts; poll job_status every ~3-5 seconds until the status is done, error, blocked, cancelled, or failed. The token and all permissions are re-checked when the job actually runs.',
				'risk'        => 'dangerous',
				'group'       => 'jobs',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'tool'      => array(
							'type'        => 'string',
							'description' => 'The long-running tool to run in the background.',
							'enum'        => Jobs::allowed_tools(),
						),
						'arguments' => array(
							'type'        => 'object',
							'description' => 'Arguments object exactly as the wrapped tool expects them (same keys as a direct call).',
						),
					),
					'required'   => array( 'tool' ),
				),
				'callback'    => array( __CLASS__, 'job_start' ),
			)
		);

		Registry::add(
			'job_status',
			array(
				'title'       => 'Background job status',
				'description' => 'Check a background job started with job_start. Returns status (queued, running, done, error, blocked, cancelled, failed), the result payload when finished, and short progress info. Calling this also nudges the queue if WP-Cron has not run, so polling makes progress even on hosts with broken cron.',
				'risk'        => 'read',
				'group'       => 'jobs',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array(
							'type'        => 'integer',
							'description' => 'Job id returned by job_start.',
							'minimum'     => 1,
						),
					),
					'required'   => array( 'job_id' ),
				),
				'callback'    => array( __CLASS__, 'job_status' ),
			)
		);

		Registry::add(
			'job_cancel',
			array(
				'title'       => 'Cancel background job',
				'description' => 'Cancel a background job. Queued jobs are cancelled silently; a job that is already running cannot be interrupted from another request (PHP has no cross-request kill) and will finish and record its result.',
				'risk'        => 'write',
				'group'       => 'jobs',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array(
							'type'        => 'integer',
							'description' => 'Job id returned by job_start.',
							'minimum'     => 1,
						),
					),
					'required'   => array( 'job_id' ),
				),
				'callback'    => array( __CLASS__, 'job_cancel' ),
			)
		);
	}

	/**
	 * job_start tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When called outside an authenticated MCP request.
	 */
	public static function job_start( array $args ) {
		$token = Registry::context_token();
		if ( null === $token ) {
			throw new Tool_Error( 'job_start must run inside an authenticated MCP call.' );
		}
		$arguments = isset( $args['arguments'] ) && is_array( $args['arguments'] ) ? $args['arguments'] : array();
		return Jobs::start( (string) $args['tool'], $arguments, $token );
	}

	/**
	 * job_status tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When called outside an authenticated MCP request.
	 */
	public static function job_status( array $args ) {
		$token = Registry::context_token();
		if ( null === $token ) {
			throw new Tool_Error( 'job_status must run inside an authenticated MCP call.' );
		}
		$id = (int) $args['job_id'];
		Jobs::pump_if_stale( $id );
		return Jobs::get_status( $id, $token );
	}

	/**
	 * job_cancel tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When called outside an authenticated MCP request.
	 */
	public static function job_cancel( array $args ) {
		$token = Registry::context_token();
		if ( null === $token ) {
			throw new Tool_Error( 'job_cancel must run inside an authenticated MCP call.' );
		}
		return Jobs::cancel( (int) $args['job_id'], $token );
	}
}
