( function () {
	'use strict';

	// Tab switching for rcmi/impact-strip blocks.
	// Works with the markup saved by the rcmi/impact-tab block:
	//   .tab-panel elements with IDs, toggled by .impact-step buttons.
	// The theme's nav.js also handles this, but we include it here
	// so the plugin is self-contained.

	function initImpactStripTabs() {
		var strips = document.querySelectorAll( '.rcmi-impact-strip-wrapper' );
		if ( ! strips.length ) {
			// Fall back to the mockup-style markup (impact-step + tab-panel).
			var tabs = document.querySelectorAll( '.impact-step' );
			var panels = document.querySelectorAll( '.tab-panel' );
			if ( tabs.length && panels.length ) {
				bindTabs( tabs, panels );
			}
			return;
		}
	}

	function bindTabs( tabs, panels ) {
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var tabId = tab.getAttribute( 'data-tab' );
				tabs.forEach( function ( t ) {
					var isActive = t.getAttribute( 'data-tab' ) === tabId;
					t.classList.toggle( 'is-active', isActive );
					t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );
				panels.forEach( function ( p ) {
					p.classList.toggle( 'is-active', p.id === tabId );
				} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initImpactStripTabs );
	} else {
		initImpactStripTabs();
	}

	// ============================================================
	// Parallax layers for rcmi/parallax blocks.
	// Each .rcmi-parallax-layer has a data-speed attribute (0–1).
	// Layers translate vertically at rate = scrollProgress * speed,
	// giving a depth effect: background slowest, foreground fastest.
	// Uses requestAnimationFrame + translate3d for GPU-composited
	// 60fps scrolling. Disabled for prefers-reduced-motion.
	// ============================================================
	function initParallax() {
		var sections = document.querySelectorAll( '.rcmi-parallax' );
		if ( ! sections.length ) {
			return;
		}

		var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		if ( reducedMotion ) {
			return;
		}

		var items = [];
		sections.forEach( function ( section ) {
			// Query any element with data-speed inside the section —
			// this includes image layers AND the content layer.
			var layers = section.querySelectorAll( '[data-speed]' );
			if ( layers.length ) {
				items.push( { section: section, layers: layers } );
			}
		} );
		if ( ! items.length ) {
			return;
		}

		var ticking = false;

		function update() {
			ticking = false;
			var viewportHeight = window.innerHeight;

			items.forEach( function ( item ) {
				var rect = item.section.getBoundingClientRect();

				// Skip sections fully outside the viewport.
				if ( rect.bottom < 0 || rect.top > viewportHeight ) {
					return;
				}

				// Progress: 0 when section top hits viewport bottom,
				// 1 when section bottom hits viewport top.
				var progress = ( viewportHeight - rect.top ) / ( viewportHeight + rect.height );
				progress = Math.min( 1, Math.max( 0, progress ) );

				// Center the range around 0: -0.5 (entering) to 0.5 (leaving).
				var centered = progress - 0.5;

				// Read direction from the section's data-direction attribute.
				// 'down' = layers drift downward (default), 'up' = layers rise,
				// 'left'/'right' = horizontal drift.
				var direction = item.section.getAttribute( 'data-direction' ) || 'down';

				item.layers.forEach( function ( layer ) {
					var speed = parseFloat( layer.getAttribute( 'data-speed' ) ) || 0;
					// Travel distance scales with section height so faster
					// layers cover more ground regardless of section size.
					var travel = rect.height * speed;
					var offset = centered * travel;
					var tx = '0', ty = '0';

					switch ( direction ) {
						case 'up':
							ty = ( -offset ).toFixed( 2 );
							break;
						case 'left':
							tx = ( -offset ).toFixed( 2 );
							break;
						case 'right':
							tx = offset.toFixed( 2 );
							break;
						case 'down':
						default:
							ty = offset.toFixed( 2 );
							break;
					}

					layer.style.transform = 'translate3d(' + tx + 'px,' + ty + 'px,0)';
				} );
			} );
		}

		function onScroll() {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( update );
			}
		}

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		window.addEventListener( 'resize', onScroll );
		update();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initParallax );
	} else {
		initParallax();
	}
} )();
