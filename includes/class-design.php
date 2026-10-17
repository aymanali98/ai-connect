<?php
/**
 * Design system storage, seeding, and builder guidance.
 *
 * @package AiConnect
 */

namespace AiConnect;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site design system used by agents to keep generated work consistent.
 */
final class Design {

	/**
	 * Default design document.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'name'       => 'Site Design',
			'updated_at' => '',
			'source'     => 'defaults',
			'colors'     => array(
				array(
					'name'  => 'Primary',
					'value' => '#1a73e8',
				),
				array(
					'name'  => 'Text',
					'value' => '#202124',
				),
				array(
					'name'  => 'Background',
					'value' => '#ffffff',
				),
				array(
					'name'  => 'Muted',
					'value' => '#5f6368',
				),
				array(
					'name'  => 'Border',
					'value' => '#dadce0',
				),
			),
			'typography' => array(
				array(
					'name'        => 'Body',
					'family'      => '-apple-system, "Segoe UI", Roboto, Arial, sans-serif',
					'size'        => '16px',
					'weight'      => '400',
					'line_height' => '1.6',
				),
				array(
					'name'        => 'Heading',
					'family'      => 'inherit',
					'size'        => '32px',
					'weight'      => '600',
					'line_height' => '1.25',
				),
			),
			'spacing'    => array( '4px', '8px', '16px', '24px', '32px', '48px', '64px' ),
			'radii'      => array( '4px', '8px', '12px', '999px' ),
			'components' => array(
				array(
					'name'  => 'Button',
					'notes' => 'Solid primary background, white text, 6px radius, 1px border, comfortable 12px/20px padding.',
				),
				array(
					'name'  => 'Card',
					'notes' => 'White surface, 1px border (Border color), 8px radius, 20px padding, no heavy shadows.',
				),
			),
			'guidelines' => "Use the palette and type scale above for every generated surface.\nPrefer spacing values from the scale.\nKeep borders subtle and radius small.\nReuse existing components instead of inventing new ones.",
		);
	}

	/**
	 * Current design document.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( AI_CONNECT_DESIGN_OPTION, null );
		if ( null === $stored ) {
			// One-time migration from the pre-1.1.0 option key (legacy row kept as backup).
			$legacy_key = defined( 'SITEWRIGHT_DESIGN_OPTION' ) ? SITEWRIGHT_DESIGN_OPTION : 'sitewright_design';
			$legacy     = get_option( $legacy_key, null );
			if ( null !== $legacy ) {
				update_option( AI_CONNECT_DESIGN_OPTION, $legacy, false );
				$stored = $legacy;
			}
		}
		if ( ! is_array( $stored ) || array() === $stored ) {
			return self::defaults();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Merge a patch into the design document.
	 *
	 * @param array $patch Partial document.
	 * @return array The new full document.
	 */
	public static function update( array $patch ) {
		$current = self::get();
		$merged  = array_merge( $current, $patch );
		$clean   = self::sanitize( $merged );
		update_option( AI_CONNECT_DESIGN_OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Reset to defaults.
	 *
	 * @return array
	 */
	public static function reset() {
		$doc = self::defaults();
		self::update( $doc );
		return self::get();
	}

	/**
	 * Sanitize a full document.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$out = self::defaults();

		if ( isset( $input['name'] ) ) {
			$out['name'] = Util::truncate( self::line( $input['name'] ), 120 );
		}
		$out['updated_at'] = Util::now();
		if ( isset( $input['source'] ) ) {
			$source        = (string) $input['source'];
			$out['source'] = in_array( $source, array( 'defaults', 'theme_json', 'theme_css', 'manual' ), true ) ? $source : 'manual';
		}

		if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
			$colors = array();
			foreach ( array_slice( $input['colors'], 0, 40 ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name  = Util::truncate( self::line( isset( $row['name'] ) ? $row['name'] : '' ), 60 );
				$value = Util::truncate( self::line( isset( $row['value'] ) ? $row['value'] : '' ), 60 );
				if ( '' === $name || '' === $value ) {
					continue;
				}
				$colors[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
			$out['colors'] = $colors;
		}

		if ( isset( $input['typography'] ) && is_array( $input['typography'] ) ) {
			$typo = array();
			foreach ( array_slice( $input['typography'], 0, 30 ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = Util::truncate( self::line( isset( $row['name'] ) ? $row['name'] : '' ), 60 );
				if ( '' === $name ) {
					continue;
				}
				$typo[] = array(
					'name'        => $name,
					'family'      => Util::truncate( self::line( isset( $row['family'] ) ? $row['family'] : '' ), 200 ),
					'size'        => Util::truncate( self::line( isset( $row['size'] ) ? $row['size'] : '' ), 30 ),
					'weight'      => Util::truncate( self::line( isset( $row['weight'] ) ? $row['weight'] : '' ), 30 ),
					'line_height' => Util::truncate( self::line( isset( $row['line_height'] ) ? $row['line_height'] : '' ), 30 ),
				);
			}
			$out['typography'] = $typo;
		}

		foreach ( array( 'spacing', 'radii' ) as $list_key ) {
			if ( isset( $input[ $list_key ] ) ) {
				$list = $input[ $list_key ];
				if ( is_string( $list ) ) {
					$list = preg_split( '/[\r\n,]+/', $list );
				}
				if ( is_array( $list ) ) {
					$clean_list = array();
					foreach ( array_slice( $list, 0, 30 ) as $entry ) {
						$entry = Util::truncate( self::line( $entry ), 30 );
						if ( '' !== $entry ) {
							$clean_list[] = $entry;
						}
					}
					$out[ $list_key ] = $clean_list;
				}
			}
		}

		if ( isset( $input['components'] ) && is_array( $input['components'] ) ) {
			$components = array();
			foreach ( array_slice( $input['components'], 0, 40 ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = Util::truncate( self::line( isset( $row['name'] ) ? $row['name'] : '' ), 60 );
				if ( '' === $name ) {
					continue;
				}
				$components[] = array(
					'name'  => $name,
					'notes' => Util::truncate( self::text( isset( $row['notes'] ) ? $row['notes'] : '' ), 500 ),
				);
			}
			$out['components'] = $components;
		}

		if ( isset( $input['guidelines'] ) ) {
			$out['guidelines'] = Util::truncate( self::text( $input['guidelines'] ), 20000 );
		}

		return $out;
	}

	/**
	 * Build a design document from theme.json / global styles when available.
	 *
	 * @return array With 'doc' data and 'from' describing the source.
	 */
	public static function seed_from_theme() {
		$doc   = self::get();
		$found = false;

		if ( function_exists( 'wp_get_global_settings' ) ) {
			$global = wp_get_global_settings();

			$palette = isset( $global['color']['palette']['theme'] ) ? $global['color']['palette']['theme'] : null;
			if ( is_array( $palette ) && array() !== $palette ) {
				$colors = array();
				foreach ( array_slice( $palette, 0, 40 ) as $entry ) {
					if ( isset( $entry['color'] ) && '' !== $entry['color'] ) {
						$colors[] = array(
							'name'  => isset( $entry['name'] ) ? (string) $entry['name'] : (string) $entry['color'],
							'value' => (string) $entry['color'],
						);
					}
				}
				if ( array() !== $colors ) {
					$doc['colors'] = $colors;
					$found         = true;
				}
			}

			$families = isset( $global['typography']['fontFamilies']['theme'] ) ? $global['typography']['fontFamilies']['theme'] : null;
			$sizes    = isset( $global['typography']['fontSizes']['theme'] ) ? $global['typography']['fontSizes']['theme'] : null;
			if ( is_array( $families ) && array() !== $families ) {
				$typo = array();
				foreach ( array_slice( $families, 0, 10 ) as $index => $entry ) {
					$size   = is_array( $sizes ) && isset( $sizes[ $index ]['size'] ) ? (string) $sizes[ $index ]['size'] : '';
					$typo[] = array(
						'name'        => isset( $entry['name'] ) ? (string) $entry['name'] : 'Font ' . ( $index + 1 ),
						'family'      => isset( $entry['fontFamily'] ) ? (string) $entry['fontFamily'] : '',
						'size'        => $size,
						'weight'      => '',
						'line_height' => '',
					);
				}
				$doc['typography'] = $typo;
				$found             = true;
			}
		}

		if ( ! $found ) {
			$css = self::seed_from_css();
			if ( null !== $css ) {
				$doc   = array_merge( $doc, $css );
				$found = true;
			}
		}

		$doc['source'] = $found ? 'theme_json' : 'defaults';
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme         = wp_get_theme();
			$doc['name']   = $theme->get( 'Name' ) ? $theme->get( 'Name' ) . ' Design' : $doc['name'];
		}
		self::update( $doc );
		return self::get();
	}

	/**
	 * Heuristic seeding from the active theme stylesheet.
	 *
	 * @return array|null
	 */
	private static function seed_from_css() {
		if ( ! function_exists( 'get_stylesheet_directory' ) ) {
			return null;
		}
		$css_file = get_stylesheet_directory() . '/style.css';
		if ( ! is_file( $css_file ) || ! is_readable( $css_file ) ) {
			return null;
		}
		$css = (string) file_get_contents( $css_file, false, null, 0, 100 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $css ) {
			return null;
		}

		$doc   = array();
		$hexes = array();
		if ( preg_match_all( '/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $css, $matches ) ) {
			foreach ( $matches[0] as $hex ) {
				$hex = strtolower( $hex );
				if ( ! in_array( $hex, $hexes, true ) ) {
					$hexes[] = $hex;
				}
				if ( count( $hexes ) >= 12 ) {
					break;
				}
			}
		}
		if ( array() !== $hexes ) {
			$colors = array();
			foreach ( $hexes as $index => $hex ) {
				$colors[] = array(
					'name'  => 'Color ' . ( $index + 1 ),
					'value' => $hex,
				);
			}
			$doc['colors'] = $colors;
		}

		$families = array();
		if ( preg_match_all( '/font-family\s*:\s*([^;\}]+)/i', $css, $matches ) ) {
			foreach ( $matches[1] as $family ) {
				$family = trim( preg_replace( '/\s+/', ' ', $family ) );
				if ( '' !== $family && ! in_array( $family, $families, true ) ) {
					$families[] = Util::truncate( $family, 200 );
				}
				if ( count( $families ) >= 4 ) {
					break;
				}
			}
		}
		if ( array() !== $families ) {
			$typo = array();
			foreach ( $families as $index => $family ) {
				$typo[] = array(
					'name'        => 0 === $index ? 'Body' : 'Font ' . ( $index + 1 ),
					'family'      => $family,
					'size'        => '',
					'weight'      => '',
					'line_height' => '',
				);
			}
			$doc['typography'] = $typo;
		}

		return ( array() === $doc ) ? null : $doc;
	}

	/**
	 * Detected theme/builder stack.
	 *
	 * @return array
	 */
	public static function detected() {
		return array(
			'block_theme'  => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'theme'        => function_exists( 'get_template' ) ? (string) get_template() : '',
			'elementor'    => defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' ),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'astra'        => function_exists( 'get_template' ) && in_array( strtolower( (string) get_template() ), array( 'astra', 'astra-child' ), true ),
		);
	}

	/**
	 * Practical guidance lines for the current stack.
	 *
	 * @return array
	 */
	public static function guidance() {
		$detected = self::detected();
		$lines    = array();

		$lines[] = 'Every generated surface must use the palette and type scale above; do not invent new colors.';

		if ( $detected['block_theme'] ) {
			$lines[] = 'This is a block theme: prefer theme.json tokens and core blocks; style with the existing presets (e.g. var(--wp--preset--color--*)) instead of hardcoded CSS where possible.';
		}
		if ( $detected['elementor'] ) {
			$lines[] = 'Elementor is active: when editing pages built with Elementor, work through its data structures/widgets rather than raw HTML, and align with Elementor Global Colors/Fonts configured from this palette.';
		}
		if ( $detected['woocommerce'] ) {
			$lines[] = 'WooCommerce is active: product pages, cart and checkout use WooCommerce templates. Keep storefront styling consistent with the palette; avoid overriding WooCommerce CSS broadly.';
		}
		if ( $detected['astra'] ) {
			$lines[] = 'The Astra theme is active: it exposes typography and color controls through the Customizer and Astra global settings; prefer adjusting those over hardcoded overrides.';
		}
		if ( ! $detected['block_theme'] && ! $detected['elementor'] ) {
			$lines[] = 'This is a classic theme: extend the existing stylesheet conventions (class prefixes, spacing rhythm) instead of introducing a parallel system.';
		}
		$lines[] = 'When in doubt about an existing convention, read the active stylesheet or theme.json before writing any markup.';

		return $lines;
	}

	/**
	 * One-line text sanitizer.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function line( $value ) {
		$value = (string) $value;
		$value = (string) preg_replace( '/[\r\n\t]+/', ' ', $value );
		return trim( strip_tags( $value ) );
	}

	/**
	 * Multi-line text sanitizer.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		$value = (string) $value;
		$value = strip_tags( $value );
		$value = (string) preg_replace( "/\r\n?/", "\n", $value );
		return trim( $value );
	}
}
