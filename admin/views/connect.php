<?php
/**
 * Connect AI page: tokens, endpoint, ready-made agent prompts.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_fresh    = isset( $data['fresh_token'] ) && is_array( $data['fresh_token'] ) ? $data['fresh_token'] : null;
$sitw_token    = $sitw_fresh ? (string) $sitw_fresh['token'] : Tokens::last_for_prompts();
$sitw_endpoint = Admin::endpoint_url();
$sitw_tokens   = Tokens::all();
$sitw_active   = Admin::active_tokens();
$sitw_agents   = Admin::agent_prompts( $sitw_token );

// Token occurrences inside the prompts are wrapped so they render in red.
$sitw_secret_esc = esc_html( '' !== $sitw_token ? $sitw_token : '<YOUR_TOKEN>' );

Admin::page_open(
	__( 'Connect AI', 'ai-connect' ),
	__( 'Create a connection token, then copy your agent\'s prompt so it can connect itself.', 'ai-connect' )
);
?>

<?php if ( ! Settings::enabled() ) : ?>
	<p class="sitw-notice sitw-notice-warn">
		<strong><?php esc_html_e( 'Ai Connect is turned off.', 'ai-connect' ); ?></strong>
		<?php esc_html_e( 'Agents cannot connect until you turn it back on — use the Enable card on this page.', 'ai-connect' ); ?>
	</p>
<?php endif; ?>

<div class="sitw-card">
	<h2 class="sitw-card-title">
		<span class="dashicons dashicons-info" aria-hidden="true"></span>
		<?php esc_html_e( 'Enable Ai Connect', 'ai-connect' ); ?>
	</h2>
	<?php Admin::action_form_open( 'save_enabled', 'connect', array( 'class' => 'sitw-form' ) ); ?>
		<label class="sitw-check sitw-check-stack">
			<input type="checkbox" name="enabled" value="1" <?php checked( Settings::enabled() ); ?> />
			<span>
				<strong><?php esc_html_e( 'Turn on Ai Connect for this site', 'ai-connect' ); ?></strong>
			</span>
		</label>
		<p class="sitw-enable-note">
			<strong class="sitw-enable-danger"><?php esc_html_e( 'Note:', 'ai-connect' ); ?></strong>
			<?php esc_html_e( 'When enabled, AI agents can execute PHP code and perform filesystem operations on this site. For development and staging environments only. Always keep backups.', 'ai-connect' ); ?>
		</p>
		<p class="sitw-enable-note">
			<?php esc_html_e( 'Use Ai Connect with a capable model and set your AI tool to ask for confirmation before every action. Read what the agent is about to do before approving.', 'ai-connect' ); ?>
		</p>
		<?php if ( ! Settings::enabled() ) : ?>
			<p class="sitw-hint"><?php esc_html_e( 'Currently off: connection attempts get a clear "turned off" error, and background jobs will not run.', 'ai-connect' ); ?></p>
		<?php endif; ?>
		<div class="sitw-actions">
			<button type="submit" class="sitw-btn"><?php esc_html_e( 'Save settings', 'ai-connect' ); ?></button>
		</div>
	</form>
</div>

<?php if ( $sitw_fresh ) : ?>
	<div class="sitw-card sitw-card-highlight">
		<h2><?php esc_html_e( 'Your new token — copy it now', 'ai-connect' ); ?></h2>
		<p><?php esc_html_e( 'This is the only time the full token is shown. Authentication keeps only a SHA-256 hash of it - a copy stays in the connection prompts below until you generate a new token or revoke this one.', 'ai-connect' ); ?></p>
		<div class="sitw-codewrap">
			<code class="sitw-secret" id="sitw-fresh-token"><?php echo esc_html( $sitw_token ); ?></code>
			<button type="button" class="sitw-btn sitw-btn-secondary sitw-copy-btn" data-sitw-copy="#sitw-fresh-token"><?php esc_html_e( 'Copy', 'ai-connect' ); ?></button>
		</div>
		<p class="sitw-sub">
			<?php
			printf(
				/* translators: 1: token name, 2: read-only state, 3: expiry. */
				esc_html__( 'Name: %1$s · Read-only: %2$s · Expires: %3$s', 'ai-connect' ),
				esc_html( (string) $sitw_fresh['name'] ),
				esc_html( ! empty( $sitw_fresh['read_only'] ) ? __( 'yes', 'ai-connect' ) : __( 'no', 'ai-connect' ) ),
				esc_html( ! empty( $sitw_fresh['expires_at'] ) ? Admin::time_display( $sitw_fresh['expires_at'] ) : __( 'never', 'ai-connect' ) )
			);
			?>
		</p>
		<div class="sitw-actions">
			<button type="button" class="sitw-btn" id="sitw-test-now" data-token="<?php echo esc_attr( $sitw_token ); ?>">
				<?php esc_html_e( 'Test this connection now', 'ai-connect' ); ?>
			</button>
		</div>
		<div id="sitw-test-result" class="sitw-test-result" hidden></div>
	</div>
