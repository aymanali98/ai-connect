<?php
/**
 * Security page: modes, token hygiene, activity log.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_log     = Log::recent( 50 );
$sitw_active  = Admin::active_tokens();
$sitw_total   = Log::count();

Admin::page_open(
	__( 'Security', 'ai-connect' ),
	__( 'Execution modes, token hygiene, and the activity log.', 'ai-connect' )
);
?>

<div class="sitw-grid">
	<div class="sitw-card">
		<h2><?php esc_html_e( 'Execution modes', 'ai-connect' ); ?></h2>
		<?php Admin::action_form_open( 'save_modes', 'security', array( 'class' => 'sitw-form' ) ); ?>
			<label class="sitw-check sitw-check-stack">
				<input type="checkbox" name="read_only" value="1" <?php checked( Settings::is_read_only() ); ?> />
				<span>
					<strong><?php esc_html_e( 'Read-only mode', 'ai-connect' ); ?></strong><br />
					<span class="sitw-hint"><?php esc_html_e( 'Only read-risk tools can run, for every token. Use this when an agent should inspect but not change anything.', 'ai-connect' ); ?></span>
				</span>
			</label>
			<label class="sitw-check sitw-check-stack">
				<input type="checkbox" name="allow_dangerous_tools" value="1" <?php checked( Settings::dangerous_allowed() ); ?> />
				<span>
					<strong><?php esc_html_e( 'Allow dangerous tools', 'ai-connect' ); ?></strong><br />
					<span class="sitw-hint"><?php esc_html_e( 'Enables run_php (arbitrary PHP), db_execute (SQL writes/DDL), run_wp_cli, install_plugin, and update_plugin. Turn off for purely content-focused sites.', 'ai-connect' ); ?></span>
				</span>
			</label>
			<div class="sitw-actions">
				<button type="submit" class="sitw-btn"><?php esc_html_e( 'Save modes', 'ai-connect' ); ?></button>
			</div>
		</form>
	</div>

	<div class="sitw-card">
		<h2><?php esc_html_e( 'Tokens', 'ai-connect' ); ?></h2>
		<p class="sitw-stat"><?php echo (int) count( $sitw_active ); ?></p>
		<p class="sitw-sub"><?php esc_html_e( 'active connection token(s)', 'ai-connect' ); ?></p>
		<p class="sitw-hint"><?php esc_html_e( 'Each token acts as its WordPress user: capability checks (manage_options) apply per request. Create and revoke tokens on the Connect AI page.', 'ai-connect' ); ?></p>
		<div class="sitw-actions">
			<a class="sitw-btn sitw-btn-secondary" href="<?php echo esc_url( Admin::url( 'connect' ) ); ?>"><?php esc_html_e( 'Manage tokens', 'ai-connect' ); ?></a>
			<?php if ( count( $sitw_active ) > 0 ) : ?>
				<?php Admin::action_form_open( 'revoke_all', 'security', array( 'class' => 'sitw-inline-form' ) ); ?>
					<button type="submit" class="sitw-btn sitw-btn-danger" data-sitw-confirm="<?php esc_attr_e( 'Revoke every active token? All connected clients will be disconnected.', 'ai-connect' ); ?>">
						<?php esc_html_e( 'Revoke all', 'ai-connect' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Recommendations', 'ai-connect' ); ?></h2>
	<ul class="sitw-list">
		<li><?php esc_html_e( 'Serve the site over HTTPS: tokens travel in the Authorization header.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'Use read-only tokens for content review, audits, and anything that should never write.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'Revoke tokens you no longer use; expired and revoked tokens are rejected immediately.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'Keep the dangerous-tools switch off unless the agent genuinely needs PHP, SQL writes, or WP-CLI.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'On production sites, prefer wp-config.php protection plus a staging copy: agents are powerful by design.', 'ai-connect' ); ?></li>
	</ul>
</div>

<div class="sitw-card">
	<h2>
		<?php esc_html_e( 'Activity log', 'ai-connect' ); ?>
		<span class="sitw-hint">
			<?php
			/* translators: %s: total row count. */
			printf( esc_html__( '%s total entries; newest 50 shown. Arguments are never captured, and secrets never appear here.', 'ai-connect' ), esc_html( number_format_i18n( $sitw_total ) ) );
			?>
		</span>
	</h2>

	<?php if ( empty( $sitw_log ) ) : ?>
		<p class="sitw-empty"><?php esc_html_e( 'The log is empty.', 'ai-connect' ); ?></p>
	<?php else : ?>
		<table class="sitw-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Tool', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Risk', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Result', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'User', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Token', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'ms', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'ai-connect' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitw_log as $sitw_row ) : ?>
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
						<td><?php echo (int) $sitw_row['user_id']; ?></td>
						<td><?php echo '' !== (string) $sitw_row['token_prefix'] ? '<code>' . esc_html( $sitw_row['token_prefix'] ) . '…</code>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?></td>
						<td><?php echo (int) $sitw_row['duration_ms']; ?></td>
						<td class="sitw-cell-sub"><?php echo esc_html( Util::truncate( (string) $sitw_row['detail'], 120 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="sitw-actions">
		<?php Admin::action_form_open( 'clear_log', 'security', array( 'class' => 'sitw-inline-form' ) ); ?>
			<button type="submit" class="sitw-btn sitw-btn-secondary" data-sitw-confirm="<?php esc_attr_e( 'Delete all log entries?', 'ai-connect' ); ?>">
				<?php esc_html_e( 'Clear log', 'ai-connect' ); ?>
			</button>
		</form>
	</div>
</div>

<?php
Admin::page_close();
