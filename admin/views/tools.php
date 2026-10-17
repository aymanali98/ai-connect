<?php
/**
 * Tools page: per-tool enable/disable.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_groups = array();
foreach ( Registry::all() as $sitw_name => $sitw_def ) {
	$sitw_groups[ $sitw_def['group'] ][ $sitw_name ] = $sitw_def;
}

$sitw_labels = array(
	'meta'     => __( 'Meta', 'ai-connect' ),
	'site'     => __( 'Site', 'ai-connect' ),
	'content'  => __( 'Content', 'ai-connect' ),
	'media'    => __( 'Media', 'ai-connect' ),
	'files'    => __( 'Files', 'ai-connect' ),
	'database' => __( 'Database', 'ai-connect' ),
	'code'     => __( 'Code', 'ai-connect' ),
	'design'   => __( 'Design', 'ai-connect' ),
);
$sitw_order = array( 'meta', 'site', 'content', 'media', 'files', 'database', 'code', 'design' );

Admin::page_open(
	__( 'Tools', 'ai-connect' ),
	__( 'Enable or disable individual MCP tools. Disabled tools disappear from tools/list and refuse to run.', 'ai-connect' )
);
?>

<div class="sitw-card">
	<?php Admin::action_form_open( 'save_tools', 'tools', array( 'class' => 'sitw-form' ) ); ?>
		<div class="sitw-toolbar">
			<button type="button" class="sitw-btn sitw-btn-secondary" data-sitw-toggle="all"><?php esc_html_e( 'Enable all', 'ai-connect' ); ?></button>
			<button type="button" class="sitw-btn sitw-btn-secondary" data-sitw-toggle="read"><?php esc_html_e( 'Read tools only', 'ai-connect' ); ?></button>
			<button type="button" class="sitw-btn sitw-btn-secondary" data-sitw-toggle="none"><?php esc_html_e( 'Disable all', 'ai-connect' ); ?></button>
		</div>

		<?php foreach ( $sitw_order as $sitw_group ) : ?>
			<?php if ( empty( $sitw_groups[ $sitw_group ] ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<h2 class="sitw-group-title"><?php echo esc_html( isset( $sitw_labels[ $sitw_group ] ) ? $sitw_labels[ $sitw_group ] : $sitw_group ); ?></h2>
			<table class="sitw-table sitw-tools-table">
				<thead>
					<tr>
						<th class="sitw-col-check"></th>
						<th><?php esc_html_e( 'Tool', 'ai-connect' ); ?></th>
						<th><?php esc_html_e( 'Description', 'ai-connect' ); ?></th>
						<th><?php esc_html_e( 'Risk', 'ai-connect' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sitw_groups[ $sitw_group ] as $sitw_name => $sitw_def ) : ?>
						<tr>
							<td>
								<input
									type="checkbox"
									name="enabled[]"
									value="<?php echo esc_attr( $sitw_name ); ?>"
									data-risk="<?php echo esc_attr( $sitw_def['risk'] ); ?>"
									<?php checked( ! Registry::is_disabled( $sitw_name ) ); ?>
								/>
							</td>
							<td>
								<code><?php echo esc_html( $sitw_name ); ?></code>
								<div class="sitw-cell-sub"><?php echo esc_html( $sitw_def['title'] ); ?></div>
							</td>
							<td class="sitw-cell-desc"><?php echo esc_html( $sitw_def['description'] ); ?></td>
							<td><?php Admin::risk_chip( $sitw_def['risk'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<div class="sitw-actions">
			<button type="submit" class="sitw-btn"><?php esc_html_e( 'Save tool selection', 'ai-connect' ); ?></button>
		</div>
	</form>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Risk levels', 'ai-connect' ); ?></h2>
	<ul class="sitw-list">
		<li><?php Admin::risk_chip( 'read' ); ?> <?php esc_html_e( 'Pure inspection: no writes to content, files, options, or the database.', 'ai-connect' ); ?></li>
		<li><?php Admin::risk_chip( 'write' ); ?> <?php esc_html_e( 'Modifies content, settings, files, or the database, but never executes arbitrary PHP or SQL.', 'ai-connect' ); ?></li>
		<li><?php Admin::risk_chip( 'dangerous' ); ?> <?php esc_html_e( 'Code and SQL execution (run_php, db_execute, run_wp_cli) plus plugin install/update.', 'ai-connect' ); ?></li>
	</ul>
	<p class="sitw-hint"><?php esc_html_e( 'Read-only mode (Security page) blocks write and dangerous tools for every token. Dangerous tools additionally require the "dangerous tools" switch.', 'ai-connect' ); ?></p>
</div>

<?php
Admin::page_close();
