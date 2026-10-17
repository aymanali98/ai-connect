<?php
/**
 * Plugin Name:       Ai Connect
 * Plugin URI:        https://aymnali.com/wordpress/Ai-Connect
 * Description:       Connect AI coding agents to WordPress over MCP. Gives agents real access: site inspection, filesystem tools, database tools, safe diagnostics, a design system, and a read-only mode. Built for development and staging sites.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ayman Ali
 * Author URI:        https://aymnali.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-connect
 * Domain Path:       /languages
 *
 * Ai Connect is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version. Ai Connect is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * @package AiConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Primary Ai Connect constants.
define( 'AI_CONNECT_VERSION', '1.1.0' );
define( 'AI_CONNECT_FILE', __FILE__ );
define( 'AI_CONNECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_CONNECT_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_CONNECT_BASENAME', plugin_basename( __FILE__ ) );
define( 'AI_CONNECT_REST_NS', 'ai-connect/v1' );
define( 'AI_CONNECT_CAP', 'manage_options' );
define( 'AI_CONNECT_OPTION', 'ai_connect_settings' );
define( 'AI_CONNECT_DESIGN_OPTION', 'ai_connect_design' );
define( 'AI_CONNECT_DB_VERSION_OPTION', 'ai_connect_db_version' );
define( 'AI_CONNECT_MAX_EXECUTION_TIME', 30 );

// Backward-compatibility aliases for Ai Connect constants.
if ( ! defined( 'SITEWRIGHT_VERSION' ) ) {
	define( 'SITEWRIGHT_VERSION', AI_CONNECT_VERSION );
}
if ( ! defined( 'SITEWRIGHT_FILE' ) ) {
	define( 'SITEWRIGHT_FILE', AI_CONNECT_FILE );
}
if ( ! defined( 'SITEWRIGHT_DIR' ) ) {
	define( 'SITEWRIGHT_DIR', AI_CONNECT_DIR );
}
if ( ! defined( 'SITEWRIGHT_URL' ) ) {
	define( 'SITEWRIGHT_URL', AI_CONNECT_URL );
}
if ( ! defined( 'SITEWRIGHT_BASENAME' ) ) {
	define( 'SITEWRIGHT_BASENAME', AI_CONNECT_BASENAME );
}
if ( ! defined( 'SITEWRIGHT_REST_NS' ) ) {
	define( 'SITEWRIGHT_REST_NS', 'sitewright/v1' );
}
if ( ! defined( 'SITEWRIGHT_CAP' ) ) {
	define( 'SITEWRIGHT_CAP', AI_CONNECT_CAP );
}
if ( ! defined( 'SITEWRIGHT_OPTION' ) ) {
	define( 'SITEWRIGHT_OPTION', 'sitewright_settings' );
}
if ( ! defined( 'SITEWRIGHT_DESIGN_OPTION' ) ) {
	define( 'SITEWRIGHT_DESIGN_OPTION', 'sitewright_design' );
}
if ( ! defined( 'SITEWRIGHT_DB_VERSION_OPTION' ) ) {
	define( 'SITEWRIGHT_DB_VERSION_OPTION', 'sitewright_db_version' );
}
if ( ! defined( 'SITEWRIGHT_MAX_EXECUTION_TIME' ) ) {
	define( 'SITEWRIGHT_MAX_EXECUTION_TIME', AI_CONNECT_MAX_EXECUTION_TIME );
}

require_once AI_CONNECT_DIR . 'includes/class-util.php';
require_once AI_CONNECT_DIR . 'includes/class-tool-error.php';
require_once AI_CONNECT_DIR . 'includes/class-settings.php';
require_once AI_CONNECT_DIR . 'includes/class-db.php';
require_once AI_CONNECT_DIR . 'includes/class-tokens.php';
require_once AI_CONNECT_DIR . 'includes/class-log.php';
require_once AI_CONNECT_DIR . 'includes/class-jobs.php';
require_once AI_CONNECT_DIR . 'includes/class-path-guard.php';
require_once AI_CONNECT_DIR . 'includes/class-sql-guard.php';
require_once AI_CONNECT_DIR . 'includes/class-registry.php';
require_once AI_CONNECT_DIR . 'includes/class-sandbox.php';
require_once AI_CONNECT_DIR . 'includes/class-design.php';
require_once AI_CONNECT_DIR . 'includes/class-diagnostics.php';
require_once AI_CONNECT_DIR . 'includes/class-mcp.php';
require_once AI_CONNECT_DIR . 'includes/class-rest.php';
require_once AI_CONNECT_DIR . 'includes/tools/class-tools-site.php';
require_once AI_CONNECT_DIR . 'includes/tools/class-tools-content.php';
require_once AI_CONNECT_DIR . 'includes/tools/class-tools-dev.php';
require_once AI_CONNECT_DIR . 'includes/tools/class-tools-design.php';
require_once AI_CONNECT_DIR . 'includes/tools/class-tools-jobs.php';
require_once AI_CONNECT_DIR . 'includes/class-admin.php';
require_once AI_CONNECT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'AiConnect\\DB', 'install' ) );
register_activation_hook( __FILE__, array( 'AiConnect\\Plugin', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'AiConnect\\Plugin', 'on_deactivate' ) );

AiConnect\Plugin::boot();
