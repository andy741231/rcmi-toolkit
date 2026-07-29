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
	var __ = wp.i18n.__;

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
				el( 'input', {
					type: 'color',
					value: stop.color || '#ffffff',
					onChange: function ( e ) { updateStop( idx, 'color', e.target.value ); },
					style: { width: '100%', height: '36px', border: '1px solid #ddd', borderRadius: '4px', cursor: 'pointer', marginBottom: '8px' }
				} ),
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
	// Custom inline formats: always-visible font-family toolbar buttons.
	// Adds Display, Body, and Serif buttons directly to the block
	// toolbar (not inside a dropdown) so editors can click them
	// without opening the "More" menu.
	// ============================================================
	var registerFormatType = wp.richText.registerFormatType;
	var BlockControls = wp.blockEditor.BlockControls;
	var ToolbarButton = wp.components.ToolbarButton;

	function makeFontFormat( slug, name, fontFamily ) {
		registerFormatType( 'rcmi/' + slug + '-font', {
			title: name,
			tagName: 'span',
			className: 'has-' + slug + '-font',
			edit: function ( props ) {
				return el( BlockControls, null,
					el( ToolbarButton, {
						icon: 'editor-textcolor',
						label: name,
						isPressed: props.isActive,
						onClick: function () {
							props.onChange(
								wp.richText.toggleFormat( props.value, {
									type: 'rcmi/' + slug + '-font',
									attributes: { style: 'font-family: ' + fontFamily }
								} )
							);
						}
					} )
				);
			}
		} );
	}

	makeFontFormat( 'display', 'Display', "'League Gothic', 'Arial Narrow', sans-serif" );
	makeFontFormat( 'body', 'Body', "'Source Sans 3', -apple-system, BlinkMacSystemFont, sans-serif" );
	makeFontFormat( 'serif', 'Serif', "'Crimson Pro', Georgia, serif" );

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
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'rcmi-quote-editor' } );
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap quote-block' },
					el( 'div', { className: 'quote-mark' }, '\u201C' ),
					el( 'div', { className: 'quote-body' },
						el( RichText, {
							tagName: 'p',
							value: attrs.quote,
							onChange: function ( v ) { setAttributes( { quote: v } ); },
							placeholder: __( 'Quote text…', 'rcmi-toolkit' ),
							allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
						} ),
						el( 'cite', null,
							el( RichText, {
								tagName: 'span',
								value: attrs.citeName,
								onChange: function ( v ) { setAttributes( { citeName: v } ); },
								placeholder: __( 'Citation name…', 'rcmi-toolkit' ),
								allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
							} ),
							el( RichText, {
								tagName: 'span',
								value: attrs.citeRole,
								onChange: function ( v ) { setAttributes( { citeRole: v } ); },
								placeholder: __( 'Citation role…', 'rcmi-toolkit' ),
								allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
							} )
						)
					),
					el( 'div', { className: 'quote-mark quote-mark-close' }, '\u201D' )
				)
			);
		},
		save: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps.save( { className: 'bg-alt' } );
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap quote-block' },
					el( 'div', { className: 'quote-mark' }, '\u201C' ),
					el( 'div', { className: 'quote-body' },
						el( 'p', null, attrs.quote ),
						el( 'cite', null, attrs.citeName, el( 'span', null, attrs.citeRole ) )
					),
					el( 'div', { className: 'quote-mark quote-mark-close' }, '\u201D' )
				)
			);
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
			var attrs = props.attributes, setAttributes = props.setAttributes;
			var blockProps = useBlockProps( { className: 'rcmi-cta-editor' } );
			return el( Fragment, null,
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Button 1', 'rcmi-toolkit' ), initialOpen: false },
						el( TextControl, { label: __( 'Button 1 Text', 'rcmi-toolkit' ), value: attrs.btn1Text, onChange: function ( v ) { setAttributes( { btn1Text: v } ); } } ),
						el( TextControl, { label: __( 'Button 1 Link', 'rcmi-toolkit' ), value: attrs.btn1Link, onChange: function ( v ) { setAttributes( { btn1Link: v } ); } } )
					),
					el( PanelBody, { title: __( 'Button 2', 'rcmi-toolkit' ), initialOpen: false },
						el( TextControl, { label: __( 'Button 2 Text', 'rcmi-toolkit' ), value: attrs.btn2Text, onChange: function ( v ) { setAttributes( { btn2Text: v } ); } } ),
						el( TextControl, { label: __( 'Button 2 Link', 'rcmi-toolkit' ), value: attrs.btn2Link, onChange: function ( v ) { setAttributes( { btn2Link: v } ); } } )
					)
				),
				el( 'section', blockProps,
					el( 'div', { className: 'wrap' },
						el( 'div', { className: 'cta-band' },
							el( 'div', { className: 'cta-copy' },
								el( RichText, {
									tagName: 'h2',
									value: attrs.heading,
									onChange: function ( v ) { setAttributes( { heading: v } ); },
									placeholder: __( 'Heading…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
								} ),
								el( RichText, {
									tagName: 'p',
									value: attrs.text,
									onChange: function ( v ) { setAttributes( { text: v } ); },
									placeholder: __( 'Text…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
								} )
							),
							el( 'div', { className: 'cta-actions' },
								el( 'a', { href: attrs.btn1Link, className: 'btn ' + attrs.btn1Style, onClick: function ( e ) { e.preventDefault(); } }, attrs.btn1Text ),
								el( 'a', { href: attrs.btn2Link, className: 'btn ' + attrs.btn2Style, onClick: function ( e ) { e.preventDefault(); } }, attrs.btn2Text )
							)
						)
					)
				)
			);
		},
		save: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps.save( { className: 'bg-primary' } );
			return el( 'section', blockProps,
				el( 'div', { className: 'wrap' },
					el( 'div', { className: 'cta-band' },
						el( 'div', { className: 'cta-copy' },
							el( 'h2', null, attrs.heading ),
							el( 'p', null, attrs.text )
						),
						el( 'div', { className: 'cta-actions' },
							el( 'a', { href: attrs.btn1Link, className: 'btn ' + attrs.btn1Style }, attrs.btn1Text ),
							el( 'a', { href: attrs.btn2Link, className: 'btn ' + attrs.btn2Style }, attrs.btn2Text )
						)
					)
				)
			);
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
						allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
					} ),
					el( RichText, {
						tagName: 'span',
						value: attrs[prefix + 'Label'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Label'] = v; setAttributes( u ); },
						placeholder: __( 'Label…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
					} ),
					el( RichText, {
						tagName: 'p',
						value: attrs[prefix + 'Desc'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Desc'] = v; setAttributes( u ); },
						placeholder: __( 'Description…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
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
			role1Title: { type: 'string', default: 'An early-stage investigator' },
			role1Desc:  { type: 'string', default: 'Find pilot funding, mentoring, and training pathways to launch your research.' },
			role1Link:  { type: 'string', default: '/cores/#investigator' },
			role2Title: { type: 'string', default: 'A community organization' },
			role2Desc:  { type: 'string', default: 'Join the Community Advisory Board or propose a shared research priority.' },
			role2Link:  { type: 'string', default: '/cores/#community' },
			role3Title: { type: 'string', default: 'A student' },
			role3Desc:  { type: 'string', default: 'Explore training opportunities and see where your research idea could go.' },
			role3Link:  { type: 'string', default: '/journey/' },
			role4Title: { type: 'string', default: 'A faculty member' },
			role4Desc:  { type: 'string', default: 'Request biostatistics, data science, or research navigation support.' },
			role4Link:  { type: 'string', default: '/cores/#research' },
			role5Title: { type: 'string', default: 'A healthcare organization' },
			role5Desc:  { type: 'string', default: 'Explore implementation support and shared chronic-disease priorities.' },
			role5Link:  { type: 'string', default: '/partners/' },
			role6Title: { type: 'string', default: 'A funder' },
			role6Desc:  { type: 'string', default: 'Review outcomes, publications, and funding leveraged to date.' },
			role6Link:  { type: 'string', default: '/publications/' },
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
			var roleFields = function ( n ) {
				var prefix = 'role' + n;
				return el( PanelBody, { title: __( 'Role ' + n, 'rcmi-toolkit' ), initialOpen: false },
					el( TextControl, { label: __( 'Link URL', 'rcmi-toolkit' ), value: attrs[prefix + 'Link'], onChange: function ( v ) { var u = {}; u[prefix + 'Link'] = v; setAttributes( u ); } } )
				);
			};
			var roleEl = function ( n ) {
				var prefix = 'role' + n;
				return el( 'a', { href: attrs[prefix + 'Link'], className: 'role-card', onClick: function ( e ) { e.preventDefault(); } },
					el( RichText, {
						tagName: 'h4',
						value: attrs[prefix + 'Title'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Title'] = v; setAttributes( u ); },
						placeholder: __( 'Role title…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
					} ),
					el( RichText, {
						tagName: 'p',
						value: attrs[prefix + 'Desc'],
						onChange: function ( v ) { var u = {}; u[prefix + 'Desc'] = v; setAttributes( u ); },
						placeholder: __( 'Description…', 'rcmi-toolkit' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
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
					roleFields( 1 ), roleFields( 2 ), roleFields( 3 ), roleFields( 4 ), roleFields( 5 ), roleFields( 6 )
				),
				el( 'section', blockProps,
					el( 'div', { className: 'wrap' },
						el( 'div', { className: 'section-head' },
							el( 'div', null,
								el( RichText, {
									tagName: 'span',
									className: 'eyebrow',
									value: attrs.eyebrow,
									onChange: function ( v ) { setAttributes( { eyebrow: v } ); },
									placeholder: __( 'Eyebrow…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
								} ),
								el( RichText, {
									tagName: 'h2',
									value: attrs.heading,
									onChange: function ( v ) { setAttributes( { heading: v } ); },
									placeholder: __( 'Heading…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
								} )
							),
							el( RichText, {
								tagName: 'p',
								className: 'section-note',
								value: attrs.note,
								onChange: function ( v ) { setAttributes( { note: v } ); },
								placeholder: __( 'Note…', 'rcmi-toolkit' ),
								allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
							} )
						),
						el( 'div', { className: 'role-grid' },
							roleEl( 1 ), roleEl( 2 ), roleEl( 3 ), roleEl( 4 ), roleEl( 5 ), roleEl( 6 )
						)
					)
				)
			);
		},
		save: function ( props ) {
			var attrs = props.attributes;
			var sectionStyle = {};
			if ( attrs.bgImageUrl ) {
				sectionStyle.backgroundImage = 'url(' + attrs.bgImageUrl + ')';
				sectionStyle.backgroundSize = 'cover';
				sectionStyle.backgroundPosition = 'center';
			}
			var blockProps = useBlockProps.save( { className: 'collaborating-section', id: 'start', style: sectionStyle } );
			var roleEl = function ( n ) {
				var prefix = 'role' + n;
				return el( 'a', { href: attrs[prefix + 'Link'], className: 'role-card' },
					el( 'h4', null, attrs[prefix + 'Title'] ),
					el( 'p', null, attrs[prefix + 'Desc'] ),
					el( 'span', { className: 'role-link' }, 'Start here \u2192' )
				);
			};
			var scrimStyle = { background: buildGradientCSS( attrs.scrimStops, attrs.scrimType, attrs.scrimAngle ) };
			return el( 'section', blockProps,
				el( 'div', { className: 'rcmi-section-scrim', 'aria-hidden': 'true', style: scrimStyle } ),
				el( 'div', { className: 'wrap' },
					el( 'div', { className: 'section-head' },
						el( 'div', null,
							el( 'span', { className: 'eyebrow' }, attrs.eyebrow ),
							el( 'h2', null, attrs.heading )
						),
						el( 'p', { className: 'section-note' }, attrs.note )
					),
					el( 'div', { className: 'role-grid' },
						roleEl( 1 ), roleEl( 2 ), roleEl( 3 ), roleEl( 4 ), roleEl( 5 ), roleEl( 6 )
					)
				)
			);
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
					{ id: 'develop', label: 'Develop', heading: 'Growing the next generation <strong>of research leaders</strong>', note: 'We invest early and often in the people who will carry chronic disease research forward — through funding, mentorship, and structured training pathways.', btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'People', title: 'Investigator Development', desc: 'Individualized pathways that move early-stage researchers from idea to independent funding.' },
						{ tag: 'Funding', title: 'Pilot Awards', desc: 'Seed funding for promising, high-risk / high-reward chronic disease research.' },
						{ tag: 'Guidance', title: 'Mentoring', desc: 'Paired mentorship with senior faculty across biostatistics, design, and dissemination.' },
						{ tag: 'Skills', title: 'Training', desc: 'Workshops and cohort programs covering methods, grant writing, and community-engaged research.' }
					] },
					{ id: 'build', label: 'Build', heading: 'Research capacity that scales with <strong>ambition</strong>', note: 'Shared infrastructure — statistical, technical, and navigational — so investigators spend less time re-building the basics and more time discovering.', btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Capacity', title: 'Research Capacity', desc: 'Institutional infrastructure that supports rigorous, reproducible science at every stage.' },
						{ tag: 'Methods', title: 'Biostatistics', desc: 'Consultation on study design, analysis plans, and power calculations.' },
						{ tag: 'Data', title: 'Data Science', desc: 'Support for data management, integration, and advanced analytics.' },
						{ tag: 'Access', title: 'Research Resources', desc: 'Shared tools, templates, and navigation support across the research lifecycle.' }
					] },
					{ id: 'partner', label: 'Partner', heading: 'Community at the center, <strong>not the edge</strong>', note: 'Research is designed with communities, not delivered to them. Our engagement model shares power over priorities and process.', btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Engagement', title: 'Community Engagement', desc: 'Ongoing, two-way relationships between researchers and community organizations.' },
						{ tag: 'Governance', title: 'Community Advisory Board', desc: 'Community leaders shape priorities, review protocols, and guide dissemination.' },
						{ tag: 'Model', title: 'Value-Based Community Engagement', desc: 'A framework that measures and reinforces mutual value across every partnership.' },
						{ tag: 'Network', title: 'Community Partnerships', desc: 'A growing network of trusted organizations across Houston\u2019s diverse communities.' }
					] },
					{ id: 'accelerate', label: 'Accelerate', heading: 'From question to real-world impact, <strong>faster</strong>', note: 'Core services and translational infrastructure exist to remove friction between a good idea and a funded, executed study.', btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Portfolio', title: 'Research Projects', desc: 'An active portfolio spanning prevention, treatment, and implementation science.' },
						{ tag: 'Infrastructure', title: 'Core Services', desc: 'Shared cores in biostatistics, community engagement, and administration.' },
						{ tag: 'Growth', title: 'Innovation', desc: 'New methods and technologies piloted to strengthen chronic disease research.' },
						{ tag: 'Bridge', title: 'Translational Science', desc: 'Moving discoveries from bench and community into practice and policy.' }
					] },
					{ id: 'improve', label: 'Improve', heading: 'We measure what matters, <strong>in public</strong>', note: 'Impact isn\u2019t a year-end summary — it\u2019s a living, monthly record of progress toward better chronic disease outcomes.', btnText: 'View More', btnLink: '#', bgImageId: 0, bgImageUrl: '', scrimStops: [ { color: '#ffffff', opacity: 0.9, position: 0 }, { color: '#ffffff', opacity: 0.54, position: 50 }, { color: '#ffffff', opacity: 0, position: 100 } ], scrimType: 'linear', scrimAngle: 90, cards: [
						{ tag: 'Voices', title: 'Impact Stories', desc: 'Real accounts of problems studied, lessons learned, and what\u2019s next.' },
						{ tag: 'Evidence', title: 'Publications', desc: 'Findings organized by theme, not by committee.' },
						{ tag: 'Live', title: 'Outcomes Dashboard', desc: 'Monthly-updated metrics on investigators, funding, and communities served.' },
						{ tag: 'Focus', title: 'Chronic Disease Priorities', desc: 'Priorities set together with the communities most affected.' }
					] }
				]
			}
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
					el( TextControl, { label: __( 'Button Text', 'rcmi-toolkit' ), value: tab.btnText, onChange: function ( v ) { updateTab( idx, 'btnText', v ); } } ),
					el( TextControl, { label: __( 'Button Link', 'rcmi-toolkit' ), value: tab.btnLink, onChange: function ( v ) { updateTab( idx, 'btnLink', v ); } } )
				);
			} );

			// Build editor preview — show tab buttons + active tab content.
			var activeTabData = tabs[ activeTabIndex ] || tabs[ 0 ] || {};
			return el( Fragment, null,
				el( InspectorControls, null, tabPanels ),
				el( 'div', blockProps,
					el( 'section', { className: 'impact-overview' },
						el( 'div', { className: 'wrap' },
							el( 'div', { className: 'impact-strip' },
								el( 'div', { className: 'impact-steps', role: 'tablist' },
									tabs.map( function ( tab, idx ) {
										return el( 'button', { key: 'btn-' + idx, className: 'impact-step' + ( idx === activeTabIndex ? ' is-active' : '' ), role: 'tab', type: 'button', onClick: function () { setActiveTabIndex( idx ); } },
											el( 'span', { className: 'impact-step-copy' }, el( 'strong', null, tab.label ) )
										);
									} )
								)
							)
						)
					),
					el( 'section', { className: 'tab-panel is-active', style: activeTabData.bgImageUrl ? { backgroundImage: 'url(' + activeTabData.bgImageUrl + ')' } : undefined },
						el( 'div', { className: 'rcmi-tab-scrim', 'aria-hidden': 'true', style: { background: buildGradientCSS( activeTabData.scrimStops, activeTabData.scrimType, activeTabData.scrimAngle ) } } ),
						el( 'div', { className: 'wrap' },
							el( 'div', { className: 'section-head' },
								el( 'div', null, el( RichText, {
									tagName: 'h2',
									value: activeTabData.heading,
									onChange: function ( v ) { updateTab( activeTabIndex, 'heading', v ); },
									placeholder: __( 'Heading…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
								} ) ),
								el( RichText, {
									tagName: 'p',
									className: 'section-note',
									value: activeTabData.note,
									onChange: function ( v ) { updateTab( activeTabIndex, 'note', v ); },
									placeholder: __( 'Note…', 'rcmi-toolkit' ),
									allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
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
											allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
										} ),
										el( RichText, {
											tagName: 'h4',
											value: card.title,
											onChange: function ( v ) { updateCard( activeTabIndex, ci, 'title', v ); },
											placeholder: __( 'Title…', 'rcmi-toolkit' ),
											allowedFormats: [ 'core/bold', 'core/italic', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
										} ),
										el( RichText, {
											tagName: 'p',
											value: card.desc,
											onChange: function ( v ) { updateCard( activeTabIndex, ci, 'desc', v ); },
											placeholder: __( 'Description…', 'rcmi-toolkit' ),
											allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
										} )
									);
								} )
							)
						)
					)
				)
			);
		},
		save: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps.save();
			var tabs = attrs.tabs || [];

			// Tab strip.
			var tabStrip = el( 'section', { className: 'impact-overview', id: 'impact-strip' },
				el( 'div', { className: 'wrap' },
					el( 'div', { className: 'impact-strip' },
						el( 'div', { className: 'impact-steps', role: 'tablist' },
							tabs.map( function ( tab, idx ) {
								return el( 'button', { key: 'btn-' + idx, className: 'impact-step' + ( idx === 0 ? ' is-active' : '' ), role: 'tab', 'aria-selected': idx === 0 ? 'true' : 'false', 'data-tab': tab.id, type: 'button' },
									el( 'span', { className: 'impact-step-copy' }, el( 'strong', null, tab.label ) )
								);
							} )
						)
					)
				)
			);

			// Tab panels.
			var panels = el( 'div', { className: 'tab-panels' },
				tabs.map( function ( tab, idx ) {
					return el( 'section', { key: 'panel-' + idx, id: tab.id, className: 'tab-panel' + ( idx === 0 ? ' is-active' : '' ) + ( idx % 2 === 1 ? ' bg-alt' : '' ), role: 'tabpanel', style: tab.bgImageUrl ? { backgroundImage: 'url(' + tab.bgImageUrl + ')' } : undefined },
						el( 'div', { className: 'rcmi-tab-scrim', 'aria-hidden': 'true', style: { background: buildGradientCSS( tab.scrimStops, tab.scrimType, tab.scrimAngle ) } } ),
						el( 'div', { className: 'wrap' },
							el( 'div', { className: 'section-head' },
								el( 'div', null, el( 'h2', { dangerouslySetInnerHTML: { __html: tab.heading } } ) ),
								el( 'p', { className: 'section-note' }, tab.note )
							),
							el( 'div', { className: 'card-grid' },
								( tab.cards || [] ).map( function ( card, ci ) {
									return el( 'div', { className: 'card', key: 'c-' + ci },
										el( 'span', { className: 'tag' }, card.tag ),
										el( 'h4', null, card.title ),
										el( 'p', null, card.desc )
									);
								} )
							),
							el( 'div', { style: { marginTop: 'var(--space-5)', display: 'flex', gap: 'var(--space-2)', flexWrap: 'wrap' } },
								el( 'a', { href: tab.btnLink, className: 'btn btn-primary' }, tab.btnText )
							)
						)
					);
				} )
			);

			return el( 'div', blockProps, tabStrip, panels );
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
				),
				el( PanelBody, { title: __( 'Content', 'rcmi-toolkit' ), initialOpen: true },
					el( TextControl, { label: __( 'Button Text', 'rcmi-toolkit' ), value: attrs.buttonText, onChange: function ( v ) { setAttributes( { buttonText: v } ); } } ),
					el( TextControl, { label: __( 'Button Link', 'rcmi-toolkit' ), value: attrs.buttonLink, onChange: function ( v ) { setAttributes( { buttonLink: v } ); } } )
				)
			);

			// Build editor preview.
			var previewChildren = [];

			if ( isParallax ) {
				previewChildren.push(
					el( 'div', { className: 'rcmi-parallax-layers' },
						layerPreview( attrs.bgImageUrl, __( 'Background', 'rcmi-toolkit' ), 1 ),
						layerPreview( attrs.midImageUrl, __( 'Middle', 'rcmi-toolkit' ), 2 ),
						layerPreview( attrs.fgImageUrl, __( 'Foreground', 'rcmi-toolkit' ), 3 )
					)
				);
			} else {
				// Static mode: single background image.
				var bgStyle = { background: '#f8f5ee' };
				if ( attrs.bgImageUrl ) {
					bgStyle = { backgroundImage: 'url(' + attrs.bgImageUrl + ')', backgroundSize: 'cover', backgroundPosition: 'center' };
				}
				previewChildren.push(
					el( 'div', { className: 'rcmi-parallax-layer-preview', style: Object.assign( { zIndex: 1 }, bgStyle ) },
						! attrs.bgImageUrl ? el( 'span', { className: 'rcmi-layer-label' }, __( 'Background', 'rcmi-toolkit' ) ) : null
					)
				);
			}

			// Scrim overlay preview.
			previewChildren.push(
				el( 'div', { className: 'rcmi-parallax-scrim', style: { background: scrimGradient } } )
			);

			// Content preview.
			var copyStyle = {};
			if ( attrs.contentAlign === 'center' ) {
				copyStyle.textAlign = 'center';
				copyStyle.margin = '0 auto';
			} else if ( attrs.contentAlign === 'right' ) {
				copyStyle.textAlign = 'right';
				copyStyle.marginLeft = 'auto';
			}

			previewChildren.push(
				el( 'div', { className: 'wrap rcmi-parallax-inner' },
					el( 'div', { className: 'rcmi-parallax-copy', style: copyStyle },
						el( RichText, {
							tagName: 'h1',
							value: attrs.headline,
							onChange: function ( v ) { setAttributes( { headline: v } ); },
							placeholder: __( 'Headline…', 'rcmi-toolkit' ),
							allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ],
							style: { margin: '0 0 10px' }
						} ),
						el( RichText, {
							tagName: 'span',
							className: 'eyebrow',
							value: attrs.eyebrow,
							onChange: function ( v ) { setAttributes( { eyebrow: v } ); },
							placeholder: __( 'Eyebrow…', 'rcmi-toolkit' ),
							allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ],
							style: { display: 'block', margin: '0 0 12px' }
						} ),
						el( RichText, {
							tagName: 'p',
							className: 'lede',
							value: attrs.lede,
							onChange: function ( v ) { setAttributes( { lede: v } ); },
							placeholder: __( 'Lede text…', 'rcmi-toolkit' ),
							allowedFormats: [ 'core/bold', 'core/italic', 'core/link', 'core/text-color', 'core/font-family', 'core/text-align', 'rcmi/display-font', 'rcmi/body-font', 'rcmi/serif-font' ]
						} ),
						el( 'div', { className: 'hero-actions' },
							el( 'a', { href: attrs.buttonLink, className: 'btn btn-primary', onClick: function ( e ) { e.preventDefault(); } }, attrs.buttonText )
						)
					)
				)
			);

			return el( Fragment, null,
				el( InspectorControls, null, inspectorChildren ),
				el( 'section', blockProps, previewChildren )
			);
		},
		save: function () {
			// Server-side rendered (dynamic block) so parallax data attributes
			// and gradient styles always reflect the latest attributes.
			return null;
		}
	} );

} )( window.wp );
