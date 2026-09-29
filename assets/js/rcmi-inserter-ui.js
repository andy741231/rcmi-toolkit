/**
 * RCMI inserter UI enhancements:
 *  - Accordion: block category panels in the main inserter are collapsible.
 *    Collapsed state persists per editor session via localStorage.
 *  - Badges: "New" / "Updated" pills next to RCMI block icons, driven by
 *    window.rcmiBlockMeta (block name -> { added, updated } ISO dates).
 *    A block is "new" for 60 days after `added`; "updated" for 60 days
 *    after `updated`.
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

	// ---- RCMI panel order + sub-headings -----------------------------------
	// Core promotes the currently-selected block type to the top of its
	// category, which scrambles the RCMI list whenever you click a block.
	// Re-order the items back to this fixed order on every render.

	var RCMI_ORDER = [
		'rcmi/section',
		'rcmi/quote-block',
		'rcmi/cta-band',
		'rcmi/impact-stats-block',
		'rcmi/role-selector-block',
		'rcmi/impact-strip-block',
		'rcmi/slide-block',
		'rcmi/slide',
		'rcmi/parallax',
		'rcmi/table',
		'rcmi/directory',
		'rcmi/directory-person'
	];

	// First block name of each visual group -> its sub-heading label.
	var RCMI_GROUPS = {
		'rcmi/section': 'Layout',
		'rcmi/quote-block': 'Content',
		'rcmi/impact-stats-block': 'Dynamic sections',
		'rcmi/slide-block': 'Media & data'
	};

	function itemBlockName( el ) {
		var btn = el.classList.contains( 'block-editor-block-types-list__item' )
			? el
			: el.querySelector( '.block-editor-block-types-list__item' );
		var cls = btn && btn.className.match( /editor-block-list-item-([a-z0-9-]+)/ );
		return cls ? cls[1].replace( '-', '/' ) : '';
	}

	function organizeRcmiPanel( content ) {
		var list = content.querySelector( '.block-editor-block-types-list' );
		if ( ! list ) {
			return;
		}
		// GB renders items in flex row wrappers ([role="presentation"], ~3
		// items per row), each item followed by a draggable-chip sibling.
		// Consolidate everything into the first row — flex-wrap re-flows
		// identically — with full-width sub-heads between groups.
		var rows = Array.from( list.querySelectorAll( ':scope > [role="presentation"]' ) );
		if ( ! rows.length ) {
			rows = [ list ];
		}
		var row0 = rows[ 0 ];

		// Collect item nodes + their chips, in DOM order across all rows.
		var units = [];
		var orphanChips = [];
		var last = null;
		rows.forEach( function ( row ) {
			Array.from( row.children ).forEach( function ( n ) {
				if ( n.classList.contains( 'rcmi-inserter-subhead' ) ) {
					return;
				}
				if ( /draggable/.test( n.className ) && ! n.querySelector( '.block-editor-block-types-list__item' ) ) {
					if ( last ) {
						last.chips.push( n );
					} else {
						orphanChips.push( n );
					}
					return;
				}
				var name = itemBlockName( n );
				if ( name ) {
					last = { node: n, name: name, chips: [] };
					units.push( last );
				} else {
					last = null;
				}
			} );
		} );

		var byName = {};
		var extras = [];
		units.forEach( function ( u ) {
			if ( RCMI_ORDER.indexOf( u.name ) !== -1 ) {
				byName[ u.name ] = u;
			} else {
				extras.push( u );
			}
		} );

		// Desired node order: sub-head + items per group, then unlisted extras.
		var wanted = [];
		var groupHeads = {};
		var pushUnit = function ( u ) {
			wanted.push( u.node );
			u.chips.forEach( function ( c ) { wanted.push( c ); } );
		};
		RCMI_ORDER.forEach( function ( name ) {
			var u = byName[ name ];
			if ( ! u ) {
				return;
			}
			var group = RCMI_GROUPS[ name ];
			if ( group ) {
				var head = row0.querySelector( '.rcmi-inserter-subhead[data-group="' + group + '"]' );
				if ( ! head ) {
					head = document.createElement( 'div' );
					head.className = 'rcmi-inserter-subhead';
					head.dataset.group = group;
					head.textContent = group;
				}
				wanted.push( head );
				groupHeads[ group ] = true;
			}
			pushUnit( u );
		} );
		extras.forEach( pushUnit );
		orphanChips.forEach( function ( c ) { wanted.push( c ); } );

		// Remove stale sub-heads for groups that have no visible items.
		Array.from( list.querySelectorAll( '.rcmi-inserter-subhead' ) ).forEach( function ( h ) {
			if ( ! groupHeads[ h.dataset.group ] ) {
				h.parentNode.removeChild( h );
			}
		} );

		// Already organized? Don't touch the DOM (avoids observer loops).
		var flat = [];
		rows.forEach( function ( row ) {
			Array.from( row.children ).forEach( function ( n ) { flat.push( n ); } );
		} );
		var current = flat.filter( function ( n ) {
			return n.classList.contains( 'rcmi-inserter-subhead' )
				|| /draggable/.test( n.className )
				|| n.classList.contains( 'block-editor-block-types-list__item' )
				|| n.querySelector( '.block-editor-block-types-list__item' );
		} );
		var laterRowsHaveItems = rows.slice( 1 ).some( function ( row ) {
			return !! row.querySelector( '.block-editor-block-types-list__item' );
		} );
		if ( ! laterRowsHaveItems && current.length === wanted.length && current.every( function ( n, i ) { return n === wanted[ i ]; } ) ) {
			return;
		}
		wanted.forEach( function ( n ) { row0.appendChild( n ); } );
	}

	function isRcmiPanel( header ) {
		var t = header.querySelector( '.block-editor-inserter__panel-title' );
		return t && t.textContent.trim() === 'RCMI Sections';
	}

	// GB swaps where panels render by selection state: with nothing selected
	// everything lives in __insertable-blocks-at-selection; with a block
	// selected __all-blocks holds the categories and the other container
	// becomes a small "context" panel promoting the selected type. Hide the
	// context panel only when __all-blocks is populated, and organize the
	// RCMI panel inside whichever container is active.
	function activeBlocksContainer() {
		var all = document.querySelector( '.block-editor-inserter__all-blocks' );
		var populated = !!( all && all.querySelector( '.block-editor-block-types-list__item' ) );
		var strip = document.querySelector( '.block-editor-inserter__insertable-blocks-at-selection' );
		if ( strip ) {
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
		var scope = activeBlocksContainer();
		headers.forEach( function ( h ) {
			if ( isRcmiPanel( h ) && scope.contains( h ) ) {
				var content = h.nextElementSibling;
				if ( content && content.classList.contains( 'block-editor-inserter__panel-content' ) ) {
					organizeRcmiPanel( content );
				}
			}
		} );
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
					// Any addition inside a panel may mean more items mounted —
					// re-run ordering/sub-heads for the RCMI category.
					var pc = n.closest ? n.closest( '.block-editor-inserter__panel-content' ) : null;
					if ( pc ) {
						var prev = pc.previousElementSibling;
						var scope = activeBlocksContainer();
						if ( prev && prev.classList.contains( 'block-editor-inserter__panel-header' ) && isRcmiPanel( prev ) && scope.contains( prev ) ) {
							organizeRcmiPanel( pc );
						}
					}
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	} );
} )();
