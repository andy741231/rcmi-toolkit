( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var registerBlockType = wp.blocks.registerBlockType;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TabPanel = wp.components.TabPanel;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var RichText = wp.blockEditor.RichText;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var __ = wp.i18n.__;

	// ============================================================
	// MobileImagePicker — stable top-level component.
	// Provides a canvas-based 2:3 portrait cropper for mobile images.
	// Extracted from the parallax block's edit() so it is not recreated
	// on every render (which caused crop-modal state loss).
	// ============================================================
	var MobileImagePicker = function ( props ) {
		var label = props.label;
		var mobileUrl = props.mobileUrl;
		var onSelect = props.onSelect;
		var onRemove = props.onRemove;

		// cropImage: null = no modal, {url,id,nw,nh} = showing crop
		var cropImage = useState( null );
		var displayW = useState( 0 );
		var displayH = useState( 0 );
		var cropX = useState( 0 );  // crop box offset in display px
		var cropY = useState( 0 );
		var uploading = useState( false );
		var dragRef = useRef( null );
		var imgRef = useRef( null );

		// Max display width for the crop modal
		var MAX_DISPLAY = 400;

		// Compute crop box size (largest 2:3 box within displayed image)
		var boxW, boxH;
		if ( displayW[0] && displayH[0] ) {
			var imgAR = displayW[0] / displayH[0];
			var cropAR = 2 / 3;
			if ( imgAR > cropAR ) {
				// Image wider than 2:3 → box height = image height
				boxH = displayH[0];
				boxW = boxH * cropAR;
			} else {
				// Image taller than 2:3 → box width = image width
				boxW = displayW[0];
				boxH = boxW / cropAR;
			}
		}

		var handleMediaSelect = function ( media ) {
			var img = new Image();
			img.onload = function () {
				var scale = Math.min( 1, MAX_DISPLAY / img.naturalWidth );
				var dw = Math.round( img.naturalWidth * scale );
				var dh = Math.round( img.naturalHeight * scale );
				displayW[1]( dw );
				displayH[1]( dh );
				// Center the crop box
				cropX[1]( Math.round( ( dw - ( dh * 2 / 3 > dw ? dw : dh * 2 / 3 ) ) / 2 ) );
				cropY[1]( Math.round( ( dh - ( dw * 3 / 2 > dh ? dh : dw * 3 / 2 ) ) / 2 ) );
				cropImage[1]( { url: media.url, id: media.id, nw: img.naturalWidth, nh: img.naturalHeight } );
			};
			img.src = media.url;
		};

		// Recompute centered crop box when image loads
		useEffect( function () {
			if ( cropImage[0] && displayW[0] && displayH[0] ) {
				var ar = displayW[0] / displayH[0];
				var bw, bh;
				if ( ar > 2 / 3 ) {
					bh = displayH[0];
					bw = bh * 2 / 3;
				} else {
					bw = displayW[0];
					bh = bw * 3 / 2;
				}
				cropX[1]( Math.round( ( displayW[0] - bw ) / 2 ) );
				cropY[1]( Math.round( ( displayH[0] - bh ) / 2 ) );
			}
		}, [ cropImage[0], displayW[0], displayH[0] ] );

		var handleCrop = function () {
			if ( ! cropImage[0] ) { return; }
			uploading[1]( true );

			var scale = cropImage[0].nw / displayW[0];
			var bw, bh;
			if ( displayW[0] / displayH[0] > 2 / 3 ) {
				bh = displayH[0]; bw = bh * 2 / 3;
			} else {
				bw = displayW[0]; bh = bw * 3 / 2;
			}
			var sx = Math.round( cropX[0] * scale );
			var sy = Math.round( cropY[0] * scale );
			var sw = Math.round( bw * scale );
			var sh = Math.round( bh * scale );

			var img = new Image();
			img.crossOrigin = 'anonymous';
			img.onload = function () {
				var canvas = document.createElement( 'canvas' );
				canvas.width = sw;
				canvas.height = sh;
				var ctx = canvas.getContext( '2d' );
				ctx.drawImage( img, sx, sy, sw, sh, 0, 0, sw, sh );
				canvas.toBlob( function ( blob ) {
					var formData = new FormData();
					formData.append( 'file', blob, 'mobile-crop-' + Date.now() + '.png' );
					wp.apiFetch( {
						path: '/wp/v2/media',
						method: 'POST',
						body: formData
					} ).then( function ( response ) {
						onSelect( response.id, response.source_url );
						cropImage[1]( null );
						uploading[1]( false );
					} ).catch( function ( err ) {
						uploading[1]( false );
						window.alert( 'Upload failed: ' + ( err.message || 'Unknown error' ) );
					} );
				}, 'image/png' );
			};
			img.src = cropImage[0].url;
		};

		// Drag handlers for crop box
		var onMouseDown = function ( e ) {
			e.preventDefault();
			dragRef.current = {
				startX: e.clientX,
				startY: e.clientY,
				origX: cropX[0],
				origY: cropY[0]
			};
			var onMove = function ( ev ) {
				if ( ! dragRef.current ) { return; }
				var dx = ev.clientX - dragRef.current.startX;
				var dy = ev.clientY - dragRef.current.startY;
				var nx = dragRef.current.origX + dx;
				var ny = dragRef.current.origY + dy;
				// Clamp within image bounds
				nx = Math.max( 0, Math.min( nx, displayW[0] - boxW ) );
				ny = Math.max( 0, Math.min( ny, displayH[0] - boxH ) );
				cropX[1]( nx );
				cropY[1]( ny );
			};
			var onUp = function () {
				dragRef.current = null;
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup', onUp );
			};
			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup', onUp );
		};

		return el( 'div', null,
			// Label + choose button
			el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px', marginTop: '12px' } }, __( 'Mobile image (portrait crop)', 'rcmi-toolkit' ) ),
			el( MediaUpload, {
				onSelect: handleMediaSelect,
				allowedTypes: 'image',
				value: 0,
				render: function ( obj ) {
					return el( wp.components.Button, {
						onClick: obj.open,
						variant: 'secondary',
						className: 'rcmi-image-picker-btn'
					}, mobileUrl ? __( 'Replace Mobile Image (Crop)', 'rcmi-toolkit' ) : __( 'Choose Mobile Image (Crop)', 'rcmi-toolkit' ) );
				}
			} ),
			// Preview + remove
			mobileUrl ? el( 'div', { className: 'rcmi-image-preview' },
				el( 'img', { src: mobileUrl, alt: label + ' (mobile)' } ),
				el( wp.components.Button, {
					onClick: onRemove,
					variant: 'tertiary',
					isDestructive: true
				}, __( 'Remove mobile image', 'rcmi-toolkit' ) )
			) : null,
			el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: '4px' } },
				__( 'Crop to portrait (2:3 ratio). Used on screens <768px with object-fit:cover.', 'rcmi-toolkit' )
			),
			// Crop modal
			cropImage[0] ? el( wp.components.Modal, {
				title: __( 'Crop to Portrait (2:3)', 'rcmi-toolkit' ),
				onRequestClose: function () { cropImage[1]( null ); },
				shouldCloseOnClickOutside: ! uploading[0],
				shouldCloseOnEsc: ! uploading[0],
				style: { maxWidth: '500px' }
			},
				el( 'div', { style: { position: 'relative', display: 'inline-block', userSelect: 'none' } },
					el( 'img', {
						ref: function ( r ) { imgRef.current = r; },
						src: cropImage[0].url,
						alt: '',
						style: { display: 'block', maxWidth: MAX_DISPLAY + 'px', height: 'auto' }
					} ),
					// Dark overlay
					el( 'div', {
						style: {
							position: 'absolute', top: 0, left: 0,
							width: '100%', height: '100%',
							background: 'rgba(0,0,0,0.5)',
							pointerEvents: 'none'
						}
					} ),
					// Crop box "hole" — use box-shadow trick to cut out
					el( 'div', {
						onMouseDown: onMouseDown,
						style: {
							position: 'absolute',
							left: cropX[0] + 'px',
							top: cropY[0] + 'px',
							width: ( boxW || 0 ) + 'px',
							height: ( boxH || 0 ) + 'px',
							cursor: 'move',
							boxShadow: '0 0 0 9999px rgba(0,0,0,0.5)',
							border: '2px solid #fff',
							background: 'transparent'
						}
					} )
				),
				el( 'div', { style: { display: 'flex', gap: '8px', marginTop: '16px', justifyContent: 'flex-end' } },
					uploading[0] ? el( wp.components.Spinner ) : null,
					el( wp.components.Button, {
						onClick: function () { cropImage[1]( null ); },
						variant: 'tertiary',
						disabled: uploading[0]
					}, __( 'Cancel', 'rcmi-toolkit' ) ),
					el( wp.components.Button, {
						onClick: handleCrop,
						variant: 'primary',
						disabled: uploading[0]
					}, __( 'Crop & Save', 'rcmi-toolkit' ) )
				)
			) : null
		);
	};

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
		var r = parseInt( h.substr( 0, 2 ), 16 );
		var g = parseInt( h.substr( 2, 2 ), 16 );
		var b = parseInt( h.substr( 4, 2 ), 16 );
		// Use isNaN check, not || (0 is falsy but valid for black #000000)
		if ( isNaN( r ) ) { r = 255; }
		if ( isNaN( g ) ) { g = 255; }
		if ( isNaN( b ) ) { b = 255; }
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

	// --- Line Height dropdown (priority 5.5) ---
	var lineHeights = [
		{ slug: 'tight',   name: __( 'Tight', 'rcmi-toolkit' ),   value: '0.8' },
		{ slug: 'snug',    name: __( 'Snug', 'rcmi-toolkit' ),    value: '1.0' },
		{ slug: 'normal',  name: __( 'Normal', 'rcmi-toolkit' ),  value: '1.2' },
		{ slug: 'relaxed', name: __( 'Relaxed', 'rcmi-toolkit' ), value: '1.4' },
		{ slug: 'loose',   name: __( 'Loose', 'rcmi-toolkit' ),   value: '1.6' }
	];

	registerFormatType( 'rcmi/line-height', {
		title: __( 'Line Height', 'rcmi-toolkit' ),
		tagName: 'span',
		className: 'has-inline-line-height',
		attributes: { style: 'style' },
		priority: 5.5,
		edit: function ( props ) {
			var activeLH = __( 'Line Height', 'rcmi-toolkit' );
			var fmt = getActiveFormat( props.value, 'rcmi/line-height' );
			if ( fmt && fmt.attributes.style ) {
				var m = fmt.attributes.style.match( /line-height:\s*([^;]+)/ );
				if ( m ) {
					for ( var i = 0; i < lineHeights.length; i++ ) {
						if ( lineHeights[ i ].value === m[ 1 ].trim() ) { activeLH = lineHeights[ i ].name; break; }
					}
				}
			}
			return el( BlockControls, null,
				el( Dropdown, {
					renderToggle: function ( ref ) {
						return el( ToolbarButton, {
							onClick: ref.onToggle,
							'aria-expanded': ref.isOpen
						}, activeLH );
					},
					renderContent: function () {
						return el( 'div', { style: { padding: '4px', minWidth: '140px' } },
							lineHeights.map( function ( lh ) {
								return el( 'button', {
									key: lh.slug,
									onClick: function () {
										props.onChange( applyFormat( props.value, {
											type: 'rcmi/line-height',
											attributes: { style: 'line-height: ' + lh.value }
										} ) );
									},
									style: { display: 'block', width: '100%', padding: '8px 12px', textAlign: 'left', lineHeight: lh.value, fontSize: '14px', cursor: 'pointer', background: 'transparent', border: 'none' }
								}, lh.name );
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
			// Reactive check: only pass the template when the block has no
			// inner blocks. This prevents the template from being re-applied
			// on re-render/re-mount (which would seed new blocks and mark
			// the post as dirty after save).
			var hasInnerBlocks = useSelect( function ( select ) {
				var block = select( 'core/block-editor' ).getBlock( props.clientId );
				return !!( block && block.innerBlocks && block.innerBlocks.length );
			}, [ props.clientId ] );
			var quoteTemplate = [
				[ 'core/paragraph', {
					placeholder: __( 'Quote text…', 'rcmi-toolkit' ),
					content: "Chronic disease doesn't yield to single disciplines or single institutions. It yields to relationships — built slowly, across communities, and measured in lives improved."
				} ],
				[ 'core/paragraph', {
					placeholder: __( 'Citation…', 'rcmi-toolkit' ),
					content: 'RCMI Coordinating Center, Guiding Principle',
					className: 'cite'
				} ]
			];
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap quote-block' },
					el( 'div', { className: 'quote-mark' }, '\u201C' ),
					el( 'div', { className: 'quote-body' },
						el( InnerBlocks, {
							allowedBlocks: [ 'core/paragraph', 'core/heading', 'core/list', 'core/quote', 'core/image' ],
							template: hasInnerBlocks ? undefined : quoteTemplate,
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
			// Reactive check: only pass the template when the block has no
			// inner blocks (same fix as quote-block and parallax).
			var hasInnerBlocks = useSelect( function ( select ) {
				var block = select( 'core/block-editor' ).getBlock( props.clientId );
				return !!( block && block.innerBlocks && block.innerBlocks.length );
			}, [ props.clientId ] );
			var ctaTemplate = [
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
								className: 'btn-outline',
								style: {
									color: { background: 'transparent', text: '#ffffff' },
									border: { radius: '999px', color: '#ffffff', width: '1px', style: 'solid' },
									spacing: { padding: { top: '14px', right: '26px', bottom: '14px', left: '26px' } }
								}
							} ],
							[ 'core/button', {
								text: 'Explore Research',
								url: '/cores/#investigator',
								className: 'btn-primary',
								style: {
									color: { background: '#ffffff', text: '#C8102E' },
									border: { radius: '999px', color: '#ffffff', width: '1px', style: 'solid' },
									spacing: { padding: { top: '14px', right: '26px', bottom: '14px', left: '26px' } }
								}
							} ]
						] ]
					] ]
				] ]
			];
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap' },
					el( 'div', { className: 'cta-band' },
						el( InnerBlocks, {
							allowedBlocks: [ 'core/columns', 'core/heading', 'core/paragraph', 'core/buttons', 'core/image', 'core/spacer', 'core/separator', 'core/group' ],
							template: hasInnerBlocks ? undefined : ctaTemplate,
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
								el( 'a', { href: attrs.ctaLink, className: 'btn btn-primary',
													style: { borderRadius: ( attrs.buttonRadius != null ? attrs.buttonRadius : 999 ) + 'px' }, onClick: function ( e ) { e.preventDefault(); } }, attrs.ctaText + ' \u2192' )
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
			tabBtnActiveTextColor: { type: 'string', default: '#c8102e' },
			// Global gradient: when enabled, overrides per-tab scrim gradients.
			globalScrim: { type: 'boolean', default: false },
			globalScrimStops: { type: 'array', default: [
				{ color: '#ffffff', opacity: 0.9, position: 0 },
				{ color: '#ffffff', opacity: 0.54, position: 50 },
				{ color: '#ffffff', opacity: 0, position: 100 }
			] },
			globalScrimType: { type: 'string', default: 'linear' },
			globalScrimAngle: { type: 'number', default: 90 },
			buttonRadius: { type: 'number', default: 999 }
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

			// Global gradient panel: set one gradient for all tabs at once.
			var globalScrimPanel = el( PanelBody, { title: __( 'Global Background Gradient', 'rcmi-toolkit' ), initialOpen: false },
				el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'When enabled, this gradient overrides the per-tab background gradient for all tabs.', 'rcmi-toolkit' ) ),
				el( ToggleControl, {
					label: __( 'Enable global gradient', 'rcmi-toolkit' ),
					checked: !! attrs.globalScrim,
					onChange: function ( v ) { setAttributes( { globalScrim: v } ); }
				} ),
				attrs.globalScrim ? el( Fragment, null,
					renderGradientPicker( attrs.globalScrimStops, attrs.globalScrimType, attrs.globalScrimAngle, function ( stops, type, angle ) {
						setAttributes( { globalScrimStops: stops, globalScrimType: type, globalScrimAngle: angle } );
					} )
				) : null
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
				el( InspectorControls, null, [ transitionPanel, layoutPanel, globalScrimPanel, tabsPanel ].concat( tabPanels ) ),
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
						el( 'div', { className: 'rcmi-tab-scrim', 'aria-hidden': 'true', style: { background: buildGradientCSS(
							attrs.globalScrim ? attrs.globalScrimStops : activeTabData.scrimStops,
							attrs.globalScrim ? attrs.globalScrimType : activeTabData.scrimType,
							attrs.globalScrim ? attrs.globalScrimAngle : activeTabData.scrimAngle
						) } } ),
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
													style: { borderRadius: ( attrs.buttonRadius != null ? attrs.buttonRadius : 999 ) + 'px' },
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
	// Block: rcmi/slide-block
	// Full-bleed slider with static background images, gradient scrim,
	// and free-form content per slide. Navigation via arrows and/or dots.
	// Optional auto-play, random first slide, and GSAP transitions.
	// No parallax, no tab labels — just slides with prev/next + dots.
	// ============================================================
	registerBlockType( 'rcmi/slide-block', {
		apiVersion: 3,
		title: __( 'RCMI Slide Block', 'rcmi-toolkit' ),
		description: __( 'Full-bleed slider with background images, gradient scrim, and editable content per slide. Auto-play, random start, arrows/dots navigation.', 'rcmi-toolkit' ),
		category: 'rcmi-sections',
		icon: 'images-alt',
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
			slides: {
				type: 'array',
				default: [
					{
						id: 'slide-1',
						bgImageId: 0,
						bgImageUrl: '',
						bgPositionX: 50,
						bgPositionY: 50,
						bgScale: 120,
						bgMobileImageId: 0,
						bgMobileImageUrl: '',
						bgMobileScale: 110,
						bgMobilePositionX: 50,
						bgMobilePositionY: 50,
						scrimStops: [
							{ color: '#f8f5ee', opacity: 0.85, position: 0 },
							{ color: '#f8f5ee', opacity: 0.34, position: 40 },
							{ color: '#f8f5ee', opacity: 0, position: 65 }
						],
						scrimType: 'linear',
						scrimAngle: 90,
						contentAlign: 'left',
						heading: 'Advancing Chronic<br>Disease Research.',
						lede: 'Building research capacity, developing investigators, and partnering with communities to improve chronic disease outcomes across Houston and beyond.',
						buttons: [ { text: 'Request Support', link: '#start' } ]
					},
					{
						id: 'slide-2',
						bgImageId: 0,
						bgImageUrl: '',
						bgPositionX: 50,
						bgPositionY: 50,
						bgScale: 120,
						bgMobileImageId: 0,
						bgMobileImageUrl: '',
						bgMobileScale: 110,
						bgMobilePositionX: 50,
						bgMobilePositionY: 50,
						scrimStops: [
							{ color: '#f8f5ee', opacity: 0.85, position: 0 },
							{ color: '#f8f5ee', opacity: 0.34, position: 40 },
							{ color: '#f8f5ee', opacity: 0, position: 65 }
						],
						scrimType: 'linear',
						scrimAngle: 90,
						contentAlign: 'left',
						heading: 'Growing the next<br>generation of leaders.',
						lede: 'We invest early and often in the people who will carry chronic disease research forward — through funding, mentorship, and structured training pathways.',
						buttons: [ { text: 'Learn More', link: '#' } ]
					}
				]
			},
			autoplay: { type: 'boolean', default: false },
			autoplayInterval: { type: 'number', default: 5 },
			pauseOnHover: { type: 'boolean', default: true },
			randomStart: { type: 'boolean', default: false },
			loop: { type: 'boolean', default: true },
			transition: { type: 'string', default: 'fade' },
			showArrows: { type: 'boolean', default: true },
			showDots: { type: 'boolean', default: true },
			navPosition: { type: 'string', default: 'bottom' },
			height: { type: 'number', default: 80 },
			globalScrim: { type: 'boolean', default: false },
			globalScrimStops: { type: 'array', default: [
				{ color: '#f8f5ee', opacity: 0.85, position: 0 },
				{ color: '#f8f5ee', opacity: 0.34, position: 40 },
				{ color: '#f8f5ee', opacity: 0, position: 65 }
			] },
			globalScrimType: { type: 'string', default: 'linear' },
			globalScrimAngle: { type: 'number', default: 90 },
			buttonRadius: { type: 'number', default: 999 }
		},
		edit: function ( props ) {
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var activeSlide = useState( 0 );
			var activeIdx = activeSlide[0];
			var setActiveIdx = activeSlide[1];
			var blockProps = useBlockProps( { className: 'rcmi-slide-block-editor' } );
			var slides = attrs.slides || [];

			var updateSlide = function ( idx, key, val ) {
				var newSlides = slides.map( function ( s, i ) {
					if ( i !== idx ) return s;
					var ns = Object.assign( {}, s );
					ns[ key ] = val;
					return ns;
				} );
				setAttributes( { slides: newSlides } );
			};

			var addSlide = function () {
				var newId = 'slide-' + Date.now();
				var newSlide = {
					id: newId,
					bgImageId: 0, bgImageUrl: '',
					bgPositionX: 50, bgPositionY: 50, bgScale: 120,
					bgMobileImageId: 0, bgMobileImageUrl: '',
					bgMobileScale: 110, bgMobilePositionX: 50, bgMobilePositionY: 50,
					scrimStops: [
						{ color: '#f8f5ee', opacity: 0.85, position: 0 },
						{ color: '#f8f5ee', opacity: 0.34, position: 40 },
						{ color: '#f8f5ee', opacity: 0, position: 65 }
					],
					scrimType: 'linear', scrimAngle: 90,
					contentAlign: 'left',
					heading: '', lede: '',
					buttons: [ { text: '', link: '#' } ]
				};
				setAttributes( { slides: slides.concat( [ newSlide ] ) } );
				setActiveIdx( slides.length );
			};

			var removeSlide = function ( idx ) {
				if ( slides.length <= 1 ) return;
				var newSlides = slides.filter( function ( _, i ) { return i !== idx; } );
				setAttributes( { slides: newSlides } );
				if ( activeIdx >= newSlides.length ) {
					setActiveIdx( newSlides.length - 1 );
				}
			};

			var moveSlide = function ( idx, dir ) {
				var newIdx = idx + dir;
				if ( newIdx < 0 || newIdx >= slides.length ) return;
				var newSlides = slides.slice();
				var tmp = newSlides[ newIdx ];
				newSlides[ newIdx ] = newSlides[ idx ];
				newSlides[ idx ] = tmp;
				setAttributes( { slides: newSlides } );
				if ( activeIdx === idx ) setActiveIdx( newIdx );
				else if ( activeIdx === newIdx ) setActiveIdx( idx );
			};

			var addButton = function ( sIdx ) {
				var newSlides = slides.map( function ( s, i ) {
					if ( i !== sIdx ) return s;
					var ns = Object.assign( {}, s );
					ns.buttons = ( ns.buttons || [] ).concat( [ { text: '', link: '#' } ] );
					return ns;
				} );
				setAttributes( { slides: newSlides } );
			};

			var removeButton = function ( sIdx, bIdx ) {
				var newSlides = slides.map( function ( s, i ) {
					if ( i !== sIdx ) return s;
					var ns = Object.assign( {}, s );
					ns.buttons = ( ns.buttons || [] ).filter( function ( _, bi ) { return bi !== bIdx; } );
					return ns;
				} );
				setAttributes( { slides: newSlides } );
			};

			var updateButton = function ( sIdx, bIdx, key, val ) {
				var newSlides = slides.map( function ( s, i ) {
					if ( i !== sIdx ) return s;
					var ns = Object.assign( {}, s );
					ns.buttons = ( ns.buttons || [] ).map( function ( b, bi ) {
						if ( bi !== bIdx ) return b;
						var nb = Object.assign( {}, b );
						nb[ key ] = val;
						return nb;
					} );
					return ns;
				} );
				setAttributes( { slides: newSlides } );
			};

			// ---- Inspector panels ----

			// Settings panel: autoplay, transition, navigation, etc.
			var settingsPanel = el( PanelBody, { title: __( 'Slider Settings', 'rcmi-toolkit' ), initialOpen: true },
				el( ToggleControl, { label: __( 'Auto-play', 'rcmi-toolkit' ), checked: !! attrs.autoplay, onChange: function ( v ) { setAttributes( { autoplay: v } ); } } ),
				attrs.autoplay ? el( Fragment, null,
					el( RangeControl, { label: __( 'Interval (seconds)', 'rcmi-toolkit' ), value: attrs.autoplayInterval, onChange: function ( v ) { setAttributes( { autoplayInterval: v } ); }, min: 3, max: 10, step: 1 } ),
					el( ToggleControl, { label: __( 'Pause on hover', 'rcmi-toolkit' ), checked: !! attrs.pauseOnHover, onChange: function ( v ) { setAttributes( { pauseOnHover: v } ); } } )
				) : null,
				el( ToggleControl, { label: __( 'Random first slide on page load', 'rcmi-toolkit' ), checked: !! attrs.randomStart, onChange: function ( v ) { setAttributes( { randomStart: v } ); }, help: __( 'Picks a random slide as the starting slide each time the page loads.', 'rcmi-toolkit' ) } ),
				el( ToggleControl, { label: __( 'Loop (infinite)', 'rcmi-toolkit' ), checked: !! attrs.loop, onChange: function ( v ) { setAttributes( { loop: v } ); } } ),
				el( SelectControl, {
					label: __( 'Transition effect', 'rcmi-toolkit' ),
					value: attrs.transition,
					options: [
						{ value: 'none',    label: __( 'None (instant switch)', 'rcmi-toolkit' ) },
						{ value: 'fade',    label: __( 'Fade (cross-fade)', 'rcmi-toolkit' ) },
						{ value: 'slide',   label: __( 'Slide (horizontal)', 'rcmi-toolkit' ) },
						{ value: 'curtain', label: __( 'Curtain (vertical)', 'rcmi-toolkit' ) },
						{ value: 'wipe',    label: __( 'Wipe (clip-path reveal)', 'rcmi-toolkit' ) },
						{ value: 'reveal',  label: __( 'Reveal (zoom + fade)', 'rcmi-toolkit' ) }
					],
					onChange: function ( v ) { setAttributes( { transition: v } ); }
				} ),
				el( RangeControl, { label: __( 'Height (vh)', 'rcmi-toolkit' ), value: attrs.height, onChange: function ( v ) { setAttributes( { height: v } ); }, min: 30, max: 100, step: 5, help: __( 'Global height for all slides.', 'rcmi-toolkit' ) } ),
				el( RangeControl, { label: __( 'Button radius (px)', 'rcmi-toolkit' ), value: attrs.buttonRadius, onChange: function ( v ) { setAttributes( { buttonRadius: v } ); }, min: 0, max: 999, step: 1, help: __( '0 = square, 999 = fully rounded (pill).', 'rcmi-toolkit' ) } )
			);

			// Navigation panel.
			var navPanel = el( PanelBody, { title: __( 'Navigation', 'rcmi-toolkit' ), initialOpen: false },
				el( ToggleControl, { label: __( 'Show arrow buttons', 'rcmi-toolkit' ), checked: !! attrs.showArrows, onChange: function ( v ) { setAttributes( { showArrows: v } ); } } ),
				el( ToggleControl, { label: __( 'Show dot indicators', 'rcmi-toolkit' ), checked: !! attrs.showDots, onChange: function ( v ) { setAttributes( { showDots: v } ); } } ),
				el( SelectControl, {
					label: __( 'Navigation position', 'rcmi-toolkit' ),
					value: attrs.navPosition,
					options: [
						{ value: 'top', label: __( 'Top', 'rcmi-toolkit' ) },
						{ value: 'bottom', label: __( 'Bottom', 'rcmi-toolkit' ) }
					],
					onChange: function ( v ) { setAttributes( { navPosition: v } ); }
				} )
			);

			// Global gradient panel.
			var globalScrimPanel = el( PanelBody, { title: __( 'Global Background Gradient', 'rcmi-toolkit' ), initialOpen: false },
				el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'When enabled, this gradient overrides the per-slide gradient for all slides.', 'rcmi-toolkit' ) ),
				el( ToggleControl, { label: __( 'Enable global gradient', 'rcmi-toolkit' ), checked: !! attrs.globalScrim, onChange: function ( v ) { setAttributes( { globalScrim: v } ); } } ),
				attrs.globalScrim ? el( Fragment, null,
					renderGradientPicker( attrs.globalScrimStops, attrs.globalScrimType, attrs.globalScrimAngle, function ( stops, type, angle ) {
						setAttributes( { globalScrimStops: stops, globalScrimType: type, globalScrimAngle: angle } );
					} )
				) : null
			);

			// Slides management panel: add/remove/reorder.
			var slidesPanel = el( PanelBody, { title: __( 'Slides', 'rcmi-toolkit' ), initialOpen: false },
				slides.map( function ( slide, idx ) {
					return el( 'div', { key: 'slide-mgmt-' + idx, style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid #f0f0f0' } },
						el( 'span', { style: { fontSize: '13px' } }, __( 'Slide ' + ( idx + 1 ) ) ),
						el( 'div', null,
							idx > 0 ? el( wp.components.Button, { onClick: function () { moveSlide( idx, -1 ); }, variant: 'tertiary', isSmall: true, icon: 'arrow-up-alt2' } ) : null,
							idx < slides.length - 1 ? el( wp.components.Button, { onClick: function () { moveSlide( idx, 1 ); }, variant: 'tertiary', isSmall: true, icon: 'arrow-down-alt2' } ) : null,
							slides.length > 1 ? el( wp.components.Button, { onClick: function () { removeSlide( idx ); }, variant: 'tertiary', isDestructive: true, isSmall: true }, __( 'Remove', 'rcmi-toolkit' ) ) : null
						)
					);
				} ),
				el( wp.components.Button, { onClick: function () { addSlide(); }, variant: 'secondary', isSmall: true, style: { marginTop: '10px' } }, __( '+ Add Slide', 'rcmi-toolkit' ) )
			);

			// Per-slide inspector panels.
			var slidePanels = slides.map( function ( slide, idx ) {
				var slideHeight = attrs.height;
				return el( PanelBody, { title: __( 'Slide ' + ( idx + 1 ), 'rcmi-toolkit' ), initialOpen: false, key: 'slide-panel-' + idx },
					// Background image
					el( MediaUpload, {
						onSelect: function ( media ) { updateSlide( idx, 'bgImageId', media.id ); updateSlide( idx, 'bgImageUrl', media.url ); },
						allowedTypes: 'image',
						value: slide.bgImageId,
						render: function ( obj ) {
							return el( wp.components.Button, { onClick: obj.open, variant: 'secondary', className: 'rcmi-image-picker-btn' },
								slide.bgImageUrl ? __( 'Replace Background Image', 'rcmi-toolkit' ) : __( 'Choose Background Image', 'rcmi-toolkit' )
							);
						}
					} ),
					slide.bgImageUrl ? el( 'div', { className: 'rcmi-image-preview' },
						el( 'img', { src: slide.bgImageUrl, alt: __( 'Slide background', 'rcmi-toolkit' ) } ),
						el( wp.components.Button, { onClick: function () { updateSlide( idx, 'bgImageId', 0 ); updateSlide( idx, 'bgImageUrl', '' ); }, variant: 'tertiary', isDestructive: true }, __( 'Remove image', 'rcmi-toolkit' ) )
					) : null,
					// Background position & scale
					el( RangeControl, { label: __( 'Background Position X (%)', 'rcmi-toolkit' ), value: slide.bgPositionX, onChange: function ( v ) { updateSlide( idx, 'bgPositionX', v ); }, min: 0, max: 100, step: 1 } ),
					el( RangeControl, { label: __( 'Background Position Y (%)', 'rcmi-toolkit' ), value: slide.bgPositionY, onChange: function ( v ) { updateSlide( idx, 'bgPositionY', v ); }, min: 0, max: 100, step: 1 } ),
					el( RangeControl, { label: __( 'Background Scale (%)', 'rcmi-toolkit' ), value: slide.bgScale, onChange: function ( v ) { updateSlide( idx, 'bgScale', v ); }, min: 100, max: 300, step: 5 } ),
					// Mobile background image
					el( 'p', { style: { fontSize: '12px', color: '#666', margin: '10px 0 4px' } }, __( 'Mobile Background (optional, for screens <768px)', 'rcmi-toolkit' ) ),
					el( MediaUpload, {
						onSelect: function ( media ) { updateSlide( idx, 'bgMobileImageId', media.id ); updateSlide( idx, 'bgMobileImageUrl', media.url ); },
						allowedTypes: 'image',
						value: slide.bgMobileImageId,
						render: function ( obj ) {
							return el( wp.components.Button, { onClick: obj.open, variant: 'secondary', isSmall: true },
								slide.bgMobileImageUrl ? __( 'Replace Mobile Image', 'rcmi-toolkit' ) : __( 'Choose Mobile Image', 'rcmi-toolkit' )
							);
						}
					} ),
					slide.bgMobileImageUrl ? el( wp.components.Button, { onClick: function () { updateSlide( idx, 'bgMobileImageId', 0 ); updateSlide( idx, 'bgMobileImageUrl', '' ); }, variant: 'tertiary', isDestructive: true, isSmall: true }, __( 'Remove mobile image', 'rcmi-toolkit' ) ) : null,
					// Per-slide gradient scrim (only if global scrim is off)
					! attrs.globalScrim ? el( 'div', { key: 'slide-grad-' + idx, style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '12px' } },
						renderGradientPicker( slide.scrimStops, slide.scrimType, slide.scrimAngle, function ( stops, type, angle ) {
							var newSlides = slides.map( function ( s, i ) {
								if ( i !== idx ) return s;
								var ns = Object.assign( {}, s );
								ns.scrimStops = stops;
								ns.scrimType = type;
								ns.scrimAngle = angle;
								return ns;
							} );
							setAttributes( { slides: newSlides } );
						} )
					) : null,
					// Content alignment
					el( SelectControl, {
						label: __( 'Content alignment', 'rcmi-toolkit' ),
						value: slide.contentAlign,
						options: [
							{ value: 'left', label: __( 'Left', 'rcmi-toolkit' ) },
							{ value: 'center', label: __( 'Center', 'rcmi-toolkit' ) },
							{ value: 'right', label: __( 'Right', 'rcmi-toolkit' ) }
						],
						onChange: function ( v ) { updateSlide( idx, 'contentAlign', v ); }
					} ),
					// Buttons section
					el( 'div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '12px' } },
						el( 'strong', null, __( 'Buttons', 'rcmi-toolkit' ) ),
						( slide.buttons || [] ).map( function ( btn, bi ) {
							return el( 'div', { key: 'btn-' + bi, style: { borderBottom: '1px solid #f0f0f0', paddingBottom: '10px', marginBottom: '10px' } },
								el( 'div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' } },
									el( 'span', { style: { fontSize: '12px', fontWeight: '600' } }, __( 'Button ' + ( bi + 1 ) ) ),
									el( wp.components.Button, { onClick: function () { removeButton( idx, bi ); }, variant: 'tertiary', isDestructive: true, isSmall: true }, __( 'Remove', 'rcmi-toolkit' ) )
								),
								el( TextControl, { label: __( 'Text', 'rcmi-toolkit' ), value: btn.text, onChange: function ( v ) { updateButton( idx, bi, 'text', v ); } } ),
								el( TextControl, { label: __( 'Link', 'rcmi-toolkit' ), value: btn.link, onChange: function ( v ) { updateButton( idx, bi, 'link', v ); } } )
							);
						} ),
						el( wp.components.Button, { onClick: function () { addButton( idx ); }, variant: 'secondary', isSmall: true }, __( '+ Add Button', 'rcmi-toolkit' ) )
					)
				);
			} );

			// ---- Editor preview ----
			var activeData = slides[ activeIdx ] || slides[ 0 ] || {};
			var slideHeight = attrs.height + 'vh';
			var scrimGradient = buildGradientCSS(
				attrs.globalScrim ? attrs.globalScrimStops : activeData.scrimStops,
				attrs.globalScrim ? attrs.globalScrimType : activeData.scrimType,
				attrs.globalScrim ? attrs.globalScrimAngle : activeData.scrimAngle
			);

			// Text color support
			var colorClass = '';
			if ( attrs.textColor ) {
				colorClass = ' has-text-color has-' + attrs.textColor + '-color';
			}

			var copyStyle = {};
			if ( activeData.contentAlign === 'center' ) {
				copyStyle.maxWidth = '760px';
				copyStyle.margin = '0 auto';
			} else if ( activeData.contentAlign === 'right' ) {
				copyStyle.maxWidth = '570px';
				copyStyle.marginLeft = 'auto';
				copyStyle.marginRight = '0';
			}

			// Build background style for the active slide
			var bgStyle = {};
			if ( activeData.bgImageUrl ) {
				bgStyle.backgroundImage = 'url(' + activeData.bgImageUrl + ')';
				bgStyle.backgroundSize = ( activeData.bgScale || 120 ) + '%';
				bgStyle.backgroundPosition = ( activeData.bgPositionX || 50 ) + '% ' + ( activeData.bgPositionY || 50 ) + '%';
				bgStyle.backgroundRepeat = 'no-repeat';
			}

			// Build nav dots for editor
			var navDots = el( 'div', { className: 'rcmi-slide-dots rcmi-slide-dots-editor' },
				slides.map( function ( _, idx ) {
					return el( 'button', {
						key: 'dot-' + idx,
						className: 'rcmi-slide-dot' + ( idx === activeIdx ? ' is-active' : '' ),
						type: 'button',
						onClick: function () { setActiveIdx( idx ); },
						'aria-label': __( 'Go to slide ' + ( idx + 1 ) )
					} );
				} )
			);

			// Build nav arrows for editor
			var navArrows = attrs.showArrows ? el( Fragment, null,
				el( 'button', { className: 'rcmi-slide-arrow rcmi-slide-arrow-prev', type: 'button', onClick: function () { setActiveIdx( activeIdx > 0 ? activeIdx - 1 : slides.length - 1 ); }, 'aria-label': __( 'Previous slide' ) }, '\u2039' ),
				el( 'button', { className: 'rcmi-slide-arrow rcmi-slide-arrow-next', type: 'button', onClick: function () { setActiveIdx( activeIdx < slides.length - 1 ? activeIdx + 1 : 0 ); }, 'aria-label': __( 'Next slide' ) }, '\u203a' )
			) : null;

			var navElements = attrs.navPosition === 'top' ? [ navDots, navArrows ] : [ navArrows, navDots ];

			return el( Fragment, null,
				el( InspectorControls, null, [ settingsPanel, navPanel, globalScrimPanel, slidesPanel ].concat( slidePanels ) ),
				el( 'div', blockProps,
					attrs.navPosition === 'top' && attrs.showDots ? navDots : null,
					el( 'section', { className: 'rcmi-slide is-active' + colorClass, style: Object.assign( { height: slideHeight }, bgStyle ) },
						el( 'div', { className: 'rcmi-slide-scrim', style: { background: scrimGradient } } ),
						el( 'div', { className: 'wrap rcmi-slide-inner' },
							el( 'div', { className: 'rcmi-slide-copy', style: copyStyle },
								el( RichText, {
									tagName: 'h2',
									value: activeData.heading,
									onChange: function ( v ) { updateSlide( activeIdx, 'heading', v ); },
									placeholder: __( 'Heading…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
								} ),
								el( RichText, {
									tagName: 'p',
									className: 'lede',
									value: activeData.lede,
									onChange: function ( v ) { updateSlide( activeIdx, 'lede', v ); },
									placeholder: __( 'Lede text…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'rcmi/text-color', 'rcmi/highlight', 'rcmi/font-family', 'rcmi/font-size' ]
								} ),
								( activeData.buttons || [] ).length > 0 ? el( 'div', { className: 'rcmi-slide-actions' },
									( activeData.buttons || [] ).map( function ( btn, bi ) {
										return el( RichText, {
											key: 'pb-' + bi,
											tagName: 'a',
											className: 'btn btn-primary',
													style: { borderRadius: ( attrs.buttonRadius != null ? attrs.buttonRadius : 999 ) + 'px' },
											value: btn.text,
											onChange: function ( v ) { updateButton( activeIdx, bi, 'text', v ); },
											placeholder: __( 'Button text…', 'rcmi-toolkit' ),
											allowedFormats: []
										} );
									} )
								) : null
							)
						),
						navArrows
					),
					attrs.navPosition === 'bottom' && attrs.showDots ? navDots : null
				)
			);
		},
		save: function () {
			// Server-side rendered (dynamic block).
			return null;
		}
	} );

	// ============================================================
	// Block: rcmi/parallax (also serves as the hero block)
	// Two modes: "static" (single background image, like the old hero block)
	// and "parallax" (three image layers that scroll at different speeds).
	// Includes editable gradient scrim and content alignment controls.
	//
	// The hero preset is exposed by PHP via window.rcmiToolkitHeroPreset and
	// is applied to newly inserted blocks via setAttributes in edit() (not
	// as attribute defaults, which would cause WordPress to strip matching
	// attributes from existing blocks on save).
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
			// Parallax mode: 'scroll' (on scroll), 'mouse' (follows mouse)
			parallaxMode: { type: 'string', default: 'scroll' },
			// Layout
			height:      { type: 'number', default: 80 },
			// Mobile parallax intensity (0-2): multiplier for parallax speed on small screens
			mobileIntensity: { type: 'number', default: 0.7 },
			tabletScaleMultiplier: { type: 'number', default: 0.75 },
			// Per-layer position (object-position) and scale (visual zoom).
			// Position X/Y: 0-100% controls which part of the image is visible.
			// Scale: 100-300% controls image size relative to the section —
			// bigger = more parallax headroom and deeper zoom.
			bgPositionX:  { type: 'number', default: 50 },
			bgPositionY:  { type: 'number', default: 50 },
			bgScale:      { type: 'number', default: 200 },
			midPositionX: { type: 'number', default: 50 },
			midPositionY: { type: 'number', default: 50 },
			midScale:     { type: 'number', default: 200 },
			fgPositionX:  { type: 'number', default: 50 },
			fgPositionY:  { type: 'number', default: 50 },
			fgScale:      { type: 'number', default: 200 },
			// Per-layer mobile scale & position (used on screens <768px).
			'bgMobileScale':    { type: 'number', default: 100 },
			'bgMobilePositionX':{ type: 'number', default: 50 },
			'bgMobilePositionY':{ type: 'number', default: 50 },
			'midMobileScale':    { type: 'number', default: 100 },
			'midMobilePositionX':{ type: 'number', default: 50 },
			'midMobilePositionY':{ type: 'number', default: 50 },
			'fgMobileScale':    { type: 'number', default: 100 },
			'fgMobilePositionX':{ type: 'number', default: 50 },
			'fgMobilePositionY':{ type: 'number', default: 50 },
			// Per-layer mobile image (optional). If set, used on screens
			// <768px. User should pre-crop to portrait before uploading.
			bgMobileImageId:  { type: 'number', default: 0 },
			bgMobileImageUrl: { type: 'string', default: '' },
			midMobileImageId:  { type: 'number', default: 0 },
			midMobileImageUrl: { type: 'string', default: '' },
			fgMobileImageId:  { type: 'number', default: 0 },
			fgMobileImageUrl: { type: 'string', default: '' },
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
			var deviceTypeState = useState( ( wp.data.select( 'core/editor' ).getDeviceType && wp.data.select( 'core/editor' ).getDeviceType() ) || 'Desktop' );
			var deviceType = deviceTypeState[0];
			var blockProps = useBlockProps( { className: 'rcmi-parallax-editor', style: { minHeight: attrs.height + 'vh' } } );

			// Follow WordPress's Desktop / Tablet / Mobile preview toolbar.
			// The editor store changes device type when the preview toolbar is
			// used; subscribing here makes the block preview update immediately.
			useEffect( function () {
				if ( ! wp.data || ! wp.data.subscribe ) {
					return undefined;
				}
				var updateDeviceType = function () {
					var selector = wp.data.select( 'core/editor' );
					var next = selector.getDeviceType ? selector.getDeviceType() : 'Desktop';
					if ( next ) {
						// Always pass the latest store value. The previous
						// comparison used a stale initial value, so switching
						// Mobile → Desktop could leave the preview in Mobile.
						deviceTypeState[1]( next );
					}
				};
				return wp.data.subscribe( updateDeviceType );
			}, [] );

			// Check whether this block already has inner blocks in the store.
			// This is reactive (triggers re-render when inner blocks change),
			// so the template is passed only when the block is empty — seeding
			// new blocks — and removed once InnerBlocks have been committed,
			// so deletions are respected. This fixes a bug where the old
			// templateApplied useRef pattern removed the template before
			// InnerBlocks had time to process it on first insert.
			var hasInnerBlocks = useSelect( function ( select ) {
				var block = select( 'core/block-editor' ).getBlock( props.clientId );
				return !!( block && block.innerBlocks && block.innerBlocks.length );
			}, [ props.clientId ] );

			// Apply the Home hero preset to newly inserted blocks.
			// The preset is exposed by PHP via window.rcmiToolkitHeroPreset.
			// We only apply it once, and only when the block appears freshly
			// inserted (mode is still 'static' and no images have been set).
			// This does NOT change attribute defaults, so existing blocks keep
			// their saved attributes when re-saved in the editor.
			var presetApplied = useRef( false );
			useEffect( function () {
				if ( presetApplied.current ) {
					return;
				}
				presetApplied.current = true;
				var preset = window.rcmiToolkitHeroPreset;
				if ( ! preset || ! preset.attributes ) {
					return;
				}
				if ( attrs.mode === 'static' && ! attrs.bgImageId && ! attrs.midImageId && ! attrs.fgImageId ) {
					setAttributes( preset.attributes );
				}
			}, [] );

			// Helper: convert hex + alpha to rgba string.
			var hexToRgba = function ( hex, alpha ) {
				var h = ( hex || '#f8f5ee' ).replace( '#', '' );
				if ( h.length === 3 ) { h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2]; }
				var r = parseInt( h.substr( 0, 2 ), 16 );
				var g = parseInt( h.substr( 2, 2 ), 16 );
				var b = parseInt( h.substr( 4, 2 ), 16 );
				if ( isNaN( r ) ) { r = 255; }
				if ( isNaN( g ) ) { g = 255; }
				if ( isNaN( b ) ) { b = 255; }
				return 'rgba(' + r + ',' + g + ',' + b + ',' + ( Math.round( alpha * 100 ) / 100 ) + ')';
			};

			// Build the scrim gradient style from multi-stop picker.
			var scrimGradient = buildGradientCSS( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle );

			// Helper: switch the editor to a specific device preview so the
			// user can immediately see the effect of setting changes.
			var switchToDevicePreview = function ( device ) {
				if ( wp.data && wp.data.dispatch && wp.data.dispatch( 'core/editor' ) && wp.data.dispatch( 'core/editor' ).setDeviceType ) {
					wp.data.dispatch( 'core/editor' ).setDeviceType( device );
				}
			};
			var switchToMobilePreview = function () { switchToDevicePreview( 'Mobile' ); };
			var switchToDesktopPreview = function () { switchToDevicePreview( 'Desktop' ); };

			// Layer picker for parallax mode. Each layer panel uses a
			// TabPanel to split Desktop and Mobile controls so only the
			// relevant breakpoint's settings are visible at a time.
			// The active tab follows the editor's device preview.
			var layerPicker = function ( label, urlKey, idKey, speedKey, posXKey, posYKey, scaleKey, mobileIdKey, mobileUrlKey, mobileScaleKey, mobilePosXKey, mobilePosYKey ) {
				// Determine which tab to show based on the editor device
				// preview. Mobile → 'mobile'; Desktop/Tablet → 'desktop'.
				var activeTab = ( deviceType === 'Mobile' ) ? 'mobile' : 'desktop';

				return el( PanelBody, { title: label, initialOpen: urlKey === 'bgImageUrl' },
					el( TabPanel, {
						className: 'rcmi-layer-tabs',
						initialTabName: activeTab,
						key: activeTab, // remount when device changes
						onSelect: function ( tabName ) {
							// Switching the tab also switches the editor
							// device preview, so the user sees the result
							// of the breakpoint they're editing.
							if ( tabName === 'mobile' ) {
								switchToMobilePreview();
							} else {
								switchToDesktopPreview();
							}
						},
						tabs: [
							{ name: 'desktop', title: __( 'Desktop', 'rcmi-toolkit' ), className: 'rcmi-layer-tab-desktop' },
							{ name: 'mobile', title: __( 'Mobile', 'rcmi-toolkit' ), className: 'rcmi-layer-tab-mobile' }
						]
					}, function ( tab ) {
						if ( tab.name === 'mobile' ) {
							// ---- Mobile tab ----
							if ( ! attrs[ urlKey ] ) {
								return el( 'p', { style: { color: '#666', fontSize: '12px' } },
									__( 'Set a desktop image first, then add a mobile crop.', 'rcmi-toolkit' ) );
							}
							return el( 'div', { className: 'rcmi-layer-tab-content' },
								// Mobile image picker with built-in 2:3 portrait cropper.
								el( MobileImagePicker, {
									label: label,
									mobileUrl: attrs[ mobileUrlKey ],
									onSelect: function ( id, url ) {
										var u = {}; u[ mobileIdKey ] = id; u[ mobileUrlKey ] = url;
										setAttributes( u );
									},
									onRemove: function () {
										var u = {}; u[ mobileIdKey ] = 0; u[ mobileUrlKey ] = '';
										setAttributes( u );
									}
								} ),
								// Mobile scale & position sliders.
								el( 'div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '12px' } },
									el( RangeControl, {
										label: __( 'Mobile scale (%)', 'rcmi-toolkit' ),
										value: attrs[ mobileScaleKey ],
										onChange: function ( v ) { var u = {}; u[ mobileScaleKey ] = v; setAttributes( u ); },
										min: 25,
										max: 300,
										help: __( 'Image size on mobile. 100% = fills section, higher = zoom in.', 'rcmi-toolkit' )
									} ),
									el( RangeControl, {
										label: __( 'Mobile position X', 'rcmi-toolkit' ),
										value: attrs[ mobilePosXKey ],
										onChange: function ( v ) { var u = {}; u[ mobilePosXKey ] = v; setAttributes( u ); },
										min: 0,
										max: 100
									} ),
									el( RangeControl, {
										label: __( 'Mobile position Y', 'rcmi-toolkit' ),
										value: attrs[ mobilePosYKey ],
										onChange: function ( v ) { var u = {}; u[ mobilePosYKey ] = v; setAttributes( u ); },
										min: 0,
										max: 100
									} )
								)
							);
						}
						// ---- Desktop tab ----
						return el( 'div', { className: 'rcmi-layer-tab-content' },
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
							el( 'div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: '12px', marginTop: '12px' } },
								el( RangeControl, {
									label: __( 'Parallax speed', 'rcmi-toolkit' ),
									value: attrs[ speedKey ],
									onChange: function ( v ) { var u = {}; u[ speedKey ] = v; setAttributes( u ); },
									min: -2,
									max: 2,
									step: 0.05,
									help: __( 'Positive = layer drifts down on scroll, negative = layer rises. 0 = static. Direction is solely determined by the sign.', 'rcmi-toolkit' )
								} ),
								el( RangeControl, {
									label: __( 'Horizontal position', 'rcmi-toolkit' ),
									value: attrs[ posXKey ],
									onChange: function ( v ) { var u = {}; u[ posXKey ] = v; setAttributes( u ); },
									min: 0,
									max: 200,
									step: 1,
									help: __( '0% = left, 50% = center, 100% = right', 'rcmi-toolkit' )
								} ),
								el( RangeControl, {
									label: __( 'Vertical position', 'rcmi-toolkit' ),
									value: attrs[ posYKey ],
									onChange: function ( v ) { var u = {}; u[ posYKey ] = v; setAttributes( u ); },
									min: 0,
									max: 200,
									step: 1,
									help: __( '0% = bottom, 50% = center, 100% = top', 'rcmi-toolkit' )
								} ),
								el( RangeControl, {
									label: __( 'Scale (%)', 'rcmi-toolkit' ),
									value: attrs[ scaleKey ],
									onChange: function ( v ) { var u = {}; u[ scaleKey ] = v; setAttributes( u ); },
									min: 25,
									max: 300,
									step: 5,
									help: __( 'Image zoom. 100% = fills section, 200% = 2× headroom (default), 300% = deep zoom. Below 100% = windowed (shows section background around image).', 'rcmi-toolkit' )
								} )
							)
						);
					} )
				);
			};

			// Layer preview for the editor — renders an <img> element matching
			// the front-end: positioned at 50%/50%, sized to scale% of the
			// section, with object-fit:cover. object-fit:cover locks the
			// aspect ratio (crops, never stretches). Panning uses two
			// mechanisms: object-position (works at any scale, in the cover-
			// crop dimension) and transform (works at scale > 100%, both
			// dimensions). At scale 100%, only object-position works.
			var layerPreview = function ( url, label, zIndex, posX, posY, scale, objectFit ) {
				// Matches the PHP render callback: scale% × scale%
				// of section, centered, object-fit:contain (full image
				// visible). Position X/Y controls the layer transform (pan).
				var imgSlack = Math.max( 0, scale - 100 ) / 2;
				var range = Math.max( 100, imgSlack );
				var posOffsetX = ( posX - 50 ) * range / scale;
				// Y axis inverted: high posY = up. object-position uses
				// (100 - posY) and transform offset uses (50 - posY).
				var posOffsetY = ( 50 - posY ) * range / scale;
				if ( url ) {
					return el( 'img', {
						className: 'rcmi-parallax-layer-preview',
						src: url,
						alt: '',
						style: {
							zIndex: zIndex,
							width: scale + '%',
							height: scale + '%',
							maxWidth: 'none',
							maxHeight: 'none',
							objectFit: objectFit || 'contain',
							objectPosition: posX + '% ' + ( 100 - posY ) + '%',
							'--pos-x': posOffsetX + '%',
							'--pos-y': posOffsetY + '%',
							transform: 'translate(calc(-50% + var(--pos-x)),calc(-50% + var(--pos-y)))'
						}
					} );
				}
				return el( 'div', {
					className: 'rcmi-parallax-layer-preview is-empty',
					style: { zIndex: zIndex }
				}, el( 'span', { className: 'rcmi-layer-label' }, label ) );
			};

			// Select the same layer values the frontend uses for the current
			// editor device preview. Mobile uses the dedicated mobile image,
			// scale, and position. Tablet uses the tablet scale multiplier
			// with interpolated pan matching the 768px responsive boundary
			// (panFactor ≈ 0 at tablet width, per frontend.js interpolation).
			var previewLayer = function ( url, mobileUrl, label, zIndex, posX, posY, scale, mobilePosX, mobilePosY, mobileScale, hasMobile ) {
				var isMobilePreview = deviceType === 'Mobile';
				var isTabletPreview = deviceType === 'Tablet';
				var previewUrl = isMobilePreview && mobileUrl ? mobileUrl : url;
				var previewScale = scale;
				var previewPosX = posX;
				var previewPosY = posY;
				var previewFit = 'contain';

				if ( isMobilePreview ) {
					previewScale = mobileScale;
					previewPosX = mobilePosX;
					previewPosY = mobilePosY;
					previewFit = hasMobile ? 'contain' : 'cover';
				} else if ( isTabletPreview ) {
					// Tablet: scale × tabletMult, pan interpolated to ~0
					// at the 768px boundary (matching frontend.js where
					// panFactor = 0 when w <= TABLET_WIDTH).
					previewScale = scale * ( attrs.tabletScaleMultiplier || 0.75 );
					// Use 50/50 for object-position centering, and set
					// transform pan to 0 by passing 50/50 (offset = 0).
					previewPosX = 50;
					previewPosY = 50;
				}

				return layerPreview( previewUrl, label, zIndex, previewPosX, previewPosY, previewScale, previewFit );
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
				// Parallax mode: show 3 layer pickers with position + scale.
				inspectorChildren.push(
					layerPicker( __( 'Background Layer (slowest)', 'rcmi-toolkit' ), 'bgImageUrl', 'bgImageId', 'bgSpeed', 'bgPositionX', 'bgPositionY', 'bgScale', 'bgMobileImageId', 'bgMobileImageUrl', 'bgMobileScale', 'bgMobilePositionX', 'bgMobilePositionY' ),
					layerPicker( __( 'Middle Layer', 'rcmi-toolkit' ), 'midImageUrl', 'midImageId', 'midSpeed', 'midPositionX', 'midPositionY', 'midScale', 'midMobileImageId', 'midMobileImageUrl', 'midMobileScale', 'midMobilePositionX', 'midMobilePositionY' ),
					layerPicker( __( 'Foreground Layer (fastest)', 'rcmi-toolkit' ), 'fgImageUrl', 'fgImageId', 'fgSpeed', 'fgPositionX', 'fgPositionY', 'fgScale', 'fgMobileImageId', 'fgMobileImageUrl', 'fgMobileScale', 'fgMobilePositionX', 'fgMobilePositionY' )
				);
			} else {
				// Static mode: single background image with position + scale.
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
						) : null,
						el( RangeControl, {
							label: __( 'Horizontal position', 'rcmi-toolkit' ),
							value: attrs.bgPositionX,
							onChange: function ( v ) { setAttributes( { bgPositionX: v } ); },
							min: 0, max: 200, step: 1,
							help: __( '0% = left, 50% = center, 100% = right', 'rcmi-toolkit' )
						} ),
						el( RangeControl, {
							label: __( 'Vertical position', 'rcmi-toolkit' ),
							value: attrs.bgPositionY,
							onChange: function ( v ) { setAttributes( { bgPositionY: v } ); },
							min: 0, max: 200, step: 1,
							help: __( '0% = bottom, 50% = center, 100% = top', 'rcmi-toolkit' )
						} ),
						el( RangeControl, {
							label: __( 'Scale (%)', 'rcmi-toolkit' ),
							value: attrs.bgScale,
							onChange: function ( v ) { setAttributes( { bgScale: v } ); },
							min: 25, max: 300, step: 5,
							help: __( 'Image zoom. 100% = fills section, 200% = 2× headroom (default), 300% = deep zoom. Below 100% = windowed.', 'rcmi-toolkit' )
						} )
					)
				);
			}

			// Reorganized inspector panels (after the layer panels above):
			//   1. Movement — parallax mode, content speed, mobile intensity, tablet multiplier
			//   2. Hero Layout — section height, content alignment
			//   3. Readability Overlay — gradient scrim picker
			//   4. Advanced Stacking — z-index controls (collapsed by default)
			inspectorChildren.push(
				// ---- Movement panel (parallax only) ----
				isParallax ? el( PanelBody, { title: __( 'Movement', 'rcmi-toolkit' ), initialOpen: false },
					el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px' } }, __( 'Parallax mode', 'rcmi-toolkit' ) ),
					el( 'div', { style: { display: 'flex', gap: '8px', marginBottom: '8px' } },
						[ 'scroll', 'mouse' ].map( function ( m ) {
							return el( wp.components.Button, {
								key: 'mode-' + m,
								onClick: function () { setAttributes( { parallaxMode: m } ); },
								variant: attrs.parallaxMode === m ? 'primary' : 'secondary',
								isPressed: attrs.parallaxMode === m
							}, m.charAt( 0 ).toUpperCase() + m.slice( 1 ) );
						} )
					),
					el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'Scroll = layers move as you scroll (default). Mouse = layers follow mouse position.', 'rcmi-toolkit' ) ),
					el( RangeControl, {
						label: __( 'Content layer speed (text + button)', 'rcmi-toolkit' ),
						value: attrs.contentSpeed,
						onChange: function ( v ) { setAttributes( { contentSpeed: v } ); },
						min: -2,
						max: 2,
						step: 0.05,
						help: __( 'Positive = content drifts down on scroll, negative = content rises. 0 = fixed. Direction is solely determined by the sign.', 'rcmi-toolkit' )
					} ),
					el( RangeControl, {
						label: __( 'Mobile parallax intensity', 'rcmi-toolkit' ),
						value: attrs.mobileIntensity,
						onChange: function ( v ) { setAttributes( { mobileIntensity: v } ); },
						min: 0,
						max: 2,
						step: 0.05,
						help: __( '0 = no parallax on mobile, 1 = normal intensity, 2 = double intensity. If edges appear, increase the layer mobile scale for headroom.', 'rcmi-toolkit' )
					} ),
					el( RangeControl, {
						label: __( 'Tablet scale multiplier', 'rcmi-toolkit' ),
						value: attrs.tabletScaleMultiplier,
						onChange: function ( v ) { setAttributes( { tabletScaleMultiplier: v } ); },
						min: 0.25,
						max: 1,
						step: 0.05,
						help: __( 'Scales layers on tablet (768–1440px). 0.75 = 75% of desktop scale. E.g., desktop 200% → tablet 150%.', 'rcmi-toolkit' )
					} )
				) : null,

				// ---- Hero Layout panel ----
				el( PanelBody, { title: __( 'Hero Layout', 'rcmi-toolkit' ), initialOpen: false },
					el( RangeControl, {
						label: __( 'Section height (viewport %)', 'rcmi-toolkit' ),
						value: attrs.height,
						onChange: function ( v ) { setAttributes( { height: v } ); },
						min: 40,
						max: 100,
						step: 5
					} ),
					el( 'label', { style: { display: 'block', fontWeight: '600', marginBottom: '4px', marginTop: '12px' } }, __( 'Content alignment', 'rcmi-toolkit' ) ),
					alignButtons
				),

				// ---- Readability Overlay panel ----
				el( PanelBody, { title: __( 'Readability Overlay', 'rcmi-toolkit' ), initialOpen: false },
					el( 'p', { style: { color: '#666', fontSize: '12px', marginTop: 0 } }, __( 'Overlay that darkens/tints the background for text readability.', 'rcmi-toolkit' ) ),
					renderGradientPicker( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle, function ( stops, type, angle ) {
						setAttributes( { scrimStops: stops, scrimType: type, scrimAngle: angle } );
					} )
				),

				// ---- Advanced Stacking panel (collapsed by default) ----
				el( PanelBody, { title: __( 'Advanced Stacking', 'rcmi-toolkit' ), initialOpen: false },
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
				)
			);

			// Build editor preview.
			var previewChildren = [];

			if ( isParallax ) {
				previewChildren.push(
					el( 'div', { className: 'rcmi-parallax-layers' },
						previewLayer( attrs.bgImageUrl, attrs.bgMobileImageUrl, __( 'Background', 'rcmi-toolkit' ), attrs.bgZIndex, attrs.bgPositionX, attrs.bgPositionY, attrs.bgScale, attrs.bgMobilePositionX, attrs.bgMobilePositionY, attrs.bgMobileScale, !! attrs.bgMobileImageUrl ),
						previewLayer( attrs.midImageUrl, attrs.midMobileImageUrl, __( 'Middle', 'rcmi-toolkit' ), attrs.midZIndex, attrs.midPositionX, attrs.midPositionY, attrs.midScale, attrs.midMobilePositionX, attrs.midMobilePositionY, attrs.midMobileScale, !! attrs.midMobileImageUrl ),
						previewLayer( attrs.fgImageUrl, attrs.fgMobileImageUrl, __( 'Foreground', 'rcmi-toolkit' ), attrs.fgZIndex, attrs.fgPositionX, attrs.fgPositionY, attrs.fgScale, attrs.fgMobilePositionX, attrs.fgMobilePositionY, attrs.fgMobileScale, !! attrs.fgMobileImageUrl )
					)
				);
			} else {
				// Static mode: single background image with position + scale.
				previewChildren.push(
					previewLayer( attrs.bgImageUrl, attrs.bgMobileImageUrl, __( 'Background', 'rcmi-toolkit' ), attrs.bgZIndex, attrs.bgPositionX, attrs.bgPositionY, attrs.bgScale, attrs.bgMobilePositionX, attrs.bgMobilePositionY, attrs.bgMobileScale, !! attrs.bgMobileImageUrl )
				);
			}

			// Scrim overlay preview (z-index from scrimZIndex attribute).
			previewChildren.push(
				el( 'div', { className: 'rcmi-parallax-scrim', style: { background: scrimGradient, zIndex: attrs.scrimZIndex } } )
			);

			// Content preview — alignment only changes the horizontal
			// position of the content block, not the text alignment within
			// (that's controlled by individual inner blocks).
			var copyStyle = { zIndex: attrs.contentZIndex };
			if ( attrs.contentAlign === 'center' ) {
				copyStyle.maxWidth = '760px';
				copyStyle.margin = '0 auto';
			} else if ( attrs.contentAlign === 'right' ) {
				copyStyle.maxWidth = '570px';
				copyStyle.marginLeft = 'auto';
				copyStyle.marginRight = '0';
			}

			// InnerBlocks content area — editors can add/reorder/remove
			// any block (heading, paragraph, buttons, images, etc.).
			// Template seeds the default hero content on new instances only.
			// After the first render, template is not passed so that deleting
			// all inner blocks doesn't re-seed the default content.
			//
			// Generate unique spectraId values for Spectra blocks so each new
			// hero gets its own IDs (Spectra uses these for styling/state).
			var genSpectraId = function () {
				var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
				var part1 = '', part2 = '';
				for ( var i = 0; i < 8; i++ ) { part1 += chars.charAt( Math.floor( Math.random() * chars.length ) ); }
				for ( var j = 0; j < 6; j++ ) { part2 += chars.charAt( Math.floor( Math.random() * chars.length ) ); }
				return 'spectra-' + part1 + '-' + part2;
			};
			var heroTemplate = [
				[ 'core/heading', {
					level: 1,
					placeholder: __( 'Headline…', 'rcmi-toolkit' ),
					content: 'Advancing Chronic Disease Research.',
					spectraAnimationType: 'fade'
				} ],
				// Eyebrow row: red Spectra "minus" icon + eyebrow text in a flex group.
				// Matches the Home page hero (page 7) — the icon supplies the red
				// glyph; the paragraph carries the eyebrow copy without a className
				// so theme .eyebrow CSS isn't relied on for the glyph.
				[ 'core/group', { layout: { type: 'flex', flexWrap: 'nowrap' } }, [
					[ 'core/group', { layout: { type: 'flex', flexWrap: 'nowrap' } }, [
						[ 'spectra/icons', { spectraId: genSpectraId() }, [
							[ 'spectra/icon', {
								icon: 'minus',
								size: '18px',
								textColor: '#C8102E',
								responsiveControls: { lg: { size: '18px' } },
								spectraId: genSpectraId()
							} ]
						] ]
					] ],
					[ 'core/paragraph', {
						placeholder: __( 'Eyebrow…', 'rcmi-toolkit' ),
						content: ' Accelerating Real‑World Impact.'
					} ]
				] ],
				[ 'core/paragraph', {
					placeholder: __( 'Lede text…', 'rcmi-toolkit' ),
					content: 'Building research capacity, developing investigators, and partnering with communities to improve chronic disease outcomes across Houston and beyond.',
					className: 'lede',
					style: { spacing: { padding: { top: 'var:preset|spacing|20', bottom: 'var:preset|spacing|20' } } }
				} ],
				[ 'core/group', { layout: { type: 'flex', flexWrap: 'nowrap' } }, [
					[ 'spectra/buttons', { spectraId: genSpectraId() }, [
						[ 'spectra/button', {
							text: 'Learn More',
							style: { border: { radius: { topLeft: '12px', topRight: '12px', bottomLeft: '12px', bottomRight: '12px' } } },
							responsiveControls: { lg: { style: { border: { radius: { topLeft: '12px', topRight: '12px', bottomLeft: '12px', bottomRight: '12px' } } } } },
							spectraId: genSpectraId()
						} ]
					] ]
				] ]
			];
			previewChildren.push(
				el( 'div', { className: 'wrap rcmi-parallax-inner', style: { zIndex: attrs.contentZIndex } },
					el( 'div', { className: 'rcmi-parallax-copy', style: copyStyle },
						el( InnerBlocks, {
							allowedBlocks: [ 'core/heading', 'core/paragraph', 'core/buttons', 'core/list', 'core/image', 'core/spacer', 'core/separator', 'core/group', 'spectra/buttons', 'spectra/button', 'spectra/container' ],
							template: hasInnerBlocks ? undefined : heroTemplate,
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