<?php endif; ?>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Create a connection token', 'ai-connect' ); ?></h2>
	<?php Admin::action_form_open( 'create_token', 'connect', array( 'class' => 'sitw-form' ) ); ?>
		<div class="sitw-form-grid">
			<label>
				<?php esc_html_e( 'Label', 'ai-connect' ); ?>
				<input type="text" name="name" placeholder="<?php esc_attr_e( 'Claude Code — laptop', 'ai-connect' ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Valid for (days)', 'ai-connect' ); ?>
				<input type="number" name="days" min="0" max="3650" value="<?php echo (int) Settings::get( 'default_token_days' ); ?>" />
				<span class="sitw-hint"><?php esc_html_e( '0 = never expires', 'ai-connect' ); ?></span>
			</label>
		</div>
		<label class="sitw-check">
			<input type="checkbox" name="read_only" value="1" />
			<span><?php esc_html_e( 'Read-only token', 'ai-connect' ); ?> <span class="sitw-hint"><?php esc_html_e( 'Only read-risk tools may run. Recommended for content and audit work.', 'ai-connect' ); ?></span></span>
		</label>
		<p class="sitw-hint"><?php esc_html_e( 'The token acts as your WordPress user; capability checks use your account.', 'ai-connect' ); ?></p>
		<div class="sitw-actions">
			<button type="submit" class="sitw-btn"><?php esc_html_e( 'Create token', 'ai-connect' ); ?></button>
		</div>
	</form>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Existing tokens', 'ai-connect' ); ?></h2>
	<?php if ( empty( $sitw_tokens ) ) : ?>
		<p class="sitw-empty"><?php esc_html_e( 'No tokens yet. Create one above to connect your first client.', 'ai-connect' ); ?></p>
	<?php else : ?>
		<table class="sitw-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Token', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Access', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Created', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Expires', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Last used', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-connect' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitw_tokens as $sitw_row ) : ?>
					<?php $sitw_status = Admin::token_status( $sitw_row ); ?>
					<tr>
						<td><?php echo esc_html( '' !== $sitw_row['name'] ? $sitw_row['name'] : '—' ); ?></td>
						<td><code><?php echo esc_html( $sitw_row['token_prefix'] ); ?>…</code></td>
						<td>
							<?php
							$sitw_row['read_only']
								? Admin::chip( __( 'read-only', 'ai-connect' ), 'info' )
								: Admin::chip( __( 'read + write', 'ai-connect' ), 'warn' );
							?>
						</td>
						<td><?php echo esc_html( Admin::time_display( $sitw_row['created_at'] ) ); ?></td>
						<td><?php echo esc_html( ! empty( $sitw_row['expires_at'] ) ? Admin::time_display( $sitw_row['expires_at'] ) : __( 'never', 'ai-connect' ) ); ?></td>
						<td><?php echo esc_html( ! empty( $sitw_row['last_used_at'] ) ? Admin::time_display( $sitw_row['last_used_at'] ) : '—' ); ?></td>
						<td><?php Admin::chip( $sitw_status[0], $sitw_status[1] ); ?></td>
						<td>
							<?php if ( 'Active' === $sitw_status[0] ) : ?>
								<?php Admin::action_form_open( 'revoke_token', 'connect', array( 'class' => 'sitw-inline-form' ) ); ?>
									<input type="hidden" name="token_id" value="<?php echo (int) $sitw_row['id']; ?>" />
									<button type="submit" class="sitw-btn sitw-btn-danger sitw-btn-sm" data-sitw-confirm="<?php esc_attr_e( 'Revoke this token? Clients using it will be rejected immediately.', 'ai-connect' ); ?>">
										<?php esc_html_e( 'Revoke', 'ai-connect' ); ?>
									</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( count( $sitw_active ) > 1 ) : ?>
			<div class="sitw-actions">
				<?php Admin::action_form_open( 'revoke_all', 'connect', array( 'class' => 'sitw-inline-form' ) ); ?>
					<button type="submit" class="sitw-btn sitw-btn-danger" data-sitw-confirm="<?php esc_attr_e( 'Revoke every active token? All connected clients will be disconnected.', 'ai-connect' ); ?>">
						<?php esc_html_e( 'Revoke all active tokens', 'ai-connect' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'MCP endpoint', 'ai-connect' ); ?></h2>
	<div class="sitw-codewrap">
		<code id="sitw-endpoint"><?php echo esc_html( $sitw_endpoint ); ?></code>
		<button type="button" class="sitw-btn sitw-btn-secondary sitw-copy-btn" data-sitw-copy="#sitw-endpoint"><?php esc_html_e( 'Copy', 'ai-connect' ); ?></button>
	</div>
	<?php if ( Admin::plain_permalinks() ) : ?>
		<p class="sitw-hint"><?php esc_html_e( 'This site uses plain permalinks, so the endpoint contains ?rest_route=. MCP clients accept that form as-is; pretty permalinks are purely cosmetic here.', 'ai-connect' ); ?></p>
	<?php endif; ?>
	<p class="sitw-hint"><?php esc_html_e( 'Transport: Streamable HTTP (stateless). Protocols supported: 2025-06-18, 2025-03-26, 2024-11-05.', 'ai-connect' ); ?></p>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Fastest: let your AI connect itself', 'ai-connect' ); ?></h2>

	<?php if ( '' !== $sitw_token ) : ?>
		<p class="sitw-notice sitw-notice-success"><?php esc_html_e( 'The last token you generated is added automatically to every prompt below - you can see it in red. If you want to change it, generate a new token or paste another token over the red text.', 'ai-connect' ); ?></p>
	<?php else : ?>
		<p class="sitw-notice sitw-notice-warn"><?php esc_html_e( 'Add your token first: create one above - the last token you generate is added to every prompt below automatically. You can always replace the red text with a different token.', 'ai-connect' ); ?></p>
	<?php endif; ?>

	<p><?php esc_html_e( 'Pick your agent, open it, and copy its short prompt. Every prompt already contains this site\'s endpoint and token, so the agent adds Ai Connect to its own MCP configuration and proves the connection by itself.', 'ai-connect' ); ?></p>
	<ol class="sitw-list sitw-steps">
		<li><?php esc_html_e( 'Create a token above if you have not already - the most recent token is filled into every prompt automatically.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'Open your agent below, click "Copy prompt", paste the whole text into its chat, and send it.', 'ai-connect' ); ?></li>
		<li><?php esc_html_e( 'The agent configures itself and verifies the connection with agent_context. If it cannot edit its own settings, it will tell you exactly which file to change.', 'ai-connect' ); ?></li>
	</ol>
	<?php foreach ( $sitw_agents as $sitw_agent ) : ?>
		<?php if ( ! empty( $sitw_agent['soon'] ) ) : ?>
			<div class="sitw-details sitw-details-soon">
				<div class="sitw-soon-summary">
					<span><?php echo esc_html( $sitw_agent['label'] ); ?></span>
					<span class="sitw-hint">- <?php echo esc_html( $sitw_agent['where'] ); ?></span>
					<?php Admin::chip( __( 'Coming Soon', 'ai-connect' ), 'info' ); ?>
				</div>
			</div>
			<?php continue; ?>
		<?php endif; ?>
		<?php
		$sitw_prompt_html = esc_html( $sitw_agent['prompt'] );
		$sitw_prompt_html = str_replace(
			$sitw_secret_esc,
			'<span class="sitw-token-mark">' . $sitw_secret_esc . '</span>',
			$sitw_prompt_html
		);
		?>
		<details class="sitw-details">
			<summary>
				<?php echo esc_html( $sitw_agent['label'] ); ?>
				<span class="sitw-hint">- <?php echo esc_html( $sitw_agent['where'] ); ?></span>
			</summary>
			<div class="sitw-codewrap">
				<pre class="sitw-pre sitw-pre-prompt" id="sitw-prompt-<?php echo esc_attr( $sitw_agent['id'] ); ?>"><?php echo $sitw_prompt_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_html() output; only the token-mark span is added. ?></pre>
				<button type="button" class="sitw-btn sitw-btn-secondary sitw-copy-btn" data-sitw-copy="#sitw-prompt-<?php echo esc_attr( $sitw_agent['id'] ); ?>"><?php esc_html_e( 'Copy prompt', 'ai-connect' ); ?></button>
			</div>
		</details>
	<?php endforeach; ?>
</div>

<?php
Admin::page_close();
