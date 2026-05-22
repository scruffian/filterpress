<?php
/**
 * FilterPress main class.
 *
 * @package FilterPress
 */

namespace FilterPress;

defined( 'ABSPATH' ) || exit;

/**
 * Registers SVG filter presets, adds a block attribute, and renders the
 * corresponding SVG <filter> definitions and CSS on the frontend.
 *
 * Mirrors the WordPress core duotone pattern: filters are applied to the
 * element identified by a block's `supports.filter.duotone` selector, so any
 * block that already opts into duotone filtering also gets FilterPress.
 */
class FilterPress {

	const GRAINY_ATTRIBUTE      = 'filterpressGrainy';
	const TURBULENCE_ATTRIBUTE  = 'filterpressTurbulence';
	const SQUIGGLE_ATTRIBUTE    = 'filterpressSquiggle';
	const GRUNGE_ATTRIBUTE      = 'filterpressGrunge';

	/**
	 * Set of grainy-gradient amounts (0-100) used on the current page.
	 *
	 * @var array<int, true>
	 */
	private static $rendered_grainy_amounts = array();

	/**
	 * Turbulence filter definitions used on the current page.
	 *
	 * @var array<string, array>
	 */
	private static $rendered_turbulences = array();

	/**
	 * Per-page counter used to give every turbulence-enabled button its own
	 * filter id. The displacement map's `scale` attribute is mutated by the
	 * Interactivity store on press/release; sharing one filter node across
	 * buttons would make their animations fight.
	 *
	 * @var int
	 */
	private static $turbulence_instance = 0;

	/**
	 * Squiggle (animated text wiggle) filter variants used on the current page.
	 *
	 * @var array<string, array>
	 */
	private static $rendered_squiggles = array();

	/**
	 * Grunge (jagged image edge) filter variants used on the current page.
	 *
	 * @var array<string, array>
	 */
	private static $rendered_grunges = array();

