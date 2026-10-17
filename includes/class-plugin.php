<?php
/**
 * Plugin bootstrap: hooks, tool registration, lifecycle.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Boot the plugin (called from the main file).
	 *
	 * @return void
	 */
	public static function boot() {
		// Load sandbox loader (crash-safe loading of agent-written PHP).
		require_once AI_CONNECT_DIR . 'includes/sandbox-loader.php';

		add_action( 'plugins_loaded', array( __CLASS__, 'init' ), 5 );
		add_action( 'rest_api_init', array( 'AiConnect\\Rest', 'register' ) );
		add_action( 'ai_connect_daily', array( 'AiConnect\\Tokens', 'cleanup' ) );
		add_action( 'ai_connect_daily', array( 'AiConnect\\Log', 'prune' ) );
		add_action( 'ai_connect_daily', array( 'AiConnect\\Jobs', 'cleanup' ) );
		add_action( 'sitewright_daily', array( 'AiConnect\\Tokens', 'cleanup' ) );
		add_action( 'sitewright_daily', array( 'AiConnect\\Log', 'prune' ) );
		add_action( 'sitewright_daily', array( 'AiConnect\\Jobs', 'cleanup' ) );
		// Background job pump (single event scheduled per job).
		add_action( 'ai_connect_jobs_pump', array( 'AiConnect\\Jobs', 'pump' ) );

		if ( is_admin() ) {
			Admin::hooks();
		}
	}

	/**
	 * Late boot: text domain, schema, tool registration.
	 *
	 * @return void
	 */
	public static function init() {
		load_plugin_textdomain( 'ai-connect', false, dirname( AI_CONNECT_BASENAME ) . '/languages' );

		DB::maybe_upgrade();

		Tools\Site::register();
		Tools\Content::register();
		Tools\Dev::register();
		Tools\Design_Tools::register();
		Tools\Jobs_Tools::register();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function on_activate() {
		DB::install();
		Sandbox::ensure_dir();
		// Migrate the pre-1.1.0 daily event to the new hook name.
		wp_clear_scheduled_hook( 'sitewright_daily' );
		if ( ! wp_next_scheduled( 'ai_connect_daily' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'ai_connect_daily' );
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function on_deactivate() {
		wp_clear_scheduled_hook( 'ai_connect_daily' );
		wp_clear_scheduled_hook( 'sitewright_daily' );
	}
}

// Class aliases for backward compatibility with the Sitewright namespace.
if ( ! class_exists( 'Sitewright\\Plugin', false ) ) {
	class_alias( 'AiConnect\\Plugin', 'Sitewright\\Plugin' );
}
