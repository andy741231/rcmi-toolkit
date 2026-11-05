/**
 * RCMI inserter UI enhancements:
 *  - Accordion: block category panels in the main inserter are collapsible.
 *    Collapsed state persists per editor session via localStorage.
 *  - Badges: "New" / "Updated" pills next to RCMI block icons, driven by
 *    window.rcmiBlockMeta (block name -> { added, updated } ISO dates).
 *    A block is "new" for 60 days after `added`; "updated" for 60 days
 *    after `updated`.
 *
 * Keep the native inserter order: moving React-owned list nodes from this
 * observer can trigger non-convergent childList feedback loops (observed:
 * editor-freezing mutation storms). Categories are ordered server-side.
 * Never reparent or reorder .block-editor-block-types-list nodes here.
 */
( function () {
	var META = window.rcmiBlockMeta || {};
	var DAY = 86400000;
	var WINDOW_MS = 62 * DAY; // ~2 months
	var LS_KEY = 'rcmi-inserter-collapsed';

	function collapsedSet() {
		try {
			return new Set( JSON.parse( localStorage.getItem( LS_KEY ) || '[]' ) );
		} catch ( e ) {
			return new Set();
		}
	}

	function saveCollapsed( set ) {
		try {
			localStorage.setItem( LS_KEY, JSON.stringify( Array.from( set ) ) );
		} catch ( e ) {}
	}

	// ---- accordion ---------------------------------------------------------

	function enhanceHeader( header ) {
		if ( header.dataset.rcmiAcc ) {
			return;
		}
		var content = header.nextElementSibling;
		if ( ! content || ! content.classList.contains( 'block-editor-inserter__panel-content' ) ) {
			return;
		}
		header.dataset.rcmiAcc = '1';

		var titleEl = header.querySelector( '.block-editor-inserter__panel-title' );
		var title = titleEl ? titleEl.textContent.trim() : '';
		var collapsed = collapsedSet();

		// Default: collapse everything except the first panel (RCMI Sections).
		var isFirst = ! header.parentElement.querySelector( '.block-editor-inserter__panel-header.rcmi-acc-done, .block-editor-inserter__panel-header[data-rcmi-acc]' )
			|| header === header.parentElement.querySelector( '.block-editor-inserter__panel-header[data-rcmi-acc]' );
		header.classList.add( 'rcmi-acc-done' );

		var shouldCollapse = collapsed.size ? collapsed.has( title ) : ! isFirst;
		setCollapsed( header, content, shouldCollapse, title );

		header.setAttribute( 'role', 'button' );
		header.setAttribute( 'tabindex', '0' );

		function toggle() {
			var nowCollapsed = ! content.classList.contains( 'rcmi-acc-collapsed' );
			setCollapsed( header, content, nowCollapsed, title );
			var set = collapsedSet();
			if ( nowCollapsed ) {
				set.add( title );
			} else {
				set.delete( title );
			}
			saveCollapsed( set );
		}

		header.addEventListener( 'click', toggle );
		header.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				toggle();
			}
		} );
	}

	function setCollapsed( header, content, collapsed, title ) {
		content.classList.toggle( 'rcmi-acc-collapsed', collapsed );
		header.classList.toggle( 'rcmi-acc-collapsed', collapsed );
		header.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
		if ( title ) {
			header.setAttribute( 'aria-label', title + ( collapsed ? ' (collapsed)' : ' (expanded)' ) );
		}
	}

	// ---- badges ------------------------------------------------------------

	function badgeLabel( name ) {
		var m = META[ name ];
		if ( ! m ) {
			return null;
		}
		var now = Date.now();
		var added = m.added ? Date.parse( m.added + 'T00:00:00' ) : 0;
		var updated = m.updated ? Date.parse( m.updated + 'T00:00:00' ) : 0;
		if ( added && now - added <= WINDOW_MS && now - added >= 0 ) {
			return 'New';
		}
		if ( updated && now - updated <= WINDOW_MS && now - updated >= 0 ) {
			return 'Updated';
		}
		return null;
	}

	function enhanceItem( item ) {
		if ( item.dataset.rcmiBadge ) {
			return;
		}
		item.dataset.rcmiBadge = '1';
		var cls = item.className.match( /editor-block-list-item-([a-z0-9-]+)/ );
		if ( ! cls ) {
			return;
		}
		var name = cls[1].replace( '-', '/' ); // only the first dash is the namespace sep
		var label = badgeLabel( name );
		if ( ! label ) {
			return;
		}
		var icon = item.querySelector( '.block-editor-block-types-list__item-icon' );
		var badge = document.createElement( 'span' );
		badge.className = 'rcmi-inserter-badge ' + ( label === 'New' ? 'is-new' : 'is-updated' );
		badge.textContent = label;
		badge.setAttribute( 'aria-hidden', 'true' );
		if ( icon ) {
			icon.style.position = 'relative';
			icon.appendChild( badge );
		} else {
			item.style.position = 'relative';
			item.appendChild( badge );
		}
	}

	// ---- active container --------------------------------------------------
	// GB swaps where panels render by selection state: with nothing selected
	// everything lives in __insertable-blocks-at-selection; with a block
	// selected __all-blocks holds the categories and the other container
	// becomes a small "context" panel promoting the selected type. Hide the
	// context panel only when __all-blocks is populated.
	function activeBlocksContainer() {
		var all = document.querySelector( '.block-editor-inserter__all-blocks' );
		var populated = !!( all && all.querySelector( '.block-editor-block-types-list__item' ) );
		var strip = document.querySelector( '.block-editor-inserter__insertable-blocks-at-selection' );
		// Toggle only when the class actually differs — writing the class
		// attribute on every observer pass feeds mutation records back into
		// every observer on the document.
		if ( strip && strip.classList.contains( 'rcmi-inserter-hidden' ) !== populated ) {
			strip.classList.toggle( 'rcmi-inserter-hidden', populated );
		}
		return populated ? all : ( strip || document );
	}

	// ---- search expands collapsed panels -----------------------------------

	function bindSearch( input ) {
		if ( input.dataset.rcmiSearchBound ) {
			return;
		}
		input.dataset.rcmiSearchBound = '1';
		var apply = function () {
			var menu = input.closest( '.block-editor-inserter__menu, .block-editor-inserter__main-area' ) || input.closest( '[class*="inserter"]' );
			if ( menu ) {
				menu.classList.toggle( 'rcmi-searching', !! input.value.trim() );
			}
		};
		input.addEventListener( 'input', apply );
		apply();
	}

	// ---- observer ----------------------------------------------------------

	function enhance( root ) {
		var headers = root.querySelectorAll ? Array.from( root.querySelectorAll( '.block-editor-inserter__panel-header' ) ) : [];
		headers.forEach( enhanceHeader );
		activeBlocksContainer();
		( root.querySelectorAll ? Array.from( root.querySelectorAll( '.block-editor-block-types-list__item' ) ) : [] )
			.forEach( enhanceItem );
		( root.querySelectorAll ? Array.from( root.querySelectorAll( '.block-editor-inserter__menu input[type="search"], .block-editor-inserter__main-area input[type="search"], .block-editor-inserter__search input' ) ) : [] )
			.forEach( bindSearch );
	}

	wp.domReady( function () {
		enhance( document );
		new MutationObserver( function ( records ) {
			for ( var i = 0; i < records.length; i++ ) {
				var nodes = records[ i ].addedNodes;
				for ( var j = 0; j < nodes.length; j++ ) {
					var n = nodes[ j ];
					if ( n.nodeType !== 1 ) {
						continue;
					}
					if ( n.classList && n.classList.contains( 'block-editor-inserter__panel-header' ) ) {
						enhanceHeader( n );
					} else if ( n.classList && n.classList.contains( 'block-editor-block-types-list__item' ) ) {
						enhanceItem( n );
					} else {
						enhance( n );
					}
					// New nodes elsewhere can swap which inserter container
					// is active — re-check the hide/show state (cheap, no DOM
					// writes unless the state actually changed).
					activeBlocksContainer();
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	} );
} )();
