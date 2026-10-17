<?php
/**
 * Design tools: read and update the site design system.
 *
 * Agent-facing strings are intentionally plain English (not translated).
 *
 * @package AiConnect
 */

namespace AiConnect\Tools;

use AiConnect\Design;
use AiConnect\Registry;
use AiConnect\Tool_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the design tool group.
 */
final class Design_Tools {

	/**
	 * Register this group.
	 *
	 * @return void
	 */
	public static function register() {
		Registry::add(
			'design_get',
			array(
				'title'       => 'Get design system',
				'description' => 'The site design system: palette, type scale, spacing, radii, component notes, and guidelines, plus stack-specific guidance (block theme, Elementor, WooCommerce, Astra). Check this before generating any UI.',
				'risk'        => 'read',
				'group'       => 'design',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'callback'    => array( __CLASS__, 'design_get' ),
			)
		);

		Registry::add(
			'design_update',
			array(
				'title'       => 'Update design system',
				'description' => 'Merge changes into the design system (per top-level key). Set seed_from_theme=true to rebuild colors/typography from theme.json or the active stylesheet, or reset=true to restore defaults.',
				'risk'        => 'write',
				'group'       => 'design',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'name'            => array(
							'type'        => 'string',
							'description' => 'Design system name.',
						),
						'colors'          => array(
							'type'        => 'array',
							'description' => 'Palette entries: name + value (CSS color).',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'  => array( 'type' => 'string' ),
									'value' => array( 'type' => 'string' ),
								),
							),
						),
						'typography'      => array(
							'type'        => 'array',
							'description' => 'Type scale entries.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'        => array( 'type' => 'string' ),
									'family'      => array( 'type' => 'string' ),
									'size'        => array( 'type' => 'string' ),
									'weight'      => array( 'type' => 'string' ),
									'line_height' => array( 'type' => 'string' ),
								),
							),
						),
						'spacing'         => array(
							'type'        => 'array',
							'description' => 'Spacing scale values.',
							'items'       => array( 'type' => 'string' ),
						),
						'radii'           => array(
							'type'        => 'array',
							'description' => 'Border radius values.',
							'items'       => array( 'type' => 'string' ),
						),
						'components'      => array(
							'type'        => 'array',
							'description' => 'Component conventions: name + notes.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'name'  => array( 'type' => 'string' ),
									'notes' => array( 'type' => 'string' ),
								),
							),
						),
						'guidelines'      => array(
							'type'        => 'string',
							'description' => 'Free-form guidelines text (multi-line).',
						),
						'seed_from_theme' => array(
							'type'        => 'boolean',
							'description' => 'Rebuild from theme.json / theme CSS.',
							'default'     => false,
						),
						'reset'           => array(
							'type'        => 'boolean',
							'description' => 'Reset the whole design system to defaults.',
							'default'     => false,
						),
					),
				),
				'callback'    => array( __CLASS__, 'design_update' ),
			)
		);
	}

	/**
	 * design_get tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function design_get( array $args ) {
		return array(
			'design'   => Design::get(),
			'stack'    => Design::detected(),
			'guidance' => Design::guidance(),
		);
	}

	/**
	 * design_update tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When nothing was provided.
	 */
	public static function design_update( array $args ) {
		if ( ! empty( $args['reset'] ) ) {
			return array(
				'mode'     => 'reset',
				'design'   => Design::reset(),
				'stack'    => Design::detected(),
				'guidance' => Design::guidance(),
			);
		}

		if ( ! empty( $args['seed_from_theme'] ) ) {
			return array(
				'mode'     => 'seed_from_theme',
				'design'   => Design::seed_from_theme(),
				'stack'    => Design::detected(),
				'guidance' => Design::guidance(),
			);
		}

		$patch = array();
		foreach ( array( 'name', 'colors', 'typography', 'spacing', 'radii', 'components', 'guidelines' ) as $key ) {
			if ( array_key_exists( $key, $args ) ) {
				$patch[ $key ] = $args[ $key ];
			}
		}
		if ( empty( $patch ) ) {
			throw new Tool_Error(
				'No design fields provided.',
				'',
				'Pass any of: name, colors, typography, spacing, radii, components, guidelines — or seed_from_theme=true / reset=true.'
			);
		}

		$updated_keys    = array_keys( $patch );
		$patch['source'] = 'manual';

		return array(
			'mode'         => 'patch',
			'updated_keys' => $updated_keys,
			'design'       => Design::update( $patch ),
			'stack'        => Design::detected(),
			'guidance'     => Design::guidance(),
		);
	}
}
