<?php
/**
 * Overview page.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_tokens  = Admin::active_tokens();
$sitw_recent  = Log::recent( 5 );
$sitw_enabled = count( Registry::enabled() );
$sitw_total   = count( Registry::all() );
$sitw_sandbox = Sandbox::status();
$sitw_env     = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
$sitw_theme   = wp_get_theme();
$sitw_last    = '';
foreach ( $sitw_tokens as $sitw_token ) {
	if ( ! empty( $sitw_token['last_used_at'] ) ) {
		$sitw_stamp = Util::to_unix( $sitw_token['last_used_at'] );
		if ( $sitw_stamp > Util::to_unix( $sitw_last ) ) {
			$sitw_last = $sitw_token['last_used_at'];
		}
	}
}

Admin::page_open(
	__( 'Ai Connect', 'ai-connect' ),
	__( 'Connect AI coding agents to this WordPress site over MCP.', 'ai-connect' ),
	false
);
?>

<div class="sitw-hero">
	<img class="sitw-hero-logo" src="<?php echo esc_url( AI_CONNECT_URL . 'assets/images/ai-connect-logo-branding.png' ); ?>" width="574" height="361" alt="<?php esc_attr_e( 'Ai Connect', 'ai-connect' ); ?>" />
</div>

<?php if ( $sitw_sandbox['safe_mode'] ) : ?>
	<div class="sitw-card sitw-card-alert">
		<h2><?php esc_html_e( 'Safe mode is active', 'ai-connect' ); ?></h2>
		<p>
			<?php
			$sitw_crash_file = is_array( $sitw_sandbox['crash'] ) && isset( $sitw_sandbox['crash']['sandbox_file'] )
				? basename( (string) $sitw_sandbox['crash']['sandbox_file'] )
				: __( 'an unknown file', 'ai-connect' );
			$sitw_crash_msg  = is_array( $sitw_sandbox['crash'] ) && isset( $sitw_sandbox['crash']['message'] )
				? (string) $sitw_sandbox['crash']['message']
				: '';
			printf(
				/* translators: %s: sandbox file name. */
				esc_html__( '%s crashed PHP while it was being loaded, so sandbox files are paused to protect the site.', 'ai-connect' ),
				'<code>' . esc_html( $sitw_crash_file ) . '</code>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
			?>
		</p>
		<?php if ( '' !== $sitw_crash_msg ) : ?>
			<pre class="sitw-pre sitw-pre-error"><?php echo esc_html( $sitw_crash_msg ); ?></pre>
		<?php endif; ?>
		<div class="sitw-actions">
			<?php Admin::action_form_open( 'sandbox_clear_crash' ); ?>
				<button type="submit" class="sitw-btn sitw-btn-danger" data-sitw-confirm="<?php esc_attr_e( 'Remove the crash marker and load sandbox files again?', 'ai-connect' ); ?>">
					<?php esc_html_e( 'Exit safe mode', 'ai-connect' ); ?>
				</button>
			</form>
			<a class="sitw-btn sitw-btn-secondary" href="<?php echo esc_url( Admin::url( 'diagnostics' ) ); ?>">
				<?php esc_html_e( 'Open Diagnostics', 'ai-connect' ); ?>
			</a>
		</div>
	</div>
<?php endif; ?>

