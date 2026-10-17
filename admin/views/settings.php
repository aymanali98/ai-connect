<?php
/**
 * Settings page.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_s = Settings::all();

Admin::page_open(
	__( 'Settings', 'ai-connect' ),
	__( 'File access, execution limits, tokens, and data handling.', 'ai-connect' )
);
?>

<p class="sitw-notice sitw-notice-info">
	<?php esc_html_e( 'Trusted development mode: Ai Connect hands an agent the same powers this administrator already has — files, database, PHP, and WP-CLI. It is protected by strong token authentication and the granular controls below, but it is not an agent sandbox: run it on development or staging sites you can restore, and keep tokens on machines you control.', 'ai-connect' ); ?>
</p>

<div class="sitw-card">
	<?php Admin::action_form_open( 'save_settings', 'settings', array( 'class' => 'sitw-form' ) ); ?>

		<h2><?php esc_html_e( 'Filesystem', 'ai-connect' ); ?></h2>
		<div class="sitw-form-grid">
			<label>
				<?php esc_html_e( 'Filesystem jail', 'ai-connect' ); ?>
				<select name="fs_jail">
					<option value="root" <?php selected( 'root', $sitw_s['fs_jail'] ); ?>><?php esc_html_e( 'WordPress root (ABSPATH)', 'ai-connect' ); ?></option>
					<option value="wp-content" <?php selected( 'wp-content', $sitw_s['fs_jail'] ); ?>><?php esc_html_e( 'wp-content only', 'ai-connect' ); ?></option>
				</select>
				<span class="sitw-hint"><?php esc_html_e( 'Base directory for relative paths and the boundary no path may escape.', 'ai-connect' ); ?></span>
			</label>
			<label>
				<?php esc_html_e( 'PHP file writes', 'ai-connect' ); ?>
				<select name="php_file_writes">
					<option value="sandbox" <?php selected( 'sandbox', $sitw_s['php_file_writes'] ); ?>><?php esc_html_e( 'Sandbox only (recommended)', 'ai-connect' ); ?></option>
					<option value="anywhere" <?php selected( 'anywhere', $sitw_s['php_file_writes'] ); ?>><?php esc_html_e( 'Anywhere in the jail (expert)', 'ai-connect' ); ?></option>
				</select>
				<span class="sitw-hint"><?php esc_html_e( 'Restricts writing executable .php to wp-content/sitewright-sandbox/. "Anywhere" lets agents edit plugin/theme PHP directly — only on disposable sites.', 'ai-connect' ); ?></span>
			</label>
		</div>

		<div class="sitw-form-grid">
			<label>
				<?php esc_html_e( 'Max file read (bytes)', 'ai-connect' ); ?>
				<input type="number" name="fs_read_max_bytes" min="1024" max="104857600" value="<?php echo (int) $sitw_s['fs_read_max_bytes']; ?>" />
				<span class="sitw-hint"><?php echo esc_html( Util::bytes( (int) $sitw_s['fs_read_max_bytes'] ) ); ?></span>
			</label>
			<label>
				<?php esc_html_e( 'Max file write (bytes)', 'ai-connect' ); ?>
				<input type="number" name="fs_write_max_bytes" min="1024" max="104857600" value="<?php echo (int) $sitw_s['fs_write_max_bytes']; ?>" />
				<span class="sitw-hint"><?php echo esc_html( Util::bytes( (int) $sitw_s['fs_write_max_bytes'] ) ); ?></span>
			</label>
		</div>

		<label class="sitw-field">
			<?php esc_html_e( 'Protected paths (one per line, relative to the jail base)', 'ai-connect' ); ?>
			<textarea name="protected_paths" rows="4"><?php echo esc_textarea( implode( "\n", (array) $sitw_s['protected_paths'] ) ); ?></textarea>
			<span class="sitw-hint"><?php esc_html_e( 'Filesystem tools refuse to read, write, or delete these paths. wp-config.php is protected by default.', 'ai-connect' ); ?></span>
		</label>

		<h2><?php esc_html_e( 'Execution', 'ai-connect' ); ?></h2>
		<div class="sitw-form-grid">
			<label>
				<?php esc_html_e( 'WP-CLI binary path', 'ai-connect' ); ?>
				<input type="text" name="wp_cli_path" value="<?php echo esc_attr( (string) $sitw_s['wp_cli_path'] ); ?>" placeholder="wp" />
				<span class="sitw-hint"><?php esc_html_e( 'Leave empty to use "wp" from PATH. Detection is cached for 5 minutes.', 'ai-connect' ); ?></span>
			</label>
			<label>
				<?php esc_html_e( 'WP-CLI timeout (seconds)', 'ai-connect' ); ?>
				<input type="number" name="wp_cli_timeout" min="5" max="600" value="<?php echo (int) $sitw_s['wp_cli_timeout']; ?>" />
			</label>
		</div>

		<h2><?php esc_html_e( 'Tokens and log', 'ai-connect' ); ?></h2>
		<div class="sitw-form-grid">
			<label>
				<?php esc_html_e( 'Default token lifetime (days)', 'ai-connect' ); ?>
				<input type="number" name="default_token_days" min="0" max="3650" value="<?php echo (int) $sitw_s['default_token_days']; ?>" />
				<span class="sitw-hint"><?php esc_html_e( 'Used as the pre-filled value when creating tokens. 0 = never expires.', 'ai-connect' ); ?></span>
			</label>
			<label>
				<?php esc_html_e( 'Log retention (rows)', 'ai-connect' ); ?>
				<input type="number" name="log_retention" min="50" max="10000" value="<?php echo (int) $sitw_s['log_retention']; ?>" />
				<span class="sitw-hint"><?php esc_html_e( 'Older entries are pruned automatically.', 'ai-connect' ); ?></span>
			</label>
		</div>

		<h2><?php esc_html_e( 'Data', 'ai-connect' ); ?></h2>
		<label class="sitw-check sitw-check-stack">
			<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( (bool) $sitw_s['delete_data_on_uninstall'] ); ?> />
			<span>
				<strong><?php esc_html_e( 'Delete all Ai Connect data on uninstall', 'ai-connect' ); ?></strong><br />
				<span class="sitw-hint"><?php esc_html_e( 'Removes the tokens, log, and jobs tables, both option families (current and pre-1.1.0), cached token transients, and sandbox files when the plugin is deleted. Off by default.', 'ai-connect' ); ?></span>
			</span>
		</label>

		<div class="sitw-actions">
			<button type="submit" class="sitw-btn"><?php esc_html_e( 'Save settings', 'ai-connect' ); ?></button>
		</div>
	</form>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'What Ai Connect stores', 'ai-connect' ); ?></h2>
	<ul class="sitw-list">
		<li><?php printf( /* translators: %s: table name. */ esc_html__( '%s — connection tokens (SHA-256 hashes; the newest token is also kept to pre-fill the connection prompts until it is replaced or revoked).', 'ai-connect' ), '<code>' . esc_html( DB::tokens_table() ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
		<li><?php printf( /* translators: %s: table name. */ esc_html__( '%s — the bounded activity log (tool name, risk, result, timing, acting user/token prefix; never arguments or secrets).', 'ai-connect' ), '<code>' . esc_html( DB::log_table() ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
		<li><?php printf( /* translators: %s: table name. */ esc_html__( '%s — background jobs started by agents (tool, arguments, status, result; visible only to the token that started them).', 'ai-connect' ), '<code>' . esc_html( DB::jobs_table() ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
		<li><?php printf( /* translators: %s: option name. */ esc_html__( '%s — plugin settings (this page).', 'ai-connect' ), '<code>' . esc_html( AI_CONNECT_OPTION ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
		<li><?php printf( /* translators: %s: option name. */ esc_html__( '%s — the design system document.', 'ai-connect' ), '<code>' . esc_html( AI_CONNECT_DESIGN_OPTION ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
		<li><?php printf( /* translators: %s: directory name. */ esc_html__( 'wp-content/%s — PHP files written by agents, loaded with crash recovery.', 'ai-connect' ), esc_html( Sandbox::DIRNAME ) ); ?></li>
	</ul>
</div>

<?php
Admin::page_close();
