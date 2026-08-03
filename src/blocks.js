( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var registerBlockType = wp.blocks.registerBlockType;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var RichText = wp.blockEditor.RichText;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var __ = wp.i18n.__;

	// UH brand color palette (matches theme.json). Used as the default
	// swatch set for ColorPalette controls in our custom blocks.
	var UH_COLORS = [
		{ name: 'White',          color: '#FFFFFF', slug: 'uh-white' },
		{ name: 'Black',          color: '#000000', slug: 'uh-black' },
		{ name: 'UH Red',         color: '#C8102E', slug: 'uh-red' },
		{ name: 'Slate',          color: '#54585A', slug: 'uh-slate' },
		{ name: 'Brick',          color: '#960C22', slug: 'uh-brick' },
		{ name: 'Chocolate',      color: '#640817', slug: 'uh-chocolate' },
		{ name: 'Cream',          color: '#FFF9D9', slug: 'uh-cream' },
		{ name: 'Gray',           color: '#888B8D', slug: 'uh-gray' },
		{ name: 'Gold',           color: '#F6BE00', slug: 'uh-gold' },
		{ name: 'Mustard',        color: '#D89B00', slug: 'uh-mustard' },
		{ name: 'Ocher',          color: '#B97800', slug: 'uh-ocher' },
		{ name: 'Teal',           color: '#00B388', slug: 'uh-teal' },
		{ name: 'Green',          color: '#00866C', slug: 'uh-green' },
		{ name: 'Forest',         color: '#005950', slug: 'uh-forest' },
		{ name: 'Background Alt', color: '#F4F5F5', slug: 'bg-alt' },
		{ name: 'Background Dark',color: '#101112', slug: 'bg-dark' },
		{ name: 'Border',         color: '#DEE1E2', slug: 'border' }
	];

	// ============================================================
	// Compact color selector: a small swatch button that opens a
	// dropdown with UH brand color swatches + custom color picker.
	// Saves vertical space vs. the full inline ColorPalette.
	// ============================================================
	function renderColorSelector( label, value, onChange ) {
		return el( 'div', { style: { marginBottom: '12px' } },
			el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px', fontSize: '11px' } }, label ),
			el( Dropdown, {
				renderToggle: function ( ref ) {
					return el( wp.components.Button, {
						onClick: ref.onToggle,
						'aria-expanded': ref.isOpen,
						variant: 'secondary',
						style: { width: '100%', justifyContent: 'flex-start', padding: '4px 8px', height: '28px' }
					},
						el( 'span', {
							style: {
								display: 'inline-block', width: '16px', height: '16px',
								borderRadius: '50%', marginRight: '8px',
								background: value || 'transparent',
								border: value ? '1px solid #ccc' : '1px dashed #ccc',
								verticalAlign: 'middle'
							}
						} ),
						el( 'span', { style: { fontSize: '12px', verticalAlign: 'middle' } },
							value ? value : __( 'Select color', 'rcmi-toolkit' )
						)
					);
				},
				renderContent: function () {
					return el( 'div', { style: { padding: '8px', width: '220px' } },
						el( ColorPalette, {
							value: value,
							colors: UH_COLORS,
							onChange: function ( color ) {
								onChange( color || '' );
							},
							disableCustomColors: false,
							clearable: true
						} )
					);
				}
			} )
		);
	}

	// ============================================================
	// Reusable multi-stop gradient picker.
	// Builds inspector controls for up to 6 color stops with
	// color, opacity, and position, plus type (linear/radial)
	// and angle (for linear). Returns an array of elements.
	// ============================================================

	function hexToRgba( hex, alpha ) {
		var h = ( hex || '#ffffff' ).replace( '#', '' );
		if ( h.length === 3 ) { h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2]; }
		var r = parseInt( h.substr( 0, 2 ), 16 ) || 255;
		var g = parseInt( h.substr( 2, 2 ), 16 ) || 255;
		var b = parseInt( h.substr( 4, 2 ), 16 ) || 255;
		return 'rgba(' + r + ',' + g + ',' + b + ',' + ( alpha != null ? alpha : 1 ) + ')';
	}

	function buildGradientCSS( stops, type, angle ) {
		if ( ! stops || ! stops.length ) { return 'transparent'; }
		var parts = stops.map( function ( s ) {
			return hexToRgba( s.color, s.opacity ) + ' ' + ( s.position || 0 ) + '%';
		} );
		if ( type === 'radial' ) {
			return 'radial-gradient(circle at center, ' + parts.join( ', ' ) + ')';
		}
		return 'linear-gradient(' + ( angle || 90 ) + 'deg, ' + parts.join( ', ' ) + ')';
	}

	// Default 3-stop gradient (matches the old hardcoded scrim).
	function defaultScrimStops( baseColor, baseOpacity ) {
		return [
			{ color: baseColor || '#f8f5ee', opacity: baseOpacity != null ? baseOpacity : 0.85, position: 0 },
			{ color: baseColor || '#f8f5ee', opacity: ( baseOpacity != null ? baseOpacity : 0.85 ) * 0.4, position: 40 },
			{ color: baseColor || '#f8f5ee', opacity: 0, position: 65 }
		];
	}

	// Render the gradient picker controls.
	// onChange( newStops, newType, newAngle ) is called with updated values.
	function renderGradientPicker( stops, type, angle, onChange ) {
		var maxStops = 6;
		stops = stops && stops.length ? stops : defaultScrimStops( '#ffffff', 0.9 );
		type = type || 'linear';
		angle = angle != null ? angle : 90;

		function updateStop( idx, key, val ) {
			var newStops = stops.map( function ( s, i ) {
				var ns = Object.assign( {}, s );
				if ( i === idx ) { ns[ key ] = val; }
				return ns;
			} );
			onChange( newStops, type, angle );
		}

		function addStop() {
			if ( stops.length >= maxStops ) { return; }
			var lastPos = stops.length ? stops[ stops.length - 1 ].position : 50;
			var newStop = { color: '#ffffff', opacity: 0.5, position: Math.min( 100, lastPos + 20 ) };
			onChange( stops.concat( [ newStop ] ), type, angle );
		}

		function removeStop( idx ) {
			if ( stops.length <= 1 ) { return; }
			onChange( stops.filter( function ( _, i ) { return i !== idx; } ), type, angle );
		}

		var stopControls = stops.map( function ( stop, idx ) {
			return el( 'div', {
				key: 'grad-stop-' + idx,
				style: { borderBottom: '1px solid #e0e0e0', paddingBottom: '12px', marginBottom: '12px' }
			},
				el( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' } },
					el( 'strong', null, __( 'Stop ' + ( idx + 1 ), 'rcmi-toolkit' ) ),
					stops.length > 1 ? el( wp.components.Button, {
						onClick: function () { removeStop( idx ); },
						variant: 'tertiary',
						isDestructive: true,
						isSmall: true
					}, __( 'Remove', 'rcmi-toolkit' ) ) : null
				),
				el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px' } }, __( 'Color', 'rcmi-toolkit' ) ),
				renderColorSelector( __( 'Color', 'rcmi-toolkit' ), stop.color || '#ffffff', function ( v ) { updateStop( idx, 'color', v ); } ),
				el( RangeControl, {
					label: __( 'Opacity', 'rcmi-toolkit' ),
					value: stop.opacity != null ? stop.opacity : 1,
					onChange: function ( v ) { updateStop( idx, 'opacity', v ); },
					min: 0,
					max: 1,
					step: 0.05
				} ),
				el( RangeControl, {
					label: __( 'Position (%)', 'rcmi-toolkit' ),
					value: stop.position || 0,
					onChange: function ( v ) { updateStop( idx, 'position', v ); },
					min: 0,
					max: 100,
					step: 1
				} )
			);
		} );

		return [
			// Gradient type toggle.
			el( SelectControl, {
				key: 'grad-type',
				label: __( 'Gradient type', 'rcmi-toolkit' ),
				value: type,
				options: [
					{ label: 'Linear', value: 'linear' },
					{ label: 'Radial', value: 'radial' }
				],
				onChange: function ( v ) { onChange( stops, v, angle ); }
			} ),
			// Angle control (linear only).
			type === 'linear' ? el( RangeControl, {
				key: 'grad-angle',
				label: __( 'Angle (degrees)', 'rcmi-toolkit' ),
				value: angle,
				onChange: function ( v ) { onChange( stops, type, v ); },
				min: 0,
				max: 360,
				step: 15
			} ) : null,
			// Live preview bar.
			el( 'label', {
				key: 'grad-preview-label',
				style: { display: 'block', fontWeight: '600', marginBottom: '4px' }
			}, __( 'Gradient preview', 'rcmi-toolkit' ) ),
			el( 'div', {
				key: 'grad-preview',
				style: {
					height: '40px',
					borderRadius: '4px',
					border: '1px solid #ddd',
					background: buildGradientCSS( stops, type, angle ),
					marginBottom: '16px'
				}
			} ),
			// Stop controls.
			stopControls,
			// Add stop button.
			stops.length < maxStops ? el( wp.components.Button, {
				key: 'grad-add',
				onClick: addStop,
				variant: 'secondary',
				isSmall: true,
				style: { width: '100%', justifyContent: 'center' }
			}, __( '+ Add color stop', 'rcmi-toolkit' ) ) : null
		];
	}

	// ============================================================
	// Custom inline formats: always-visible toolbar controls.
	//
	// Toolbar layout (left → right), controlled by priority:
	//   [Block icon] [Drag] [Move ↑↓]
	//   [Bold] [Italic]              ← core (priority 1-2)
	//   [Font Family ▾]              ← priority 4
	//   [Font Size ▾]                ← priority 5
	//   [Highlight]                  ← priority 6
	//   [Align text]                 ← core (priority 10)
	// ============================================================
	var registerFormatType = wp.richText.registerFormatType;
	var unregisterFormatType = wp.richText.unregisterFormatType;
	var BlockControls = wp.blockEditor.BlockControls;
	var ToolbarButton = wp.components.ToolbarButton;
	var Dropdown = wp.components.Dropdown;
	var ColorPalette = wp.components.ColorPalette;
	var applyFormat = wp.richText.applyFormat;
	var removeFormat = wp.richText.removeFormat;
	var getActiveFormat = wp.richText.getActiveFormat;

	// Unregister core text-color so our custom one can use the same className
	try { unregisterFormatType( 'core/text-color' ); } catch ( e ) {}

	function getColors() {
		var s = wp.data.select( 'core/editor' ).getEditorSettings();
		return s.colors || [];
	}

	var fontFamilies = [
		{ slug: 'display', name: 'League Gothic', fontFamily: "'League Gothic', 'Arial Narrow', sans-serif" },
		{ slug: 'body', name: 'Source Sans 3', fontFamily: "'Source Sans 3', -apple-system, BlinkMacSystemFont, sans-serif" },
		{ slug: 'serif', name: 'Crimson Pro', fontFamily: "'Crimson Pro', Georgia, serif" }
	];

	var fontSizes = [
		{ slug: 'small', name: 'Small', size: '0.78rem' },
		{ slug: 'medium', name: 'Medium', size: '1rem' },
		{ slug: 'large', name: 'Large', size: '1.1rem' },
		{ slug: 'x-large', name: 'Extra Large', size: '1.35rem' },
		{ slug: 'xx-large', name: 'Display', size: '2rem' },
		{ slug: 'xxx-large', name: 'Hero', size: '3.75rem' }
	];

	// --- Font Family dropdown (priority 4) ---
	registerFormatType( 'rcmi/font-family', {
		title: __( 'Font Family', 'rcmi-toolkit' ),
		tagName: 'span',
		className: 'has-inline-font-family',
		attributes: { style: 'style', class: 'class' },
		priority: 4,
		edit: function ( props ) {
			var activeFont = __( 'Font Family', 'rcmi-toolkit' );
			var fmt = getActiveFormat( props.value, 'rcmi/font-family' );
			if ( fmt && fmt.attributes.style ) {
				var m = fmt.attributes.style.match( /font-family:\s*([^;]+)/ );
				if ( m ) {
					for ( var i = 0; i < fontFamilies.length; i++ ) {
						if ( fontFamilies[ i ].fontFamily === m[ 1 ].trim() ) { activeFont = fontFamilies[ i ].name; break; }
					}
				}
			}
			return el( BlockControls, null,
				el( Dropdown, {
					renderToggle: function ( ref ) {
						return el( ToolbarButton, {
							onClick: ref.onToggle,
							'aria-expanded': ref.isOpen
						}, activeFont );
					},
					renderContent: function () {
						return el( 'div', { style: { padding: '4px', minWidth: '160px' } },
							fontFamilies.map( function ( f ) {
								return el( 'button', {
									key: f.slug,
									onClick: function () {
										props.onChange( applyFormat( props.value, {
											type: 'rcmi/font-family',
											attributes: { style: 'font-family: ' + f.fontFamily, class: 'has-' + f.slug + '-font' }
										} ) );
									},
									style: { display: 'block', width: '100%', padding: '8px 12px', textAlign: 'left', fontFamily: f.fontFamily, fontSize: '14px', cursor: 'pointer', background: 'transparent', border: 'none' }
								}, f.name );
							} )
						);
					}
				} )
			);
		}
	} );

	// --- Font Size dropdown (priority 5) ---
	registerFormatType( 'rcmi/font-size', {
		title: __( 'Font Size', 'rcmi-toolkit' ),
		tagName: 'span',
		className: 'has-inline-font-size',
		attributes: { style: 'style' },
		priority: 5,
		edit: function ( props ) {
			var activeSize = __( 'Font Size', 'rcmi-toolkit' );
			var fmt = getActiveFormat( props.value, 'rcmi/font-size' );
			if ( fmt && fmt.attributes.style ) {
				var m = fmt.attributes.style.match( /font-size:\s*([^;]+)/ );
				if ( m ) {
					for ( var i = 0; i < fontSizes.length; i++ ) {
						if ( fontSizes[ i ].size === m[ 1 ].trim() ) { activeSize = fontSizes[ i ].name; break; }
					}
				}
			}
			return el( BlockControls, null,
				el( Dropdown, {
					renderToggle: function ( ref ) {
						return el( ToolbarButton, {
							onClick: ref.onToggle,
							'aria-expanded': ref.isOpen
						}, activeSize );
					},
					renderContent: function () {
						return el( 'div', { style: { padding: '4px', minWidth: '140px' } },
							fontSizes.map( function ( f ) {
								return el( 'button', {
									key: f.slug,
									onClick: function () {
										props.onChange( applyFormat( props.value, {
											type: 'rcmi/font-size',
											attributes: { style: 'font-size: ' + f.size }
										} ) );
									},
									style: { display: 'block', width: '100%', padding: '8px 12px', textAlign: 'left', fontSize: f.size, cursor: 'pointer', background: 'transparent', border: 'none' }
								}, f.name );
							} )
						);
					}
				} )
			);
		}
	} );

	// --- Text Color (priority 6) ---
	// Icon: "A" with a color bar underneath that reflects the active color.
	function makeTextColorIcon( color ) {
		var swatch = color || 'currentColor';
		return el( 'svg', { width: 20, height: 20, viewBox: '0 0 20 20', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': true },
			el( 'path', { d: 'M10 2.5 4.5 15h2.2l1.1-2.8h4.4l1.1 2.8h2.2L10 2.5zm0 3.2 1.5 4.6h-3L10 5.7z', fill: 'currentColor' } ),
			el( 'rect', { x: 3.5, y: 16, width: 13, height: 2, rx: 1, fill: swatch } )
		);
	}

	registerFormatType( 'rcmi/text-color', {
		title: __( 'Text Color', 'rcmi-toolkit' ),
		tagName: 'mark',
		className: 'has-inline-color',
		attributes: { style: 'style', class: 'class' },
		priority: 6,
		edit: function ( props ) {
			var activeColor;
			var fmt = getActiveFormat( props.value, 'rcmi/text-color' );
			if ( fmt ) {
				var cls = ( fmt.attributes.class || '' ).split( /\s+/ );
				var colors = getColors();
				for ( var c = 0; c < cls.length; c++ ) {
					var match = cls[ c ].match( /^has-(.+)-color$/ );
					if ( match && match[ 1 ] !== 'inline' ) {
						for ( var i = 0; i < colors.length; i++ ) {
							if ( colors[ i ].slug === match[ 1 ] ) { activeColor = colors[ i ].color; break; }
						}
						if ( activeColor ) break;
					}
				}
				if ( ! activeColor ) {
					var style = fmt.attributes.style || '';
					var m = style.match( /(?:^|;)color:\s*([^;]+)/ );
					if ( m ) { activeColor = m[ 1 ].trim(); }
				}
			}
			return el( BlockControls, null,
				el( Dropdown, {
					renderToggle: function ( ref ) {
						return el( ToolbarButton, {
							icon: makeTextColorIcon( activeColor ),
							label: __( 'Text Color', 'rcmi-toolkit' ),
							isPressed: props.isActive,
							onClick: ref.onToggle,
							'aria-expanded': ref.isOpen
						} );
					},
					renderContent: function () {
						return el( 'div', { style: { padding: '8px' } },
							el( ColorPalette, {
								value: activeColor,
								colors: UH_COLORS,
								onChange: function ( color ) {
									if ( ! color ) {
										props.onChange( removeFormat( props.value, { type: 'rcmi/text-color' } ) );
									} else {
										var colors = UH_COLORS;
										var preset = null;
										for ( var i = 0; i < colors.length; i++ ) {
											if ( colors[ i ].color === color ) { preset = colors[ i ]; break; }
										}
										var attrs;
										if ( preset ) {
											attrs = { class: 'has-inline-color has-' + preset.slug + '-color', style: 'color:' + color + ';background-color:rgba(0, 0, 0, 0)' };
										} else {
											attrs = { class: 'has-inline-color', style: 'color:' + color + ';background-color:rgba(0, 0, 0, 0)' };
										}
										props.onChange( applyFormat( props.value, { type: 'rcmi/text-color', attributes: attrs } ) );
									}
								}
							} )
						);
					}
				} )
			);
		}
	} );

	// --- Highlight (background color, priority 7) ---
	// Icon: marker pen with a color bar that reflects the active highlight color.
	function makeHighlightIcon( color ) {
		var swatch = color || 'currentColor';
		return el( 'svg', { width: 20, height: 20, viewBox: '0 0 20 20', xmlns: 'http://www.w3.org/2000/svg', 'aria-hidden': true },
			el( 'path', { d: 'm4 13.8 7.8-7.8 3.5 3.5-7.8 7.8H4v-3.5zm8.8-8.8 1.1-1.1a1.6 1.6 0 0 1 2.3 0l.7.7a1.6 1.6 0 0 1 0 2.3l-1.1 1.1-3-3z', fill: 'currentColor' } ),
			el( 'path', { d: 'M3 17.5h14', stroke: swatch, 'stroke-width': 2.5, 'stroke-linecap': 'round' } )
		);
	}

	registerFormatType( 'rcmi/highlight', {
		title: __( 'Highlight', 'rcmi-toolkit' ),
		tagName: 'mark',
		className: 'has-inline-highlight',
		attributes: { style: 'style' },
		priority: 7,
		edit: function ( props ) {
			var activeColor;
			var fmt = getActiveFormat( props.value, 'rcmi/highlight' );
			if ( fmt && fmt.attributes.style ) {
				var m = fmt.attributes.style.match( /background-color:\s*([^;]+)/ );
				if ( m ) { activeColor = m[ 1 ].trim(); }
			}
			return el( BlockControls, null,
				el( Dropdown, {
					renderToggle: function ( ref ) {
						return el( ToolbarButton, {
							icon: makeHighlightIcon( activeColor ),
							label: __( 'Highlight', 'rcmi-toolkit' ),
							isPressed: props.isActive,
							onClick: ref.onToggle,
							'aria-expanded': ref.isOpen
						} );
					},
					renderContent: function () {
						return el( 'div', { style: { padding: '8px' } },
							el( ColorPalette, {
								value: activeColor,
								colors: UH_COLORS,
								onChange: function ( color ) {
									if ( ! color ) {
										props.onChange( removeFormat( props.value, { type: 'rcmi/highlight' } ) );
									} else {
										props.onChange( applyFormat( props.value, {
											type: 'rcmi/highlight',
											attributes: { style: 'background-color:' + color }
										} ) );
									}
								}
							} )
						);
					}
				} )
			);
		}
	} );

	// Block: rcmi/quote-block
	// Large pull quote with quotation marks and citation.
	// ============================================================
	registerBlockType( 'rcmi/quote-block', {
		apiVersion: 3,
		title: __( 'RCMI Quote Block', 'rcmi-toolkit' ),
		description: __( 'Large pull quote with quotation marks and citation.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'format-quote',
		supports: {
			html: false,
			align: [ 'full', 'wide' ],
			color: {
				text: true,
				background: false,
				gradient: false,
				link: false,
			},
			typography: {
				fontFamily: true,
				textAlign: true,
			},
		},
		attributes: {
			quote:    { type: 'string', default: "Chronic disease doesn't yield to single disciplines or single institutions. It yields to relationships — built slowly, across communities, and measured in lives improved." },
			citeName: { type: 'string', default: 'RCMI Coordinating Center' },
			citeRole: { type: 'string', default: 'Guiding Principle' }
		},
		edit: function ( props ) {
			var blockProps = useBlockProps( { className: 'rcmi-quote-editor' } );
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap quote-block' },
					el( 'div', { className: 'quote-mark' }, '\u201C' ),
					el( 'div', { className: 'quote-body' },
						el( InnerBlocks, {
							allowedBlocks: [ 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'core/image' ],
							template: [
								[ 'core/paragraph', {
									placeholder: __( 'Quote text…', 'rcmi-toolkit' ),
									content: "Chronic disease doesn't yield to single disciplines or single institutions. It yields to relationships — built slowly, across communities, and measured in lives improved."
								} ],
								[ 'core/paragraph', {
									placeholder: __( 'Citation…', 'rcmi-toolkit' ),
									content: 'RCMI Coordinating Center, Guiding Principle',
									className: 'cite'
								} ]
							],
							templateLock: false
						} )
					),
					el( 'div', { className: 'quote-mark quote-mark-close' }, '\u201D' )
				)
			);
		},
		save: function () {
			// InnerBlocks content is serialized between the block delimiters.
			// The render_callback wraps it with the quote-block layout.
			return el( InnerBlocks.Content );
		}
	} );

	// ============================================================
	// Block: rcmi/cta-band
	// Call-to-action band with heading + buttons.
	// ============================================================
	registerBlockType( 'rcmi/cta-band', {
		apiVersion: 3,
		title: __( 'RCMI CTA Band', 'rcmi-toolkit' ),
		description: __( 'A call-to-action band with heading on the left and buttons on the right.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'megaphone',
		supports: {
			html: false,
			align: [ 'full', 'wide' ],
			color: {
				text: true,
				background: false,
				gradient: false,
				link: false,
			},
			typography: {
				fontFamily: true,
				textAlign: true,
			},
		},
		attributes: {
			heading:     { type: 'string', default: 'Ready to start?' },
			text:        { type: 'string', default: 'Find the support you need to move your research forward.' },
			btn1Text:    { type: 'string', default: 'Request Support' },
			btn1Link:    { type: 'string', default: '/#start' },
			btn1Style:   { type: 'string', default: 'btn-outline' },
			btn2Text:    { type: 'string', default: 'Explore Research' },
			btn2Link:    { type: 'string', default: '/cores/#investigator' },
			btn2Style:   { type: 'string', default: 'btn-primary' }
		},
		edit: function ( props ) {
			var blockProps = useBlockProps( { className: 'rcmi-cta-editor' } );
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap' },
					el( 'div', { className: 'cta-band' },
						el( InnerBlocks, {
							allowedBlocks: [ 'core/columns', 'core/heading', 'core/paragraph', 'core/buttons', 'core/image', 'core/spacer', 'core/separator' ],
							template: [
								[ 'core/columns', {}, [
									[ 'core/column', { className: 'cta-copy' }, [
										[ 'core/heading', {
											level: 2,
											placeholder: __( 'Heading…', 'rcmi-toolkit' ),
											content: 'Ready to start?'
										} ],
										[ 'core/paragraph', {
											placeholder: __( 'Text…', 'rcmi-toolkit' ),
											content: 'Find the support you need to move your research forward.'
										} ]
									] ],
									[ 'core/column', { className: 'cta-actions' }, [
										[ 'core/buttons', {}, [
											[ 'core/button', {
												text: 'Request Support',
												url: '/#start',
												className: 'btn-outline'
											} ],
											[ 'core/button', {
												text: 'Explore Research',
												url: '/cores/#investigator',
												className: 'btn-primary'
											} ]
										] ]
									] ]
								] ]
							],
							templateLock: false
						} )
					)
				)
			);
		},
		save: function () {
			// InnerBlocks content is serialized between the block delimiters.
			// The render_callback wraps it with the CTA band layout.
			return el( InnerBlocks.Content );
		}
	} );

	// ============================================================
	// Block: rcmi/impact-stats-block
	// Four-stat grid with large numbers, labels, descriptions, CTA.
	// ============================================================
	registerBlockType( 'rcmi/impact-stats-block', {
		apiVersion: 3,
		title: __( 'RCMI Impact Stats (Editable)', 'rcmi-toolkit' ),
		description: __( '1–6 stat grid with large numbers, labels, descriptions, and a CTA button.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'chart-bar',
		supports: {
			html: false,
			align: [ 'full', 'wide' ],
			color: {
				text: true,
				background: false,
				gradient: false,
				link: false,
			},
			typography: {
				fontFamily: true,
				textAlign: true,
			},
		},
		attributes: {
			statCount:  { type: 'number', default: 4 },
			stat1Value: { type: 'string', default: '62' },
			stat1Label: { type: 'string', default: 'Active Investigators' },
			stat1Desc:  { type: 'string', default: 'Researchers advancing chronic disease science across Houston and beyond.' },
			stat2Value: { type: 'string', default: '38' },
			stat2Label: { type: 'string', default: 'Community Partnerships' },
			stat2Desc:  { type: 'string', default: 'Trusted relationships helping shape relevant, equitable research.' },
			stat3Value: { type: 'string', default: '19' },
			stat3Label: { type: 'string', default: 'Counties Served' },
			stat3Desc:  { type: 'string', default: 'Research capacity and support reaching communities throughout the region.' },
			stat4Value: { type: 'string', default: '24' },
			stat4Label: { type: 'string', default: 'Active Research Projects' },
			stat4Desc:  { type: 'string', default: 'Studies translating strong ideas into meaningful real-world impact.' },
			stat5Value: { type: 'string', default: '' },
			stat5Label: { type: 'string', default: '' },
			stat5Desc:  { type: 'string', default: '' },
			stat6Value: { type: 'string', default: '' },
			stat6Label: { type: 'string', default: '' },
			stat6Desc:  { type: 'string', default: '' },
			ctaText:    { type: 'string', default: 'Learn More' },
			ctaLink:    { type: 'string', default: '/dashboard/' }
		},
		edit: function ( props ) {
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'rcmi-impact-stats-editor' } );
			var statEl = function ( n ) {
				var prefix = 'stat' + n;
				return el( 'article', { className: 'impact-stat' },
					el( RichText, {
						tagName: 'strong',
						value: attrs[prefix + 'Value'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Value'] = v; setAttributes( u ); },
						placeholder: __( 'Value…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
					} ),
					el( RichText, {
						tagName: 'span',
						value: attrs[prefix + 'Label'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Label'] = v; setAttributes( u ); },
						placeholder: __( 'Label…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
					} ),
					el( RichText, {
						tagName: 'p',
						value: attrs[prefix + 'Desc'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Desc'] = v; setAttributes( u ); },
						placeholder: __( 'Description…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
					} )
				);
			};
			var stats = [];
			for ( var i = 1; i <= ( attrs.statCount || 4 ); i++ ) {
				stats.push( statEl( i ) );
			}
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Layout', 'rcmi-toolkit' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Number of Stats', 'rcmi-toolkit' ),
							value: attrs.statCount || 4,
							min: 1,
							max: 6,
							onChange: function ( v ) { setAttributes( { statCount: v } ); }
						} )
					),
					el( PanelBody, { title: __( 'CTA Button', 'rcmi-toolkit' ), initialOpen: false },
						el( TextControl, { label: __( 'Button Text', 'rcmi-toolkit' ), value: attrs.ctaText, onChange: function ( v ) { setAttributes( { ctaText: v } ); } } ),
						el( TextControl, { label: __( 'Button Link', 'rcmi-toolkit' ), value: attrs.ctaLink, onChange: function ( v ) { setAttributes( { ctaLink: v } ); } } )
					)
				),
				el( 'div', blockProps,
					el( 'div', { className: 'wrap impact-stats-wrap' },
						el( 'div', { className: 'impact-stats', style: { gridTemplateColumns: 'repeat(' + ( attrs.statCount || 4 ) + ', 1fr)' } },
							stats,
							el( 'div', { className: 'impact-stats-cta' },
								el( 'a', { href: attrs.ctaLink, className: 'btn btn-primary', onClick: function ( e ) { e.preventDefault(); } }, attrs.ctaText + ' \u2192' )
							)
						)
					)
				)
			);
		},
		save: function () {
			// Server-side rendered (dynamic block).
			return null;
		}
	} );

	// ============================================================
	// Block: rcmi/role-selector-block
	// "I am..." section with 6 role cards.
	// ============================================================
	registerBlockType( 'rcmi/role-selector-block', {
		apiVersion: 3,
		title: __( 'RCMI Role Selector (Editable)', 'rcmi-toolkit' ),
		description: __( '"I am..." section with role cards for different audiences.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'groups',
		supports: {
			html: false,
			align: [ 'full', 'wide' ],
			color: {
				text: true,
				background: false,
				gradient: false,
				link: false,
			},
			typography: {
				fontFamily: true,
				textAlign: true,
			},
		},
		attributes: {
			eyebrow: { type: 'string', default: 'Start Collaborating' },
			heading: { type: 'string', default: 'I am\u2026' },
			note:    { type: 'string', default: 'Choose the path that fits you best. Every route leads to the resources most relevant to you.' },
			roles: { type: 'array', default: [
				{ title: 'An early-stage investigator', desc: 'Find pilot funding, mentoring, and training pathways to launch your research.', link: '/cores/#investigator' },
				{ title: 'A community organization', desc: 'Join the Community Advisory Board or propose a shared research priority.', link: '/cores/#community' },
				{ title: 'A student', desc: 'Explore training opportunities and see where your research idea could go.', link: '/journey/' },
				{ title: 'A faculty member', desc: 'Request biostatistics, data science, or research navigation support.', link: '/cores/#research' },
				{ title: 'A healthcare organization', desc: 'Explore implementation support and shared chronic-disease priorities.', link: '/partners/' },
				{ title: 'A funder', desc: 'Review outcomes, publications, and funding leveraged to date.', link: '/publications/' }
			] },
			scrimStops: { type: 'array', default: [
				{ color: '#ffffff', opacity: 0.9, position: 0 },
				{ color: '#ffffff', opacity: 0.54, position: 50 },
				{ color: '#ffffff', opacity: 0, position: 100 }
			] },
			scrimType: { type: 'string', default: 'linear' },
			scrimAngle: { type: 'number', default: 125 },
			bgImageId: { type: 'number', default: 0 },
			bgImageUrl: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var blockProps = useBlockProps( {
			className: 'rcmi-role-selector-editor collaborating-section',
			style: attrs.bgImageUrl ? {
				backgroundImage: 'url(' + attrs.bgImageUrl + ')',
				backgroundSize: 'cover',
				backgroundPosition: 'center'
			} : undefined
		} );
			var roles = attrs.roles || [];

			var updateRole = function ( idx, key, val ) {
				var newRoles = roles.map( function ( r, i ) {
					if ( i !== idx ) return r;
					var nr = Object.assign( {}, r );
					nr[ key ] = val;
					return nr;
				} );
				setAttributes( { roles: newRoles } );
			};
			var addRole = function () {
				setAttributes( { roles: roles.concat( [ { title: '', desc: '', link: '#' } ] ) } );
			};
			var removeRole = function ( idx ) {
				if ( roles.length <= 1 ) return;
				setAttributes( { roles: roles.filter( function ( _, i ) { return i !== idx; } ) } );
			};

			// Roles management panel: add/remove/reorder role cards.
			var rolesPanel = el( PanelBody, { title: __( 'Role Cards', 'rcmi-toolkit' ), initialOpen: false },
				roles.map( function ( role, idx ) {
					return el( 'div', { key: 'role-mgmt-' + idx, style: { borderBottom: '1px solid #f0f0f0', paddingBottom: '10px', marginBottom: '10px' } },
						el( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
							el( 'span', { style: { fontSize: '12px', fontWeight: '600' } }, __( 'Card ' + ( idx + 1 ), 'rcmi-toolkit' ) ),
							el( 'div', null,
								idx > 0 ? el( wp.components.Button, {
									onClick: function () {
										var newRoles = roles.slice();
										var tmp = newRoles[ idx - 1 ];
										newRoles[ idx - 1 ] = newRoles[ idx ];
										newRoles[ idx ] = tmp;
										setAttributes( { roles: newRoles } );
									},
									variant: 'tertiary', isSmall: true, icon: 'arrow-up-alt2'
								} ) : null,
								idx < roles.length - 1 ? el( wp.components.Button, {
									onClick: function () {
										var newRoles = roles.slice();
										var tmp = newRoles[ idx + 1 ];
										newRoles[ idx + 1 ] = newRoles[ idx ];
										newRoles[ idx ] = tmp;
										setAttributes( { roles: newRoles } );
									},
									variant: 'tertiary', isSmall: true, icon: 'arrow-down-alt2'
								} ) : null,
								roles.length > 1 ? el( wp.components.Button, {
									onClick: function () { removeRole( idx ); },
									variant: 'tertiary', isDestructive: true, isSmall: true
								}, __( 'Remove', 'rcmi-toolkit' ) ) : null
							)
						),
						el( TextControl, { label: __( 'Title', 'rcmi-toolkit' ), value: role.title, onChange: function ( v ) { updateRole( idx, 'title', v ); } } ),
						el( TextareaControl, { label: __( 'Description', 'rcmi-toolkit' ), value: role.desc, onChange: function ( v ) { updateRole( idx, 'desc', v ); } } ),
						el( TextControl, { label: __( 'Link URL', 'rcmi-toolkit' ), value: role.link, onChange: function ( v ) { updateRole( idx, 'link', v ); } } )
					);
				} ),
				el( wp.components.Button, {
					onClick: function () { addRole(); },
					variant: 'secondary', isSmall: true, style: { marginTop: '10px' }
				}, __( '+ Add Card', 'rcmi-toolkit' ) )
			);

			var roleEl = function ( role, idx ) {
				return el( 'a', { key: 'role-' + idx, href: role.link, className: 'role-card', onClick: function ( e ) { e.preventDefault(); } },
					el( RichText, {
						tagName: 'h4',
						value: role.title,
						onChange: function ( v ) { updateRole( idx, 'title', v ); },
						placeholder: __( 'Role title…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
					} ),
					el( RichText, {
						tagName: 'p',
						value: role.desc,
						onChange: function ( v ) { updateRole( idx, 'desc', v ); },
						placeholder: __( 'Description…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
					} ),
					el( 'span', { className: 'role-link' }, 'Start here \u2192' )
				);
			};
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Background & Scrim', 'rcmi-toolkit' ), initialOpen: false },
						el( 'p', null, __( 'Background Image', 'rcmi-toolkit' ) ),
						el( MediaUpload, {
							onSelect: function ( media ) {
								setAttributes( { bgImageId: media.id, bgImageUrl: media.url } );
							},
							allowedTypes: 'image',
							value: attrs.bgImageId,
							render: function ( obj ) {
								return el( wp.components.Button, {
									onClick: obj.open,
									className: 'rcmi-image-picker-btn',
									variant: 'secondary'
								},
									attrs.bgImageUrl ? __( 'Replace Background Image', 'rcmi-toolkit' ) : __( 'Choose Background Image', 'rcmi-toolkit' )
								);
							}
						} ),
						attrs.bgImageUrl ? el( 'div', { className: 'rcmi-image-preview' },
							el( 'img', { src: attrs.bgImageUrl, alt: __( 'Background preview', 'rcmi-toolkit' ) } ),
							el( wp.components.Button, {
								onClick: function () { setAttributes( { bgImageId: 0, bgImageUrl: '' } ); },
								variant: 'tertiary',
								isDestructive: true
							}, __( 'Remove image', 'rcmi-toolkit' ) )
						) : null,
						renderGradientPicker( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle, function ( stops, type, angle ) {
							setAttributes( { scrimStops: stops, scrimType: type, scrimAngle: angle } );
						} )
					),
					rolesPanel
				),
				el( 'section', blockProps,
					el( 'div', { className: 'rcmi-section-scrim', 'aria-hidden': 'true', style: { background: buildGradientCSS( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle ) } } ),
					el( 'div', { className: 'wrap' },
						el( 'div', { className: 'section-head' },
							el( 'div', null,
								el( RichText, {
									tagName: 'span',
									className: 'eyebrow',
									value: attrs.eyebrow,
									onChange: function ( v ) { setAttributes( { eyebrow: v } ); },
									placeholder: __( 'Eyebrow…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
								} ),
								el( RichText, {
									tagName: 'h2',
									value: attrs.heading,
									onChange: function ( v ) { setAttributes( { heading: v } ); },
									placeholder: __( 'Heading…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
								} )
							),
							el( RichText, {
								tagName: 'p',
								className: 'section-note',
								value: attrs.note,
								onChange: function ( v ) { setAttributes( { note: v } ); },
								placeholder: __( 'Note…', 'rcmi-toolkit' ),
								allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
							} )
						),
						el( 'div', { className: 'role-grid' },
							roles.map( function ( role, idx ) { return roleEl( role, idx ); } )
						)
					)
				)
			);
		},
		save: function () {
			// Server-side rendered (dynamic block).
			return null;
		}
	} );

	// ============================================================
	// Block: rcmi/impact-strip-block
	// Interactive tabbed section with 5 tabs, each with heading,
	// note, 4 cards, and a button. Uses a tabs JSON attribute.
	// ============================================================
	registerBlockType( 'rcmi/impact-strip-block', {
		apiVersion: 3,
		title: __( 'RCMI Impact Strip (Editable)', 'rcmi-toolkit' ),
		description: __( 'Interactive tabbed section with five tabs, each showing a section head and card grid.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'table-row-after',
		supports: {
			html: false,
			align: [ 'full', 'wide' ],
			color: {
				text: true,
				background: false,
				gradient: false,
				link: false,
			},
			typography: {
				fontFamily: true,
				textAlign: true,
			},
		},
		attributes: {
			tabs: {
				type: 'array',
				default: [
					{ id: 'develop', label: 'Develop', heading: 'Growing the next generation <strong>of research leaders</strong>', note: 'We invest early and often in the people who will carry chronic disease research forward — through funding, mentorship, and structured training pathways.', buttons: [ { text: 'View More', link: '#' } ], btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'People', title: 'Investigator Development', desc: 'Individualized pathways that move early-stage researchers from idea to independent funding.' },
						{ tag: 'Funding', title: 'Pilot Awards', desc: 'Seed funding for promising, high-risk / high-reward chronic disease research.' },
						{ tag: 'Guidance', title: 'Mentoring', desc: 'Paired mentorship with senior faculty across biostatistics, design, and dissemination.' },
						{ tag: 'Skills', title: 'Training', desc: 'Workshops and cohort programs covering methods, grant writing, and community-engaged research.' }
					] },
					{ id: 'build', label: 'Build', heading: 'Research capacity that scales with <strong>ambition</strong>', note: 'Shared infrastructure — statistical, technical, and navigational — so investigators spend less time re-building the basics and more time discovering.', buttons: [ { text: 'View More', link: '#' } ], btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Capacity', title: 'Research Capacity', desc: 'Institutional infrastructure that supports rigorous, reproducible science at every stage.' },
						{ tag: 'Methods', title: 'Biostatistics', desc: 'Consultation on study design, analysis plans, and power calculations.' },
						{ tag: 'Data', title: 'Data Science', desc: 'Support for data management, integration, and advanced analytics.' },
						{ tag: 'Access', title: 'Research Resources', desc: 'Shared tools, templates, and navigation support across the research lifecycle.' }
					] },
					{ id: 'partner', label: 'Partner', heading: 'Community at the center, <strong>not the edge</strong>', note: 'Research is designed with communities, not delivered to them. Our engagement model shares power over priorities and process.', buttons: [ { text: 'View More', link: '#' } ], btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Engagement', title: 'Community Engagement', desc: 'Ongoing, two-way relationships between researchers and community organizations.' },
						{ tag: 'Governance', title: 'Community Advisory Board', desc: 'Community leaders shape priorities, review protocols, and guide dissemination.' },
						{ tag: 'Model', title: 'Value-Based Community Engagement', desc: 'A framework that measures and reinforces mutual value across every partnership.' },
						{ tag: 'Network', title: 'Community Partnerships', desc: 'A growing network of trusted organizations across Houston\u2019s diverse communities.' }
					] },
					{ id: 'accelerate', label: 'Accelerate', heading: 'From question to real-world impact, <strong>faster</strong>', note: 'Core services and translational infrastructure exist to remove friction between a good idea and a funded, executed study.', buttons: [ { text: 'View More', link: '#' } ], btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Portfolio', title: 'Research Projects', desc: 'An active portfolio spanning prevention, treatment, and implementation science.' },
						{ tag: 'Infrastructure', title: 'Core Services', desc: 'Shared cores in biostatistics, community engagement, and administration.' },
						{ tag: 'Growth', title: 'Innovation', desc: 'New methods and technologies piloted to strengthen chronic disease research.' },
						{ tag: 'Bridge', title: 'Translational Science', desc: 'Moving discoveries from bench and community into practice and policy.' }
					] },
					{ id: 'improve', label: 'Improve', heading: 'We measure what matters, <strong>in public</strong>', note: 'Impact isn\u2019t a year-end summary — it\u2019s a living, monthly record of progress toward better chronic disease outcomes.', buttons: [ { text: 'View More', link: '#' } ], btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Voices', title: 'Impact Stories', desc: 'Real accounts of problems studied, lessons learned, and what\u2019s next.' },
						{ tag: 'Evidence', title: 'Publications', desc: 'Findings organized by theme, not by committee.' },
						{ tag: 'Live', title: 'Outcomes Dashboard', desc: 'Monthly-updated metrics on investigators, funding, and communities served.' },
						{ tag: 'Focus', title: 'Chronic Disease Priorities', desc: 'Priorities set together with the communities most affected.' }
					] }
				]
			},
			transition: { type: 'string', default: 'none' },
			height: { type: 'number', default: 0 },
			tabBtnBgColor: { type: 'string', default: '#fbf7f0' },
			tabBtnTextColor: { type: 'string', default: '#7d2832' },
			tabBtnActiveBgColor: { type: 'string', default: '#ffffff' },
			tabBtnActiveTextColor: { type: 'string', default: '#c8102e' }
		},
		edit: function ( props ) {
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var activeTab = useState( 0 );
			var activeTabIndex = activeTab[0];
			var setActiveTabIndex = activeTab[1];
			var blockProps = useBlockProps( { className: 'rcmi-impact-strip-block-editor' } );
			var tabs = attrs.tabs || [];

			var updateTab = function ( idx, key, val ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== idx ) return t;
					var nt = Object.assign( {}, t );
					nt[ key ] = val;
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};
			var addTab = function () {
				var newId = 'tab-' + Date.now();
				var newTab = {
					id: newId, label: __( 'New Tab', 'rcmi-toolkit' ),
					heading: '', note: '',
					buttons: [ { text: '', link: '#' } ],
					bgImageId: 0, bgImageUrl: '',
					scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ],
					scrimType: 'linear', scrimAngle: 90,
					cards: [ { tag: '', title: '', desc: '' } ]
				};
				setAttributes( { tabs: tabs.concat( [ newTab ] ) } );
			};
			var removeTab = function ( idx ) {
				if ( tabs.length <= 1 ) return;
				var newTabs = tabs.filter( function ( _, i ) { return i !== idx; } );
				setAttributes( { tabs: newTabs } );
				if ( activeTabIndex >= newTabs.length ) {
					setActiveTabIndex( newTabs.length - 1 );
				}
			};
			var updateCard = function ( tabIdx, cardIdx, key, val ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== tabIdx ) return t;
					var nt = Object.assign( {}, t );
					nt.cards = nt.cards.map( function ( c, ci ) {
						if ( ci !== cardIdx ) return c;
						var nc = Object.assign( {}, c );
						nc[ key ] = val;
						return nc;
					} );
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};
			var addCard = function ( tabIdx ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== tabIdx ) return t;
					var nt = Object.assign( {}, t );
					nt.cards = ( nt.cards || [] ).concat( [ { tag: '', title: '', desc: '' } ] );
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};
			var removeCard = function ( tabIdx, cardIdx ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== tabIdx ) return t;
					var nt = Object.assign( {}, t );
					nt.cards = ( nt.cards || [] ).filter( function ( _, ci ) { return ci !== cardIdx; } );
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};
			var addButton = function ( tabIdx ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== tabIdx ) return t;
					var nt = Object.assign( {}, t );
					nt.buttons = ( nt.buttons || [] ).concat( [ { text: '', link: '#' } ] );
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};
			var removeButton = function ( tabIdx, btnIdx ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== tabIdx ) return t;
					var nt = Object.assign( {}, t );
					nt.buttons = ( nt.buttons || [] ).filter( function ( _, bi ) { return bi !== btnIdx; } );
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};
			var updateButton = function ( tabIdx, btnIdx, key, val ) {
				var newTabs = tabs.map( function ( t, i ) {
					if ( i !== tabIdx ) return t;
					var nt = Object.assign( {}, t );
					nt.buttons = ( nt.buttons || [] ).map( function ( b, bi ) {
						if ( bi !== btnIdx ) return b;
						var nb = Object.assign( {}, b );
						nb[ key ] = val;
						return nb;
					} );
					return nt;
				} );
				setAttributes( { tabs: newTabs } );
			};

			// Transition settings panel.
			var transitionPanel = el( PanelBody, { title: __( 'Tab Transition', 'rcmi-toolkit' ), initialOpen: true },
				el( SelectControl, {
					label: __( 'Transition effect', 'rcmi-toolkit' ),
					value: attrs.transition,
					options: [
						{ value: 'none',    label: __( 'None (instant switch)', 'rcmi-toolkit' ) },
						{ value: 'fade',    label: __( 'Fade (ease in/out)', 'rcmi-toolkit' ) },
						{ value: 'slide',   label: __( 'Slide (horizontal scroll)', 'rcmi-toolkit' ) },
						{ value: 'curtain', label: __( 'Curtain (vertical scroll)', 'rcmi-toolkit' ) },
						{ value: 'wipe',    label: __( 'Wipe (clip-path reveal)', 'rcmi-toolkit' ) },
						{ value: 'reveal',  label: __( 'Reveal (zoom + fade)', 'rcmi-toolkit' ) }
					],
					onChange: function ( v ) { setAttributes( { transition: v } ); }
				} ),
				el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'Animation played when switching tabs. "Fade" cross-fades. "Slide" scrolls horizontally. "Curtain" scrolls vertically. "Wipe" reveals the new panel with a clip-path sweep. "Reveal" zooms the new panel in while fading.', 'rcmi-toolkit' ) )
			);

			// Layout panel: height + colors.
			var layoutPanel = el( PanelBody, { title: __( 'Layout', 'rcmi-toolkit' ), initialOpen: false },
				el( RangeControl, {
					label: __( 'Tab panel height (px)', 'rcmi-toolkit' ),
					value: attrs.height,
					onChange: function ( v ) { setAttributes( { height: v } ); },
					min: 0, max: 800, step: 10,
					help: __( 'Fixed height for all tab panels. Set to 0 for auto height.', 'rcmi-toolkit' )
				} ),
				renderColorSelector( __( 'Inactive Button Background', 'rcmi-toolkit' ), attrs.tabBtnBgColor, function ( v ) { setAttributes( { tabBtnBgColor: v } ); } ),
				renderColorSelector( __( 'Inactive Button Text Color', 'rcmi-toolkit' ), attrs.tabBtnTextColor, function ( v ) { setAttributes( { tabBtnTextColor: v } ); } ),
				renderColorSelector( __( 'Active Button Background', 'rcmi-toolkit' ), attrs.tabBtnActiveBgColor, function ( v ) { setAttributes( { tabBtnActiveBgColor: v } ); } ),
				renderColorSelector( __( 'Active Button Text Color', 'rcmi-toolkit' ), attrs.tabBtnActiveTextColor, function ( v ) { setAttributes( { tabBtnActiveTextColor: v } ); } )
			);

			// Tabs management panel: add/remove tabs.
			var tabsPanel = el( PanelBody, { title: __( 'Tabs', 'rcmi-toolkit' ), initialOpen: false },
				tabs.map( function ( tab, idx ) {
					return el( 'div', { key: 'tab-mgmt-' + idx, style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid #f0f0f0' } },
						el( 'span', { style: { fontSize: '13px' } }, ( idx + 1 ) + '. ' + tab.label ),
						el( 'div', null,
							idx > 0 ? el( wp.components.Button, {
								onClick: function () {
									var newTabs = tabs.slice();
									var tmp = newTabs[ idx - 1 ];
									newTabs[ idx - 1 ] = newTabs[ idx ];
									newTabs[ idx ] = tmp;
									setAttributes( { tabs: newTabs } );
									if ( activeTabIndex === idx ) setActiveTabIndex( idx - 1 );
									else if ( activeTabIndex === idx - 1 ) setActiveTabIndex( idx );
								},
								variant: 'tertiary',
								isSmall: true,
								icon: 'arrow-up-alt2'
							} ) : null,
							idx < tabs.length - 1 ? el( wp.components.Button, {
								onClick: function () {
									var newTabs = tabs.slice();
									var tmp = newTabs[ idx + 1 ];
									newTabs[ idx + 1 ] = newTabs[ idx ];
									newTabs[ idx ] = tmp;
									setAttributes( { tabs: newTabs } );
									if ( activeTabIndex === idx ) setActiveTabIndex( idx + 1 );
									else if ( activeTabIndex === idx + 1 ) setActiveTabIndex( idx );
								},
								variant: 'tertiary',
								isSmall: true,
								icon: 'arrow-down-alt2'
							} ) : null,
							tabs.length > 1 ? el( wp.components.Button, {
								onClick: function () { removeTab( idx ); },
								variant: 'tertiary',
								isDestructive: true,
								isSmall: true
							}, __( 'Remove', 'rcmi-toolkit' ) ) : null
						)
					);
				} ),
				el( wp.components.Button, {
					onClick: function () { addTab(); },
					variant: 'secondary',
					isSmall: true,
					style: { marginTop: '10px' }
				}, __( '+ Add Tab', 'rcmi-toolkit' ) )
			);

			// Build inspector controls for each tab.
			var tabPanels = tabs.map( function ( tab, idx ) {
				return el( PanelBody, { title: __( 'Tab: ' + tab.label, 'rcmi-toolkit' ), initialOpen: false, key: 'tab-panel-' + idx },
					el( TextControl, { label: __( 'Tab Label', 'rcmi-toolkit' ), value: tab.label, onChange: function ( v ) { updateTab( idx, 'label', v ); } } ),
					el( MediaUpload, {
						onSelect: function ( media ) {
							var u = {}; u.tabs = tabs.map( function ( t, i ) {
								if ( i !== idx ) return t;
								var nt = Object.assign( {}, t );
								nt.bgImageId = media.id;
								nt.bgImageUrl = media.url;
								return nt;
							} );
							setAttributes( u );
						},
						allowedTypes: 'image',
						value: tab.bgImageId,
						render: function ( obj ) {
							return el( wp.components.Button, { onClick: obj.open, variant: 'secondary', className: 'rcmi-image-picker-btn' },
								tab.bgImageUrl ? __( 'Replace Background Image', 'rcmi-toolkit' ) : __( 'Choose Background Image', 'rcmi-toolkit' )
							);
						}
					} ),
					tab.bgImageUrl ? el( 'div', { className: 'rcmi-image-preview' },
						el( 'img', { src: tab.bgImageUrl, alt: __( 'Tab background', 'rcmi-toolkit' ) } ),
						el( wp.components.Button, {
							onClick: function () {
								var u = {}; u.tabs = tabs.map( function ( t, i ) {
									if ( i !== idx ) return t;
									var nt = Object.assign( {}, t );
									nt.bgImageId = 0;
									nt.bgImageUrl = '';
									return nt;
								} );
								setAttributes( u );
							},
							variant: 'tertiary',
							isDestructive: true
						}, __( 'Remove image', 'rcmi-toolkit' ) )
					) : null,
					// Per-tab gradient scrim controls.
					el( 'div', { key: 'tab-grad-' + idx }, renderGradientPicker( tab.scrimStops, tab.scrimType, tab.scrimAngle, function ( stops, type, angle ) {
						var u = {}; u.tabs = tabs.map( function ( t, i ) {
							if ( i !== idx ) return t;
							var nt = Object.assign( {}, t );
							nt.scrimStops = stops;
							nt.scrimType = type;
							nt.scrimAngle = angle;
							return nt;
						} );
						setAttributes( u );
					} ) ),
					// Cards section: list each card with remove button + add card button.
					el( 'div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '12px' } },
						el( 'strong', null, __( 'Cards', 'rcmi-toolkit' ) ),
						( tab.cards || [] ).map( function ( card, ci ) {
							return el( 'div', { key: 'card-' + ci, style: { borderBottom: '1px solid #f0f0f0', paddingBottom: '10px', marginBottom: '10px' } },
								el( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
									el( 'span', { style: { fontSize: '12px', fontWeight: '600' } }, __( 'Card ' + ( ci + 1 ), 'rcmi-toolkit' ) ),
									( tab.cards || [] ).length > 1 ? el( wp.components.Button, {
										onClick: function () { removeCard( idx, ci ); },
										variant: 'tertiary',
										isDestructive: true,
										isSmall: true
									}, __( 'Remove', 'rcmi-toolkit' ) ) : null
								),
								el( TextControl, { label: __( 'Tag', 'rcmi-toolkit' ), value: card.tag, onChange: function ( v ) { updateCard( idx, ci, 'tag', v ); } } ),
								el( TextControl, { label: __( 'Title', 'rcmi-toolkit' ), value: card.title, onChange: function ( v ) { updateCard( idx, ci, 'title', v ); } } ),
								el( TextareaControl, { label: __( 'Description', 'rcmi-toolkit' ), value: card.desc, onChange: function ( v ) { updateCard( idx, ci, 'desc', v ); } } )
							);
						} ),
						( tab.cards || [] ).length < 8 ? el( wp.components.Button, {
							onClick: function () { addCard( idx ); },
							variant: 'secondary',
							isSmall: true
						}, __( '+ Add Card', 'rcmi-toolkit' ) ) : null
					),
					// Buttons section: list each button with remove + add button.
					el( 'div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '12px' } },
						el( 'strong', null, __( 'Buttons', 'rcmi-toolkit' ) ),
						( tab.buttons || [] ).map( function ( btn, bi ) {
							return el( 'div', { key: 'btn-' + bi, style: { borderBottom: '1px solid #f0f0f0', paddingBottom: '10px', marginBottom: '10px' } },
								el( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
									el( 'span', { style: { fontSize: '12px', fontWeight: '600' } }, __( 'Button ' + ( bi + 1 ), 'rcmi-toolkit' ) ),
									el( wp.components.Button, {
										onClick: function () { removeButton( idx, bi ); },
										variant: 'tertiary',
										isDestructive: true,
										isSmall: true
									}, __( 'Remove', 'rcmi-toolkit' ) )
								),
								el( TextControl, { label: __( 'Text', 'rcmi-toolkit' ), value: btn.text, onChange: function ( v ) { updateButton( idx, bi, 'text', v ); } } ),
								el( TextControl, { label: __( 'Link', 'rcmi-toolkit' ), value: btn.link, onChange: function ( v ) { updateButton( idx, bi, 'link', v ); } } )
							);
						} ),
						el( wp.components.Button, {
							onClick: function () { addButton( idx ); },
							variant: 'secondary',
							isSmall: true
						}, __( '+ Add Button', 'rcmi-toolkit' ) )
					)
				);
			} );

			// Build editor preview — show tab buttons + active tab content.
			var activeTabData = tabs[ activeTabIndex ] || tabs[ 0 ] || {};
			// Pass tab button colors as CSS custom properties on .impact-strip
			// so the .is-active class can apply the correct colors via CSS.
			var stripStyle = {};
			if ( attrs.tabBtnBgColor ) { stripStyle['--tab-btn-bg'] = attrs.tabBtnBgColor; }
			if ( attrs.tabBtnTextColor ) { stripStyle['--tab-btn-text'] = attrs.tabBtnTextColor; }
			if ( attrs.tabBtnActiveBgColor ) { stripStyle['--tab-btn-active-bg'] = attrs.tabBtnActiveBgColor; }
			if ( attrs.tabBtnActiveTextColor ) { stripStyle['--tab-btn-active-text'] = attrs.tabBtnActiveTextColor; }
			return el( Fragment, null,
				el( InspectorControls, null, [ transitionPanel, layoutPanel, tabsPanel ].concat( tabPanels ) ),
				el( 'div', blockProps,
					el( 'section', { className: 'impact-overview' },
						el( 'div', { className: 'wrap' },
							el( 'div', { className: 'impact-strip', style: stripStyle },
								el( 'div', { className: 'impact-steps', role: 'tablist' },
									tabs.map( function ( tab, idx ) {
										var isActive = idx === activeTabIndex;
										return el( 'button', { key: 'btn-' + idx, className: 'impact-step' + ( isActive ? ' is-active' : '' ), role: 'tab', type: 'button', onClick: function () { setActiveTabIndex( idx ); } },
											el( 'span', { className: 'impact-step-copy' }, el( 'strong', null, tab.label ) )
										);
									} )
								)
							)
						)
					),
					el( 'section', { className: 'tab-panel is-active', style: Object.assign(
						{ height: attrs.height ? attrs.height + 'px' : undefined },
						activeTabData.bgImageUrl ? { backgroundImage: 'url(' + activeTabData.bgImageUrl + ')' } : {}
					) },
						el( 'div', { className: 'rcmi-tab-scrim', 'aria-hidden': 'true', style: { background: buildGradientCSS( activeTabData.scrimStops, activeTabData.scrimType, activeTabData.scrimAngle ) } } ),
						el( 'div', { className: 'wrap' },
							el( 'div', { className: 'section-head' },
								el( 'div', null, el( RichText, {
									tagName: 'h2',
									value: activeTabData.heading,
									onChange: function ( v ) { updateTab( activeTabIndex, 'heading', v ); },
									placeholder: __( 'Heading…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
								} ) ),
								el( RichText, {
									tagName: 'p',
									className: 'section-note',
									value: activeTabData.note,
									onChange: function ( v ) { updateTab( activeTabIndex, 'note', v ); },
									placeholder: __( 'Note…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
								} )
							),
							el( 'div', { className: 'card-grid' },
								( activeTabData.cards || [] ).map( function ( card, ci ) {
									return el( 'div', { className: 'card', key: 'pc-' + ci },
										el( RichText, {
											tagName: 'span',
											className: 'tag',
											value: card.tag,
											onChange: function ( v ) { updateCard( activeTabIndex, ci, 'tag', v ); },
											placeholder: __( 'Tag…', 'rcmi-toolkit' ),
											allowedFormats: [ 'core/bold', 'core/italic', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
										} ),
										el( RichText, {
											tagName: 'h4',
											value: card.title,
											onChange: function ( v ) { updateCard( activeTabIndex, ci, 'title', v ); },
											placeholder: __( 'Title…', 'rcmi-toolkit' ),
											allowedFormats: [ 'core/bold', 'core/italic', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
										} ),
										el( RichText, {
											tagName: 'p',
											value: card.desc,
											onChange: function ( v ) { updateCard( activeTabIndex, ci, 'desc', v ); },
											placeholder: __( 'Description…', 'rcmi-toolkit' ),
											allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
										} )
									);
								} )
							),
							( activeTabData.buttons || [] ).length > 0 ? el( 'div', { style: { marginTop: 'var(--space-5)', display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' } },
								( activeTabData.buttons || [] ).map( function ( btn, bi ) {
									return el( RichText, {
										key: 'pb-' + bi,
										tagName: 'a',
										className: 'btn btn-primary',
										value: btn.text,
										onChange: function ( v ) { updateButton( activeTabIndex, bi, 'text', v ); },
										placeholder: __( 'Button text…', 'rcmi-toolkit' ),
										allowedFormats: []
									} );
								} )
							) : null
						)
					)
				)
			);
		},
		save: function () {
			// Server-side rendered (dynamic block) so height, colors,
			// transitions, and tab data always reflect the latest attributes.
			return null;
		}
	} );

	// ============================================================
	// Block: rcmi/parallax (also serves as the hero block)
	// Two modes: "static" (single background image, like the old hero block)
	// and "parallax" (three image layers that scroll at different speeds).
	// Includes editable gradient scrim and content alignment controls.
	// ============================================================
	registerBlockType( 'rcmi/parallax', {
		apiVersion: 3,
		title: __( 'RCMI Hero', 'rcmi-toolkit' ),
		description: __( 'Hero section with background image. Switch to Parallax mode for a 3-layer depth effect. Includes editable gradient scrim and content alignment.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'images-alt2',
		supports: {
			html: false,
			align: [ 'full', 'wide' ],
			color: {
				text: true,      // Enables text-color format button in RichText toolbar
				background: false,
				gradient: false,
				link: false,     // Don't color <a> elements (button keeps its own color)
			},
			typography: {
				fontFamily: true,
				textAlign: true,
			},
		},
		attributes: {
			mode:        { type: 'string', default: 'static' }, // 'static' or 'parallax'
			// Static mode: single background image
			bgImageId:   { type: 'number', default: 0 },
			bgImageUrl:  { type: 'string', default: '' },
			// Parallax mode: three layers with speeds
			bgSpeed:     { type: 'number', default: 0.2 },
			midImageId:  { type: 'number', default: 0 },
			midImageUrl: { type: 'string', default: '' },
			midSpeed:    { type: 'number', default: 0.45 },
			fgImageId:   { type: 'number', default: 0 },
			fgImageUrl:  { type: 'string', default: '' },
			fgSpeed:     { type: 'number', default: 0.7 },
			// Content layer speed (text + button as 4th parallax layer)
			contentSpeed: { type: 'number', default: 0.1 },
			// Layer z-index (stacking order). Lower = further back.
			// Defaults match the original CSS: bg=0, mid=1, fg=2, scrim=3, content=4.
			bgZIndex:     { type: 'number', default: 0 },
			midZIndex:    { type: 'number', default: 1 },
			fgZIndex:     { type: 'number', default: 2 },
			scrimZIndex:  { type: 'number', default: 3 },
			contentZIndex:{ type: 'number', default: 4 },
			// Parallax direction: 'down', 'up', 'left', 'right'
			parallaxDirection: { type: 'string', default: 'down' },
			// Layout
			height:      { type: 'number', default: 80 },
			// Gradient scrim (editable multi-stop overlay for text readability)
			scrimStops:  { type: 'array', default: [
				{ color: '#f8f5ee', opacity: 0.85, position: 0 },
				{ color: '#f8f5ee', opacity: 0.34, position: 40 },
				{ color: '#f8f5ee', opacity: 0, position: 65 }
			] },
			scrimType:   { type: 'string', default: 'linear' },
			scrimAngle:  { type: 'number', default: 90 },
			// Content alignment
			contentAlign: { type: 'string', default: 'left' }, // 'left', 'center', 'right'
			// Content fields
			eyebrow:     { type: 'string', default: 'Accelerating Real‑World Impact.' },
			headline:    { type: 'string', default: 'Advancing Chronic<br> Disease Research.' },
			lede:        { type: 'string', default: 'Building research capacity, developing investigators, and partnering with communities to improve chronic disease outcomes across Houston and beyond.' },
			buttonText:  { type: 'string', default: 'Request Support' },
			buttonLink:  { type: 'string', default: '#start' }
		},
		edit: function ( props ) {
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var isParallax = attrs.mode === 'parallax';
			var blockProps = useBlockProps( { className: 'rcmi-parallax-editor', style: { minHeight: attrs.height + 'vh' } } );

			// Helper: convert hex + alpha to rgba string.
			var hexToRgba = function ( hex, alpha ) {
				var h = ( hex || '#f8f5ee' ).replace( '#', '' );
				if ( h.length === 3 ) { h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2]; }
				var r = parseInt( h.substr( 0, 2 ), 16 );
				var g = parseInt( h.substr( 2, 2 ), 16 );
				var b = parseInt( h.substr( 4, 2 ), 16 );
				return 'rgba(' + r + ',' + g + ',' + b + ',' + ( Math.round( alpha * 100 ) / 100 ) + ')';
			};

			// Build the scrim gradient style from multi-stop picker.
			var scrimGradient = buildGradientCSS( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle );

			// Layer picker for parallax mode.
			var layerPicker = function ( label, urlKey, idKey, speedKey ) {
				return el( PanelBody, { title: label, initialOpen: urlKey === 'bgImageUrl' },
					el( MediaUpload, {
						onSelect: function ( media ) {
							var u = {};
							u[ idKey ] = media.id;
							u[ urlKey ] = media.url;
							setAttributes( u );
						},
						allowedTypes: 'image',
						value: attrs[ idKey ],
						render: function ( obj ) {
							return el( wp.components.Button, {
								onClick: obj.open,
								variant: 'secondary',
								className: 'rcmi-image-picker-btn'
							}, attrs[ urlKey ] ? __( 'Replace Image', 'rcmi-toolkit' ) : __( 'Choose Image', 'rcmi-toolkit' ) );
						}
					} ),
					attrs[ urlKey ] ? el( 'div', { className: 'rcmi-image-preview' },
						el( 'img', { src: attrs[ urlKey ], alt: label } ),
						el( wp.components.Button, {
							onClick: function () { var u = {}; u[ idKey ] = 0; u[ urlKey ] = ''; setAttributes( u ); },
							variant: 'tertiary',
							isDestructive: true
						}, __( 'Remove image', 'rcmi-toolkit' ) )
					) : null,
					el( RangeControl, {
						label: __( 'Parallax speed (0 = static, 1 = fastest)', 'rcmi-toolkit' ),
						value: attrs[ speedKey ],
						onChange: function ( v ) { var u = {}; u[ speedKey ] = v; setAttributes( u ); },
						min: 0,
						max: 1,
						step: 0.05
					} )
				);
			};

			// Layer preview div for the editor.
			var layerPreview = function ( url, label, zIndex ) {
				var style = { zIndex: zIndex };
				if ( url ) {
					style.backgroundImage = 'url(' + url + ')';
				}
				return el( 'div', { className: 'rcmi-parallax-layer-preview', style: style },
					! url ? el( 'span', { className: 'rcmi-layer-label' }, label ) : null
				);
			};

			// Alignment buttons.
			var alignButtons = el( 'div', { style: { display: 'flex', gap: '8px', marginBottom: '8px' } },
				[ 'left', 'center', 'right' ].map( function ( a ) {
					return el( wp.components.Button, {
						key: 'align-' + a,
						onClick: function () { setAttributes( { contentAlign: a } ); },
						variant: attrs.contentAlign === a ? 'primary' : 'secondary',
						isPressed: attrs.contentAlign === a
					}, a.charAt( 0 ).toUpperCase() + a.slice( 1 ) );
				} )
			);

			// Build inspector controls.
			var inspectorChildren = [
				// Mode toggle — always first.
				el( PanelBody, { title: __( 'Hero Mode', 'rcmi-toolkit' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Display mode', 'rcmi-toolkit' ),
						value: attrs.mode,
						options: [
							{ value: 'static', label: __( 'Static (single background image)', 'rcmi-toolkit' ) },
							{ value: 'parallax', label: __( 'Parallax (3-layer depth effect)', 'rcmi-toolkit' ) }
						],
						onChange: function ( v ) { setAttributes( { mode: v } ); }
					} )
				)
			];

			if ( isParallax ) {
				// Parallax mode: show 3 layer pickers.
				inspectorChildren.push(
					layerPicker( __( 'Background Layer (slowest)', 'rcmi-toolkit' ), 'bgImageUrl', 'bgImageId', 'bgSpeed' ),
					layerPicker( __( 'Middle Layer', 'rcmi-toolkit' ), 'midImageUrl', 'midImageId', 'midSpeed' ),
					layerPicker( __( 'Foreground Layer (fastest)', 'rcmi-toolkit' ), 'fgImageUrl', 'fgImageId', 'fgSpeed' )
				);
			} else {
				// Static mode: single background image picker.
				inspectorChildren.push(
					el( PanelBody, { title: __( 'Background Image', 'rcmi-toolkit' ), initialOpen: true },
						el( MediaUpload, {
							onSelect: function ( media ) {
								setAttributes( { bgImageId: media.id, bgImageUrl: media.url } );
							},
							allowedTypes: 'image',
							value: attrs.bgImageId,
							render: function ( obj ) {
								return el( wp.components.Button, {
									onClick: obj.open,
									variant: 'secondary',
									className: 'rcmi-image-picker-btn'
								}, attrs.bgImageUrl ? __( 'Replace Background Image', 'rcmi-toolkit' ) : __( 'Choose Background Image', 'rcmi-toolkit' ) );
							}
						} ),
						attrs.bgImageUrl ? el( 'div', { className: 'rcmi-image-preview' },
							el( 'img', { src: attrs.bgImageUrl, alt: __( 'Background preview', 'rcmi-toolkit' ) } ),
							el( wp.components.Button, {
								onClick: function () { setAttributes( { bgImageId: 0, bgImageUrl: '' } ); },
								variant: 'tertiary',
								isDestructive: true
							}, __( 'Remove image', 'rcmi-toolkit' ) )
						) : null
					)
				);
			}

			// Gradient scrim controls — always available.
			inspectorChildren.push(
				el( PanelBody, { title: __( 'Layer Order', 'rcmi-toolkit' ), initialOpen: false },
					el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'Control the stacking order of layers. Lower numbers appear further back, higher numbers appear in front. The scrim stays between image layers and text.', 'rcmi-toolkit' ) ),
					isParallax ? el( Fragment, null,
						el( RangeControl, {
							label: __( 'Background layer z-index', 'rcmi-toolkit' ),
							value: attrs.bgZIndex,
							onChange: function ( v ) { setAttributes( { bgZIndex: v } ); },
							min: 0, max: 10, step: 1
						} ),
						el( RangeControl, {
							label: __( 'Middle layer z-index', 'rcmi-toolkit' ),
							value: attrs.midZIndex,
							onChange: function ( v ) { setAttributes( { midZIndex: v } ); },
							min: 0, max: 10, step: 1
						} ),
						el( RangeControl, {
							label: __( 'Foreground layer z-index', 'rcmi-toolkit' ),
							value: attrs.fgZIndex,
							onChange: function ( v ) { setAttributes( { fgZIndex: v } ); },
							min: 0, max: 10, step: 1
						} )
					) : el( RangeControl, {
						label: __( 'Background image z-index', 'rcmi-toolkit' ),
						value: attrs.bgZIndex,
						onChange: function ( v ) { setAttributes( { bgZIndex: v } ); },
						min: 0, max: 10, step: 1
					} ),
					el( RangeControl, {
						label: __( 'Gradient scrim z-index', 'rcmi-toolkit' ),
						value: attrs.scrimZIndex,
						onChange: function ( v ) { setAttributes( { scrimZIndex: v } ); },
						min: 0, max: 10, step: 1,
						help: __( 'The gradient overlay. Set between image layers and text for readability, or above text to dim it.', 'rcmi-toolkit' )
					} ),
					el( RangeControl, {
						label: __( 'Text content z-index', 'rcmi-toolkit' ),
						value: attrs.contentZIndex,
						onChange: function ( v ) { setAttributes( { contentZIndex: v } ); },
						min: 0, max: 10, step: 1,
						help: __( 'Set higher than image layers to keep text on top, or lower to hide text behind images.', 'rcmi-toolkit' )
					} ),
					el( 'div', { style: { marginTop: '12px', display: 'flex', gap: '8px' } },
						el( wp.components.Button, {
							onClick: function () { setAttributes( { bgZIndex: 0, midZIndex: 1, fgZIndex: 2, scrimZIndex: 3, contentZIndex: 4 } ); },
							variant: 'secondary',
							isSmall: true
						}, __( 'Reset to defaults', 'rcmi-toolkit' ) )
					)
				),
				el( PanelBody, { title: __( 'Gradient Scrim', 'rcmi-toolkit' ), initialOpen: false },
					el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'Overlay that darkens/tints the background for text readability.', 'rcmi-toolkit' ) ),
					renderGradientPicker( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle, function ( stops, type, angle ) {
						setAttributes( { scrimStops: stops, scrimType: type, scrimAngle: angle } );
					} )
				),
				el( PanelBody, { title: __( 'Layout', 'rcmi-toolkit' ), initialOpen: false },
					el( RangeControl, {
						label: __( 'Section height (viewport %)', 'rcmi-toolkit' ),
						value: attrs.height,
						onChange: function ( v ) { setAttributes( { height: v } ); },
						min: 40,
						max: 100,
						step: 5
					} ),
					isParallax ? el( 'div', { style: { marginTop: '16px' } },
						el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px' } }, __( 'Parallax direction', 'rcmi-toolkit' ) ),
						el( 'div', { style: { display: 'flex', gap: '8px', marginBottom: '8px' } },
							[ 'down', 'up', 'left', 'right' ].map( function ( d ) {
								return el( wp.components.Button, {
									key: 'dir-' + d,
									onClick: function () { setAttributes( { parallaxDirection: d } ); },
									variant: attrs.parallaxDirection === d ? 'primary' : 'secondary',
									isPressed: attrs.parallaxDirection === d
								}, d.charAt( 0 ).toUpperCase() + d.slice( 1 ) );
							} )
						),
						el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'Direction layers move as you scroll down. "Down" = layers drift downward (default). "Up" = layers rise. "Left/Right" = horizontal drift.', 'rcmi-toolkit' ) ),
						el( RangeControl, {
							label: __( 'Content layer speed (text + button)', 'rcmi-toolkit' ),
							value: attrs.contentSpeed,
							onChange: function ( v ) { setAttributes( { contentSpeed: v } ); },
							min: 0,
							max: 1,
							step: 0.05,
							help: __( '0 = content stays fixed, higher = content drifts with parallax', 'rcmi-toolkit' )
						} )
					) : null,
					el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px' } }, __( 'Content alignment', 'rcmi-toolkit' ) ),
					alignButtons
				)
			);

			// Build editor preview.
			var previewChildren = [];

			if ( isParallax ) {
				previewChildren.push(
					el( 'div', { className: 'rcmi-parallax-layers' },
						layerPreview( attrs.bgImageUrl, __( 'Background', 'rcmi-toolkit' ), attrs.bgZIndex ),
						layerPreview( attrs.midImageUrl, __( 'Middle', 'rcmi-toolkit' ), attrs.midZIndex ),
						layerPreview( attrs.fgImageUrl, __( 'Foreground', 'rcmi-toolkit' ), attrs.fgZIndex )
					)
				);
			} else {
				// Static mode: single background image.
				var bgStyle = { background: '#f8f5ee' };
				if ( attrs.bgImageUrl ) {
					bgStyle = { backgroundImage: 'url(' + attrs.bgImageUrl + ')', backgroundSize: 'cover', backgroundPosition: 'center' };
				}
				previewChildren.push(
					el( 'div', { className: 'rcmi-parallax-layer-preview', style: Object.assign( { zIndex: attrs.bgZIndex }, bgStyle ) },
						! attrs.bgImageUrl ? el( 'span', { className: 'rcmi-layer-label' }, __( 'Background', 'rcmi-toolkit' ) ) : null
					)
				);
			}

			// Scrim overlay preview (z-index from scrimZIndex attribute).
			previewChildren.push(
				el( 'div', { className: 'rcmi-parallax-scrim', style: { background: scrimGradient, zIndex: attrs.scrimZIndex } } )
			);

			// Content preview.
			var copyStyle = { zIndex: attrs.contentZIndex };
			if ( attrs.contentAlign === 'center' ) {
				copyStyle.textAlign = 'center';
				copyStyle.margin = '0 auto';
			} else if ( attrs.contentAlign === 'right' ) {
				copyStyle.textAlign = 'right';
				copyStyle.marginLeft = 'auto';
			}

			// InnerBlocks content area — editors can add/reorder/remove
			// any block (heading, paragraph, buttons, images, etc.).
			// Template seeds the default hero content on new instances.
			previewChildren.push(
				el( 'div', { className: 'wrap rcmi-parallax-inner', style: { zIndex: attrs.contentZIndex } },
					el( 'div', { className: 'rcmi-parallax-copy', style: copyStyle },
						el( InnerBlocks, {
							allowedBlocks: [ 'core/heading', 'core/paragraph', 'core/buttons', 'core/list', 'core/image', 'core/spacer', 'core/separator' ],
							template: [
								[ 'core/heading', {
									level: 1,
									placeholder: __( 'Headline…', 'rcmi-toolkit' ),
									content: 'Advancing Chronic Disease Research.'
								} ],
								[ 'core/paragraph', {
									placeholder: __( 'Eyebrow…', 'rcmi-toolkit' ),
									content: 'Accelerating Real‑World Impact.',
									className: 'eyebrow'
								} ],
								[ 'core/paragraph', {
									placeholder: __( 'Lede text…', 'rcmi-toolkit' ),
									content: 'Building research capacity, developing investigators, and partnering with communities to improve chronic disease outcomes across Houston and beyond.',
									className: 'lede'
								} ],
								[ 'core/buttons', {}, [
									[ 'core/button', {
										text: 'Request Support',
										url: '#start',
										className: 'btn btn-primary'
									} ]
								] ]
							],
							templateLock: false
						} )
					)
				)
			);

			return el( Fragment, null,
				el( InspectorControls, null, inspectorChildren ),
				el( 'section', blockProps, previewChildren )
			);
		},
		save: function () {
			// InnerBlocks content is serialized between the block delimiters.
			// The render_callback wraps it with parallax layers + scrim.
			return el( InnerBlocks.Content );
		}
	} );

} )( window.wp );
