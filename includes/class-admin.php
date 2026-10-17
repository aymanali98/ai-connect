<?php
/**
 * Admin UI controller: menus, assets, form handling, and shared render helpers.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the seven admin pages and processes their forms.
 */
final class Admin {

	/**
	 * Nonce action for every admin form.
	 */
	const NONCE = 'ai_connect_action';

	/**
	 * Transient prefix holding the just-created plaintext token per user.
	 */
	const NEW_TOKEN_TRANSIENT = 'ai_connect_new_token_';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// The sidebar icon shows on every admin screen; admin.css only loads on
		// plugin pages, so the size rule is printed globally.
		add_action( 'admin_head', array( __CLASS__, 'menu_icon_style' ) );
		add_action( 'admin_post_ai_connect_action', array( __CLASS__, 'handle_post' ) );
		// Legacy action name: forms rendered by pre-1.1.0 caches still submit it.
		add_action( 'admin_post_sitewright_action', array( __CLASS__, 'handle_post' ) );
		// Keep old ?page=sitewright* bookmarks working after the slug rename.
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_pages' ) );
	}

	/**
	 * Keep the sidebar menu icon at WordPress's standard 20px size on every
	 * admin screen. The asset itself is 2x (40px) so it stays sharp on retina
	 * screens; design and colours are untouched.
	 *
	 * @return void
	 */
	public static function menu_icon_style() {
		echo '<style id="ai-connect-menu-icon">#adminmenu #toplevel_page_ai-connect .wp-menu-image img{display:block;width:20px;height:auto;margin:7px auto 0;padding:0}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS, no dynamic data.
	}

	/**
	 * Redirect pre-1.1.0 admin URLs (?page=sitewright...) to the new slugs.
	 *
	 * @return void
	 */
	public static function redirect_legacy_pages() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page || 0 !== strpos( $page, 'sitewright' ) ) {
			return;
		}
		$sub = trim( substr( $page, strlen( 'sitewright' ) ), '-' );
		wp_safe_redirect( self::url( $sub ) );
		exit;
	}

	/**
	 * Register the seven pages.
	 *
	 * @return void
	 */
	public static function menu() {
		$cap = AI_CONNECT_CAP;

		add_menu_page(
			__( 'Ai Connect', 'ai-connect' ),
			__( 'Ai Connect', 'ai-connect' ),
			$cap,
			'ai-connect',
			array( __CLASS__, 'render_overview' ),
			AI_CONNECT_URL . 'assets/images/ai-connect-icon-40.png',
			58
		);

		$pages = array(
			''             => array( __( 'Overview', 'ai-connect' ), array( __CLASS__, 'render_overview' ) ),
			'connect'      => array( __( 'Connect AI', 'ai-connect' ), array( __CLASS__, 'render_connect' ) ),
			'tools'        => array( __( 'Tools', 'ai-connect' ), array( __CLASS__, 'render_tools' ) ),
			'design'       => array( __( 'Design System', 'ai-connect' ), array( __CLASS__, 'render_design' ) ),
			'diagnostics'  => array( __( 'Diagnostics', 'ai-connect' ), array( __CLASS__, 'render_diagnostics' ) ),
			'security'     => array( __( 'Security', 'ai-connect' ), array( __CLASS__, 'render_security' ) ),
			'settings'     => array( __( 'Settings', 'ai-connect' ), array( __CLASS__, 'render_settings' ) ),
		);

		foreach ( $pages as $slug => $page ) {
			$menu_slug = '' === $slug ? 'ai-connect' : 'ai-connect-' . $slug;
			add_submenu_page( 'ai-connect', $page[0], $page[0], $cap, $menu_slug, $page[1] );
		}
	}

	/**
	 * Load assets on Ai Connect pages only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'ai-connect' ) ) {
			return;
		}

		wp_enqueue_style( 'ai-connect-admin', AI_CONNECT_URL . 'assets/admin.css', array(), AI_CONNECT_VERSION );
		wp_enqueue_script( 'ai-connect-admin', AI_CONNECT_URL . 'assets/admin.js', array(), AI_CONNECT_VERSION, true );
		wp_localize_script(
			'ai-connect-admin',
			'AiConnectAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( AI_CONNECT_REST_NS . '/admin/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'copy'     => __( 'Copy', 'ai-connect' ),
					'copied'   => __( 'Copied', 'ai-connect' ),
					'testing'  => __( 'Testing...', 'ai-connect' ),
					'running'  => __( 'Running checks...', 'ai-connect' ),
					'error'    => __( 'The request failed. Check the browser console.', 'ai-connect' ),
					'confirm'  => __( 'Are you sure?', 'ai-connect' ),
				),
			)
		);
	}

	/**
	 * URL of a Ai Connect admin page.
	 *
	 * @param string $page Sub-page slug ('' for overview).
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $page = '', $args = array() ) {
		$base = 'admin.php?page=ai-connect' . ( '' !== $page ? '-' . $page : '' );
		return add_query_arg( $args, admin_url( $base ) );
	}

	/**
	 * The MCP endpoint URL for display and client configs.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( AI_CONNECT_REST_NS . '/mcp' );
	}

	/**
	 * Whether the site uses plain (non-pretty) permalinks.
	 *
	 * @return bool
	 */
	public static function plain_permalinks() {
		return '' === (string) get_option( 'permalink_structure', '' );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render one view file.
	 *
	 * @param string $view View slug (file name without .php).
	 * @param array  $data Data available to the view.
	 * @return void
	 */
	public static function render_page( $view, array $data = array() ) {
		if ( ! current_user_can( AI_CONNECT_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ai-connect' ) );
		}
		$file = AI_CONNECT_DIR . 'admin/views/' . $view . '.php';
		if ( ! is_file( $file ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'View missing.', 'ai-connect' ) . '</p></div>';
			return;
		}
		include $file;
	}

	/**
	 * Overview page callback.
	 *
	 * @return void
	 */
	public static function render_overview() {
		self::render_page( 'overview' );
	}

	/**
	 * Connect AI page callback.
	 *
	 * @return void
	 */
	public static function render_connect() {
		$key   = self::NEW_TOKEN_TRANSIENT . get_current_user_id();
		$fresh = get_transient( $key );
		if ( false !== $fresh ) {
			// One-time display: drop the cached payload after it is rendered so
			// the banner can never be replayed. (The separate newest-token copy
			// used to pre-fill the prompts has its own lifecycle - see
			// Tokens::last_for_prompts().)
			delete_transient( $key );
		}
		self::render_page( 'connect', array( 'fresh_token' => is_array( $fresh ) ? $fresh : null ) );
	}

	/**
	 * Tools page callback.
	 *
	 * @return void
	 */
	public static function render_tools() {
		self::render_page( 'tools' );
	}

	/**
	 * Design System page callback.
	 *
	 * @return void
	 */
	public static function render_design() {
		self::render_page( 'design' );
	}

	/**
	 * Diagnostics page callback.
	 *
	 * @return void
	 */
	public static function render_diagnostics() {
		self::render_page( 'diagnostics' );
	}

	/**
	 * Security page callback.
	 *
	 * @return void
	 */
	public static function render_security() {
		self::render_page( 'security' );
	}

	/**
	 * Settings page callback.
	 *
	 * @return void
	 */
	public static function render_settings() {
		self::render_page( 'settings' );
	}

	/**
	 * Open the page wrapper and print the notice (if any).
	 *
	 * @param string $title     Page title.
	 * @param string $subtitle  One-line description.
	 * @param bool   $show_mark Whether to print the small brand mark next to the title.
	 * @return void
	 */
	public static function page_open( $title, $subtitle = '', $show_mark = true ) {
		echo '<div class="wrap sitw-wrap">';
		echo '<div class="sitw-brand-bar">';
		if ( $show_mark ) {
			echo '<img class="sitw-brand-mark" src="' . esc_url( AI_CONNECT_URL . 'assets/images/ai-connect-icon-96.png' ) . '" width="40" height="39" alt="" />';
		}
		echo '<div class="sitw-head"><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="sitw-sub">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		echo '</div>';
		self::render_notice();
	}

	/**
	 * Close the page wrapper.
	 *
	 * @return void
	 */
	public static function page_close() {
		echo '</div>';
	}

	/**
	 * Notices from the sitw_notice query arg.
	 *
	 * @return array|null {type, message}
	 */
	public static function notice() {
		$key = isset( $_GET['sitw_notice'] ) ? sanitize_key( wp_unslash( $_GET['sitw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'token_created'    => array( 'success', __( 'Connection token created. Copy it now: it is shown once, and only a hash is stored.', 'ai-connect' ) ),
			'token_revoked'    => array( 'success', __( 'The token was revoked. Clients using it will be rejected immediately.', 'ai-connect' ) ),
			'tokens_revoked'   => array( 'success', __( 'All active tokens were revoked.', 'ai-connect' ) ),
			'log_cleared'      => array( 'success', __( 'The activity log was cleared.', 'ai-connect' ) ),
			'saved'            => array( 'success', __( 'Changes saved.', 'ai-connect' ) ),
			'tools_saved'      => array( 'success', __( 'Tool selection saved. Disabled tools disappeared from tools/list immediately.', 'ai-connect' ) ),
			'design_saved'     => array( 'success', __( 'Design system saved.', 'ai-connect' ) ),
			'design_seeded'    => array( 'success', __( 'Design system rebuilt from the active theme.', 'ai-connect' ) ),
			'design_reset'     => array( 'success', __( 'Design system reset to defaults.', 'ai-connect' ) ),
			'sandbox_repaired' => array( 'success', __( 'Sandbox directory is ready.', 'ai-connect' ) ),
			'sandbox_cleared'  => array( 'success', __( 'Safe mode exited. The crash marker was removed; sandbox files load again.', 'ai-connect' ) ),
			'report_sent'      => array( 'success', __( 'Thank you — the report was sent to support@aymnali.com.', 'ai-connect' ) ),
			'report_failed'    => array( 'warn', __( 'The report could not be sent from this site. Please email support@aymnali.com directly.', 'ai-connect' ) ),
			'report_empty'     => array( 'warn', __( 'Write a short description of the problem first.', 'ai-connect' ) ),
		);
		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}
		return array(
			'type'    => $map[ $key ][0],
			'message' => $map[ $key ][1],
		);
	}

	/**
	 * Print the current notice.
	 *
	 * @return void
	 */
	public static function render_notice() {
		$notice = self::notice();
		if ( null === $notice ) {
			return;
		}
		printf(
			'<div class="sitw-notice sitw-notice-%s">%s</div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Print a status chip.
	 *
	 * @param string $label   Chip text.
	 * @param string $variant ok|warn|err|info|muted.
	 * @return void
	 */
	public static function chip( $label, $variant = 'muted' ) {
		printf( '<span class="sitw-chip sitw-chip-%s">%s</span>', esc_attr( $variant ), esc_html( $label ) );
	}

	/**
	 * Chip for a risk level.
	 *
	 * @param string $risk read|write|dangerous.
	 * @return void
	 */
	public static function risk_chip( $risk ) {
		$map = array(
			'read'      => 'info',
			'write'     => 'warn',
			'dangerous' => 'err',
		);
		self::chip( $risk, isset( $map[ $risk ] ) ? $map[ $risk ] : 'muted' );
	}

	/**
	 * Human local time for a MySQL UTC timestamp.
	 *
	 * @param string $mysql Timestamp.
	 * @return string
	 */
	public static function time_display( $mysql ) {
		$ts = Util::to_unix( (string) $mysql );
		if ( $ts <= 0 ) {
			return '—';
		}
		return date_i18n( 'M j, Y H:i', $ts );
	}

	/**
	 * Open a POST form that submits to admin-post.php with our action.
	 *
	 * @param string $action sitw_action value.
	 * @param string $page   Redirect sub-page.
	 * @param array  $attrs  Extra form attributes (e.g. class, data-sitw-confirm).
	 * @return void
	 */
	public static function action_form_open( $action, $page = '', $attrs = array() ) {
		$attr_html = '';
		foreach ( $attrs as $name => $value ) {
			$attr_html .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		printf(
			'<form method="post" action="%s"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$attr_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
		echo '<input type="hidden" name="action" value="ai_connect_action" />';
		printf( '<input type="hidden" name="sitw_action" value="%s" />', esc_attr( $action ) );
		printf( '<input type="hidden" name="sitw_redirect" value="%s" />', esc_attr( self::url( $page ) ) );
		wp_nonce_field( self::NONCE );
	}

	/**
	 * Active (usable) tokens.
	 *
	 * @return array
	 */
	public static function active_tokens() {
		$out = array();
		foreach ( Tokens::all() as $row ) {
			$status = self::token_status( $row );
			if ( 'Active' === $status[0] ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Display status for a token row.
	 *
	 * @param array $row Token row.
	 * @return array{0: string, 1: string} [label, chip variant]
	 */
	public static function token_status( array $row ) {
		if ( ! empty( $row['revoked_at'] ) && '0000-00-00 00:00:00' !== $row['revoked_at'] ) {
			return array( 'Revoked', 'muted' );
		}
		if ( Tokens::is_expired( $row ) ) {
			return array( 'Expired', 'warn' );
		}
		return array( 'Active', 'ok' );
	}

	/**
	 * Short, agent-facing prompts that let each supported client connect itself.
	 *
	 * Every prompt is self-contained: it carries the endpoint and token, shows
	 * the agent how to add Ai Connect to its own MCP configuration, and how to
	 * verify the result. Prompts stay plain English and are not translated.
	 *
	 * Entries flagged with 'soon' => true are cards for clients that cannot be
	 * configured yet; they carry no prompt and are rendered disabled.
	 *
	 * @param string $token Plaintext token, or '' to render <YOUR_TOKEN>.
	 * @return array List of ['id','label','where','prompt'] (+ 'soon' for planned clients).
	 */
	public static function agent_prompts( $token ) {
		$endpoint = self::endpoint_url();
		$secret   = '' !== (string) $token ? (string) $token : '<YOUR_TOKEN>';
		$auth     = 'Authorization: Bearer ' . $secret;

		$vscode_json = Util::json_compact(
			array(
				'servers' => array(
					'ai-connect' => array(
						'type'    => 'http',
						'url'     => $endpoint,
						'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
					),
				),
			)
		);

		$cursor_json = Util::json_compact(
			array(
				'mcpServers' => array(
					'ai-connect' => array(
						'url'     => $endpoint,
						'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
					),
				),
			)
		);

		$windsurf_json = Util::json_compact(
			array(
				'mcpServers' => array(
					'ai-connect' => array(
						'serverUrl' => $endpoint,
						'headers'   => array( 'Authorization' => 'Bearer ' . $secret ),
					),
				),
			)
		);

		$bridge_json = Util::json_compact(
			array(
				'mcpServers' => array(
					'ai-connect' => array(
						'command' => 'npx',
						'args'    => array( '-y', 'mcp-remote', $endpoint, '--header', $auth ),
					),
				),
			)
		);

		$opencode_json = Util::json_compact(
			array(
				'mcp' => array(
					'ai-connect' => array(
						'type'    => 'remote',
						'url'     => $endpoint,
						'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
						'enabled' => true,
					),
				),
			)
		);

		$codex_toml = '  [mcp_servers.ai-connect]' . "\n"
			. '  url = "' . $endpoint . '"' . "\n"
			. '  http_headers = { Authorization = "Bearer ' . $secret . '" }';

		$head        = 'Connect yourself to my WordPress site over MCP (Model Context Protocol).';
		$verify      = 'Then verify: call agent_context and report the site name, WordPress and PHP versions, and active modes.';
		$secret_note = 'The token above is a secret - never print it in replies or commit it to a repository.';

		return array(
			array(
				'id'     => 'claude-code',
				'label'  => 'Claude Code',
				'where'  => __( 'terminal CLI - installs itself by command', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'Run this once in your terminal to add the server to your own MCP configuration:',
						'  claude mcp add --transport http ai-connect ' . $endpoint . ' --header "' . $auth . '"',
						'',
						$verify,
						'If the new server is not available in this session yet, tell me to restart you.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'vscode',
				'label'  => 'VS Code / GitHub Copilot',
				'where'  => __( '.vscode/mcp.json (project or user)', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'Add this server to my VS Code MCP configuration - the .vscode/mcp.json file in this workspace, or "MCP: Open User Configuration" to enable it in every project - then reload the window:',
						'  ' . $vscode_json,
						'',
						$verify,
						'If you cannot edit MCP settings yourself, tell me exactly where to paste this.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'cursor',
				'label'  => 'Cursor',
				'where'  => '~/.cursor/mcp.json',
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'Add this server to ~/.cursor/mcp.json (create the file if it does not exist), then reload:',
						'  ' . $cursor_json,
						'',
						$verify,
						'If you cannot edit files on this machine, show me the exact file and snippet to paste.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'windsurf',
				'label'  => 'Windsurf',
				'where'  => '~/.codeium/windsurf/mcp_config.json',
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'Add this server to ~/.codeium/windsurf/mcp_config.json (or via the Windsurf MCP settings panel), then reload:',
						'  ' . $windsurf_json,
						'',
						$verify,
						'If you cannot edit files on this machine, show me the exact file and snippet to paste.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'claude-desktop',
				'label'  => 'Claude Desktop',
				'where'  => __( 'claude_desktop_config.json - via npx mcp-remote', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'I use Claude Desktop, which is a stdio-only client. Add this server to my claude_desktop_config.json using the mcp-remote bridge, then tell me to restart the app:',
						'  ' . $bridge_json,
						'',
						'If you cannot edit files on this computer, show me exactly where to paste this.',
						'After the restart, verify: call agent_context and report the site name, WordPress and PHP versions, and active modes.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'chatgpt-desktop',
				'label'  => 'ChatGPT Desktop',
				'where'  => __( 'shared Codex config (~/.codex/config.toml)', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'I use the ChatGPT desktop app; it reads MCP servers from the shared Codex config file (~/.codex/config.toml, or %USERPROFILE%\.codex\config.toml on Windows). Add this server block to that file (create it if it does not exist), save it, then tell me to restart the ChatGPT app:',
						$codex_toml,
						'',
						$verify,
						'If you cannot edit files on this computer, show me exactly where to paste this.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'antigravity',
				'label'  => 'Google Antigravity',
				'where'  => __( 'mcp_config.json - via npx mcp-remote', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'I use Google Antigravity, which is a stdio-only client. Add this server to my mcp_config.json (or via its MCP settings) using the mcp-remote bridge, then reload:',
						'  ' . $bridge_json,
						'',
						$verify,
						'If you cannot edit files on this computer, show me exactly where to paste this.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'opencode',
				'label'  => 'OpenCode',
				'where'  => __( 'opencode.json - project or ~/.config/opencode/', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head,
						'',
						'Add this server to your own configuration - opencode.json in this project, or ~/.config/opencode/opencode.json to enable it in every project - then restart:',
						'  ' . $opencode_json,
						'',
						$verify,
						'If you cannot edit your own configuration, tell me exactly which file to change.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'other',
				'label'  => __( 'Any other MCP client', 'ai-connect' ),
				'where'  => __( 'Streamable HTTP - native transport', 'ai-connect' ),
				'prompt' => implode(
					"\n",
					array(
						$head . ' Use Streamable HTTP (stateless, one JSON-RPC message per POST).',
						'',
						'Connection details:',
						'  URL:    ' . $endpoint,
						'  Header: ' . $auth,
						'',
						$verify,
						'If your client only speaks stdio, ask me for the npx mcp-remote bridge command.',
						$secret_note,
					)
				),
			),
			array(
				'id'     => 'chatgpt-web',
				'label'  => 'ChatGPT Web',
				'where'  => __( 'web app', 'ai-connect' ),
				'soon'   => true,
				'prompt' => '',
			),
			array(
				'id'     => 'gemini-web',
				'label'  => 'Gemini Web / Desktop',
				'where'  => __( 'web and desktop app', 'ai-connect' ),
				'soon'   => true,
				'prompt' => '',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Form handling
	 * ------------------------------------------------------------------- */

	/**
	 * Handle every admin form submission.
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! current_user_can( AI_CONNECT_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ai-connect' ) );
		}
		check_admin_referer( self::NONCE );

		$action   = isset( $_POST['sitw_action'] ) ? sanitize_key( wp_unslash( $_POST['sitw_action'] ) ) : '';
		$redirect = isset( $_POST['sitw_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['sitw_redirect'] ) ) : self::url();
		$notice   = 'saved';

		switch ( $action ) {
			case 'create_token':
				$created = Tokens::create(
					array(
						'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
						'user_id'   => get_current_user_id(),
						'days'      => isset( $_POST['days'] ) ? (int) $_POST['days'] : (int) Settings::get( 'default_token_days' ),
						'read_only' => ! empty( $_POST['read_only'] ),
					)
				);
				set_transient( self::NEW_TOKEN_TRANSIENT . get_current_user_id(), $created, 600 );
				$notice = 'token_created';
				break;

			case 'revoke_token':
				Tokens::revoke( isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0 );
				$notice = 'token_revoked';
				break;

			case 'revoke_all':
				Tokens::revoke_all();
				$notice = 'tokens_revoked';
				break;

			case 'clear_log':
				Log::clear();
				$notice = 'log_cleared';
				break;

			case 'save_modes':
				Settings::update(
					array(
						'read_only'             => ! empty( $_POST['read_only'] ),
						'allow_dangerous_tools' => ! empty( $_POST['allow_dangerous_tools'] ),
					)
				);
				$notice = 'saved';
				break;

			case 'save_settings':
				$patch = array();
				foreach ( array( 'default_token_days', 'log_retention', 'fs_read_max_bytes', 'fs_write_max_bytes', 'wp_cli_timeout' ) as $key ) {
					if ( isset( $_POST[ $key ] ) ) {
						$patch[ $key ] = (int) $_POST[ $key ];
					}
				}
				if ( isset( $_POST['fs_jail'] ) ) {
					$patch['fs_jail'] = sanitize_key( wp_unslash( $_POST['fs_jail'] ) );
				}
				if ( isset( $_POST['php_file_writes'] ) ) {
					$patch['php_file_writes'] = sanitize_key( wp_unslash( $_POST['php_file_writes'] ) );
				}
				if ( isset( $_POST['wp_cli_path'] ) ) {
					$patch['wp_cli_path'] = sanitize_text_field( wp_unslash( $_POST['wp_cli_path'] ) );
				}
				if ( isset( $_POST['protected_paths'] ) ) {
					$patch['protected_paths'] = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['protected_paths'] ) );
				}
				$patch['delete_data_on_uninstall'] = ! empty( $_POST['delete_data_on_uninstall'] );
				Settings::update( $patch );
				delete_transient( 'ai_connect_wpcli_status' );
				$notice = 'saved';
				break;

			case 'save_enabled':
				Settings::update( array( 'enabled' => ! empty( $_POST['enabled'] ) ) );
				$notice = 'saved';
				break;

			case 'report_problem':
				$message = isset( $_POST['report_message'] ) ? trim( (string) sanitize_textarea_field( wp_unslash( $_POST['report_message'] ) ) ) : '';
				if ( '' === $message ) {
					$notice = 'report_empty';
					break;
				}
				$user    = wp_get_current_user();
				$reply   = $user && $user->user_email ? (string) $user->user_email : '';
				$headers = array();
				if ( '' !== $reply ) {
					$headers[] = 'Reply-To: ' . $reply;
				}
				$sent = wp_mail(
					'support@aymnali.com',
					'[Ai Connect] Problem report from ' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					implode(
						"\n",
						array(
							'Ai Connect problem report',
							'',
							mb_substr( $message, 0, 5000 ),
							'',
							'--- Environment ---',
							'Site: ' . home_url(),
							'Admin: ' . ( '' !== $reply ? $reply : '(unknown)' ),
							'WordPress: ' . get_bloginfo( 'version' ),
							'Ai Connect: ' . AI_CONNECT_VERSION,
							'PHP: ' . PHP_VERSION,
						)
					),
					$headers
				);
				$notice = $sent ? 'report_sent' : 'report_failed';
				break;

			case 'save_tools':
				$enabled = array();
				if ( isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] ) ) {
					$enabled = array_map( 'sanitize_key', wp_unslash( $_POST['enabled'] ) );
				}
				$disabled = array();
				foreach ( array_keys( Registry::all() ) as $name ) {
					if ( ! in_array( $name, $enabled, true ) ) {
						$disabled[] = $name;
					}
				}
				Settings::update( array( 'disabled_tools' => $disabled ) );
				$notice = 'tools_saved';
				break;

			case 'save_design':
				$patch = array( 'source' => 'manual' );

				if ( isset( $_POST['design_name'] ) ) {
					$patch['name'] = sanitize_text_field( wp_unslash( $_POST['design_name'] ) );
				}
				if ( isset( $_POST['guidelines'] ) ) {
					$patch['guidelines'] = sanitize_textarea_field( wp_unslash( $_POST['guidelines'] ) );
				}
				if ( isset( $_POST['spacing'] ) ) {
					$patch['spacing'] = sanitize_textarea_field( wp_unslash( $_POST['spacing'] ) );
				}
				if ( isset( $_POST['radii'] ) ) {
					$patch['radii'] = sanitize_textarea_field( wp_unslash( $_POST['radii'] ) );
				}

				$colors = array();
				if ( isset( $_POST['color_name'] ) && is_array( $_POST['color_name'] ) ) {
					$names  = wp_unslash( $_POST['color_name'] );
					$values = isset( $_POST['color_value'] ) && is_array( $_POST['color_value'] ) ? wp_unslash( $_POST['color_value'] ) : array();
					foreach ( $names as $index => $name ) {
						$name  = sanitize_text_field( (string) $name );
						$value = isset( $values[ $index ] ) ? sanitize_text_field( (string) $values[ $index ] ) : '';
						if ( '' !== $name && '' !== $value ) {
							$colors[] = array(
								'name'  => $name,
								'value' => $value,
							);
						}
					}
					$patch['colors'] = $colors;
				}

				$typo = array();
				if ( isset( $_POST['typo_name'] ) && is_array( $_POST['typo_name'] ) ) {
					$names    = wp_unslash( $_POST['typo_name'] );
					$families = isset( $_POST['typo_family'] ) && is_array( $_POST['typo_family'] ) ? wp_unslash( $_POST['typo_family'] ) : array();
					$sizes    = isset( $_POST['typo_size'] ) && is_array( $_POST['typo_size'] ) ? wp_unslash( $_POST['typo_size'] ) : array();
					$weights  = isset( $_POST['typo_weight'] ) && is_array( $_POST['typo_weight'] ) ? wp_unslash( $_POST['typo_weight'] ) : array();
					$heights  = isset( $_POST['typo_line_height'] ) && is_array( $_POST['typo_line_height'] ) ? wp_unslash( $_POST['typo_line_height'] ) : array();
					foreach ( $names as $index => $name ) {
						$name = sanitize_text_field( (string) $name );
						if ( '' === $name ) {
							continue;
						}
						$typo[] = array(
							'name'        => $name,
							'family'      => isset( $families[ $index ] ) ? sanitize_text_field( (string) $families[ $index ] ) : '',
							'size'        => isset( $sizes[ $index ] ) ? sanitize_text_field( (string) $sizes[ $index ] ) : '',
							'weight'      => isset( $weights[ $index ] ) ? sanitize_text_field( (string) $weights[ $index ] ) : '',
							'line_height' => isset( $heights[ $index ] ) ? sanitize_text_field( (string) $heights[ $index ] ) : '',
						);
					}
					$patch['typography'] = $typo;
				}

				$components = array();
				if ( isset( $_POST['comp_name'] ) && is_array( $_POST['comp_name'] ) ) {
					$names = wp_unslash( $_POST['comp_name'] );
					$notes = isset( $_POST['comp_notes'] ) && is_array( $_POST['comp_notes'] ) ? wp_unslash( $_POST['comp_notes'] ) : array();
					foreach ( $names as $index => $name ) {
						$name = sanitize_text_field( (string) $name );
						if ( '' === $name ) {
							continue;
						}
						$components[] = array(
							'name'  => $name,
							'notes' => isset( $notes[ $index ] ) ? sanitize_textarea_field( (string) $notes[ $index ] ) : '',
						);
					}
					$patch['components'] = $components;
				}

				Design::update( $patch );
				$notice = 'design_saved';
				break;

			case 'seed_design':
				Design::seed_from_theme();
				$notice = 'design_seeded';
				break;

			case 'reset_design':
				Design::reset();
				$notice = 'design_reset';
				break;

			case 'sandbox_repair':
				Sandbox::ensure_dir();
				$notice = 'sandbox_repaired';
				break;

			case 'sandbox_clear_crash':
				Sandbox::clear_crash();
				$notice = 'sandbox_cleared';
				break;
		}

		wp_safe_redirect( add_query_arg( 'sitw_notice', $notice, $redirect ) );
		exit;
	}
}
