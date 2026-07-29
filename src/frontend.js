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
		// Bind tabs for each impact-strip wrapper.
		strips.forEach( function ( strip ) {
			var tabs = strip.querySelectorAll( '.impact-step' );
			var panelsContainer = strip.querySelector( '.tab-panels' );
			var panels = panelsContainer ? panelsContainer.querySelectorAll( '.tab-panel' ) : [];
			if ( tabs.length && panels.length ) {
				bindTabs( tabs, panels, panelsContainer );
			}
		} );
	}

	function bindTabs( tabs, panels, panelsContainer ) {
		var isAnimating = false;
		var hasGsap = typeof window.gsap !== 'undefined';
		var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		// GSAP-powered transition definitions.
		// Each returns a GSAP timeline that animates from oldPanel to newPanel.
		// Panels overlap via position:absolute during the animation.
		var gsapTransitions = {
			fade: function ( oldPanel, newPanel ) {
				return gsap.timeline()
					.set( newPanel, { opacity: 0, display: 'block', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldPanel, { opacity: 0, duration: 0.4, ease: 'power2.inOut' }, 0 )
					.to( newPanel, { opacity: 1, duration: 0.4, ease: 'power2.inOut' }, 0 );
			},
			slide: function ( oldPanel, newPanel ) {
				return gsap.timeline()
					.set( newPanel, { opacity: 1, xPercent: 100, display: 'block', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldPanel, { opacity: 0, xPercent: -100, duration: 0.45, ease: 'power3.inOut' }, 0 )
					.to( newPanel, { xPercent: 0, duration: 0.45, ease: 'power3.inOut' }, 0 );
			},
			curtain: function ( oldPanel, newPanel ) {
				return gsap.timeline()
					.set( newPanel, { opacity: 1, yPercent: 100, display: 'block', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldPanel, { opacity: 0, yPercent: -100, duration: 0.45, ease: 'power3.inOut' }, 0 )
					.to( newPanel, { yPercent: 0, duration: 0.45, ease: 'power3.inOut' }, 0 );
			},
			wipe: function ( oldPanel, newPanel ) {
				// Clip-path wipe: new panel reveals left-to-right over the old one.
				return gsap.timeline()
					.set( newPanel, { opacity: 1, display: 'block', position: 'absolute', top: 0, left: 0, right: 0, clipPath: 'inset(0 100% 0 0)' } )
					.to( newPanel, { clipPath: 'inset(0 0% 0 0)', duration: 0.5, ease: 'power2.inOut' }, 0 )
					.to( oldPanel, { opacity: 0.3, duration: 0.5, ease: 'power2.inOut' }, 0 );
			},
			reveal: function ( oldPanel, newPanel ) {
				// Zoom-pan reveal: new panel scales up from 1.08 → 1 while fading in.
				return gsap.timeline()
					.set( newPanel, { opacity: 0, scale: 1.08, display: 'block', position: 'absolute', top: 0, left: 0, right: 0, transformOrigin: 'center center' } )
					.to( oldPanel, { opacity: 0, scale: 0.96, duration: 0.5, ease: 'power2.inOut', transformOrigin: 'center center' }, 0 )
					.to( newPanel, { opacity: 1, scale: 1, duration: 0.5, ease: 'power2.out' }, 0 );
			}
		};

		function cleanup( oldPanel, newPanel ) {
			if ( oldPanel ) {
				gsap.set( oldPanel, { clearProps: 'all' } );
				oldPanel.classList.remove( 'is-active' );
			}
			gsap.set( newPanel, { clearProps: 'all' } );
			newPanel.classList.add( 'is-active' );
			if ( panelsContainer ) {
				panelsContainer.classList.remove( 'is-animating' );
				panelsContainer.style.minHeight = '';
			}
			isAnimating = false;
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				if ( isAnimating ) return;
				var tabId = tab.getAttribute( 'data-tab' );

				var currentPanel = null;
				panels.forEach( function ( p ) {
					if ( p.classList.contains( 'is-active' ) ) { currentPanel = p; }
				} );

				var targetPanel = null;
				panels.forEach( function ( p ) {
					if ( p.id === tabId ) { targetPanel = p; }
				} );

				if ( ! targetPanel || targetPanel === currentPanel ) {
					tabs.forEach( function ( t ) {
						var isActive = t.getAttribute( 'data-tab' ) === tabId;
						t.classList.toggle( 'is-active', isActive );
						t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
					} );
					return;
				}

				// Update tab button states immediately.
				tabs.forEach( function ( t ) {
					var isActive = t.getAttribute( 'data-tab' ) === tabId;
					t.classList.toggle( 'is-active', isActive );
					t.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				} );

				var transition = panelsContainer ? panelsContainer.getAttribute( 'data-transition' ) : 'none';
				if ( ! transition || transition === 'none' || reducedMotion ) {
					// Instant switch.
					panels.forEach( function ( p ) {
						p.classList.toggle( 'is-active', p.id === tabId );
					} );
					return;
				}

				// Animated transition.
				isAnimating = true;
				if ( panelsContainer ) {
					panelsContainer.classList.add( 'is-animating' );
					// Retain the container height while panels are position:absolute,
					// so the section below doesn't jump up during the transition.
					if ( currentPanel ) {
						panelsContainer.style.minHeight = currentPanel.offsetHeight + 'px';
					}
				}

				if ( hasGsap && gsapTransitions[ transition ] ) {
					// GSAP path: build a timeline, clean up on complete.
					var tl = gsapTransitions[ transition ]( currentPanel, targetPanel );
					tl.eventCallback( 'onComplete', function () {
						cleanup( currentPanel, targetPanel );
					} );
				} else {
					// CSS fallback (kept for resilience if GSAP fails to load).
					targetPanel.classList.add( 'tab-entering', 'is-active' );
					void targetPanel.offsetHeight;
					requestAnimationFrame( function () {
						targetPanel.classList.add( 'tab-entered' );
					} );
					if ( currentPanel ) {
						currentPanel.classList.add( 'tab-leaving' );
					}
					setTimeout( function () {
						if ( currentPanel ) {
							currentPanel.classList.remove( 'is-active', 'tab-leaving' );
						}
						targetPanel.classList.remove( 'tab-entering', 'tab-entered' );
						if ( panelsContainer ) {
							panelsContainer.classList.remove( 'is-animating' );
							panelsContainer.style.minHeight = '';
						}
						isAnimating = false;
					}, 420 );
				}
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
