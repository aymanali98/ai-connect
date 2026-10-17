<?php
/**
 * Design System page: current document, editor, seeding.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sitw_design   = Design::get();
$sitw_stack    = Design::detected();
$sitw_guidance = Design::guidance();

$sitw_colors = ! empty( $sitw_design['colors'] ) ? $sitw_design['colors'] : array( array( 'name' => '', 'value' => '' ) );
$sitw_typo   = ! empty( $sitw_design['typography'] ) ? $sitw_design['typography'] : array( array( 'name' => '', 'family' => '', 'size' => '', 'weight' => '', 'line_height' => '' ) );
$sitw_comps  = ! empty( $sitw_design['components'] ) ? $sitw_design['components'] : array( array( 'name' => '', 'notes' => '' ) );

Admin::page_open(
	__( 'Design System', 'ai-connect' ),
	__( 'The shared design language agents follow when generating UI.', 'ai-connect' )
);
?>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Current design system', 'ai-connect' ); ?></h2>
	<p class="sitw-sub">
		<strong><?php echo esc_html( $sitw_design['name'] ); ?></strong> ·
		<?php esc_html_e( 'source:', 'ai-connect' ); ?> <?php Admin::chip( (string) $sitw_design['source'], 'info' ); ?> ·
		<?php
		/* translators: %s: timestamp. */
		printf( esc_html__( 'updated %s', 'ai-connect' ), esc_html( Admin::time_display( $sitw_design['updated_at'] ) ) );
		?>
	</p>

	<h3><?php esc_html_e( 'Colors', 'ai-connect' ); ?></h3>
	<?php if ( empty( $sitw_design['colors'] ) ) : ?>
		<p class="sitw-empty"><?php esc_html_e( 'No colors defined.', 'ai-connect' ); ?></p>
	<?php else : ?>
		<div class="sitw-swatches">
			<?php foreach ( $sitw_design['colors'] as $sitw_c ) : ?>
				<div class="sitw-swatch-item">
					<span class="sitw-swatch" style="background:<?php echo esc_attr( $sitw_c['value'] ); ?>"></span>
					<span>
						<code><?php echo esc_html( $sitw_c['value'] ); ?></code><br />
						<span class="sitw-hint"><?php echo esc_html( $sitw_c['name'] ); ?></span>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Typography', 'ai-connect' ); ?></h3>
	<table class="sitw-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Role', 'ai-connect' ); ?></th>
				<th><?php esc_html_e( 'Family', 'ai-connect' ); ?></th>
				<th><?php esc_html_e( 'Size', 'ai-connect' ); ?></th>
				<th><?php esc_html_e( 'Weight', 'ai-connect' ); ?></th>
				<th><?php esc_html_e( 'Line height', 'ai-connect' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $sitw_design['typography'] as $sitw_t ) : ?>
				<tr>
					<td><?php echo esc_html( $sitw_t['name'] ); ?></td>
					<td><span class="sitw-hint"><?php echo esc_html( $sitw_t['family'] ); ?></span></td>
					<td><?php echo esc_html( $sitw_t['size'] ); ?></td>
					<td><?php echo esc_html( $sitw_t['weight'] ); ?></td>
					<td><?php echo esc_html( $sitw_t['line_height'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h3><?php esc_html_e( 'Spacing scale', 'ai-connect' ); ?></h3>
	<p>
		<?php foreach ( $sitw_design['spacing'] as $sitw_value ) : ?>
			<span class="sitw-chip sitw-chip-muted"><?php echo esc_html( $sitw_value ); ?></span>
		<?php endforeach; ?>
	</p>

	<h3><?php esc_html_e( 'Radii', 'ai-connect' ); ?></h3>
	<p>
		<?php foreach ( $sitw_design['radii'] as $sitw_value ) : ?>
			<span class="sitw-chip sitw-chip-muted"><?php echo esc_html( $sitw_value ); ?></span>
		<?php endforeach; ?>
	</p>

	<h3><?php esc_html_e( 'Components', 'ai-connect' ); ?></h3>
	<ul class="sitw-list">
		<?php foreach ( $sitw_design['components'] as $sitw_comp ) : ?>
			<li><strong><?php echo esc_html( $sitw_comp['name'] ); ?></strong> — <?php echo esc_html( $sitw_comp['notes'] ); ?></li>
		<?php endforeach; ?>
	</ul>

	<h3><?php esc_html_e( 'Guidelines', 'ai-connect' ); ?></h3>
	<?php if ( '' !== trim( (string) $sitw_design['guidelines'] ) ) : ?>
		<pre class="sitw-pre"><?php echo esc_html( $sitw_design['guidelines'] ); ?></pre>
	<?php else : ?>
		<p class="sitw-empty"><?php esc_html_e( 'No guidelines yet.', 'ai-connect' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Detected stack', 'ai-connect' ); ?></h3>
	<p>
		<?php Admin::chip( $sitw_stack['theme'], 'muted' ); ?>
		<?php $sitw_stack['block_theme'] ? Admin::chip( __( 'block theme', 'ai-connect' ), 'info' ) : Admin::chip( __( 'classic theme', 'ai-connect' ), 'muted' ); ?>
		<?php if ( $sitw_stack['elementor'] ) : ?><?php Admin::chip( 'Elementor', 'info' ); ?><?php endif; ?>
		<?php if ( $sitw_stack['woocommerce'] ) : ?><?php Admin::chip( 'WooCommerce', 'info' ); ?><?php endif; ?>
		<?php if ( $sitw_stack['astra'] ) : ?><?php Admin::chip( 'Astra', 'info' ); ?><?php endif; ?>
	</p>

	<h3><?php esc_html_e( 'Agent guidance', 'ai-connect' ); ?></h3>
	<ul class="sitw-list">
		<?php foreach ( $sitw_guidance as $sitw_line ) : ?>
			<li><?php echo esc_html( $sitw_line ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>

<div class="sitw-card">
	<h2><?php esc_html_e( 'Edit design system', 'ai-connect' ); ?></h2>
	<?php Admin::action_form_open( 'save_design', 'design', array( 'class' => 'sitw-form' ) ); ?>

		<label class="sitw-field">
			<?php esc_html_e( 'Name', 'ai-connect' ); ?>
			<input type="text" name="design_name" value="<?php echo esc_attr( $sitw_design['name'] ); ?>" />
		</label>

		<h3><?php esc_html_e( 'Colors', 'ai-connect' ); ?></h3>
		<table class="sitw-table" id="sitw-colors-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Value', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Preview', 'ai-connect' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitw_colors as $sitw_c ) : ?>
					<tr>
						<td><input type="text" name="color_name[]" value="<?php echo esc_attr( $sitw_c['name'] ); ?>" /></td>
						<td><input type="text" name="color_value[]" value="<?php echo esc_attr( $sitw_c['value'] ); ?>" class="sitw-color-input" /></td>
						<td><span class="sitw-swatch" style="background:<?php echo esc_attr( $sitw_c['value'] ); ?>"></span></td>
						<td><button type="button" class="sitw-icon-btn" data-sitw-remove-row aria-label="<?php esc_attr_e( 'Remove row', 'ai-connect' ); ?>">&times;</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<button type="button" class="sitw-btn sitw-btn-secondary sitw-btn-sm" data-sitw-add-row="sitw-colors-rows"><?php esc_html_e( 'Add color', 'ai-connect' ); ?></button>

		<h3><?php esc_html_e( 'Typography', 'ai-connect' ); ?></h3>
		<table class="sitw-table" id="sitw-typo-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Role', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Family', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Size', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Weight', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Line height', 'ai-connect' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitw_typo as $sitw_t ) : ?>
					<tr>
						<td><input type="text" name="typo_name[]" value="<?php echo esc_attr( $sitw_t['name'] ); ?>" /></td>
						<td><input type="text" name="typo_family[]" value="<?php echo esc_attr( $sitw_t['family'] ); ?>" /></td>
						<td><input type="text" name="typo_size[]" value="<?php echo esc_attr( $sitw_t['size'] ); ?>" /></td>
						<td><input type="text" name="typo_weight[]" value="<?php echo esc_attr( $sitw_t['weight'] ); ?>" /></td>
						<td><input type="text" name="typo_line_height[]" value="<?php echo esc_attr( $sitw_t['line_height'] ); ?>" /></td>
						<td><button type="button" class="sitw-icon-btn" data-sitw-remove-row aria-label="<?php esc_attr_e( 'Remove row', 'ai-connect' ); ?>">&times;</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<button type="button" class="sitw-btn sitw-btn-secondary sitw-btn-sm" data-sitw-add-row="sitw-typo-rows"><?php esc_html_e( 'Add type style', 'ai-connect' ); ?></button>

		<div class="sitw-form-grid">
			<label>
				<?php esc_html_e( 'Spacing scale (one per line)', 'ai-connect' ); ?>
				<textarea name="spacing" rows="6"><?php echo esc_textarea( implode( "\n", $sitw_design['spacing'] ) ); ?></textarea>
			</label>
			<label>
				<?php esc_html_e( 'Radii (one per line)', 'ai-connect' ); ?>
				<textarea name="radii" rows="6"><?php echo esc_textarea( implode( "\n", $sitw_design['radii'] ) ); ?></textarea>
			</label>
		</div>

		<h3><?php esc_html_e( 'Components', 'ai-connect' ); ?></h3>
		<table class="sitw-table" id="sitw-comp-rows">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'ai-connect' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'ai-connect' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sitw_comps as $sitw_comp ) : ?>
					<tr>
						<td><input type="text" name="comp_name[]" value="<?php echo esc_attr( $sitw_comp['name'] ); ?>" /></td>
						<td><input type="text" name="comp_notes[]" value="<?php echo esc_attr( $sitw_comp['notes'] ); ?>" /></td>
						<td><button type="button" class="sitw-icon-btn" data-sitw-remove-row aria-label="<?php esc_attr_e( 'Remove row', 'ai-connect' ); ?>">&times;</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<button type="button" class="sitw-btn sitw-btn-secondary sitw-btn-sm" data-sitw-add-row="sitw-comp-rows"><?php esc_html_e( 'Add component', 'ai-connect' ); ?></button>

		<label class="sitw-field">
			<?php esc_html_e( 'Guidelines (multi-line)', 'ai-connect' ); ?>
			<textarea name="guidelines" rows="8"><?php echo esc_textarea( $sitw_design['guidelines'] ); ?></textarea>
		</label>

		<div class="sitw-actions">
			<button type="submit" class="sitw-btn"><?php esc_html_e( 'Save design system', 'ai-connect' ); ?></button>
		</div>
	</form>

	<div class="sitw-actions">
		<?php Admin::action_form_open( 'seed_design', 'design', array( 'class' => 'sitw-inline-form' ) ); ?>
			<button type="submit" class="sitw-btn sitw-btn-secondary"><?php esc_html_e( 'Seed from theme (theme.json / CSS)', 'ai-connect' ); ?></button>
		</form>
		<?php Admin::action_form_open( 'reset_design', 'design', array( 'class' => 'sitw-inline-form' ) ); ?>
			<button type="submit" class="sitw-btn sitw-btn-danger" data-sitw-confirm="<?php esc_attr_e( 'Reset the design system to defaults? Your edits will be lost.', 'ai-connect' ); ?>"><?php esc_html_e( 'Reset to defaults', 'ai-connect' ); ?></button>
		</form>
	</div>
</div>

<?php
Admin::page_close();
