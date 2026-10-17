<?php
/**
 * Diagnostics page: live checks plus sandbox management.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_sandbox = Sandbox::status();

Admin::page_open(
	__( 'Diagnostics', 'ai-connect' ),
	__( 'Environment checks with actionable fixes, plus PHP sandbox management.', 'ai-connect' )
);
?>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Environment checks', 'ai-connect' ); ?></h2>
	<div class="sitw-toolbar">
		<button type="button" class="sitw-btn" id="sitw-run-diagnostics"><?php esc_html_e( 'Run checks', 'ai-connect' ); ?></button>
		<span id="sitw-diag-status" class="sitw-hint"></span>
	</div>
	<div id="sitw-diag-results">
		<p class="sitw-empty"><?php esc_html_e( 'Click "Run checks" to test this environment.', 'ai-connect' ); ?></p>
	</div>
	<div class="sitw-actions">
		<button type="button" class="sitw-btn sitw-btn-secondary sitw-copy-btn" id="sitw-copy-diag" data-sitw-copy="#sitw-diag-json" disabled>
			<?php esc_html_e( 'Copy full report (JSON)', 'ai-connect' ); ?>
		</button>
	</div>
	<pre class="sitw-pre sitw-hidden" id="sitw-diag-json"></pre>
	<p class="sitw-hint"><?php esc_html_e( 'Slow checks (loopback REST request, WP-CLI detection) are included. The same report is available to agents through the diagnostics tool.', 'ai-connect' ); ?></p>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'PHP sandbox', 'ai-connect' ); ?></h2>
	<p>
		<?php
		$sitw_sandbox['exists'] ? Admin::chip( __( 'directory exists', 'ai-connect' ), 'ok' ) : Admin::chip( __( 'missing', 'ai-connect' ), 'warn' );
		if ( $sitw_sandbox['exists'] ) {
			$sitw_sandbox['writable'] ? Admin::chip( __( 'writable', 'ai-connect' ), 'ok' ) : Admin::chip( __( 'not writable', 'ai-connect' ), 'err' );
		}
		$sitw_sandbox['safe_mode'] ? Admin::chip( __( 'SAFE MODE', 'ai-connect' ), 'err' ) : Admin::chip( __( 'operational', 'ai-connect' ), 'ok' );
		printf( '<span class="sitw-chip sitw-chip-info">%s: %s</span>', esc_html__( 'PHP write policy', 'ai-connect' ), esc_html( (string) $sitw_sandbox['policy'] ) );
		?>
	</p>
	<p class="sitw-hint"><code><?php echo esc_html( $sitw_sandbox['dir'] ); ?></code></p>

	<?php if ( $sitw_sandbox['safe_mode'] && is_array( $sitw_sandbox['crash'] ) ) : ?>
		<?php if ( isset( $sitw_sandbox['crash']['message'] ) ) : ?>
			<pre class="sitw-pre sitw-pre-error"><?php echo esc_html( (string) $sitw_sandbox['crash']['message'] ); ?></pre>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! empty( $sitw_sandbox['files'] ) ) : ?>
		<h3><?php esc_html_e( 'Sandbox files', 'ai-connect' ); ?></h3>
		<ul class="sitw-list">
			<?php foreach ( $sitw_sandbox['files'] as $sitw_file ) : ?>
				<li><code><?php echo esc_html( $sitw_file ); ?></code></li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="sitw-empty"><?php esc_html_e( 'No sandbox files yet. PHP files written by agents land here.', 'ai-connect' ); ?></p>
	<?php endif; ?>

	<div class="sitw-actions">
		<?php Admin::action_form_open( 'sandbox_repair', 'diagnostics', array( 'class' => 'sitw-inline-form' ) ); ?>
			<button type="submit" class="sitw-btn sitw-btn-secondary"><?php esc_html_e( 'Repair sandbox', 'ai-connect' ); ?></button>
		</form>
		<?php if ( $sitw_sandbox['safe_mode'] ) : ?>
			<?php Admin::action_form_open( 'sandbox_clear_crash', 'diagnostics', array( 'class' => 'sitw-inline-form' ) ); ?>
				<button type="submit" class="sitw-btn sitw-btn-danger" data-sitw-confirm="<?php esc_attr_e( 'Remove the crash marker and load sandbox files again?', 'ai-connect' ); ?>">
					<?php esc_html_e( 'Exit safe mode', 'ai-connect' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>
	<p class="sitw-hint"><?php esc_html_e( 'Sandbox PHP files are loaded on every request with crash recovery: if one of them crashes PHP while loading, it is flagged and the rest keep working (safe mode).', 'ai-connect' ); ?></p>
</div>

<?php
Admin::page_close();
