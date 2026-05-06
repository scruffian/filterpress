/**
 * FilterPress — editor integration.
 *
 * Three feature areas:
 *   - Grainy gradients: noise overlay over gradient-supporting blocks.
 *   - Squiggle: animated frame-by-frame text wiggle (any block).
 *   - Button animation: press-to-deform turbulence on core/button.
 *
 * Attributes are added via blocks.registerBlockType, UI via editor.BlockEdit,
 * and the visual effect is mirrored in the canvas via editor.BlockListBlock.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! data ) {
		return;
	}

	var hooks = wp.hooks;
	var element = wp.element;
	var compose = wp.compose;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var i18n = wp.i18n;

	var Fragment = element.Fragment;
	var createElement = element.createElement;
	var useEffect = element.useEffect;
	var useState = element.useState;
	var createHigherOrderComponent = compose.createHigherOrderComponent;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var ToolsPanelItem =
		components.ToolsPanelItem || components.__experimentalToolsPanelItem;
	var __ = i18n.__;

	/**
	 * Tiny subscribable store for editor-only state (e.g. "show the
	 * deformation continuously in the editor"). Keyed by clientId so each
	 * block manages its own preview toggle. Not persisted to attributes —
	 * resets when the editor reloads.
	 */
	var previewState = {};
	var previewListeners = new Set();
	function getPreviewOn( clientId ) {
		return Boolean( previewState[ clientId ] );
	}
	function setPreviewOn( clientId, value ) {
		if ( value ) {
			previewState[ clientId ] = true;
		} else {
			delete previewState[ clientId ];
		}
		previewListeners.forEach( function ( fn ) {
			fn( clientId );
		} );
	}
	function usePreviewOn( clientId ) {
		var pair = useState( getPreviewOn( clientId ) );
		var on = pair[ 0 ];
		var setLocal = pair[ 1 ];
		useEffect(
			function () {
				var cb = function ( changed ) {
					if ( changed === clientId ) {
						setLocal( getPreviewOn( clientId ) );
					}
				};
				previewListeners.add( cb );
				return function () {
					previewListeners.delete( cb );
				};
			},
			[ clientId ]
		);
		return on;
	}

	var GRAINY_ATTRIBUTE = data.grainyAttribute;
	var TURBULENCE_ATTRIBUTE = data.turbulenceAttribute;
	var SQUIGGLE_ATTRIBUTE = data.squiggleAttribute;
	var GRUNGE_ATTRIBUTE = data.grungeAttribute;
	var TURBULENCE_EFFECTS = data.turbulenceEffects || {};
	var GRAINY_NOISE_URI = data.grainyNoiseUri;

	/**
	 * Whether a block declares core gradient support.
	 *
	 * @param {Object} blockType
	 * @return {boolean}
	 */
	function isGradientBlock( blockType ) {
		return Boolean(
			blockType &&
				blockType.supports &&
				blockType.supports.color &&
				blockType.supports.color.gradients
		);
	}

	/**
	 * Whether a block instance has an active gradient (preset or custom).
	 *
	 * @param {Object} attrs
	 * @return {boolean}
	 */
	function blockHasGradient( attrs ) {
		if ( ! attrs ) {
			return false;
		}
		if ( attrs.gradient ) {
			return true;
		}
		if ( attrs.style && attrs.style.color && attrs.style.color.gradient ) {
			return true;
		}
		return Boolean(
			attrs.style && attrs.style.background && attrs.style.background.gradient
		);
	}

	/**
	 * Whether the block is eligible for turbulence effects. Restricted to
	 * core/button for now.
	 *
	 * @param {string} blockName
	 * @return {boolean}
	 */
	function isTurbulenceBlock( blockName ) {
		return blockName === 'core/button';
	}

	/**
	 * Whether the block is eligible for the grunge edge effect. Restricted
	 * to core/image.
	 *
	 * @param {string} blockName
	 * @return {boolean}
	 */
	function isGrungeBlock( blockName ) {
		return blockName === 'core/image';
	}

	/**
	 * Returns { width, color } when the image has both a parseable px
	 * border-width and a literal hex border-color, else null. Mirrors the
	 * PHP-side gate so the editor preview matches the frontend's variant
	 * choice exactly.
	 *
	 * @param {Object} attrs
	 * @return {{width:number,color:string}|null}
	 */
	function borderPaintInfo( attrs ) {
		var border = attrs && attrs.style && attrs.style.border;
		if ( ! border || ! border.width || typeof border.width !== 'string' ) {
			return null;
		}
		var m = /^(\d+(?:\.\d+)?)px$/.exec( border.width.trim() );
		if ( ! m ) {
			return null;
		}
		var width = parseFloat( m[ 1 ] );
		if ( ! ( width > 0 ) ) {
			return null;
		}
		// Color isn't required — the SVG filter samples whatever the CSS
		// rendered, so preset palette colors and any color value just work.
		return { width: width };
	}

	/**
	 * Adds FilterPress attributes to blocks. Grainy is gated by gradient
	 * support; turbulence is restricted to core/button; squiggle is
	 * available on every block.
	 */
	hooks.addFilter(
		'blocks.registerBlockType',
		'filterpress/add-attribute',
		function ( settings, name ) {
			var changed = settings;
			if ( isGradientBlock( settings ) ) {
				changed = Object.assign( {}, changed, {
					attributes: Object.assign( {}, changed.attributes, {
						[ GRAINY_ATTRIBUTE ]: { type: 'object' },
					} ),
				} );
			}
			if ( isTurbulenceBlock( name ) ) {
				changed = Object.assign( {}, changed, {
					attributes: Object.assign( {}, changed.attributes, {
						[ TURBULENCE_ATTRIBUTE ]: { type: 'object' },
					} ),
				} );
			}
			if ( isGrungeBlock( name ) ) {
				changed = Object.assign( {}, changed, {
					attributes: Object.assign( {}, changed.attributes, {
						[ GRUNGE_ATTRIBUTE ]: { type: 'object' },
					} ),
				} );
			}
			// Squiggle is available on every block — no support gate.
			changed = Object.assign( {}, changed, {
				attributes: Object.assign( {}, changed.attributes, {
					[ SQUIGGLE_ATTRIBUTE ]: { type: 'object' },
				} ),
			} );
			return changed;
		}
	);

	/**
	 * Adds the InspectorControls panel.
	 */
	var withInspectorControls = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var blockType = wp.blocks.getBlockType( props.name );
			var supportsGradient = isGradientBlock( blockType );
			var hasGradient = supportsGradient && blockHasGradient( props.attributes );
			var supportsTurbulence = isTurbulenceBlock( props.name );
			var supportsGrunge = isGrungeBlock( props.name );
			// Squiggle is available on every block, so the inspector always
			// has at least one FilterPress panel to render.

			var grainyPanel = null;
			if ( hasGradient && ToolsPanelItem ) {
				var grainy = props.attributes[ GRAINY_ATTRIBUTE ] || {};
				var grainAmount =
					typeof grainy.amount === 'number'
						? Math.max( 0, Math.min( 100, grainy.amount ) )
						: 0;

				var onGrainChange = function ( next ) {
					var n = typeof next === 'number' ? Math.max( 0, Math.min( 100, next ) ) : 0;
					var attrs = {};
					attrs[ GRAINY_ATTRIBUTE ] = n > 0 ? { amount: n } : undefined;
					props.setAttributes( attrs );
				};

				grainyPanel = createElement(
					InspectorControls,
					{ group: 'color' },
					createElement(
						ToolsPanelItem,
						{
							hasValue: function () {
								return grainAmount > 0;
							},
							label: __( 'Grain', 'filterpress' ),
							onDeselect: function () {
								var attrs = {};
								attrs[ GRAINY_ATTRIBUTE ] = undefined;
								props.setAttributes( attrs );
							},
							isShownByDefault: true,
							panelId: props.clientId,
						},
						createElement( RangeControl, {
							label: __( 'Grain', 'filterpress' ),
							value: grainAmount,
							onChange: onGrainChange,
							min: 0,
							max: 100,
							step: 1,
							__nextHasNoMarginBottom: true,
						} )
					)
				);
			}

			var turbulencePanel = null;
			if ( supportsTurbulence ) {
				var turb = props.attributes[ TURBULENCE_ATTRIBUTE ] || {};
				var turbEffect =
					turb.effect && TURBULENCE_EFFECTS[ turb.effect ] ? turb.effect : '';
				var turbDefaults = turbEffect ? TURBULENCE_EFFECTS[ turbEffect ] : null;
				var turbBaseFreq =
					typeof turb.baseFrequency === 'number'
						? turb.baseFrequency
						: turbDefaults
						? turbDefaults.baseFrequency
						: 0;
				var turbScale =
					typeof turb.scale === 'number'
						? turb.scale
						: turbDefaults
						? turbDefaults.scale
						: 0;

				var turbOptions = [
					{ label: __( 'None', 'filterpress' ), value: '' },
				].concat(
					Object.keys( TURBULENCE_EFFECTS ).map( function ( key ) {
						return { label: TURBULENCE_EFFECTS[ key ].label, value: key };
					} )
				);

				var onTurbEffectChange = function ( next ) {
					var attrs = {};
					if ( ! next || ! TURBULENCE_EFFECTS[ next ] ) {
						attrs[ TURBULENCE_ATTRIBUTE ] = undefined;
					} else {
						var def = TURBULENCE_EFFECTS[ next ];
						attrs[ TURBULENCE_ATTRIBUTE ] = {
							effect: next,
							baseFrequency: def.baseFrequency,
							scale: def.scale,
						};
					}
					props.setAttributes( attrs );
				};

				var onTurbBaseFreqChange = function ( next ) {
					if ( ! turbEffect ) {
						return;
					}
					var attrs = {};
					attrs[ TURBULENCE_ATTRIBUTE ] = {
						effect: turbEffect,
						baseFrequency: typeof next === 'number' ? next : turbBaseFreq,
						scale: turbScale,
					};
					props.setAttributes( attrs );
				};

				var onTurbScaleChange = function ( next ) {
					if ( ! turbEffect ) {
						return;
					}
					var attrs = {};
					attrs[ TURBULENCE_ATTRIBUTE ] = {
						effect: turbEffect,
						baseFrequency: turbBaseFreq,
						scale: typeof next === 'number' ? next : turbScale,
					};
					props.setAttributes( attrs );
				};

				var previewOn = usePreviewOn( props.clientId );
				turbulencePanel = createElement(
					InspectorControls,
					{ group: 'styles' },
					createElement(
						PanelBody,
						{ title: __( 'Animation', 'filterpress' ), initialOpen: false },
						createElement( SelectControl, {
							label: __( 'Effect', 'filterpress' ),
							value: turbEffect,
							options: turbOptions,
							onChange: onTurbEffectChange,
							__nextHasNoMarginBottom: true,
						} ),
						turbEffect
							? createElement( RangeControl, {
									label: __( 'Base frequency', 'filterpress' ),
									value: turbBaseFreq,
									onChange: onTurbBaseFreqChange,
									min: 0,
									max: 0.2,
									step: 0.005,
									__nextHasNoMarginBottom: true,
							  } )
							: null,
						turbEffect
							? createElement( RangeControl, {
									label: __( 'Scale', 'filterpress' ),
									value: turbScale,
									onChange: onTurbScaleChange,
									min: 0,
									max: 30,
									step: 0.5,
									__nextHasNoMarginBottom: true,
							  } )
							: null,
						turbEffect && ToggleControl
							? createElement( ToggleControl, {
									label: __( 'Show in editor', 'filterpress' ),
									help: __(
										'Always render the deformed state while editing. Frontend behavior is unchanged.',
										'filterpress'
									),
									checked: previewOn,
									onChange: function ( v ) {
										setPreviewOn( props.clientId, v );
									},
									__nextHasNoMarginBottom: true,
							  } )
							: null
					)
				);
			}

			var squigglePanel = null;
			{
				var squiggle = props.attributes[ SQUIGGLE_ATTRIBUTE ];
				var sqEnabled = Boolean( squiggle );
				var sqIntensity =
					squiggle && typeof squiggle.intensity === 'number'
						? Math.max( 0, Math.min( 10, squiggle.intensity ) )
						: 2;
				var sqSpeed =
					squiggle && typeof squiggle.speed === 'number'
						? Math.max( 1, Math.min( 30, Math.round( squiggle.speed ) ) )
						: 12;

				var setSquiggle = function ( next ) {
					var attrs = {};
					attrs[ SQUIGGLE_ATTRIBUTE ] = next;
					props.setAttributes( attrs );
				};

				squigglePanel = createElement(
					InspectorControls,
					{ group: 'styles' },
					createElement(
						PanelBody,
						{ title: __( 'Squiggle', 'filterpress' ), initialOpen: false },
						createElement( ToggleControl, {
							label: __( 'Enable', 'filterpress' ),
							checked: sqEnabled,
							onChange: function ( v ) {
								setSquiggle( v ? { intensity: 2, speed: 12 } : undefined );
							},
							__nextHasNoMarginBottom: true,
						} ),
						sqEnabled
							? createElement( RangeControl, {
									label: __( 'Intensity', 'filterpress' ),
									value: sqIntensity,
									onChange: function ( v ) {
										setSquiggle( {
											intensity: typeof v === 'number' ? v : sqIntensity,
											speed: sqSpeed,
										} );
									},
									min: 0,
									max: 10,
									step: 0.5,
									__nextHasNoMarginBottom: true,
							  } )
							: null,
						sqEnabled
							? createElement( RangeControl, {
									label: __( 'Speed (fps)', 'filterpress' ),
									value: sqSpeed,
									onChange: function ( v ) {
										setSquiggle( {
											intensity: sqIntensity,
											speed: typeof v === 'number' ? Math.round( v ) : sqSpeed,
										} );
									},
									min: 1,
									max: 30,
									step: 1,
									__nextHasNoMarginBottom: true,
							  } )
							: null
					)
				);
			}

			var grungePanel = null;
			if ( supportsGrunge ) {
				var grunge = props.attributes[ GRUNGE_ATTRIBUTE ];
				var grungeEnabled = Boolean( grunge );
				var grungeRug =
					grunge && typeof grunge.ruggedness === 'number'
						? Math.max( 0.005, Math.min( 0.2, grunge.ruggedness ) )
						: 0.05;
				var grungeDepth =
					grunge && typeof grunge.depth === 'number'
						? Math.max( 0, Math.min( 200, grunge.depth ) )
						: 15;
				var grungeStyleOptions = [
					{ label: __( 'Torn', 'filterpress' ), value: 'torn' },
					{ label: __( 'Brush', 'filterpress' ), value: 'brush' },
					{ label: __( 'Stamp', 'filterpress' ), value: 'stamp' },
				];
				var grungeStyle =
					grunge &&
					typeof grunge.style === 'string' &&
					[ 'torn', 'brush', 'stamp' ].indexOf( grunge.style ) >= 0
						? grunge.style
						: 'torn';

				var setGrunge = function ( next ) {
					var attrs = {};
					attrs[ GRUNGE_ATTRIBUTE ] = next;
					props.setAttributes( attrs );
				};

				grungePanel = createElement(
					InspectorControls,
					{ group: 'styles' },
					createElement(
						PanelBody,
						{ title: __( 'Grunge edges', 'filterpress' ), initialOpen: false },
						createElement( ToggleControl, {
							label: __( 'Enable', 'filterpress' ),
							checked: grungeEnabled,
							onChange: function ( v ) {
								setGrunge(
									v
										? { style: 'torn', ruggedness: 0.05, depth: 15 }
										: undefined
								);
							},
							__nextHasNoMarginBottom: true,
						} ),
						grungeEnabled
							? createElement( SelectControl, {
									label: __( 'Style', 'filterpress' ),
									value: grungeStyle,
									options: grungeStyleOptions,
									onChange: function ( v ) {
										setGrunge( {
											style: v || 'torn',
											ruggedness: grungeRug,
											depth: grungeDepth,
										} );
									},
									__nextHasNoMarginBottom: true,
							  } )
							: null,
						grungeEnabled
							? createElement( RangeControl, {
									label: __( 'Ruggedness', 'filterpress' ),
									value: grungeRug,
									onChange: function ( v ) {
										setGrunge( {
											style: grungeStyle,
											ruggedness: typeof v === 'number' ? v : grungeRug,
											depth: grungeDepth,
										} );
									},
									min: 0.005,
									max: 0.2,
									step: 0.005,
									__nextHasNoMarginBottom: true,
							  } )
							: null,
						grungeEnabled
							? createElement( RangeControl, {
									label: __( 'Depth', 'filterpress' ),
									value: grungeDepth,
									onChange: function ( v ) {
										setGrunge( {
											style: grungeStyle,
											ruggedness: grungeRug,
											depth: typeof v === 'number' ? v : grungeDepth,
										} );
									},
									min: 0,
									max: 200,
									step: 1,
									__nextHasNoMarginBottom: true,
							  } )
							: null
					)
				);
			}

			return createElement(
				Fragment,
				null,
				createElement( BlockEdit, props ),
				grainyPanel,
				turbulencePanel,
				squigglePanel,
				grungePanel
			);
		};
	}, 'withFilterPressInspectorControls' );
	hooks.addFilter( 'editor.BlockEdit', 'filterpress/inspector', withInspectorControls );

	/**
	 * Applies the FilterPress filter inside the editor.
	 *
	 * - Adds the filter class to the block wrapper so CSS filter rules apply.
	 * - Writes a <style> rule into the block's owner document so the filter
	 *   applies to the same element targeted on the frontend.
	 * - Injects the SVG <filter> into that same document's <defs>.
	 */
	var withEditorFilter = createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			var blockType = wp.blocks.getBlockType( props.name );

			var grainy = props.attributes && props.attributes[ GRAINY_ATTRIBUTE ];
			var grainAmount =
				grainy && typeof grainy.amount === 'number'
					? Math.max( 0, Math.min( 100, Math.round( grainy.amount ) ) )
					: 0;
			var hasGrain =
				grainAmount > 0 &&
				isGradientBlock( blockType ) &&
				blockHasGradient( props.attributes );

			var turb = props.attributes && props.attributes[ TURBULENCE_ATTRIBUTE ];
			var turbEffect =
				turb && turb.effect && TURBULENCE_EFFECTS[ turb.effect ] ? turb.effect : '';
			var turbDefaults = turbEffect ? TURBULENCE_EFFECTS[ turbEffect ] : null;
			var turbBaseFreq = turbEffect
				? typeof turb.baseFrequency === 'number'
					? Math.max( 0, Math.min( 0.5, turb.baseFrequency ) )
					: turbDefaults.baseFrequency
				: 0;
			var turbScale = turbEffect
				? typeof turb.scale === 'number'
					? Math.max( 0, Math.min( 50, turb.scale ) )
					: turbDefaults.scale
				: 0;
			var hasTurbulence = Boolean( turbEffect ) && isTurbulenceBlock( props.name );
			var turbId = hasTurbulence
				? 'filterpress-turb-' +
				  turbEffect +
				  '-' +
				  Math.round( turbBaseFreq * 1000 ) +
				  '-' +
				  Math.round( turbScale * 10 )
				: null;
			var previewOn = usePreviewOn( props.clientId );
			var showTurbulencePreview = hasTurbulence && previewOn;

			var squiggle = props.attributes && props.attributes[ SQUIGGLE_ATTRIBUTE ];
			var sqIntensity =
				squiggle && typeof squiggle.intensity === 'number'
					? Math.max( 0, Math.min( 10, squiggle.intensity ) )
					: 0;
			var sqSpeed =
				squiggle && typeof squiggle.speed === 'number'
					? Math.max( 1, Math.min( 30, Math.round( squiggle.speed ) ) )
					: 12;
			var hasSquiggle = sqIntensity > 0;
			var sqId = hasSquiggle
				? 'filterpress-squiggle-' +
				  Math.round( sqIntensity * 10 ) +
				  '-' +
				  sqSpeed
				: null;

			var grunge = props.attributes && props.attributes[ GRUNGE_ATTRIBUTE ];
			var grungeRug =
				grunge && typeof grunge.ruggedness === 'number'
					? Math.max( 0.005, Math.min( 0.2, grunge.ruggedness ) )
					: 0.05;
			var grungeDepth =
				grunge && typeof grunge.depth === 'number'
					? Math.max( 0, Math.min( 200, grunge.depth ) )
					: 0;
			var grungeCanvasStyle =
				grunge &&
				typeof grunge.style === 'string' &&
				[ 'torn', 'brush', 'stamp' ].indexOf( grunge.style ) >= 0
					? grunge.style
					: 'torn';
			var hasGrunge =
				Boolean( grunge ) && grungeDepth > 0 && isGrungeBlock( props.name );
			var grungePaint = hasGrunge ? borderPaintInfo( props.attributes ) : null;
			var grungeId = null;
			if ( hasGrunge ) {
				if ( grungePaint ) {
					grungeId =
						'filterpress-grunge-b-' +
						grungeCanvasStyle +
						'-' +
						Math.round( grungeRug * 1000 ) +
						'-' +
						Math.round( grungeDepth * 10 ) +
						'-' +
						Math.round( grungePaint.width * 10 );
				} else {
					grungeId =
						'filterpress-grunge-' +
						grungeCanvasStyle +
						'-' +
						Math.round( grungeRug * 1000 ) +
						'-' +
						Math.round( grungeDepth * 10 );
				}
			}

			useEffect(
				function () {
					if ( ! hasGrain && ! hasTurbulence && ! hasSquiggle && ! hasGrunge ) {
						return;
					}
					// The editor canvas is iframed (WP 6.2+); the block element
					// can only reference a filter defined in its own document.
					// Inject into every same-origin document we can reach.
					getCandidateDocs().forEach( function ( doc ) {
						if ( hasGrain ) {
							injectGrainyRules( doc, grainAmount );
						}
						if ( hasTurbulence ) {
							injectTurbulenceDefs( doc, turbId, turbEffect, turbBaseFreq, turbScale );
							injectTurbulenceStyleRule( doc, turbId, '.wp-block-button__link' );
						}
						if ( hasSquiggle ) {
							injectSquiggleDefs( doc, sqId, sqIntensity, sqSpeed );
							injectStyleRule( doc, sqId, '' );
						}
						if ( hasGrunge ) {
							if ( grungePaint ) {
								injectGrungeBorderDefs(
									doc,
									grungeId,
									grungeCanvasStyle,
									grungeRug,
									grungeDepth,
									grungePaint.width
								);
								injectGrungeBorderStyleRule( doc, grungeId, 'img' );
							} else {
								injectGrungeDefs(
									doc,
									grungeId,
									grungeCanvasStyle,
									grungeRug,
									grungeDepth
								);
								injectStyleRule( doc, grungeId, 'img' );
							}
						}
					} );
				},
				[
					hasGrain,
					grainAmount,
					hasTurbulence,
					turbId,
					turbEffect,
					turbBaseFreq,
					turbScale,
					hasSquiggle,
					sqId,
					sqIntensity,
					sqSpeed,
					hasGrunge,
					grungeId,
					grungeCanvasStyle,
					grungeRug,
					grungeDepth,
					grungePaint && grungePaint.width,
					props.clientId,
				]
			);

			if ( ! hasGrain && ! hasTurbulence && ! hasSquiggle && ! hasGrunge ) {
				return createElement( BlockListBlock, props );
			}

			var classes = [];
			if ( hasGrain ) {
				classes.push( 'filterpress-grainy' );
				classes.push( 'filterpress-grainy-' + grainAmount );
			}
			if ( hasTurbulence ) {
				classes.push( turbId );
				if ( showTurbulencePreview ) {
					classes.push( 'is-filterpress-preview' );
				}
			}
			if ( hasSquiggle ) {
				classes.push( sqId );
			}
			if ( hasGrunge ) {
				classes.push( grungeId );
			}

			var newProps = Object.assign( {}, props, {
				className: [ props.className ].concat( classes ).filter( Boolean ).join( ' ' ),
				wrapperProps: Object.assign( {}, props.wrapperProps, {
					className: [ props.wrapperProps && props.wrapperProps.className ]
						.concat( classes )
						.filter( Boolean )
						.join( ' ' ),
				} ),
			} );

			return createElement( BlockListBlock, newProps );
		};
	}, 'withFilterPressEditorFilter' );
	hooks.addFilter( 'editor.BlockListBlock', 'filterpress/apply', withEditorFilter );

	var DEFS_ID = 'filterpress-editor-defs';
	var STYLE_ID = 'filterpress-editor-style';

	/**
	 * Returns the parent document plus every same-origin iframe document
	 * currently present in the DOM. The block editor canvas may be iframed,
	 * so we need to inject filter defs wherever the block is rendered.
	 */
	function getCandidateDocs() {
		var docs = [ document ];
		var iframes = document.querySelectorAll( 'iframe' );
		for ( var i = 0; i < iframes.length; i++ ) {
			try {
				var d = iframes[ i ].contentDocument;
				if ( d && d !== document && docs.indexOf( d ) === -1 ) {
					docs.push( d );
				}
			} catch ( e ) {
				// Cross-origin iframe — skip.
			}
		}
		return docs;
	}

	function ensureSVGDefsEl( doc ) {
		var svg = doc.getElementById( DEFS_ID );
		if ( svg ) {
			return svg.querySelector( 'defs' );
		}
		var SVG_NS = 'http://www.w3.org/2000/svg';
		var XLINK_NS = 'http://www.w3.org/1999/xlink';
		svg = doc.createElementNS( SVG_NS, 'svg' );
		svg.setAttribute( 'id', DEFS_ID );
		// Declare xlink so feImage's xlink:href fallback resolves on older WebKit.
		svg.setAttributeNS(
			'http://www.w3.org/2000/xmlns/',
			'xmlns:xlink',
			XLINK_NS
		);
		svg.setAttribute( 'width', '0' );
		svg.setAttribute( 'height', '0' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		svg.style.position = 'absolute';
		svg.style.left = '-9999px';
		svg.style.overflow = 'hidden';
		var defs = doc.createElementNS( SVG_NS, 'defs' );
		svg.appendChild( defs );
		doc.body.appendChild( svg );
		return defs;
	}

	function ensureStyleEl( doc ) {
		var style = doc.getElementById( STYLE_ID );
		if ( style ) {
			return style;
		}
		style = doc.createElement( 'style' );
		style.id = STYLE_ID;
		doc.head.appendChild( style );
		return style;
	}

	function injectStyleRule( doc, id, selector ) {
		var style = ensureStyleEl( doc );
		var rule = selector
			? '.' + id + ' ' + selector + '{filter:url(#' + id + ');}'
			: '.' + id + '{filter:url(#' + id + ');}';
		if ( style.textContent.indexOf( rule ) === -1 ) {
			style.textContent += rule;
		}
	}

	function buildTurbulenceSVG( id, effect, baseFreq, scale ) {
		var f = Math.round( baseFreq * 10000 ) / 10000;
		var s = Math.round( scale * 100 ) / 100;
		switch ( effect ) {
			case 'rough':
				return (
					'<filter id="' + id + '">' +
					'<feTurbulence type="fractalNoise" baseFrequency="' + f + '" numOctaves="3" result="noise"/>' +
					'<feDisplacementMap in="SourceGraphic" in2="noise" scale="' + s + '"/>' +
					'</filter>'
				);
			case 'ink':
				return (
					'<filter id="' + id + '">' +
					'<feMorphology operator="dilate" radius="1" in="SourceGraphic" result="dilated"/>' +
					'<feTurbulence type="fractalNoise" baseFrequency="' + f + '" numOctaves="2" result="noise"/>' +
					'<feDisplacementMap in="dilated" in2="noise" scale="' + s + '"/>' +
					'</filter>'
				);
			case 'watercolor':
				return (
					'<filter id="' + id + '">' +
					'<feTurbulence type="fractalNoise" baseFrequency="' + f + '" numOctaves="3" result="noise"/>' +
					'<feDisplacementMap in="SourceGraphic" in2="noise" scale="' + s + '" result="displaced"/>' +
					'<feGaussianBlur in="displaced" stdDeviation="0.6"/>' +
					'</filter>'
				);
			case 'wavy':
				return (
					'<filter id="' + id + '">' +
					'<feTurbulence type="turbulence" baseFrequency="' + f + '" numOctaves="2" result="noise"/>' +
					'<feDisplacementMap in="SourceGraphic" in2="noise" scale="' + s + '"/>' +
					'</filter>'
				);
		}
		return '';
	}

	function injectTurbulenceStyleRule( doc, id, selector ) {
		var style = ensureStyleEl( doc );
		var activeRule = selector
			? '.' + id + ' ' + selector + ':active{filter:url(#' + id + ');}'
			: '.' + id + ':active{filter:url(#' + id + ');}';
		var previewRule = selector
			? '.' + id + '.is-filterpress-preview ' + selector + '{filter:url(#' + id + ');}'
			: '.' + id + '.is-filterpress-preview{filter:url(#' + id + ');}';
		if ( style.textContent.indexOf( activeRule ) === -1 ) {
			style.textContent += activeRule;
		}
		if ( style.textContent.indexOf( previewRule ) === -1 ) {
			style.textContent += previewRule;
		}
	}

	function injectTurbulenceDefs( doc, id, effect, baseFreq, scale ) {
		var defs = ensureSVGDefsEl( doc );
		if ( doc.getElementById( id ) ) {
			return;
		}
		var wrapper = doc.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		wrapper.innerHTML = buildTurbulenceSVG( id, effect, baseFreq, scale );
		var filterEl = wrapper.querySelector( 'filter' );
		if ( filterEl ) {
			defs.appendChild( filterEl );
		}
	}

	function buildSquiggleSVG( id, intensity, speed ) {
		var scale = Math.round( intensity * 100 ) / 100;
		var fps = Math.max( 1, Math.min( 30, Math.round( speed ) ) );
		// SMIL discrete needs >=2 values; widen N and stretch dur so cadence
		// stays at exactly `fps` changes/second.
		var n = Math.max( 2, fps );
		var dur = ( Math.round( ( n / fps ) * 10000 ) / 10000 ) + 's';
		var values = [];
		for ( var i = 1; i <= n; i++ ) {
			values.push( i );
		}
		return (
			'<filter id="' + id + '">' +
			'<feTurbulence type="fractalNoise" baseFrequency="0.04" numOctaves="2" seed="1">' +
			'<animate attributeName="seed" values="' + values.join( ';' ) + '" dur="' + dur + '" calcMode="discrete" repeatCount="indefinite"/>' +
			'</feTurbulence>' +
			'<feDisplacementMap in="SourceGraphic" scale="' + scale + '"/>' +
			'</filter>'
		);
	}

	function buildGrungeSVG( id, style, ruggedness, depth ) {
		var dep = Math.round( depth * 100 ) / 100;
		var noise = buildGrungeNoiseSVG( style, ruggedness );
		var setup = grungeBaseShapeSVG( style );
		var input = grungeChewInput( style );
		return (
			'<filter id="' + id + '" x="-50%" y="-50%" width="200%" height="200%">' +
			noise +
			setup +
			'<feDisplacementMap in="' + input + '" in2="noise" scale="' + dep + '" result="displacedAlpha"/>' +
			'<feComponentTransfer in="displacedAlpha" result="mask">' +
			'<feFuncA type="discrete" tableValues="0 0 0 1"/>' +
			'</feComponentTransfer>' +
			'<feComposite in="SourceGraphic" in2="mask" operator="in"/>' +
			'</filter>'
		);
	}

	function buildGrungeNoiseSVG( style, ruggedness ) {
		var r = Math.max( 0.005, Math.min( 0.2, ruggedness ) );
		switch ( style ) {
			case 'brush': {
				var bx = Math.round( r * 0.4 * 10000 ) / 10000;
				var by = Math.round( Math.max( 0.15, r * 6 ) * 10000 ) / 10000;
				return (
					'<feTurbulence type="fractalNoise" baseFrequency="' +
					bx +
					' ' +
					by +
					'" numOctaves="2" result="noise"/>'
				);
			}
			case 'stamp': {
				var bs = Math.round( Math.max( 0.005, r * 0.35 ) * 10000 ) / 10000;
				return (
					'<feTurbulence type="fractalNoise" baseFrequency="' +
					bs +
					'" numOctaves="1" result="noise"/>'
				);
			}
			case 'torn':
			default: {
				var bt = Math.round( r * 10000 ) / 10000;
				return (
					'<feTurbulence type="fractalNoise" baseFrequency="' +
					bt +
					'" numOctaves="3" result="noise"/>'
				);
			}
		}
	}

	function isGrungeMaskStyle( style ) {
		return style === 'brush' || style === 'stamp';
	}

	function grungeMaskSVG( style ) {
		// See PHP grunge_mask_svg() — same shapes, viewBox 0 0 400 400 with
		// painted content at coords 100-300.
		switch ( style ) {
			case 'brush':
				return (
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" preserveAspectRatio="none">' +
					'<g fill="#fff">' +
					'<path d="M 100 198 C 102 180 115 167 138 161 C 165 156 200 154 235 158 C 263 162 285 170 295 185 C 300 200 295 213 285 222 C 270 232 240 238 210 240 C 175 242 145 239 125 233 C 110 228 100 215 100 200 Z"/>' +
					'<path d="M 130 152 C 160 149 220 148 260 153 L 263 158 C 220 153 160 154 130 158 Z"/>' +
					'<path d="M 145 145 C 175 143 215 142 248 144 L 250 149 C 215 146 175 146 145 148 Z"/>' +
					'<path d="M 135 248 C 170 251 220 252 255 250 L 252 244 C 220 246 170 246 135 244 Z"/>' +
					'<path d="M 150 254 C 180 256 215 256 240 254 L 240 250 C 215 252 180 252 150 250 Z"/>' +
					'<circle cx="285" cy="225" r="1.5"/>' +
					'<circle cx="293" cy="215" r="2"/>' +
					'</g>' +
					'</svg>'
				);
			case 'stamp':
				return (
					'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400" preserveAspectRatio="none">' +
					'<path fill="#fff" fill-rule="evenodd" d="' +
					'M 102 108 L 125 102 L 152 112 L 178 105 L 205 115 L 232 103 L 258 113 L 285 105 L 297 112' +
					' L 295 138 L 298 165 L 292 192 L 297 218 L 290 245 L 296 272 L 290 295' +
					' L 268 298 L 245 290 L 220 297 L 195 290 L 170 298 L 145 290 L 120 297 L 105 295' +
					' L 100 268 L 105 240 L 100 215 L 108 188 L 102 162 L 105 135 Z' +
					' M 145 145 L 165 142 L 170 165 L 148 168 Z' +
					' M 215 175 L 245 178 L 240 200 L 213 197 Z' +
					' M 175 220 L 200 222 L 195 248 L 170 245 Z' +
					' M 250 250 L 275 252 L 270 275 L 248 272 Z' +
					'"/>' +
					'</svg>'
				);
		}
		return '';
	}

	function grungeMaskDataUri( style ) {
		return 'data:image/svg+xml;utf8,' + encodeURIComponent( grungeMaskSVG( style ) );
	}

	function grungeBaseShapeSVG( style ) {
		if ( ! isGrungeMaskStyle( style ) ) {
			return '';
		}
		var uri = grungeMaskDataUri( style );
		// Load the designed mask, intersect with SourceAlpha to crop. The
		// grunge texture lives in the SVG path data itself.
		return (
			'<feImage href="' +
			uri +
			'" xlink:href="' +
			uri +
			'" preserveAspectRatio="none" result="rawShape"/>' +
			'<feComposite in="rawShape" in2="SourceAlpha" operator="in" result="baseShape"/>'
		);
	}

	function grungeChewInput( style ) {
		return isGrungeMaskStyle( style ) ? 'baseShape' : 'SourceAlpha';
	}

	function buildGrungeBorderSVG( id, style, ruggedness, depth, borderWidth ) {
		// Round morphology radii to integer pixels — sub-pixel kernels are
		// rounded inconsistently across browsers and can produce empty masks.
		var bwInt = Math.round( borderWidth );
		var offsetInt = Math.round( borderWidth / 2 );
		var bw = bwInt;
		var off = offsetInt;
		var bwOff = bwInt + offsetInt;
		// Cap displacement at 2*bw so the chewed border stays proportional
		// to the user's CSS border width.
		var depCapped = Math.min( depth, Math.max( 2 * borderWidth, 2 ) );
		var dep = Math.round( depCapped * 100 ) / 100;
		var noise = buildGrungeNoiseSVG( style, ruggedness );
		// See PHP build_grunge_border_svg(). Pipeline builds a thicker
		// ring with outer edge at element edge and inner edge at bw+offset
		// inset, so the image+border outer extent is preserved.
		return (
			'<filter id="' + id + '" x="-50%" y="-50%" width="200%" height="200%">' +
			noise +
			'<feMorphology in="SourceAlpha" operator="erode" radius="' + bw + '" result="contentMaskOriginal"/>' +
			'<feComposite in="SourceAlpha" in2="contentMaskOriginal" operator="out" result="borderMaskOriginal"/>' +
			'<feComposite in="SourceGraphic" in2="borderMaskOriginal" operator="in" result="coloredBorder"/>' +
			'<feMorphology in="SourceAlpha" operator="erode" radius="' + bwOff + '" result="extendedInner"/>' +
			'<feComposite in="SourceAlpha" in2="extendedInner" operator="out" result="extendedBorderMask"/>' +
			'<feMorphology in="coloredBorder" operator="dilate" radius="' + off + '" result="extendedColoredBorder"/>' +
			'<feComposite in="extendedColoredBorder" in2="extendedBorderMask" operator="in" result="shiftedColoredBorder"/>' +
			// Image extends to the original content edge so it's visible
			// behind chewed retreats in the inner overlap.
			'<feComposite in="SourceGraphic" in2="contentMaskOriginal" operator="in" result="imageContent"/>' +
			'<feDisplacementMap in="shiftedColoredBorder" in2="noise" scale="' + dep + '" result="displacedBorder"/>' +
			'<feComponentTransfer in="displacedBorder" result="chewedBorder">' +
			'<feFuncA type="discrete" tableValues="0 0 0 1"/>' +
			'</feComponentTransfer>' +
			'<feMerge>' +
			'<feMergeNode in="imageContent"/>' +
			'<feMergeNode in="chewedBorder"/>' +
			'</feMerge>' +
			'</filter>'
		);
	}

	function injectGrungeBorderDefs( doc, id, style, ruggedness, depth, borderWidth ) {
		var defs = ensureSVGDefsEl( doc );
		if ( doc.getElementById( id ) ) {
			return;
		}
		var wrapper = doc.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		wrapper.innerHTML = buildGrungeBorderSVG(
			id,
			style,
			ruggedness,
			depth,
			borderWidth
		);
		var filterEl = wrapper.querySelector( 'filter' );
		if ( filterEl ) {
			defs.appendChild( filterEl );
		}
	}

	function injectGrungeBorderStyleRule( doc, id, selector ) {
		var style = ensureStyleEl( doc );
		var sel = selector ? '.' + id + ' ' + selector : '.' + id;
		var rule = sel + '{filter:url(#' + id + ');}';
		if ( style.textContent.indexOf( rule ) === -1 ) {
			style.textContent += rule;
		}
	}

	function injectGrungeDefs( doc, id, style, ruggedness, depth ) {
		var defs = ensureSVGDefsEl( doc );
		if ( doc.getElementById( id ) ) {
			return;
		}
		var wrapper = doc.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		wrapper.innerHTML = buildGrungeSVG( id, style, ruggedness, depth );
		var filterEl = wrapper.querySelector( 'filter' );
		if ( filterEl ) {
			defs.appendChild( filterEl );
		}
	}

	function injectSquiggleDefs( doc, id, intensity, speed ) {
		var defs = ensureSVGDefsEl( doc );
		if ( doc.getElementById( id ) ) {
			return;
		}
		var wrapper = doc.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		wrapper.innerHTML = buildSquiggleSVG( id, intensity, speed );
		var filterEl = wrapper.querySelector( 'filter' );
		if ( filterEl ) {
			defs.appendChild( filterEl );
		}
	}

	function injectGrainyRules( doc, amount ) {
		var style = ensureStyleEl( doc );
		var baseRule =
			'.filterpress-grainy{position:relative;isolation:isolate;}' +
			'.filterpress-grainy::before{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;background-image:url("' +
			GRAINY_NOISE_URI +
			'");background-repeat:repeat;background-size:200px 200px;mix-blend-mode:multiply;opacity:var(--filterpress-grain-opacity,0.5);}';
		if ( style.textContent.indexOf( '.filterpress-grainy::before' ) === -1 ) {
			style.textContent += baseRule;
		}
		var amountRule =
			'.filterpress-grainy-' + amount + '{--filterpress-grain-opacity:' +
			Math.round( ( amount / 100 ) * 100 ) / 100 +
			';}';
		if ( style.textContent.indexOf( amountRule ) === -1 ) {
			style.textContent += amountRule;
		}
	}
} )( window.wp, window.FILTERPRESS_DATA );
