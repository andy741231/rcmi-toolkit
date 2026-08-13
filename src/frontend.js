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
				// Cross-fade: new panel fades in ON TOP of old panel.
				// Old panel stays at full opacity underneath so its ::before
				// gradient and .rcmi-tab-scrim overlay don't double up at the
				// midpoint (which causes a visible flash/jitter).
				return gsap.timeline()
					.set( newPanel, { opacity: 0, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0, zIndex: 2 } )
					.set( oldPanel, { zIndex: 1 } )
					.to( newPanel, { opacity: 1, duration: 0.4, ease: 'power2.inOut' } );
			},
			slide: function ( oldPanel, newPanel ) {
				return gsap.timeline()
					.set( newPanel, { opacity: 1, xPercent: 100, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldPanel, { opacity: 0, xPercent: -100, duration: 0.45, ease: 'power3.inOut' }, 0 )
					.to( newPanel, { xPercent: 0, duration: 0.45, ease: 'power3.inOut' }, 0 );
			},
			curtain: function ( oldPanel, newPanel ) {
				return gsap.timeline()
					.set( newPanel, { opacity: 1, yPercent: 100, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldPanel, { opacity: 0, yPercent: -100, duration: 0.45, ease: 'power3.inOut' }, 0 )
					.to( newPanel, { yPercent: 0, duration: 0.45, ease: 'power3.inOut' }, 0 );
			},
			wipe: function ( oldPanel, newPanel ) {
				// Clip-path wipe: new panel reveals left-to-right over the old one.
				return gsap.timeline()
					.set( newPanel, { opacity: 1, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0, clipPath: 'inset(0 100% 0 0)' } )
					.to( newPanel, { clipPath: 'inset(0 0% 0 0)', duration: 0.5, ease: 'power2.inOut' }, 0 )
					.to( oldPanel, { opacity: 0.3, duration: 0.5, ease: 'power2.inOut' }, 0 );
			},
			reveal: function ( oldPanel, newPanel ) {
				// Zoom-pan reveal: new panel scales up from 1.08 → 1 while fading in.
				// Old panel stays in place underneath (no fade-out) to avoid
				// double-overlay jitter at the midpoint.
				return gsap.timeline()
					.set( newPanel, { opacity: 0, scale: 1.08, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0, transformOrigin: 'center center', zIndex: 2 } )
					.set( oldPanel, { zIndex: 1 } )
					.to( newPanel, { opacity: 1, scale: 1, duration: 0.5, ease: 'power2.out' } );
			}
		};

		function cleanup( oldPanel, newPanel ) {
			// Only clear properties GSAP actually set during the transition
			// (opacity, transform, display, position, offsets, zIndex, clipPath,
			// transformOrigin). Using clearProps:'all' would also wipe height and
			// background-image — inline styles set by the PHP render callback —
			// causing the panel height to reset after every tab transition.
			var gsapProps = 'opacity,transform,display,position,top,left,right,zIndex,clipPath,transformOrigin';
			if ( oldPanel ) {
				gsap.set( oldPanel, { clearProps: gsapProps } );
				oldPanel.classList.remove( 'is-active' );
			}
			gsap.set( newPanel, { clearProps: gsapProps } );
			newPanel.classList.add( 'is-active' );
			if ( panelsContainer ) {
				panelsContainer.classList.remove( 'is-animating' );
				panelsContainer.style.minHeight = '';
			}
			isAnimating = false;
		}

		tabs.forEach( function ( tab ) {
			// Prevent the browser from focusing the button on mouse click,
			// which would scroll the button into the viewport center.
			// Standard pattern for tab widgets — keyboard users can still
			// Tab to the button and press Enter/Space to activate it.
			tab.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); } );
			tab.addEventListener( 'click', function ( e ) {
				if ( isAnimating ) return;
				e.preventDefault();
				// Remove focus from the button to prevent the browser from
				// scrolling to keep the focused element in view. This must
				// happen synchronously before any layout changes.
				if ( document.activeElement === tab ) { tab.blur(); }
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
					// Measure the panel height BEFORE adding is-animating.
					// Reading offsetHeight after is-animating would force a
					// reflow where panels are already position:absolute and the
					// container has briefly collapsed to zero height — the
					// browser's scroll anchoring sees that collapse and scrolls
					// to compensate. By measuring first and setting minHeight in
					// the same synchronous block as is-animating, the container
					// never collapses and no layout shift occurs.
					var panelHeight = currentPanel ? currentPanel.offsetHeight : 0;
					panelsContainer.classList.add( 'is-animating' );
					if ( panelHeight ) {
						panelsContainer.style.minHeight = panelHeight + 'px';
					}
				}

				// Add is-active to the new panel BEFORE the animation starts so
				// CSS-based background images (#id.is-active) are present throughout
				// the transition. Without this, the background image pops in abruptly
				// at cleanup when is-active is finally added.
				//
				// Pre-set opacity:0 via inline style BEFORE adding is-active so the
				// panel is already invisible when its display changes to block.
				// Without this, there's a race where the browser paints the panel
				// at full opacity (from is-active) before GSAP's .set(opacity:0) runs,
				// causing a brief flash of the next image. GSAP will take over the
				// opacity management once the timeline is created.
				if ( hasGsap && gsapTransitions[ transition ] ) {
					targetPanel.style.opacity = '0';
				}
				targetPanel.classList.add( 'is-active' );

				if ( hasGsap && gsapTransitions[ transition ] ) {
					// GSAP path: build a timeline, clean up on complete.
					var tl = gsapTransitions[ transition ]( currentPanel, targetPanel );
					tl.eventCallback( 'onComplete', function () {
						cleanup( currentPanel, targetPanel );
					} );
				} else {
					// CSS fallback (kept for resilience if GSAP fails to load).
					targetPanel.classList.add( 'tab-entering' );
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
	// Each .rcmi-parallax-layer is an <img> element with a data-speed
	// attribute (-2 to 2). Two modes controlled by data-mode on the section:
	//   scroll (default): layers translate based on scroll position
	//   mouse: layers follow mouse position (parallax tilt effect)
	//
	// Speed sign controls direction. The foreground/content layers and
	// background/middle layers always move in opposite directions to
	// create depth. The sign of each layer's speed flips which way it
	// moves:
	//   positive speed: foreground drifts down, background/middle rise
	//   negative speed: foreground rises, background/middle drift down
	//
	// SIMPLE ABSOLUTE APPROACH:
	// Layers stay exactly as PHP renders them: position:absolute, scale% ×
	// scale% of section, centered, object-fit:cover. The engine ONLY adds a
	// parallax Y-offset on top of the existing centering/pan transform.
	// No fixed positioning, no clip-path, no resizing — so the published
	// page matches the editor preview by construction.
	//
	// The offset range is viewport-height × speed, which is large enough
	// that the background can drift opposite to the scroll direction
	// (climbwales.co.uk effect). At scale=100% the layer has no slack, so
	// movement may reveal gaps at the section edges — this is accepted
	// (gaps are preferable to cropping the image). Increasing scale adds
	// slack and eliminates gaps.
	//
	// Uses requestAnimationFrame + translate3d for GPU-composited 60fps.
	// Disabled for prefers-reduced-motion.
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

		// Mobile intensity and tablet scale multiplier are read per-section
		// from data attributes (set by PHP from each block's own attributes).
		// Desktop uses full intensity (1.0).
		var TABLET_WIDTH = 768;
		var DESKTOP_WIDTH = 1440;

		function isMobileWidth() {
			return window.matchMedia( '(max-width: ' + ( TABLET_WIDTH - 1 ) + 'px)' ).matches;
		}

		function getTravelMultiplier( item ) {
			if ( ! isMobileWidth() ) {
				return 1.0;
			}
			var mi = parseFloat( item.mobileIntensity );
			if ( isNaN( mi ) ) {
				mi = 0.7;
			}
			return Math.max( 0, Math.min( 2, mi ) );
		}

		// Build items list. Each item stores its section, layers, and the
		// original inline transform of each layer (so we can restore it
		// when the section leaves the viewport).
		var items = [];
		sections.forEach( function ( section ) {
			var layers = section.querySelectorAll( '[data-speed]' );
			if ( ! layers.length ) {
				return;
			}
			// Cache each layer's original transform (the centering + pan
			// transform set by PHP), its original pan CSS custom properties
			// (--pos-x, --pos-y), and its original scale (width/height %)
			// so we can scale them proportionally on small screens.
			var layerData = [];
			layers.forEach( function ( layer ) {
				layerData.push( {
					el: layer,
					baseTransform: layer.style.transform || '',
					posX: layer.style.getPropertyValue( '--pos-x' ) || '0%',
					posY: layer.style.getPropertyValue( '--pos-y' ) || '0%',
					scale: parseFloat( layer.style.width ) || 100,
					mobileScale: parseFloat( layer.getAttribute( 'data-mobile-scale' ) ) || 100,
					mobilePosX: parseFloat( layer.getAttribute( 'data-mobile-pos-x' ) ) || 50,
					mobilePosY: parseFloat( layer.getAttribute( 'data-mobile-pos-y' ) ) || 50,
					origObjectFit: getComputedStyle( layer ).objectFit || 'contain',
					origObjectPosition: layer.style.objectPosition || ''
				} );
			} );
			var initialRect = section.getBoundingClientRect();
			var initialMargin = 200;
			items.push( {
				section: section,
				layers: layers,
				layerData: layerData,
				// Initialize visibility synchronously so the first scroll does
				// not apply the parallax transform for the first time and jump.
				visible: initialRect.bottom >= -initialMargin && initialRect.top <= window.innerHeight + initialMargin,
				mode: section.getAttribute( 'data-mode' ) || 'scroll',
				// Per-block responsive settings (each hero has its own).
				mobileIntensity: section.getAttribute( 'data-mobile-intensity' ),
				tabletScaleMult: section.getAttribute( 'data-tablet-scale-mult' )
			} );
		} );
		if ( ! items.length ) {
			return;
		}

		// ---- Responsive scale + pan (smooth transition) ----
		// Scale and pan are reduced on smaller screens to keep images
		// visible. Three breakpoints with smooth interpolation:
		//   - Desktop (≥1440px): full scale and pan (user's values)
		//   - Tablet (768–1440px): scale × tabletMult, pan interpolated
		//   - Mobile (≤767px): dedicated per-layer mobile scale & position
		// The tablet multiplier is read per-item so each hero block can
		// have independent responsive behavior.

		function applyPanScaling() {
			var w = window.innerWidth;

			items.forEach( function ( item ) {
				// Read this item's own tablet multiplier.
				var itemTabletMult = parseFloat( item.tabletScaleMult );
				if ( isNaN( itemTabletMult ) ) { itemTabletMult = 0.75; }

				var scaleMult, panFactor;

				if ( w >= DESKTOP_WIDTH ) {
					// Desktop: full scale, full pan.
					scaleMult = 1;
					panFactor = 1;
				} else if ( w <= TABLET_WIDTH - 1 ) {
					// Mobile: dedicated per-layer mobile scale & position.
					// scaleMult/panFactor are not used on mobile.
					scaleMult = 0;
					panFactor = 0;
				} else {
					// Tablet: interpolate between tabletMult (at the tablet
					// boundary) and 1 (at 1440px) for scale and pan.
					var t = ( w - TABLET_WIDTH ) / ( DESKTOP_WIDTH - TABLET_WIDTH );
					scaleMult = itemTabletMult + ( 1 - itemTabletMult ) * t;
					panFactor = t;
				}

				var isMobile = w <= TABLET_WIDTH - 1;

				item.layerData.forEach( function ( d ) {
					// On mobile, use the dedicated mobile scale & position.
					// On desktop/tablet, use the responsive interpolation.
					var useScale, usePosX, usePosY, useObjectPosition;

					if ( isMobile ) {
						useScale = d.mobileScale;
						// Compute pan offset from mobile position values.
						var mSlack = Math.max( 0, useScale - 100 ) / 2;
						var mRange = Math.max( 100, mSlack );
						usePosX = ( d.mobilePosX - 50 ) * mRange / useScale;
						// Y axis inverted: high posY = up (matches editor).
						usePosY = ( 50 - d.mobilePosY ) * mRange / useScale;
						useObjectPosition = d.mobilePosX + '% ' + ( 100 - d.mobilePosY ) + '%';
					} else {
						useScale = d.scale * scaleMult;
						var origX = parseFloat( d.posX ) || 0;
						var origY = parseFloat( d.posY ) || 0;
						usePosX = origX * panFactor;
						usePosY = origY * panFactor;
						useObjectPosition = d.origObjectPosition;
					}

					d.el.style.width = useScale + '%';
					d.el.style.height = useScale + '%';
					d.el.style.setProperty( '--pos-x', usePosX + '%' );
					d.el.style.setProperty( '--pos-y', usePosY + '%' );

					// On mobile: if a dedicated mobile image is set,
					// use contain (show the full pre-cropped image). If no
					// mobile image, use cover (fills screen, may crop).
					if ( isMobile ) {
						var hasMobile = d.el.getAttribute( 'data-has-mobile' ) === '1';
						d.el.style.objectFit = hasMobile ? 'contain' : 'cover';
						d.el.style.objectPosition = useObjectPosition;
					} else {
						d.el.style.objectFit = d.origObjectFit;
						d.el.style.objectPosition = useObjectPosition;
					}
				} );
			} );
		}

		// Apply on init, then flag the section as JS-managed so the
		// first-paint CSS rule (which uses !important to prevent the
		// mobile jump) stops overriding inline styles. From this point
		// JS owns all responsive scaling/panning.
		applyPanScaling();
		items.forEach( function ( item ) {
			item.section.classList.add( 'rcmi-parallax-js' );
		} );

		// IntersectionObserver: track which sections are on-screen.
		if ( 'IntersectionObserver' in window ) {
			var io = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					var item = items.find( function ( i ) { return i.section === entry.target; } );
					if ( item ) {
						item.visible = entry.isIntersecting;
						// Restore base transform when section leaves viewport.
						if ( ! entry.isIntersecting ) {
							item.layerData.forEach( function ( d ) {
								d.el.style.transform = d.baseTransform;
							} );
						}
					}
				} );
			}, { rootMargin: '200px' } );
			items.forEach( function ( item ) { io.observe( item.section ); } );
		}

		// ---- Per-layer direction ----
		// Direction is solely determined by the sign of each layer's
		// data-speed attribute. Positive = layer drifts down as you
		// scroll down, negative = layer rises. No layer-class-based
		// direction multiplier — same speed = same movement.

		// ---- Mode 1: Scroll ----
		var ticking = false;

		function updateScroll() {
			ticking = false;
			var viewportHeight = window.innerHeight;

			items.forEach( function ( item ) {
				if ( item.mode !== 'scroll' ) {
					return;
				}
				if ( ! item.visible ) {
					return;
				}

				var rect = item.section.getBoundingClientRect();
				var travelMultiplier = getTravelMultiplier( item );

				// Distance of the section's center from the viewport's center.
				// As you scroll down, the section moves up, so this value
				// decreases. The offset changes at exactly `speed` px per
				// px scrolled, making the parallax rate directly proportional
				// to speed (not diluted by the scroll-progress formula).
				//   At speed=1: layer is locked to viewport (net 0 movement)
				//   At speed=2: layer moves down 1px per px scrolled (visible)
				//   At speed<1: layer drifts up slowly (subtle depth)
				// Negative speed reverses the direction.
				var sectionCenter = rect.top + rect.height / 2;
				var viewportCenter = viewportHeight / 2;
				var distFromCenter = sectionCenter - viewportCenter;

				item.layerData.forEach( function ( d ) {
					var layer = d.el;
					var speed = parseFloat( layer.getAttribute( 'data-speed' ) ) || 0;

					// Offset = -distFromCenter × speed × travelMultiplier.
					// The negative sign makes the layer move opposite to the
					// section's scroll direction (for speed>0): as the section
					// scrolls up (distFromCenter decreases), the offset
					// increases (layer moves down on screen).
					// A negative speed flips the direction.
					// No clamping — layers move freely at full speed. Gaps
					// may appear at the section edges when the layer slides
					// out of view; increase scale to add headroom.
					var offset = -distFromCenter * speed * travelMultiplier;

					// On mobile, the travelMultiplier (mobile intensity) is
					// the sole dampener. If edges appear at low mobile scale,
					// increase the layer's mobile scale to add headroom.

					// Append the parallax offset to the layer's base transform
					// (which handles centering + panning). The base transform
					// is translate(calc(-50% + var(--pos-x)), calc(-50% + var(--pos-y))).
					// We add the parallax Y offset as a second translate.
					layer.style.transform = d.baseTransform + ' translate3d(0, ' + offset.toFixed( 2 ) + 'px, 0)';
				} );
			} );
		}

		function onScroll() {
			if ( ! ticking ) {
				ticking = true;
				window.requestAnimationFrame( updateScroll );
			}
		}

		// ---- Mode 2: Mouse ----
		var mouseItems = items.filter( function ( i ) { return i.mode === 'mouse'; } );
		var mousePending = false;
		var mouseTargetY = 0;

		if ( mouseItems.length ) {
			window.addEventListener( 'mousemove', function ( e ) {
				mouseTargetY = ( e.clientY / window.innerHeight ) * 2 - 1;
				if ( ! mousePending ) {
					mousePending = true;
					window.requestAnimationFrame( updateMouse );
				}
			}, { passive: true } );
		}

		function updateMouse() {
			mousePending = false;
			mouseItems.forEach( function ( item ) {
				if ( ! item.visible ) {
					return;
				}
				var travelMultiplier = getTravelMultiplier( item );
				var rect = item.section.getBoundingClientRect();
				item.layerData.forEach( function ( d ) {
					var layer = d.el;
					var speed = parseFloat( layer.getAttribute( 'data-speed' ) ) || 0;
					var travelY = rect.height * speed * 0.15 * travelMultiplier;
					layer.style.transform = d.baseTransform
						+ ' translate3d(0, ' + ( mouseTargetY * travelY ).toFixed( 2 ) + 'px, 0)';
				} );
			} );
		}

		// ---- Init ----
		if ( items.some( function ( i ) { return i.mode === 'scroll'; } ) ) {
			window.addEventListener( 'scroll', onScroll, { passive: true } );
			window.addEventListener( 'resize', function () { applyPanScaling(); onScroll(); } );
			updateScroll();
			// Recalculate after layout fully settles (fixed header offset,
			// web fonts, etc.). Without this, the initial offsets are
			// calculated before nav.js applies --rcmi-header-offset, causing
			// the parallax images to jump on the first scroll event.
			window.addEventListener( 'load', updateScroll );
		} else {
			// Mouse-only: still need resize for pan scaling.
			window.addEventListener( 'resize', applyPanScaling );
		}
		// Mouse mode: initialize layers to base transform.
		mouseItems.forEach( function ( item ) {
			item.layerData.forEach( function ( d ) {
				d.el.style.transform = d.baseTransform;
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initImpactStripTabs );
	} else {
		initImpactStripTabs();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initParallax );
	} else {
		initParallax();
	}

	// ============================================================
	// Slide Block: rcmi/slide-block
	// Full-bleed slider with arrows/dots navigation, auto-play,
	// random first slide, and GSAP transitions.
	// ============================================================
	function initSlideBlock() {
		var wrappers = document.querySelectorAll( '.rcmi-slide-block' );
		if ( ! wrappers.length ) {
			return;
		}

		var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var hasGsap = typeof window.gsap !== 'undefined';

		// GSAP transition definitions (same as impact strip).
		var slideTransitions = {
			fade: function ( oldSlide, newSlide ) {
				return gsap.timeline()
					.set( newSlide, { opacity: 0, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0, zIndex: 2 } )
					.set( oldSlide, { zIndex: 1 } )
					.to( newSlide, { opacity: 1, duration: 0.5, ease: 'power2.inOut' } );
			},
			slide: function ( oldSlide, newSlide, dir ) {
				var fromX = dir > 0 ? 100 : -100;
				var toX = dir > 0 ? -100 : 100;
				return gsap.timeline()
					.set( newSlide, { opacity: 1, xPercent: fromX, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldSlide, { opacity: 0, xPercent: toX, duration: 0.5, ease: 'power3.inOut' }, 0 )
					.to( newSlide, { xPercent: 0, duration: 0.5, ease: 'power3.inOut' }, 0 );
			},
			curtain: function ( oldSlide, newSlide ) {
				return gsap.timeline()
					.set( newSlide, { opacity: 1, yPercent: 100, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0 } )
					.to( oldSlide, { opacity: 0, yPercent: -100, duration: 0.5, ease: 'power3.inOut' }, 0 )
					.to( newSlide, { yPercent: 0, duration: 0.5, ease: 'power3.inOut' }, 0 );
			},
			wipe: function ( oldSlide, newSlide ) {
				return gsap.timeline()
					.set( newSlide, { opacity: 1, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0, clipPath: 'inset(0 100% 0 0)' } )
					.to( newSlide, { clipPath: 'inset(0 0% 0 0)', duration: 0.55, ease: 'power2.inOut' }, 0 )
					.to( oldSlide, { opacity: 0.3, duration: 0.55, ease: 'power2.inOut' }, 0 );
			},
			reveal: function ( oldSlide, newSlide ) {
				return gsap.timeline()
					.set( newSlide, { opacity: 0, scale: 1.08, display: 'flex', position: 'absolute', top: 0, left: 0, right: 0, transformOrigin: 'center center', zIndex: 2 } )
					.set( oldSlide, { zIndex: 1 } )
					.to( newSlide, { opacity: 1, scale: 1, duration: 0.55, ease: 'power2.out' } );
			}
		};

		wrappers.forEach( function ( block ) {
			var track = block.querySelector( '.rcmi-slide-track' );
			var slides = track ? Array.prototype.slice.call( track.querySelectorAll( '.rcmi-slide' ) ) : [];
			if ( ! slides.length ) {
				return;
			}

			var autoplay = block.getAttribute( 'data-autoplay' ) === '1';
			var interval = parseInt( block.getAttribute( 'data-interval' ), 10 ) || 5;
			var pauseOnHover = block.getAttribute( 'data-pause-on-hover' ) === '1';
			var randomStart = block.getAttribute( 'data-random-start' ) === '1';
			var loop = block.getAttribute( 'data-loop' ) === '1';
			var transition = block.getAttribute( 'data-transition' ) || 'fade';
			var slideCount = parseInt( block.getAttribute( 'data-slide-count' ), 10 ) || slides.length;

			var dots = Array.prototype.slice.call( block.querySelectorAll( '.rcmi-slide-dot' ) );
			var prevBtn = block.querySelector( '.rcmi-slide-arrow-prev' );
			var nextBtn = block.querySelector( '.rcmi-slide-arrow-next' );

			var currentIdx = 0;
			var isAnimating = false;
			var autoplayTimer = null;
			var isPaused = false;

			// Random first slide: pick a random index on page load.
			if ( randomStart && slideCount > 1 ) {
				currentIdx = Math.floor( Math.random() * slideCount );
				// Apply the random start immediately.
				slides.forEach( function ( s, i ) {
					s.classList.toggle( 'is-active', i === currentIdx );
				} );
				dots.forEach( function ( d, i ) {
					d.classList.toggle( 'is-active', i === currentIdx );
				} );
			}

			function cleanup( oldSlide, newSlide ) {
				var gsapProps = 'opacity,transform,display,position,top,left,right,zIndex,clipPath,transformOrigin';
				// Clear the new slide FIRST so it returns to static position
				// and display:flex (from .is-active CSS) before we remove
				// the track's minHeight. This prevents a white flash caused
				// by the track collapsing for a frame between clearing
				// minHeight and the new slide taking up space in flow.
				gsap.set( newSlide, { clearProps: gsapProps } );
				newSlide.classList.add( 'is-active' );
				if ( oldSlide ) {
					gsap.set( oldSlide, { clearProps: gsapProps } );
					oldSlide.classList.remove( 'is-active' );
				}
				track.classList.remove( 'is-animating' );
				track.style.minHeight = '';
				isAnimating = false;
			}

			function updateDots() {
				dots.forEach( function ( d, i ) {
					d.classList.toggle( 'is-active', i === currentIdx );
				} );
			}

			function goTo( newIdx, dir ) {
				if ( isAnimating ) return;
				if ( newIdx === currentIdx ) return;
				if ( newIdx < 0 ) {
					if ( ! loop ) return;
					newIdx = slides.length - 1;
				}
				if ( newIdx >= slides.length ) {
					if ( ! loop ) return;
					newIdx = 0;
				}
				if ( dir === undefined ) {
					dir = newIdx > currentIdx ? 1 : -1;
				}

				var oldSlide = slides[ currentIdx ];
				var newSlide = slides[ newIdx ];

				updateDots();

				if ( ! transition || transition === 'none' || reducedMotion || ! hasGsap || ! slideTransitions[ transition ] ) {
					// Instant switch.
					slides.forEach( function ( s, i ) {
						s.classList.toggle( 'is-active', i === newIdx );
					} );
					currentIdx = newIdx;
					return;
				}

				// Animated transition.
				isAnimating = true;
				var panelHeight = oldSlide ? oldSlide.offsetHeight : 0;
				track.classList.add( 'is-animating' );
				if ( panelHeight ) {
					track.style.minHeight = panelHeight + 'px';
				}

				// Pre-set opacity:0 before adding is-active.
				newSlide.style.opacity = '0';
				newSlide.classList.add( 'is-active' );

				var tl = slideTransitions[ transition ]( oldSlide, newSlide, dir );
				tl.eventCallback( 'onComplete', function () {
					cleanup( oldSlide, newSlide );
					currentIdx = newIdx;
				} );
			}

			function next() {
				goTo( currentIdx + 1, 1 );
			}
			function prev() {
				goTo( currentIdx - 1, -1 );
			}

			// Arrow buttons.
			if ( nextBtn ) {
				nextBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					next();
					restartAutoplay();
				} );
			}
			if ( prevBtn ) {
				prevBtn.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					prev();
					restartAutoplay();
				} );
			}

			// Dot indicators.
			dots.forEach( function ( dot, i ) {
				dot.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					goTo( i );
					restartAutoplay();
				} );
			} );

			// Keyboard navigation.
			block.setAttribute( 'tabindex', '0' );
			block.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'ArrowLeft' ) {
					e.preventDefault();
					prev();
					restartAutoplay();
				} else if ( e.key === 'ArrowRight' ) {
					e.preventDefault();
					next();
					restartAutoplay();
				}
			} );

			// Touch/swipe support.
			var touchStartX = 0;
			var touchStartY = 0;
			var touchEndX = 0;
			var touchEndY = 0;

			block.addEventListener( 'touchstart', function ( e ) {
				touchStartX = e.changedTouches[0].screenX;
				touchStartY = e.changedTouches[0].screenY;
			}, { passive: true } );

			block.addEventListener( 'touchend', function ( e ) {
				touchEndX = e.changedTouches[0].screenX;
				touchEndY = e.changedTouches[0].screenY;
				var dx = touchEndX - touchStartX;
				var dy = touchEndY - touchStartY;
				// Only trigger if horizontal swipe is dominant.
				if ( Math.abs( dx ) > 50 && Math.abs( dx ) > Math.abs( dy ) ) {
					if ( dx < 0 ) {
						next();
					} else {
						prev();
					}
					restartAutoplay();
				}
			}, { passive: true } );

			// Auto-play.
			function startAutoplay() {
				if ( ! autoplay || reducedMotion ) return;
				stopAutoplay();
				autoplayTimer = setInterval( function () {
					if ( ! isPaused && ! isAnimating ) {
						next();
					}
				}, interval * 1000 );
			}

			function stopAutoplay() {
				if ( autoplayTimer ) {
					clearInterval( autoplayTimer );
					autoplayTimer = null;
				}
			}

			function restartAutoplay() {
				if ( autoplay ) {
					stopAutoplay();
					startAutoplay();
				}
			}

			if ( autoplay && pauseOnHover ) {
				block.addEventListener( 'mouseenter', function () { isPaused = true; } );
				block.addEventListener( 'mouseleave', function () { isPaused = false; } );
				block.addEventListener( 'focusin', function () { isPaused = true; } );
				block.addEventListener( 'focusout', function () { isPaused = false; } );
			}

			// Start auto-play on load.
			startAutoplay();

			// Pause when tab is not visible.
			document.addEventListener( 'visibilitychange', function () {
				if ( document.hidden ) {
					stopAutoplay();
				} else {
					startAutoplay();
				}
			} );

			// Mobile background image: swap on resize.
			var MOBILE_WIDTH = 768;
			function applyMobileBg() {
				var isMobile = window.matchMedia( '(max-width: ' + ( MOBILE_WIDTH - 1 ) + 'px)' ).matches;
				slides.forEach( function ( slide ) {
					var mobileBg = slide.getAttribute( 'data-mobile-bg' );
					if ( ! mobileBg ) return;
					var mobileScale = slide.getAttribute( 'data-mobile-scale' ) || 110;
					var mobilePosX = slide.getAttribute( 'data-mobile-pos-x' ) || 50;
					var mobilePosY = slide.getAttribute( 'data-mobile-pos-y' ) || 50;
					if ( isMobile ) {
						slide.style.backgroundImage = 'url(' + mobileBg + ')';
						slide.style.backgroundSize = mobileScale + '%';
						slide.style.backgroundPosition = mobilePosX + '% ' + mobilePosY + '%';
					} else {
						// Restore desktop background from the original style attribute.
						var origStyle = slide.getAttribute( 'style' ) || '';
						var bgMatch = origStyle.match( /background-image:url\([^)]+\)/ );
						if ( bgMatch ) {
							slide.style.backgroundImage = bgMatch[0].replace( 'background-image:', '' );
						}
						var sizeMatch = origStyle.match( /background-size:\d+%/ );
						if ( sizeMatch ) {
							slide.style.backgroundSize = sizeMatch[0].replace( 'background-size:', '' );
						}
						var posMatch = origStyle.match( /background-position:\d+%\s*\d+%/ );
						if ( posMatch ) {
							slide.style.backgroundPosition = posMatch[0].replace( 'background-position:', '' );
						}
					}
				} );
			}
			applyMobileBg();
			window.addEventListener( 'resize', applyMobileBg );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initSlideBlock );
	} else {
		initSlideBlock();
	}

} )();
