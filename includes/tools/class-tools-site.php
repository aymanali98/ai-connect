<?php
/**
 * Site tools: context, inspection, plugins/themes/users, options, abilities,
 * cache, and plugin lifecycle actions.
 *
 * Agent-facing strings are intentionally plain English (not translated).
 *
 * @package AiConnect
 */

namespace AiConnect\Tools;

use AiConnect\Design;
use AiConnect\Diagnostics;
use AiConnect\Mcp;
use AiConnect\Registry;
use AiConnect\Settings;
use AiConnect\Tool_Error;
use AiConnect\Tokens;
use AiConnect\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the site/environment tool group.
 */
final class Site {

	/**
	 * Register this group.
	 *
	 * @return void
	 */
	public static function register() {
		Registry::add(
			'agent_context',
			array(
				'title'       => 'Agent context',
				'description' => 'Transport-neutral orientation for this WordPress site: environment summary, current modes (read-only, dangerous tools), the server instruction text, and design-system guidance. Call this first when you connect.',
				'risk'        => 'read',
				'group'       => 'meta',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'callback'    => array( __CLASS__, 'agent_context' ),
			)
		);

		Registry::add(
			'site_info',
			array(
				'title'       => 'Site info',
				'description' => 'Detailed environment report: WordPress/PHP versions, URLs, active theme, plugin counts, content counts, detected builders (Elementor/WooCommerce), debug flags, and PHP limits.',
				'risk'        => 'read',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'callback'    => array( __CLASS__, 'site_info' ),
			)
		);

		Registry::add(
			'list_plugins',
			array(
				'title'       => 'List plugins',
				'description' => 'Every installed plugin with version, active state, and whether an update is available. The "plugin" field is the exact key to pass to activate_plugin, deactivate_plugin, and update_plugin.',
				'risk'        => 'read',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'active_only' => array(
							'type'        => 'boolean',
							'description' => 'Only return active plugins.',
							'default'     => false,
						),
					),
				),
				'callback'    => array( __CLASS__, 'list_plugins' ),
			)
		);

		Registry::add(
			'list_themes',
			array(
				'title'       => 'List themes',
				'description' => 'Installed themes with version, parent theme, and which one is active.',
				'risk'        => 'read',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'callback'    => array( __CLASS__, 'list_themes' ),
			)
		);

		Registry::add(
			'list_users',
			array(
				'title'       => 'List users',
				'description' => 'WordPress users with IDs, login names, display names, and roles. Email addresses included only when include_email is true.',
				'risk'        => 'read',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'per_page'      => array(
							'type'        => 'integer',
							'description' => 'Users per page (1-200).',
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 200,
						),
						'page'          => array(
							'type'        => 'integer',
							'description' => 'Page number.',
							'default'     => 1,
							'minimum'     => 1,
						),
						'role'          => array(
							'type'        => 'string',
							'description' => 'Filter by role slug (e.g. administrator, editor).',
							'default'     => '',
						),
						'search'        => array(
							'type'        => 'string',
							'description' => 'Search login, display name, or email.',
							'default'     => '',
						),
						'include_email' => array(
							'type'        => 'boolean',
							'description' => 'Include email addresses.',
							'default'     => false,
						),
					),
				),
				'callback'    => array( __CLASS__, 'list_users' ),
			)
		);

		Registry::add(
			'diagnostics',
			array(
				'title'       => 'Diagnostics',
				'description' => 'Run environment checks: versions, extensions, HTTPS, REST reachability, tables, sandbox status, WP-CLI, file-mod constants, PHP limits, disk space, cron. Each check has status ok/warn/fail plus an actionable fix.',
				'risk'        => 'read',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'full' => array(
							'type'        => 'boolean',
							'description' => 'Run slow checks too (loopback request, WP-CLI detection).',
							'default'     => true,
						),
					),
				),
				'callback'    => array( __CLASS__, 'diagnostics' ),
			)
		);

		Registry::add(
			'get_option',
			array(
				'title'       => 'Get option',
				'description' => 'Read a wp_options value (any option, including serialized arrays). Large values are truncated in the response with a byte size note.',
				'risk'        => 'read',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'name' => array(
							'type'        => 'string',
							'description' => 'Option name.',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'name' ),
				),
				'callback'    => array( __CLASS__, 'get_option' ),
			)
		);

		Registry::add(
			'update_option',
			array(
				'title'       => 'Update option',
				'description' => 'Write a wp_options value. A safety denylist blocks critical options (siteurl, home, active_plugins, ...) and all Ai Connect options (ai_connect_* / sitewright_*). Returns whether anything changed.',
				'risk'        => 'write',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array(
							'type'        => 'string',
							'description' => 'Option name.',
							'minLength'   => 1,
						),
						'value' => array(
							'description' => 'New value: string, number, boolean, or array/object for complex options.',
						),
					),
					'required'   => array( 'name', 'value' ),
				),
				'callback'    => array( __CLASS__, 'update_option' ),
			)
		);

		Registry::add(
			'discover_abilities',
			array(
				'title'       => 'Discover abilities',
				'description' => 'List abilities registered through the WordPress Abilities API (WordPress 6.9+), so you can call them with run_ability. Falls back to reporting availability on older WordPress.',
				'risk'        => 'read',
				'group'       => 'meta',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Optional filter on name, label, or description.',
							'default'     => '',
						),
					),
				),
				'callback'    => array( __CLASS__, 'discover_abilities' ),
			)
		);

		Registry::add(
			'run_ability',
			array(
				'title'       => 'Run ability',
				'description' => 'Execute a registered WordPress ability (Abilities API, WordPress 6.9+) by name with an input object. Use discover_abilities first to find valid names.',
				'risk'        => 'write',
				'group'       => 'meta',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'ability' => array(
							'type'        => 'string',
							'description' => 'Ability name, e.g. namespace/ability.',
							'minLength'   => 1,
						),
						'input'   => array(
							'type'        => 'object',
							'description' => 'Input payload for the ability (shape depends on the ability).',
							'default'     => array(),
						),
					),
					'required'   => array( 'ability' ),
				),
				'callback'    => array( __CLASS__, 'run_ability' ),
			)
		);

		Registry::add(
			'clear_cache',
			array(
				'title'       => 'Clear cache',
				'description' => 'Flush the WordPress object cache and, when detected, common page caches (LiteSpeed, WP Rocket, W3TC, Cache Enabler, Breeze, SiteGround Optimizer, Autoptimize).',
				'risk'        => 'write',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'callback'    => array( __CLASS__, 'clear_cache' ),
			)
		);

		Registry::add(
			'install_plugin',
			array(
				'title'       => 'Install plugin',
				'description' => 'Install a plugin from the WordPress.org directory (by slug) or from a direct ZIP URL, optionally activating it. Requires WRITE access to the filesystem and DISALLOW_FILE_MODS off.',
				'risk'        => 'dangerous',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'slug'     => array(
							'type'        => 'string',
							'description' => 'WordPress.org plugin slug (e.g. woocommerce).',
							'default'     => '',
						),
						'url'      => array(
							'type'        => 'string',
							'description' => 'Direct download URL of a plugin ZIP (alternative to slug).',
							'default'     => '',
						),
						'activate' => array(
							'type'        => 'boolean',
							'description' => 'Activate after install.',
							'default'     => false,
						),
					),
				),
				'callback'    => array( __CLASS__, 'install_plugin' ),
			)
		);

		Registry::add(
			'update_plugin',
			array(
				'title'       => 'Update plugin',
				'description' => 'Update one installed plugin to its latest available version (must already be flagged as having an update; refreshing the update cache is done automatically).',
				'risk'        => 'dangerous',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file key, e.g. contact-form-7/wp-contact-form-7.php (see list_plugins).',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'plugin' ),
				),
				'callback'    => array( __CLASS__, 'update_plugin' ),
			)
		);

		Registry::add(
			'activate_plugin',
			array(
				'title'       => 'Activate plugin',
				'description' => 'Activate an installed plugin by its file key (see list_plugins).',
				'risk'        => 'write',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file key, e.g. akismet/akismet.php.',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'plugin' ),
				),
				'callback'    => array( __CLASS__, 'activate_plugin' ),
			)
		);

		Registry::add(
			'deactivate_plugin',
			array(
				'title'       => 'Deactivate plugin',
				'description' => 'Deactivate an active plugin by its file key (see list_plugins). To fully neutralize a plugin\'s PHP while keeping files, prefer file deletion/renaming tools instead.',
				'risk'        => 'write',
				'group'       => 'site',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file key, e.g. akismet/akismet.php.',
							'minLength'   => 1,
						),
					),
					'required'   => array( 'plugin' ),
				),
				'callback'    => array( __CLASS__, 'deactivate_plugin' ),
			)
		);
	}

	/**
	 * agent_context tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function agent_context( array $args ) {
		return array(
			'server'          => array(
				'name'     => 'ai-connect',
				'version'  => AI_CONNECT_VERSION,
				'endpoint' => rest_url( AI_CONNECT_REST_NS . '/mcp' ),
				'protocol' => Mcp::SUPPORTED_PROTOCOLS[0],
			),
			'modes'           => array(
				'read_only'               => Settings::is_read_only(),
				'dangerous_tools_enabled' => Settings::dangerous_allowed(),
			),
			'environment'     => self::environment_summary(),
			'instructions'    => Mcp::instructions(),
			'design_guidance' => Design::guidance(),
			'tool_counts'     => array(
				'enabled' => count( Registry::enabled() ),
				'total'   => count( Registry::all() ),
			),
		);
	}

	/**
	 * site_info tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function site_info( array $args ) {
		$post_counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'post' ) : null;
		$page_counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'page' ) : null;
		$media       = function_exists( 'wp_count_posts' ) ? wp_count_posts( 'attachment' ) : null;

		$user_count = null;
		if ( function_exists( 'get_user_count' ) ) {
			$user_count = get_user_count();
		} elseif ( function_exists( 'count_users' ) ) {
			$counts     = count_users();
			$user_count = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : null;
		}

		$flags = array(
			'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'wp_debug_display'    => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'disable_wp_cron'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'disallow_file_mods'  => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'disallow_file_edit'  => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'external_object_cache' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'pretty_permalinks'   => '' !== (string) get_option( 'permalink_structure', '' ),
		);

		$free = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return array(
			'environment' => self::environment_summary(),
			'counts'      => array(
				'posts_published' => $post_counts && isset( $post_counts->publish ) ? (int) $post_counts->publish : null,
				'posts_drafts'    => $post_counts && isset( $post_counts->draft ) ? (int) $post_counts->draft : null,
				'pages_published' => $page_counts && isset( $page_counts->publish ) ? (int) $page_counts->publish : null,
				'media'           => $media && isset( $media->inherit ) ? (int) $media->inherit : null,
				'users'           => $user_count,
			),
			'flags'       => $flags,
			'limits'      => array(
				'memory_limit'       => (string) ini_get( 'memory_limit' ),
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'post_max_size'      => (string) ini_get( 'post_max_size' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			),
			'disk_free'   => false !== $free ? Util::bytes( $free ) : null,
			'paths'       => array(
				'abspath'         => Util::norm_path( ABSPATH ),
				'wp_content'      => Util::norm_path( WP_CONTENT_DIR ),
				'sandbox'         => \AiConnect\Sandbox::dir(),
				'uploads_baseurl' => wp_upload_dir()['baseurl'],
			),
		);
	}

	/**
	 * list_plugins tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function list_plugins( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( ! function_exists( 'get_plugin_updates' ) && is_readable( ABSPATH . 'wp-admin/includes/update.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active  = array_merge( $active, $network );
		}
		$updates = function_exists( 'get_plugin_updates' ) ? get_plugin_updates() : array();

		$items = array();
		foreach ( $all as $file => $data ) {
			$is_active = in_array( $file, $active, true );
			if ( ! empty( $args['active_only'] ) && ! $is_active ) {
				continue;
			}
			$items[] = array(
				'plugin'           => $file,
				'name'             => isset( $data['Name'] ) ? $data['Name'] : $file,
				'version'          => isset( $data['Version'] ) ? $data['Version'] : '',
				'active'           => $is_active,
				'update_available' => isset( $updates[ $file ]->update->new_version ) ? (string) $updates[ $file ]->update->new_version : null,
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['active'] !== $b['active'] ) {
					return $a['active'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'total'  => count( $all ),
			'active' => count( array_intersect( array_keys( $all ), $active ) ),
			'items'  => $items,
		);
	}

	/**
	 * list_themes tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function list_themes( array $args ) {
		$themes  = wp_get_themes();
		$current = get_stylesheet();
		$items   = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$items[] = array(
				'stylesheet'    => $stylesheet,
				'name'          => $theme->get( 'Name' ),
				'version'       => $theme->get( 'Version' ),
				'parent'        => $theme->get( 'Template' ) ? $theme->get( 'Template' ) : null,
				'active'        => $stylesheet === $current,
				'block_theme'   => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
			);
		}
		return array(
			'active_stylesheet' => $current,
			'items'             => $items,
		);
	}

	/**
	 * list_users tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function list_users( array $args ) {
		$query_args = array(
			'number'  => isset( $args['per_page'] ) ? (int) $args['per_page'] : 50,
			'paged'   => isset( $args['page'] ) ? (int) $args['page'] : 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);
		if ( ! empty( $args['role'] ) ) {
			$query_args['role'] = $args['role'];
		}
		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = '*' . $args['search'] . '*';
		}

		$query = new \WP_User_Query( $query_args );
		$items = array();
		foreach ( (array) $query->get_results() as $user ) {
			$item = array(
				'id'           => (int) $user->ID,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'roles'        => array_values( (array) $user->roles ),
				'registered'   => $user->user_registered,
			);
			if ( ! empty( $args['include_email'] ) ) {
				$item['email'] = $user->user_email;
			}
			$items[] = $item;
		}

		return array(
			'total' => (int) $query->get_total(),
			'items' => $items,
		);
	}

	/**
	 * diagnostics tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function diagnostics( array $args ) {
		$full = array_key_exists( 'full', $args ) ? (bool) $args['full'] : true;
		return Diagnostics::run( $full );
	}

	/**
	 * get_option tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function get_option( array $args ) {
		$name     = (string) $args['name'];
		$sentinel = new \stdClass();
		$value    = get_option( $name, $sentinel );

		if ( $value === $sentinel ) {
			return array(
				'name'   => $name,
				'exists' => false,
				'value'  => null,
			);
		}

		$encoded = Util::json_compact( $value );
		$bytes   = strlen( $encoded );
		$truncated = false;
		if ( $bytes > 100 * 1024 ) {
			$value     = Util::truncate( $encoded, 100 * 1024 ) . ' [truncated]';
			$truncated = true;
		}

		return array(
			'name'      => $name,
			'exists'    => true,
			'value'     => $value,
			'bytes'     => $bytes,
			'truncated' => $truncated,
		);
	}

	/**
	 * update_option tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the option is protected.
	 */
	public static function update_option( array $args ) {
		$name  = (string) $args['name'];
		$value = $args['value'];

		$denylist = apply_filters(
			'ai_connect/option_write_denylist',
			array(
				'siteurl',
				'home',
				'admin_email',
				'users_can_register',
				'default_role',
				'active_plugins',
				'template',
				'stylesheet',
				'permalink_structure',
				'cron',
				'whitelist_keys',
			)
		);
		if ( in_array( $name, $denylist, true ) || 0 === strpos( $name, 'sitewright_' ) || 0 === strpos( $name, 'ai_connect_' ) ) {
			throw new Tool_Error(
				'This option is protected and cannot be written by tools: ' . $name,
				'Critical site behavior (URLs, active plugins, cron, and this plugin\'s own settings) is intentionally locked.',
				'Change it through wp-admin, WP-CLI, or a PHP snippet with run_php if you truly need to.'
			);
		}

		$bytes = strlen( Util::json_compact( $value ) );
		if ( $bytes > 256 * 1024 ) {
			throw new Tool_Error( 'Option value is too large (' . Util::bytes( $bytes ) . '); the limit is 256 KB.' );
		}

		$old     = get_option( $name, null );
		$changed = update_option( $name, $value, false );

		return array(
			'name'        => $name,
			'changed'     => (bool) $changed,
			'had_value'   => null !== $old,
			'value_bytes' => $bytes,
		);
	}

	/**
	 * discover_abilities tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function discover_abilities( array $args ) {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array(
				'available'       => false,
				'note'            => 'The WordPress Abilities API is not present on this site (it ships with WordPress 6.9+). All Ai Connect tools work without it.',
				'ai_connect_tools' => array_keys( Registry::enabled() ),
			);
		}

		$search    = isset( $args['search'] ) ? strtolower( (string) $args['search'] ) : '';
		$abilities = wp_get_abilities();
		$items     = array();

		foreach ( (array) $abilities as $key => $ability ) {
			if ( ! is_object( $ability ) ) {
				continue;
			}
			$name  = method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : (string) $key;
			$label = method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : '';
			$desc  = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
			$cat   = method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';

			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $label . ' ' . $desc ), $search ) ) {
				continue;
			}

			$items[] = array(
				'name'        => $name,
				'label'       => $label,
				'description' => Util::truncate( $desc, 500 ),
				'category'    => $cat,
			);
			if ( count( $items ) >= 200 ) {
				break;
			}
		}

		return array(
			'available' => true,
			'count'     => count( $items ),
			'abilities' => $items,
		);
	}

	/**
	 * run_ability tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the ability is unavailable or fails.
	 */
	public static function run_ability( array $args ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			throw new Tool_Error(
				'The WordPress Abilities API is not available on this site.',
				'It ships with WordPress 6.9+. Current version: ' . get_bloginfo( 'version' ) . '.',
				'Use run_php or WP-CLI for equivalent functionality.'
			);
		}

		$name    = (string) $args['ability'];
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			throw new Tool_Error(
				'Unknown ability: ' . $name,
				'Nothing is registered under that name.',
				'Call discover_abilities to list registered abilities.'
			);
		}
		if ( ! method_exists( $ability, 'execute' ) ) {
			throw new Tool_Error( 'This ability cannot be executed through the current API version.' );
		}

		$input = isset( $args['input'] ) && is_array( $args['input'] ) ? $args['input'] : array();

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $error ) {
			throw new Tool_Error( 'The ability failed: ' . $error->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( 'The ability returned an error: ' . $result->get_error_message() );
		}

		return array(
			'ability' => $name,
			'result'  => $result,
		);
	}

	/**
	 * clear_cache tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function clear_cache( array $args ) {
		$cleared = array();

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$cleared[] = 'WordPress object cache';
		}

		$known = array(
			'litespeed_purge_all'       => 'LiteSpeed Cache',
			'rocket_clean_domain'       => 'WP Rocket',
			'w3tc_flush_all'            => 'W3 Total Cache',
			'cache_enabler_clear_complete_cache' => 'Cache Enabler',
			'breeze_clear_all_cache'    => 'Breeze',
			'sg_cachepress_purge_cache' => 'SiteGround Optimizer',
			'autoptimize_flush_pagecache' => 'Autoptimize',
			'wp_cache_clear_cache'      => 'WP Super Cache (object)',
		);
		foreach ( $known as $function => $label ) {
			if ( function_exists( $function ) ) {
				call_user_func( $function );
				$cleared[] = $label;
			}
		}

		do_action( 'ai_connect/cleared_cache' );

		return array( 'cleared' => $cleared );
	}

	/**
	 * install_plugin tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When installation is impossible.
	 */
	public static function install_plugin( array $args ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new Tool_Error( 'Plugin installation is disabled on this site.', 'DISALLOW_FILE_MODS is set in wp-config.php.' );
		}
		if ( ! current_user_can( 'install_plugins' ) ) {
			throw new Tool_Error( 'The current user cannot install plugins.' );
		}

		$slug = isset( $args['slug'] ) ? strtolower( (string) $args['slug'] ) : '';
		$url  = isset( $args['url'] ) ? (string) $args['url'] : '';
		$link = '';
		$name = '';

		if ( '' !== $slug ) {
			if ( ! preg_match( '/^[a-z0-9\-]+$/', $slug ) ) {
				throw new Tool_Error( 'Invalid plugin slug: ' . $slug );
			}
		} elseif ( '' !== $url ) {
			$link = esc_url_raw( $url );
			if ( '' === $link || 0 !== strpos( $link, 'http' ) ) {
				throw new Tool_Error( 'Invalid download URL.' );
			}
			$name = basename( (string) wp_parse_url( $link, PHP_URL_PATH ) );
		} else {
			throw new Tool_Error( 'Provide either "slug" (WordPress.org) or "url" (direct ZIP).' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		if ( '' === $link ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false, 'description' => false ),
				)
			);
			if ( is_wp_error( $api ) ) {
				throw new Tool_Error( 'Plugin not found in the WordPress.org directory: ' . $slug, $api->get_error_message() );
			}
			$link = $api->download_link;
			$name = $api->name;
		}

		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			throw new Tool_Error( 'The WordPress filesystem API is unavailable.', 'This host may require FTP/SSH credentials for plugin installs; set FS_METHOD in wp-config.php or install manually.' );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $link );

		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( 'Install failed: ' . $result->get_error_message() );
		}
		if ( ! $result ) {
			$error = $upgrader->skin && method_exists( $upgrader->skin, 'get_upgrade_messages' ) ? '' : '';
			throw new Tool_Error( 'Install failed with no details returned.', $error, 'Check filesystem permissions on wp-content/plugins.' );
		}

		$file      = $upgrader->plugin_info();
		$activated = false;
		$activate_error = '';
		if ( ! empty( $args['activate'] ) && $file ) {
			$activation = activate_plugin( $file );
			if ( is_wp_error( $activation ) ) {
				$activate_error = $activation->get_error_message();
			} else {
				$activated = true;
			}
		}

		return array(
			'installed'      => true,
			'name'           => $name,
			'plugin'         => $file ? $file : null,
			'activated'      => $activated,
			'activate_error' => '' !== $activate_error ? $activate_error : null,
		);
	}

	/**
	 * update_plugin tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When update is impossible.
	 */
	public static function update_plugin( array $args ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			throw new Tool_Error( 'Plugin updates are disabled on this site.', 'DISALLOW_FILE_MODS is set in wp-config.php.' );
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			throw new Tool_Error( 'The current user cannot update plugins.' );
		}

		$file = trim( (string) $args['plugin'] );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			throw new Tool_Error( 'Unknown plugin file: ' . $file, '', 'Use the exact "plugin" value from list_plugins.' );
		}

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! $updates || empty( $updates->response[ $file ] ) ) {
			return array(
				'plugin'  => $file,
				'updated' => false,
				'note'    => 'No update is available for this plugin right now.',
			);
		}
		$new_version = isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : '';

		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			throw new Tool_Error( 'The WordPress filesystem API is unavailable.' );
		}

		$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( 'Update failed: ' . $result->get_error_message() );
		}
		if ( ! $result ) {
			throw new Tool_Error( 'Update failed with no details returned.', '', 'Check filesystem permissions.' );
		}

		$installed = get_plugins();

		return array(
			'plugin'      => $file,
			'updated'     => true,
			'new_version' => $new_version,
			'now_at'      => isset( $installed[ $file ]['Version'] ) ? $installed[ $file ]['Version'] : $new_version,
		);
	}

	/**
	 * activate_plugin tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When activation is impossible.
	 */
	public static function activate_plugin( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$file = trim( (string) $args['plugin'] );
		$all  = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			throw new Tool_Error( 'Unknown plugin file: ' . $file, '', 'Use the exact "plugin" value from list_plugins.' );
		}
		if ( is_plugin_active( $file ) ) {
			return array(
				'plugin'         => $file,
				'already_active' => true,
			);
		}

		$result = activate_plugin( $file, '', false, true );
		if ( is_wp_error( $result ) ) {
			throw new Tool_Error( 'Activation failed: ' . $result->get_error_message() );
		}

		return array(
			'plugin'  => $file,
			'active'  => is_plugin_active( $file ),
		);
	}

	/**
	 * deactivate_plugin tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When deactivation is impossible.
	 */
	public static function deactivate_plugin( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$file = trim( (string) $args['plugin'] );
		$all  = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			throw new Tool_Error( 'Unknown plugin file: ' . $file, '', 'Use the exact "plugin" value from list_plugins.' );
		}
		if ( ! is_plugin_active( $file ) ) {
			return array(
				'plugin'           => $file,
				'already_inactive' => true,
			);
		}

		deactivate_plugins( $file, true );

		return array(
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * Shared environment summary.
	 *
	 * @return array
	 */
	private static function environment_summary() {
		$theme = wp_get_theme();
		return array(
			'site_name'        => get_bloginfo( 'name' ),
			'site_url'         => home_url(),
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'locale'           => get_locale(),
			'timezone'         => wp_timezone_string(),
			'multisite'        => is_multisite(),
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			'https'            => is_ssl(),
			'theme'            => array(
				'name'        => $theme->get( 'Name' ),
				'stylesheet'  => $theme->get_stylesheet(),
				'block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			),
			'active_plugins'   => count( (array) get_option( 'active_plugins', array() ) ),
			'elementor'        => defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ),
			'woocommerce'      => class_exists( 'WooCommerce' ),
		);
	}
}