	/**
	 * Bootstraps hooks.
	 */
	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		add_action( 'init', array( __CLASS__, 'register_view_module' ), 20 );
		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 10, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'output_svg_defs' ) );
	}

	/**
	 * Registers the frontend Interactivity API view module.
	 *
	 * Enqueued lazily from {@see apply_turbulence()} the first time a
	 * turbulence-enabled button renders on the page.
	 */
	public static function register_view_module() {
		if ( ! function_exists( 'wp_register_script_module' ) ) {
			return;
		}
		wp_register_script_module(
			'filterpress-view',
			FILTERPRESS_URL . 'build/view.js',
			array( '@wordpress/interactivity' ),
			FILTERPRESS_VERSION
		);
	}

	/**
	 * Returns the catalog of turbulence-based texture effects.
	 *
	 * Each effect defines:
	 *   - label: Human-readable name shown in the UI.
	 *   - baseFrequency: Default feTurbulence baseFrequency (smaller = larger blobs).
	 *   - scale: Default feDisplacementMap scale (pixel displacement strength).
	 *
	 * @return array
	 */
	public static function get_turbulence_effects() {
		return array(
			'rough'      => array(
				'label'         => __( 'Rough edges', 'filterpress' ),
				'baseFrequency' => 0.02,
				'scale'         => 5,
			),
			'ink'        => array(
				'label'         => __( 'Inky bleed', 'filterpress' ),
				'baseFrequency' => 0.05,
				'scale'         => 10,
			),
			'watercolor' => array(
				'label'         => __( 'Watercolor', 'filterpress' ),
				'baseFrequency' => 0.01,
				'scale'         => 20,
			),
			'wavy'       => array(
				'label'         => __( 'Wavy', 'filterpress' ),
				'baseFrequency' => 0.08,
				'scale'         => 6,
			),
		);
	}

	/**
	 * Whether the block is eligible for turbulence effects.
	 *
	 * Restricted to `core/button` for now.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private static function is_turbulence_block( $block_name ) {
		return 'core/button' === $block_name;
	}

	/**
	 * Whether the block is eligible for the grunge edge effect.
	 *
	 * Restricted to `core/image` for now.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private static function is_grunge_block( $block_name ) {
		return 'core/image' === $block_name;
	}

	/**
	 * Whether a block declares core gradient support.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private static function is_gradient_block( $block_name ) {
		if ( empty( $block_name ) ) {
			return false;
		}

		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( $block_name );
		return $block_type && ! empty( $block_type->supports['color']['gradients'] );
	}

	/**
	 * Whether the block instance has an active gradient (preset or custom).
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private static function block_has_gradient( $block ) {
		if ( empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			return false;
		}
		$attrs = $block['attrs'];
		if ( ! empty( $attrs['gradient'] ) ) {
			return true;
		}
		if ( ! empty( $attrs['style']['color']['gradient'] ) ) {
			return true;
		}
		return ! empty( $attrs['style']['background']['gradient'] );
	}

	/**
	 * Enqueues the editor bundle.
	 */
	public static function enqueue_editor_assets() {
		$handle = 'filterpress-editor';

		wp_register_script(
			$handle,
			FILTERPRESS_URL . 'build/editor.js',
			array(
				'wp-blocks',
				'wp-block-editor',
				'wp-components',
				'wp-compose',
				'wp-element',
				'wp-hooks',
				'wp-i18n',
			),
			FILTERPRESS_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'FILTERPRESS_DATA',
			array(
				'grainyAttribute'     => self::GRAINY_ATTRIBUTE,
				'turbulenceAttribute' => self::TURBULENCE_ATTRIBUTE,
				'turbulenceEffects'   => self::get_turbulence_effects(),
				'squiggleAttribute'   => self::SQUIGGLE_ATTRIBUTE,
				'grungeAttribute'     => self::GRUNGE_ATTRIBUTE,
				'classPrefix'         => 'filterpress-',
				'grainyNoiseUri'      => self::noise_data_uri(),
			)
		);

		wp_enqueue_script( $handle );

		wp_register_style(
			$handle,
			false,
			array(),
			FILTERPRESS_VERSION
		);
		wp_enqueue_style( $handle );
	}

	/**
	 * Renders the filter on the server.
	 *
	 * Adds the FilterPress class to the block wrapper and records the filter
	 * for emission in the footer. Uses the block's existing duotone selector
	 * so the CSS filter targets the correct child element (e.g. the <img>).
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function render_block( $block_content, $block ) {
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		$block_content = self::apply_grainy_gradient( $block_content, $block );
		$block_content = self::apply_turbulence( $block_content, $block );
		$block_content = self::apply_squiggle( $block_content, $block );
		$block_content = self::apply_grunge( $block_content, $block );

		return $block_content;
	}

	/**
	 * Adds the grunge (jagged edge) class for image blocks that opt in.
	 *
	 * Pure SVG: feTurbulence noise → feDisplacementMap (depth) →
	 * feComponentTransfer with a discrete alpha threshold so the displaced
	 * edge is sharp rather than feathered.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	private static function apply_grunge( $block_content, $block ) {
		if ( empty( $block['attrs'][ self::GRUNGE_ATTRIBUTE ] ) ) {
			return $block_content;
		}
		if ( ! self::is_grunge_block( $block['blockName'] ) ) {
			return $block_content;
		}

		$g          = $block['attrs'][ self::GRUNGE_ATTRIBUTE ];
		$ruggedness = isset( $g['ruggedness'] )
			? max( 0.005, min( 0.2, (float) $g['ruggedness'] ) )
			: 0.05;
		$depth      = isset( $g['depth'] )
			? max( 0, min( 200, (float) $g['depth'] ) )
			: 15;
		$styles     = array( 'torn', 'brush', 'splat', 'burst', 'stamp' );
		$style      = isset( $g['style'] ) && in_array( $g['style'], $styles, true )
			? $g['style']
			: 'torn';

		// Note: we intentionally don't bail when depth === 0. The filter
		// still produces the un-displaced colored ring at depth=0; gating
		// on depth>0 caused a visible border-size jump when the user
		// transitioned depth 0 → 1.

		$rug_int   = (int) round( $ruggedness * 1000 );
		$depth_int = (int) round( $depth * 10 );

		$paint = self::border_paint_info( $block );
		if ( $paint ) {
			$width_int = (int) round( $paint['width'] * 10 );
			$id        = sprintf(
				'filterpress-grunge-b-%s-%d-%d-%d',
				$style,
				$rug_int,
				$depth_int,
				$width_int
			);
			self::$rendered_grunges[ $id ] = array(
				'variant'     => 'border',
				'style'       => $style,
				'ruggedness'  => $ruggedness,
				'depth'       => $depth,
				'borderWidth' => $paint['width'],
				'selector'    => 'img',
			);
		} else {
			$id                            = sprintf(
				'filterpress-grunge-%s-%d-%d',
				$style,
				$rug_int,
				$depth_int
			);
			self::$rendered_grunges[ $id ] = array(
				'variant'    => 'chew',
				'style'      => $style,
				'ruggedness' => $ruggedness,
				'depth'      => $depth,
				'selector'   => 'img',
			);
		}

		return self::append_class_to_root( $block_content, $id );
	}

	/**
	 * Returns ['width' => float (px), 'color' => '#rrggbb'] when the image
	 * block has both a parseable px-based border width and a literal border
	 * color set. Returns null otherwise (preset border colors aren't resolved
	 * server-side, so they fall back to the chew variant).
	 *
	 * @param array $block Parsed block.
	 * @return array|null
	 */
	private static function border_paint_info( $block ) {
		if ( empty( $block['attrs']['style']['border'] ) || ! is_array( $block['attrs']['style']['border'] ) ) {
			return null;
		}
		$border = $block['attrs']['style']['border'];
		if ( empty( $border['width'] ) || ! is_string( $border['width'] ) ) {
			return null;
		}
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)px$/', trim( $border['width'] ), $m ) ) {
			return null;
		}
		$width = (float) $m[1];
		if ( $width <= 0 ) {
			return null;
		}
		// Color isn't required — we sample whatever the CSS renders, so
		// preset palette colors and any color value all just work.
		return array( 'width' => $width );
	}

	/**
	 * Adds the squiggle (animated wiggle) class when the block opts in.
	 *
	 * Implementation is pure SVG — feTurbulence with an animated `seed`
	 * (calcMode=discrete) gives the "boiling" hand-drawn frame jitter, and
	 * feDisplacementMap pushes pixels by `intensity`. No frontend JS.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	private static function apply_squiggle( $block_content, $block ) {
		if ( empty( $block['attrs'][ self::SQUIGGLE_ATTRIBUTE ] ) ) {
			return $block_content;
		}

		$sq        = $block['attrs'][ self::SQUIGGLE_ATTRIBUTE ];
		$intensity = isset( $sq['intensity'] )
			? max( 0, min( 10, (float) $sq['intensity'] ) )
			: 2;
		$speed     = isset( $sq['speed'] )
			? max( 1, min( 30, (int) $sq['speed'] ) )
			: 12;

		if ( $intensity <= 0 ) {
			return $block_content;
		}

		$intensity_int = (int) round( $intensity * 10 );
		$id            = sprintf( 'filterpress-squiggle-%d-%d', $intensity_int, $speed );

		self::$rendered_squiggles[ $id ] = array(
			'intensity' => $intensity,
			'speed'     => $speed,
		);

		return self::append_class_to_root( $block_content, $id );
	}

	/**
	 * Adds the turbulence-effect class for the chosen effect, when applicable.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	private static function apply_turbulence( $block_content, $block ) {
		if ( empty( $block['attrs'][ self::TURBULENCE_ATTRIBUTE ] ) ) {
			return $block_content;
		}

		$turb = $block['attrs'][ self::TURBULENCE_ATTRIBUTE ];
		if ( empty( $turb['effect'] ) ) {
			return $block_content;
		}

		$effects = self::get_turbulence_effects();
		$effect  = sanitize_key( $turb['effect'] );
		if ( ! isset( $effects[ $effect ] ) ) {
			return $block_content;
		}

		if ( ! self::is_turbulence_block( $block['blockName'] ) ) {
			return $block_content;
		}

		$defaults  = $effects[ $effect ];
		$base_freq = isset( $turb['baseFrequency'] )
			? max( 0, min( 0.5, (float) $turb['baseFrequency'] ) )
			: (float) $defaults['baseFrequency'];
		$scale     = isset( $turb['scale'] )
			? max( 0, min( 50, (float) $turb['scale'] ) )
			: (float) $defaults['scale'];

		$freq_int  = (int) round( $base_freq * 1000 );
		$scale_int = (int) round( $scale * 10 );
		// Per-button instance so each button gets its own feDisplacementMap to
		// animate, instead of fighting over a shared one.
		++self::$turbulence_instance;
		$id = sprintf(
			'filterpress-turb-%s-%d-%d-%d',
			$effect,
			$freq_int,
			$scale_int,
			self::$turbulence_instance
		);

		self::$rendered_turbulences[ $id ] = array(
			'effect'        => $effect,
			'baseFrequency' => $base_freq,
			'scale'         => $scale,
			'selector'      => '.wp-block-button__link.filterpress-turbulence-on',
		);

		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( 'filterpress-view' );
		}

		$block_content = self::append_class_to_root( $block_content, $id );
		$block_content = self::add_turbulence_directives( $block_content, $id, $scale );

		return $block_content;
	}

	/**
	 * Adds Interactivity API directives to a button block so clicks animate the
	 * turbulence-on state in and out.
	 *
	 * @param string $content      Block HTML.
	 * @param string $filter_id    The SVG filter id this button uses.
	 * @param float  $target_scale The pressed-state feDisplacementMap scale to animate to.
	 * @return string
	 */
	private static function add_turbulence_directives( $content, $filter_id, $target_scale ) {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$processor = new \WP_HTML_Tag_Processor( $content );
		// Outer wrapper: <div class="wp-block-button"> — host the namespace + context.
		if ( ! $processor->next_tag() ) {
			return $content;
		}
		$processor->set_attribute( 'data-wp-interactive', 'filterpress' );
		$processor->set_attribute(
			'data-wp-context',
			wp_json_encode(
				array(
					'on'          => false,
					'filterId'    => $filter_id,
					'targetScale' => (float) $target_scale,
				)
			)
		);

		// Inner: <a class="wp-block-button__link"> — bind the click and class toggle.
		if ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$processor->set_attribute( 'data-wp-on--pointerdown', 'actions.startTurbulence' );
			$processor->set_attribute( 'data-wp-on--pointerup', 'actions.endTurbulence' );
			$processor->set_attribute( 'data-wp-on--pointerleave', 'actions.endTurbulence' );
			$processor->set_attribute( 'data-wp-on--pointercancel', 'actions.endTurbulence' );
			$processor->set_attribute( 'data-wp-class--filterpress-turbulence-on', 'context.on' );
		}

		return $processor->get_updated_html();
	}

	/**
	 * Adds grainy-gradient classes when the block opts in and has a gradient.
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	private static function apply_grainy_gradient( $block_content, $block ) {
		if ( empty( $block['attrs'][ self::GRAINY_ATTRIBUTE ] ) ) {
			return $block_content;
		}

		$grainy = $block['attrs'][ self::GRAINY_ATTRIBUTE ];
		$amount = is_array( $grainy ) && isset( $grainy['amount'] )
			? (int) $grainy['amount']
			: (int) $grainy;
		$amount = max( 0, min( 100, $amount ) );
		if ( $amount <= 0 ) {
			return $block_content;
		}

		if ( ! self::is_gradient_block( $block['blockName'] ) ) {
			return $block_content;
		}

		if ( ! self::block_has_gradient( $block ) ) {
			return $block_content;
		}

		self::$rendered_grainy_amounts[ $amount ] = true;

		$block_content = self::append_class_to_root( $block_content, 'filterpress-grainy' );
		$block_content = self::append_class_to_root( $block_content, 'filterpress-grainy-' . $amount );

		return $block_content;
	}

	/**
	 * Adds a class name to the first HTML tag in the given content.
	 *
	 * Uses WP_HTML_Tag_Processor when available for safe attribute editing.
	 *
	 * @param string $content HTML.
	 * @param string $class   Class to append.
	 * @return string
	 */
	private static function append_class_to_root( $content, $class ) {
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new \WP_HTML_Tag_Processor( $content );
			if ( $processor->next_tag() ) {
				$processor->add_class( $class );
				return $processor->get_updated_html();
			}
		}

		// Fallback: inject into the first class attribute or add one.
		if ( preg_match( '/^(\s*<[a-zA-Z0-9]+)([^>]*?)class="([^"]*)"/s', $content, $m ) ) {
			return preg_replace(
				'/^(\s*<[a-zA-Z0-9]+)([^>]*?)class="([^"]*)"/s',
				'$1$2class="$3 ' . esc_attr( $class ) . '"',
				$content,
				1
			);
		}

		return preg_replace(
			'/^(\s*<[a-zA-Z0-9]+)/s',
			'$1 class="' . esc_attr( $class ) . '"',
			$content,
			1
		);
	}

	/**
	 * Outputs the SVG <filter> definitions and scoped CSS for every filter
	 * actually used on the page.
	 */
	public static function output_svg_defs() {
		if (
			empty( self::$rendered_grainy_amounts )
			&& empty( self::$rendered_turbulences )
			&& empty( self::$rendered_squiggles )
			&& empty( self::$rendered_grunges )
		) {
			return;
		}

		$svg_body = '';
		$css      = '';


		foreach ( self::$rendered_turbulences as $id => $data ) {
			$svg_body .= self::build_turbulence_svg(
				$id,
				$data['effect'],
				$data['baseFrequency'],
				$data['scale']
			);

			$selector = '.' . $id;
			if ( ! empty( $data['selector'] ) ) {
				$selector = '.' . $id . ' ' . $data['selector'];
			}

			$css .= sprintf(
				'%s{filter:url(#%s);}',
				$selector,
				$id
			);
		}

		foreach ( self::$rendered_squiggles as $id => $data ) {
			$svg_body .= self::build_squiggle_svg( $id, $data['intensity'], $data['speed'] );
			$css      .= sprintf( '.%1$s{filter:url(#%1$s);}', $id );
		}

		foreach ( self::$rendered_grunges as $id => $data ) {
			$style = isset( $data['style'] ) ? $data['style'] : 'torn';

			if ( ! empty( $data['variant'] ) && 'border' === $data['variant'] ) {
				$svg_body .= self::build_grunge_border_svg(
					$id,
					$style,
					$data['ruggedness'],
					$data['depth'],
					$data['borderWidth']
				);

				$base = '.' . $id;
				$sel  = ! empty( $data['selector'] ) ? $base . ' ' . $data['selector'] : $base;
				// Let the CSS border render normally — the SVG filter
				// extracts and chews the rendered border ring.
				$css .= sprintf( '%s{filter:url(#%s);}', $sel, $id );
				continue;
			}

			$svg_body .= self::build_grunge_svg( $id, $style, $data['ruggedness'], $data['depth'] );

			$selector = '.' . $id;
			if ( ! empty( $data['selector'] ) ) {
				$selector = '.' . $id . ' ' . $data['selector'];
			}
			$css .= sprintf( '%s{filter:url(#%s);}', $selector, $id );
		}

		if ( ! empty( self::$rendered_grainy_amounts ) ) {
			$css .= self::build_grainy_css( array_keys( self::$rendered_grainy_amounts ) );
		}

		if ( '' !== $svg_body ) {
			printf(
				'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 0 0" width="0" height="0" focusable="false" role="none" style="visibility:hidden;position:absolute;left:-9999px;overflow:hidden;" aria-hidden="true"><defs>%s</defs></svg>',
				$svg_body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG markup is built from sanitized inputs by the dedicated builders.
			);
		}

		if ( '' !== $css ) {
			printf( '<style id="filterpress-inline-css">%s</style>', $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is built from sanitized ids, selectors, and integer amounts.
		}
	}

	/**
	 * Builds the CSS for the grainy-gradient overlay.
	 *
	 * Emits a single base rule plus one tiny rule per used amount. The overlay
	 * is a tiled SVG noise data URI rendered as a `::before` pseudo-element with
	 * `mix-blend-mode: multiply`, so it grains whatever gradient sits under it.
	 *
	 * @param int[] $amounts Used amounts, integers in 1-100.
	 * @return string
	 */
	private static function build_grainy_css( $amounts ) {
		$uri = self::noise_data_uri();
		$css = sprintf(
			'.filterpress-grainy{position:relative;isolation:isolate;}'
			. '.filterpress-grainy::before{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;background-image:url("%s");background-repeat:repeat;background-size:200px 200px;mix-blend-mode:multiply;opacity:var(--filterpress-grain-opacity,0.5);}',
			$uri
		);
		foreach ( $amounts as $amount ) {
			$amount = max( 1, min( 100, (int) $amount ) );
			$css   .= sprintf(
				'.filterpress-grainy-%1$d{--filterpress-grain-opacity:%2$s;}',
				$amount,
				(string) round( $amount / 100, 2 )
			);
		}
		return $css;
	}

	/**
	 * Returns the SVG used for the grainy-gradient overlay.
	 *
	 * @return string
	 */
	private static function noise_svg() {
		// feComponentTransfer slope/intercept stretches mid-grey toward black/white,
		// turning the soft fractalNoise into harsher, more visible grain.
		return '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
			. '<filter id="n">'
			. '<feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch"/>'
			. '<feComponentTransfer>'
			. '<feFuncR type="linear" slope="3" intercept="-1"/>'
			. '<feFuncG type="linear" slope="3" intercept="-1"/>'
			. '<feFuncB type="linear" slope="3" intercept="-1"/>'
			. '</feComponentTransfer>'
			. '</filter>'
			. '<rect width="200" height="200" filter="url(#n)"/>'
			. '</svg>';
	}

	/**
	 * Returns the noise SVG as a data URI.
	 *
	 * @return string
	 */
	private static function noise_data_uri() {
		return 'data:image/svg+xml;utf8,' . rawurlencode( self::noise_svg() );
	}

	/**
	 * Builds the SVG <filter> markup for a turbulence-based texture effect.
	 *
	 * @param string $id        Filter id.
	 * @param string $effect    Effect slug (rough|ink|watercolor|wavy).
	 * @param float  $base_freq feTurbulence baseFrequency.
	 * @param float  $scale     feDisplacementMap scale.
	 * @return string
	 */
	private static function build_turbulence_svg( $id, $effect, $base_freq, $scale ) {
		$id_attr = esc_attr( $id );
		$f       = esc_attr( (string) round( $base_freq, 4 ) );
		// Rendered scale starts at 0 (resting state). The JS Interactivity store
		// reads the press-state target from data-wp-context and animates the
		// scale attribute on press/release.
		unset( $scale );

		switch ( $effect ) {
			case 'rough':
				return sprintf(
					'<filter id="%1$s">'
					. '<feTurbulence type="fractalNoise" baseFrequency="%2$s" numOctaves="3" result="noise"/>'
					. '<feDisplacementMap in="SourceGraphic" in2="noise" scale="0"/>'
					. '</filter>',
					$id_attr,
					$f
				);

			case 'ink':
				return sprintf(
					'<filter id="%1$s">'
					. '<feMorphology operator="dilate" radius="1" in="SourceGraphic" result="dilated"/>'
					. '<feTurbulence type="fractalNoise" baseFrequency="%2$s" numOctaves="2" result="noise"/>'
					. '<feDisplacementMap in="dilated" in2="noise" scale="0"/>'
					. '</filter>',
					$id_attr,
					$f
				);

			case 'watercolor':
				return sprintf(
					'<filter id="%1$s">'
					. '<feTurbulence type="fractalNoise" baseFrequency="%2$s" numOctaves="3" result="noise"/>'
					. '<feDisplacementMap in="SourceGraphic" in2="noise" scale="0" result="displaced"/>'
					. '<feGaussianBlur in="displaced" stdDeviation="0.6"/>'
					. '</filter>',
					$id_attr,
					$f
				);

			case 'wavy':
				return sprintf(
					'<filter id="%1$s">'
					. '<feTurbulence type="turbulence" baseFrequency="%2$s" numOctaves="2" result="noise"/>'
					. '<feDisplacementMap in="SourceGraphic" in2="noise" scale="0"/>'
					. '</filter>',
					$id_attr,
					$f
				);
		}

		return '';
	}

	/**
	 * Builds the SVG <filter> markup for the squiggle (animated wiggle) effect.
	 *
	 * Uses SMIL `<animate calcMode="discrete">` on feTurbulence's seed to flip
	 * between N noise patterns per second — gives the classic boiling /
	 * hand-drawn frame-by-frame look. feDisplacementMap pushes glyphs by
	 * `intensity` pixels.
	 *
	 * @param string $id        Filter id.
	 * @param float  $intensity Displacement strength in pixels (0-5).
	 * @param int    $speed     Frames per second (1-30).
	 * @return string
	 */
	private static function build_squiggle_svg( $id, $intensity, $speed ) {
		$id_attr = esc_attr( $id );
		$scale   = esc_attr( (string) round( max( 0, min( 10, (float) $intensity ) ), 2 ) );
		$fps     = max( 1, min( 30, (int) $speed ) );
		// SMIL discrete animation needs >= 2 values to actually flip; below 2
		// fps we still want the seed to change once per second, so widen N
		// and stretch dur to keep the cadence at exactly $fps changes/second.
		$n   = max( 2, $fps );
		$dur = $n / $fps;

		$values = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$values[] = (string) $i;
		}
		$values_attr = esc_attr( implode( ';', $values ) );
		$dur_attr    = esc_attr( (string) round( $dur, 4 ) . 's' );

		return sprintf(
			'<filter id="%1$s">'
			. '<feTurbulence type="fractalNoise" baseFrequency="0.04" numOctaves="2" seed="1">'
			. '<animate attributeName="seed" values="%2$s" dur="%4$s" calcMode="discrete" repeatCount="indefinite"/>'
			. '</feTurbulence>'
			. '<feDisplacementMap in="SourceGraphic" scale="%3$s"/>'
			. '</filter>',
			$id_attr,
			$values_attr,
			$scale,
			$dur_attr
		);
	}

	/**
	 * Builds the SVG <filter> markup for the grunge (jagged image edge) effect.
	 *
	 * Pure SVG, no animation. Filter region is widened by 15% on every side
	 * so displaced pixels don't clip against the default 10% safe area.
	 *
	 * @param string $id         Filter id.
	 * @param float  $ruggedness feTurbulence baseFrequency (smaller = bigger chunks).
	 * @param float  $depth      feDisplacementMap scale in pixels.
	 * @return string
	 */
	/**
	 * Builds the SVG <filter> markup for the border-painted grunge variant.
	 *
	 * The user's CSS border is suppressed by the accompanying CSS rule; this
	 * filter regenerates an equivalent painted ring instead. Pipeline:
	 *
	 *   noise → dilate(SourceAlpha, borderWidth) → composite-out vs SourceAlpha
	 *         → displace(ring, noise) → threshold → flood(borderColor) → merge
	 *
	 * @param string $id           Filter id.
	 * @param float  $ruggedness   feTurbulence baseFrequency.
	 * @param float  $depth        feDisplacementMap scale (px).
	 * @param float  $border_width Painted ring thickness (px).
	 * @param string $border_color Hex color (#rrggbb or shorter).
	 * @return string
	 */
	private static function build_grunge_border_svg( $id, $style, $ruggedness, $depth, $border_width ) {
		$id_attr = esc_attr( $id );
		// Integer-rounded radii — sub-pixel feMorphology kernels are
		// rounded inconsistently across browsers.
		$bw      = (int) round( max( 0, (float) $border_width ) );
		$off     = (int) round( $bw / 2 );
		$bw_off  = $bw + $off;
		// Cap displacement at 2·bw so the chew stays proportional to
		// border width.
		$dep     = round( min( max( 0, (float) $depth ), max( 2 * $bw, 2.0 ) ), 2 );
		$noise   = self::grunge_noise_svg( $style, $ruggedness );

		// Pipeline: extract original CSS border ring with its colour, build
		// a thicker ring centred on the original border line (outer at the
		// element edge, inner extended bw/2 inward), chew it, merge over
		// the un-displaced image content.
		return '<filter id="' . $id_attr . '" x="-50%" y="-50%" width="200%" height="200%">'
			. $noise
			. '<feMorphology in="SourceAlpha" operator="erode" radius="' . $bw . '" result="contentMask"/>'
			. '<feComposite in="SourceAlpha" in2="contentMask" operator="out" result="borderMask"/>'
			. '<feComposite in="SourceGraphic" in2="borderMask" operator="in" result="coloredBorder"/>'
			. '<feMorphology in="SourceAlpha" operator="erode" radius="' . $bw_off . '" result="thickInner"/>'
			. '<feComposite in="SourceAlpha" in2="thickInner" operator="out" result="thickMask"/>'
			. '<feMorphology in="coloredBorder" operator="dilate" radius="' . $off . '" result="extendedColor"/>'
			. '<feComposite in="extendedColor" in2="thickMask" operator="in" result="ring"/>'
			. '<feComposite in="SourceGraphic" in2="contentMask" operator="in" result="image"/>'
			. '<feDisplacementMap in="ring" in2="noise" scale="' . $dep . '" result="displaced"/>'
			. '<feComponentTransfer in="displaced" result="chewed">'
			. '<feFuncA type="discrete" tableValues="0 0 1 1"/>'
			. '</feComponentTransfer>'
			. '<feMerge>'
			. '<feMergeNode in="image"/>'
			. '<feMergeNode in="chewed"/>'
			. '</feMerge>'
			. '</filter>';
	}

	/**
	 * Returns the <feTurbulence ... result="noise"/> markup tuned for a given
	 * grunge style. Each style uses a noticeably different noise character
	 * so the chew pattern alone makes torn/brush/stamp visually distinct —
	 * important for the border variant where the mask SVGs aren't used.
	 *
	 * @param string $style      Style slug.
	 * @param float  $ruggedness User-controlled ruggedness (0.005-0.2).
	 * @return string
	 */
	private static function grunge_noise_svg( $style, $ruggedness ) {
		$r = max( 0.005, min( 0.2, (float) $ruggedness ) );
		// Mask styles look best at very low ruggedness; piecewise-remap so the
		// bottom half of the slider expands below the old floor.
		if ( 'brush' === $style || 'splat' === $style || 'burst' === $style ) {
			$r = $r >= 0.1 ? ( $r - 0.1 ) * 1.95 + 0.005 : $r * 0.05;
		}
		switch ( $style ) {
			case 'brush':
				// Two anisotropic noises (horizontal + vertical streaks)
				// averaged together so streaky brush-drag features show
				// up on every edge of the ring — top/bottom pick up the
				// h-streaks, left/right pick up the v-streaks.
				$bx = esc_attr( (string) round( $r * 0.4, 4 ) );
				$by = esc_attr( (string) round( max( 0.15, $r * 6 ), 4 ) );
				return '<feTurbulence type="fractalNoise" baseFrequency="' . $bx . ' ' . $by . '" numOctaves="2" seed="1" result="hNoise"/>'
					. '<feTurbulence type="fractalNoise" baseFrequency="' . $by . ' ' . $bx . '" numOctaves="2" seed="2" result="vNoise"/>'
					. '<feComposite in="hNoise" in2="vNoise" operator="arithmetic" k1="0" k2="0.5" k3="0.5" k4="0" result="noise"/>';

			case 'splat':
				// More extreme variant of brush: longer streaks, more
				// octaves for finer detail, all-edge coverage via
				// h+v average.
				$bx = esc_attr( (string) round( $r * 0.25, 4 ) );
				$by = esc_attr( (string) round( max( 0.2, $r * 9 ), 4 ) );
				return '<feTurbulence type="fractalNoise" baseFrequency="' . $bx . ' ' . $by . '" numOctaves="3" seed="3" result="hNoise"/>'
					. '<feTurbulence type="fractalNoise" baseFrequency="' . $by . ' ' . $bx . '" numOctaves="3" seed="5" result="vNoise"/>'
					. '<feComposite in="hNoise" in2="vNoise" operator="arithmetic" k1="0" k2="0.5" k3="0.5" k4="0" result="noise"/>';

			case 'burst':
				// Most extreme — extra-long streaks and 4 octaves for
				// chaotic multi-scale detail.
				$bx = esc_attr( (string) round( $r * 0.15, 4 ) );
				$by = esc_attr( (string) round( max( 0.25, $r * 12 ), 4 ) );
				return '<feTurbulence type="fractalNoise" baseFrequency="' . $bx . ' ' . $by . '" numOctaves="4" seed="7" result="hNoise"/>'
					. '<feTurbulence type="fractalNoise" baseFrequency="' . $by . ' ' . $bx . '" numOctaves="4" seed="11" result="vNoise"/>'
					. '<feComposite in="hNoise" in2="vNoise" operator="arithmetic" k1="0" k2="0.5" k3="0.5" k4="0" result="noise"/>';

			case 'stamp':
				// Low-frequency single-octave noise → big chunky blobs, like
				// a stamp pressed unevenly with patchy coverage.
				$b = esc_attr( (string) round( max( 0.005, $r * 0.35 ), 4 ) );
				return '<feTurbulence type="fractalNoise" baseFrequency="' . $b . '" numOctaves="1" result="noise"/>';

			case 'torn':
			default:
				// Multi-octave fractal noise → fine-grained irregularity,
				// like a torn paper edge.
				$b = esc_attr( (string) round( $r, 4 ) );
				return '<feTurbulence type="fractalNoise" baseFrequency="' . $b . '" numOctaves="3" result="noise"/>';
		}
	}

	/**
	 * Returns true for styles whose base shape is a designed SVG mask rather
	 * than a plain SourceAlpha rectangle.
	 *
	 * @param string $style Style slug.
	 * @return bool
	 */
	private static function grunge_is_mask_style( $style ) {
		return 'brush' === $style || 'splat' === $style || 'burst' === $style || 'stamp' === $style;
	}

	/**
	 * Returns the inline SVG for a mask-style's base shape. ViewBox is 200x200;
	 * stretched to fit the source via preserveAspectRatio="none" on feImage.
	 *
	 * @param string $style Style slug.
	 * @return string
	 */
	private static function grunge_mask_svg( $style ) {
		// ViewBox is 0 0 400 400; the painted shape lives at coords 100-300
		// (the centre 50%). When stretched to fill the filter region
		// (-50%/200%), that centre 50% maps onto the source's bounds
		// independent of how the browser interprets percentages on
		// filter primitive subregions.
		switch ( $style ) {
			case 'brush':
				// Single asymmetric blob with a trailing tail on the right
				// and two splatter dots. Brush's chew pipeline preserves
				// alpha (no threshold), so the slight overall translucency
				// reaches the output unchanged.
				return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" preserveAspectRatio="none">'
					. '<g fill="#fff">'
					. '<path opacity="0.85" d="M 102 195 C 108 175 125 170 145 175 C 165 173 188 180 210 175 C 230 172 250 178 270 180 C 285 182 295 188 305 195 L 322 196 C 326 201 322 206 305 206 L 295 207 C 290 218 268 222 245 224 C 220 220 195 226 170 222 C 150 219 130 224 115 217 C 100 212 95 202 102 195 Z"/>'
					. '<circle opacity="0.5" cx="332" cy="208" r="2"/>'
					. '<circle opacity="0.3" cx="340" cy="214" r="1"/>'
					. '</g>'
					. '</svg>';

			case 'burst':
				// Most extreme: two distinct main blobs with drips in
				// multiple directions + heavy splatter scattered around.
				return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" preserveAspectRatio="none">'
					. '<g fill="#fff">'
					. '<path opacity="0.85" d="M 78 195 C 82 158 115 148 148 158 C 178 162 208 178 235 165 C 265 158 295 175 318 168 C 340 178 352 200 340 222 C 322 240 285 226 255 232 C 220 240 188 226 158 234 C 128 232 100 244 82 230 C 65 215 70 205 78 195 Z"/>'
					. '<path opacity="0.65" d="M 250 252 C 268 248 285 258 282 274 C 270 282 252 276 246 264 C 244 258 246 254 250 252 Z"/>'
					. '<path opacity="0.55" d="M 332 188 C 352 184 368 192 365 210 L 340 215 Z"/>'
					. '<path opacity="0.5" d="M 175 235 C 178 252 180 268 175 270 C 168 262 168 246 172 238 Z"/>'
					. '<path opacity="0.5" d="M 105 232 C 100 245 95 252 90 248 C 88 240 92 232 100 230 Z"/>'
					. '<path opacity="0.45" d="M 95 165 C 80 158 70 165 72 178 L 90 175 Z"/>'
					. '<circle opacity="0.7" cx="370" cy="225" r="2.8"/>'
					. '<circle opacity="0.55" cx="382" cy="232" r="1.8"/>'
					. '<circle opacity="0.4" cx="392" cy="222" r="1.2"/>'
					. '<circle opacity="0.6" cx="60" cy="190" r="2.5"/>'
					. '<circle opacity="0.4" cx="48" cy="200" r="1.5"/>'
					. '<circle opacity="0.5" cx="42" cy="180" r="1.2"/>'
					. '<circle opacity="0.6" cx="220" cy="278" r="2"/>'
					. '<circle opacity="0.4" cx="240" cy="288" r="1.5"/>'
					. '<circle opacity="0.5" cx="155" cy="285" r="1.3"/>'
					. '<circle opacity="0.4" cx="290" cy="270" r="1.5"/>'
					. '<circle opacity="0.5" cx="125" cy="148" r="1.5"/>'
					. '<circle opacity="0.4" cx="200" cy="138" r="1.2"/>'
					. '<circle opacity="0.4" cx="305" cy="148" r="1.3"/>'
					. '</g>'
					. '</svg>';

			case 'splat':
				// Bigger, wilder paintbrush smear with multiple wispy tails
				// (right + bottom drip) and lots of scattered splatter dots.
				return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" preserveAspectRatio="none">'
					. '<g fill="#fff">'
					. '<path opacity="0.85" d="M 88 198 C 92 168 118 160 145 168 C 168 165 192 180 218 170 C 245 165 272 182 295 175 C 315 178 332 198 326 218 C 312 232 282 222 258 230 C 230 236 200 226 175 232 C 150 230 128 238 108 228 C 88 218 78 208 88 198 Z"/>'
					. '<path opacity="0.55" d="M 320 195 C 338 192 350 198 348 215 C 340 222 325 218 320 213 Z"/>'
					. '<path opacity="0.45" d="M 195 230 C 197 245 198 255 192 252 C 188 246 188 235 192 230 Z"/>'
					. '<circle opacity="0.7" cx="350" cy="220" r="2.5"/>'
					. '<circle opacity="0.5" cx="362" cy="225" r="1.8"/>'
					. '<circle opacity="0.4" cx="370" cy="218" r="1.2"/>'
					. '<circle opacity="0.5" cx="78" cy="195" r="2"/>'
					. '<circle opacity="0.4" cx="62" cy="200" r="1.5"/>'
					. '<circle opacity="0.6" cx="190" cy="262" r="1.8"/>'
					. '<circle opacity="0.4" cx="220" cy="270" r="1.2"/>'
					. '</g>'
					. '</svg>';

			case 'stamp':
				// Polygonal block with chipped edges + four internal worn
				// patches via fill-rule="evenodd" (subpaths inside the
				// main outline punch through to transparent).
				return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" preserveAspectRatio="none">'
					. '<path fill="#fff" fill-rule="evenodd" d="'
					. 'M 102 108 L 125 102 L 152 112 L 178 105 L 205 115 L 232 103 L 258 113 L 285 105 L 297 112'
					. ' L 295 138 L 298 165 L 292 192 L 297 218 L 290 245 L 296 272 L 290 295'
					. ' L 268 298 L 245 290 L 220 297 L 195 290 L 170 298 L 145 290 L 120 297 L 105 295'
					. ' L 100 268 L 105 240 L 100 215 L 108 188 L 102 162 L 105 135 Z'
					. ' M 145 145 L 165 142 L 170 165 L 148 168 Z'
					. ' M 215 175 L 245 178 L 240 200 L 213 197 Z'
					. ' M 175 220 L 200 222 L 195 248 L 170 245 Z'
					. ' M 250 250 L 275 252 L 270 275 L 248 272 Z'
					. '"/>'
					. '</svg>';
		}
		return '';
	}

	/**
	 * Returns the data URI form of a mask-style's SVG.
	 *
	 * @param string $style Style slug.
	 * @return string
	 */
	private static function grunge_mask_data_uri( $style ) {
		return 'data:image/svg+xml;utf8,' . rawurlencode( self::grunge_mask_svg( $style ) );
	}

	/**
	 * Emits the feImage + composite-in markup that produces a `baseShape`
	 * result aligned to the source's bounds. Returns empty string for
	 * non-mask styles (callers should fall back to SourceAlpha).
	 *
	 * The x/y/width/height percentages are calibrated for our fixed
	 * filter region of -50%/200% — feImage at 25%/50% maps exactly onto
	 * the source element's bounding box.
	 *
	 * @param string $style Style slug.
	 * @return string
	 */
	private static function grunge_base_shape_svg( $style ) {
		// Only stamp clips the full image with its painted mask SVG; the
		// brushy styles would crop the image into a paint blob, which
		// looks wrong on a borderless image — they fall back to SourceAlpha.
		if ( 'stamp' !== $style ) {
			return '';
		}
		$uri = esc_attr( self::grunge_mask_data_uri( $style ) );
		// Load the designed mask SVG via feImage (xlink:href for old WebKit)
		// then intersect with SourceAlpha so the mask crops to the source.
		// The grunge texture lives in the SVG path data itself.
		return '<feImage href="' . $uri . '" xlink:href="' . $uri . '" preserveAspectRatio="none" result="rawShape"/>'
			. '<feComposite in="rawShape" in2="SourceAlpha" operator="in" result="baseShape"/>';
	}

	/**
	 * Returns the SVG `in` reference to use as the chewable mask: the mask
	 * style's `baseShape` result, or `SourceAlpha` for plain styles.
	 *
	 * @param string $style Style slug.
	 * @return string
	 */
	private static function grunge_chew_input( $style ) {
		return 'stamp' === $style ? 'baseShape' : 'SourceAlpha';
	}

	/**
	 * Returns the feFuncA primitive used to threshold the chewed alpha for
	 * a given style. Brush passes alpha through unchanged so the mask's
	 * opacity variation reaches the output; torn and stamp use a hard
	 * threshold for sharp jagged edges.
	 *
	 * @param string $style Style slug.
	 * @return string
	 */
	private static function grunge_chew_funca_svg( $style ) {
		if ( 'brush' === $style || 'splat' === $style || 'burst' === $style ) {
			return '<feFuncA type="identity"/>';
		}
		return '<feFuncA type="discrete" tableValues="0 0 0 1"/>';
	}

	private static function build_grunge_svg( $id, $style, $ruggedness, $depth ) {
		$id_attr = esc_attr( $id );
		// Mask styles use very low-frequency noise (smoother chewing),
		// so the same displacement scale produces less visible chew —
		// boost the scale to keep the depth slider meaningful.
		$dep_mul = ( 'brush' === $style || 'splat' === $style || 'burst' === $style ) ? 5 : 1;
		$dep     = esc_attr( (string) round( max( 0, min( 200, (float) $depth ) ) * $dep_mul, 2 ) );
		$noise   = self::grunge_noise_svg( $style, $ruggedness );
		$setup   = self::grunge_base_shape_svg( $style );
		$input   = self::grunge_chew_input( $style );

		return '<filter id="' . $id_attr . '" x="-50%" y="-50%" width="200%" height="200%">'
			. $noise
			. $setup
			. '<feDisplacementMap in="' . $input . '" in2="noise" scale="' . $dep . '" result="displacedAlpha"/>'
			. '<feComponentTransfer in="displacedAlpha" result="mask">'
			. self::grunge_chew_funca_svg( $style )
			. '</feComponentTransfer>'
			. '<feComposite in="SourceGraphic" in2="mask" operator="in"/>'
			. '</filter>';
	}

}
