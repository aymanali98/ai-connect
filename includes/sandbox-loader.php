<?php
/**
 * Ai Connect sandbox loader.
 *
 * Loads agent-written PHP from wp-content/sitewright-sandbox/ on every request
 * with crash detection: if PHP terminates while a sandbox file is loading
 * (parse error, fatal, timeout), a .crashed marker is written and the next
 * request skips all sandbox files until an administrator clears safe mode in
 * Ai Connect -> Diagnostics.
 *
 * @package AiConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load once, even if the plugin file is included more than once.
if ( defined( 'AI_CONNECT_SANDBOX_LOADER_DONE' ) ) {
	return;
}
define( 'AI_CONNECT_SANDBOX_LOADER_DONE', true );

// WordPress' own plugin sandbox probes must not run agent code.
if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
	return;
}

if ( ! class_exists( 'AiConnect\\Sandbox' ) ) {
	return;
}

$ai_connect_sandbox_dir = AiConnect\Sandbox::dir();
if ( ! is_dir( $ai_connect_sandbox_dir ) ) {
	return;
}

$ai_connect_crashed_file = AiConnect\Sandbox::crashed_file();

// --- Safe mode: a previous request crashed while loading --------------------
if ( file_exists( $ai_connect_crashed_file ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( AI_CONNECT_CAP ) ) {
				return;
			}
			$info    = AiConnect\Sandbox::crash_info();
			$message = isset( $info['message'] ) ? (string) $info['message'] : 'Unknown error.';
			$file    = isset( $info['sandbox_file'] ) ? basename( (string) $info['sandbox_file'] ) : 'a sandbox file';
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p><p><a href="%s">%s</a></p></div>',
				esc_html__( 'Ai Connect: safe mode is active.', 'ai-connect' ),
				sprintf(
					/* translators: %s: sandbox file name. */
					esc_html__( 'The sandbox file %s crashed PHP while loading, so all sandbox files are skipped. Fix or delete the file, then exit safe mode.', 'ai-connect' ),
					esc_html( $file )
				),
				esc_html( $message ),
				esc_url( admin_url( 'admin.php?page=ai-connect-diagnostics' ) ),
				esc_html__( 'Open Ai Connect Diagnostics', 'ai-connect' )
			);
		}
	);
	return;
}

// --- Normal load -------------------------------------------------------------
$ai_connect_sandbox_files = glob( $ai_connect_sandbox_dir . '/*.php' );
if ( ! is_array( $ai_connect_sandbox_files ) || array() === $ai_connect_sandbox_files ) {
	return;
}

$ai_connect_current_file = null;

register_shutdown_function(
	function () use ( $ai_connect_crashed_file, &$ai_connect_current_file ) {
		if ( null === $ai_connect_current_file ) {
			return; // Loading finished; a later failure is not a sandbox crash.
		}
		$error = error_get_last();
		$fatal = $error && ( $error['type'] & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR ) );
		if ( ! $fatal ) {
			return; // A clean exit() while loading is a legitimate pattern, not a crash.
		}
		$info = array(
			'type'         => (int) $error['type'],
			'message'      => (string) $error['message'],
			'file'         => (string) $error['file'],
			'line'         => (int) $error['line'],
			'sandbox_file' => (string) $ai_connect_current_file,
			'time'         => gmdate( 'c' ),
		);
		@file_put_contents( $ai_connect_crashed_file, wp_json_encode( $info ), LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
);

foreach ( $ai_connect_sandbox_files as $ai_connect_sandbox_file ) {
	$ai_connect_current_file = $ai_connect_sandbox_file;
	$ai_connect_level        = ob_get_level();
	ob_start();

	try {
		require_once $ai_connect_sandbox_file;
	} catch ( \Throwable $ai_connect_error ) {
		$info = array(
			'type'         => E_ERROR,
			'message'      => $ai_connect_error->getMessage(),
			'file'         => $ai_connect_error->getFile(),
			'line'         => $ai_connect_error->getLine(),
			'sandbox_file' => $ai_connect_sandbox_file,
			'time'         => gmdate( 'c' ),
		);
		@file_put_contents( $ai_connect_crashed_file, wp_json_encode( $info ), LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// Throw away anything the file printed while loading; a sandbox loader must
	// not leak output into admin pages, REST/MCP responses, or the front end.
	while ( ob_get_level() > $ai_connect_level ) {
		ob_end_clean();
	}
}

$ai_connect_current_file = null;