<div class="sitw-grid">
	<div class="sitw-card">
		<h2><?php esc_html_e( 'Connection', 'ai-connect' ); ?></h2>
		<p class="sitw-stat"><?php echo (int) count( $sitw_tokens ); ?></p>
		<p class="sitw-sub"><?php esc_html_e( 'active connection token(s)', 'ai-connect' ); ?></p>
		<?php if ( '' !== $sitw_last ) : ?>
			<p class="sitw-sub">
				<?php
				/* translators: %s: timestamp. */
				printf( esc_html__( 'Last used %s', 'ai-connect' ), esc_html( Admin::time_display( $sitw_last ) ) );
				?>
			</p>
		<?php endif; ?>
		<div class="sitw-actions">
			<a class="sitw-btn" href="<?php echo esc_url( Admin::url( 'connect' ) ); ?>"><?php esc_html_e( 'Connect AI', 'ai-connect' ); ?></a>
		</div>
	</div>

	<div class="sitw-card">
		<h2><?php esc_html_e( 'Modes', 'ai-connect' ); ?></h2>
		<p>
			<?php esc_html_e( 'Ai Connect:', 'ai-connect' ); ?>
			<?php Settings::enabled() ? Admin::chip( __( 'ON', 'ai-connect' ), 'ok' ) : Admin::chip( __( 'off', 'ai-connect' ), 'warn' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Read-only:', 'ai-connect' ); ?>
			<?php Settings::is_read_only() ? Admin::chip( __( 'ON', 'ai-connect' ), 'warn' ) : Admin::chip( __( 'off', 'ai-connect' ), 'muted' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Dangerous tools:', 'ai-connect' ); ?>
			<?php Settings::dangerous_allowed() ? Admin::chip( __( 'enabled', 'ai-connect' ), 'warn' ) : Admin::chip( __( 'disabled', 'ai-connect' ), 'muted' ); ?>
		</p>
		<div class="sitw-actions">
			<a class="sitw-btn sitw-btn-secondary" href="<?php echo esc_url( Admin::url( 'security' ) ); ?>"><?php esc_html_e( 'Manage modes', 'ai-connect' ); ?></a>
		</div>
	</div>

	<div class="sitw-card">
		<h2><?php esc_html_e( 'Site', 'ai-connect' ); ?></h2>
		<p class="sitw-sub">
			<?php echo esc_html( 'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION ); ?><br />
			<?php echo esc_html( $sitw_theme->get( 'Name' ) ); ?>
		</p>
		<p>
			<?php Admin::chip( $sitw_env, 'production' === $sitw_env ? 'warn' : 'info' ); ?>
			<?php is_ssl() ? Admin::chip( 'HTTPS', 'ok' ) : Admin::chip( 'HTTP', 'err' ); ?>
		</p>
		<div class="sitw-actions">
			<a class="sitw-btn sitw-btn-secondary" href="<?php echo esc_url( Admin::url( 'diagnostics' ) ); ?>"><?php esc_html_e( 'Run diagnostics', 'ai-connect' ); ?></a>
		</div>
	</div>

	<div class="sitw-card">
		<h2><?php esc_html_e( 'Tools', 'ai-connect' ); ?></h2>
		<p class="sitw-stat"><?php echo (int) $sitw_enabled; ?><span class="sitw-stat-of">/<?php echo (int) $sitw_total; ?></span></p>
		<p class="sitw-sub"><?php esc_html_e( 'enabled MCP tools', 'ai-connect' ); ?></p>
		<div class="sitw-actions">
			<a class="sitw-btn sitw-btn-secondary" href="<?php echo esc_url( Admin::url( 'tools' ) ); ?>"><?php esc_html_e( 'Manage tools', 'ai-connect' ); ?></a>
		</div>
	</div>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Get started', 'ai-connect' ); ?></h2>
	<ol class="sitw-steps">
		<li><?php esc_html_e( 'Create a connection token on the Connect AI page.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'Paste the ready-made configuration into your AI client (Claude Code, VS Code, Cursor, Windsurf, Antigravity, ...).', 'ai-connect' ); ?></li>
		<li><?php printf( /* translators: %s: tool name. */ esc_html__( 'Ask the agent to call %s first: it returns environment info, modes, and design guidance.', 'ai-connect' ), '<code>agent_context</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
	</ol>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Recent activity', 'ai-connect' ); ?></h2>
	<?php if ( empty( $sitw_recent ) ) : ?>
		<p class="sitw-empty"><?php esc_html_e( 'No tool calls recorded yet. Activity appears here once an agent connects.', 'ai-connect' ); ?></p>
	<?php else : ?>
		<table class="sitw-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Tool', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Risk', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Result', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'ai-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitw_recent as $sitw_row ) : ?>
					<tr>
						<td><?php echo esc_html( Admin::time_display( $sitw_row['created_at'] ) ); ?></td>
						<td><code><?php echo esc_html( $sitw_row['tool'] ); ?></code></td>
						<td><?php Admin::risk_chip( (string) $sitw_row['risk'] ); ?></td>
						<td>
							<?php
							$sitw_variant = 'ok' === $sitw_row['status'] ? 'ok' : ( 'blocked' === $sitw_row['status'] ? 'warn' : 'err' );
							Admin::chip( (string) $sitw_row['status'], $sitw_variant );
							?>
						</td>
						<td class="sitw-cell-sub"><?php echo esc_html( Util::truncate( (string) $sitw_row['detail'], 90 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<div class="sitw-actions">
			<a class="sitw-btn sitw-btn-secondary" href="<?php echo esc_url( Admin::url( 'security' ) ); ?>"><?php esc_html_e( 'Full log', 'ai-connect' ); ?></a>
		</div>
	<?php endif; ?>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Report a problem', 'ai-connect' ); ?></h2>
	<p><?php esc_html_e( 'Something broken or confusing? Describe it below - it is emailed straight to support@aymnali.com, with your site details attached so the problem is easier to reproduce and fix.', 'ai-connect' ); ?></p>
	<?php Admin::action_form_open( 'report_problem', '', array( 'class' => 'sitw-form' ) ); ?>
		<label class="sitw-field">
			<?php esc_html_e( 'What happened?', 'ai-connect' ); ?>
			<textarea name="report_message" rows="4" maxlength="5000" placeholder="<?php esc_attr_e( 'What did you do, what did you expect, and what happened instead?', 'ai-connect' ); ?>"></textarea>
		</label>
		<div class="sitw-actions">
			<button type="submit" class="sitw-btn"><?php esc_html_e( 'Send report', 'ai-connect' ); ?></button>
			<span class="sitw-hint"><?php esc_html_e( 'Site URL, WordPress, PHP, and plugin versions are attached automatically.', 'ai-connect' ); ?></span>
		</div>
	</form>
</div>

<?php
Admin::page_close();
