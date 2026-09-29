/**
 * Fix Gutenberg's "Paste styles" on Spectra blocks.
 *
 * Spectra stores the values that actually generate CSS in a per-device
 * `responsiveControls` attribute, while WP core's Paste styles only copies
 * the whitelisted attributes (style, textColor, className, ...). Without
 * this patch the pasted value updates the editor preview, but the frontend
 * render — and the editor after reload — rebuilds from the untouched
 * `responsiveControls`, so the pasted style silently reverts.
 *
 * This script detects the paste dispatch (an updateBlockAttributes call
 * whose map is limited to core's style whitelist, targeting a spectra/*
 * block), re-reads the clipboard markup the paste just consumed, verifies
 * it produced exactly this update, then applies the source block's
 * `responsiveControls` to the target so editor and frontend agree.
 */
( function () {
	if ( ! window.wp || ! wp.data || ! wp.blocks ) {
		return;
	}

	var STORE = 'core/block-editor';
	var STYLE_KEYS = {
		align: true, borderColor: true, backgroundColor: true, textAlign: true,
		textColor: true, gradient: true, className: true, fontFamily: true,
		fontSize: true, layout: true, style: true
	};

	function isSpectra( name ) {
		return typeof name === 'string' && name.indexOf( 'spectra/' ) === 0;
	}

	// The updateBlockAttributes map produced by core's paste styles only
	// ever contains whitelisted keys (some set to undefined).
	function isStylePasteMap( attrs ) {
		var keys = Object.keys( attrs );
		if ( ! keys.length ) {
			return false;
		}
		var hasValue = false;
		for ( var i = 0; i < keys.length; i++ ) {
			if ( ! STYLE_KEYS[ keys[ i ] ] ) {
				return false;
			}
			if ( attrs[ keys[ i ] ] !== undefined ) {
				hasValue = true;
			}
		}
		return hasValue;
	}

	// Index path from the block list root down to the target, matching how
	// core's recursivelyUpdateBlockAttributes pairs inner blocks by position.
	function indexPathFor( clientId ) {
		var sel = wp.data.select( STORE );
		var path = [];
		var id = clientId;
		for ( ;; ) {
			var parent = sel.getBlockRootClientId( id );
			path.unshift( sel.getBlockOrder( parent ).indexOf( id ) );
			if ( ! parent ) {
				break;
			}
			id = parent;
		}
		return path;
	}

	// A single parsed block of the same type is used directly (copying an
	// inner block serializes that block alone); otherwise descend by the
	// target's index path, which mirrors core's positional pairing.
	function findSourceBlock( parsed, targetName, indexPath ) {
		if ( parsed.length === 1 && parsed[ 0 ].name === targetName ) {
			return parsed[ 0 ];
		}
		var level = parsed;
		var node = null;
		for ( var i = 0; i < indexPath.length; i++ ) {
			node = level[ indexPath[ i ] ];
			if ( ! node ) {
				return null;
			}
			level = node.innerBlocks || [];
		}
		return node && node.name === targetName ? node : null;
	}

	// Only trust the clipboard when its parsed block produced exactly this
	// update — guards against acting on unrelated dispatches while stale
	// block markup sits on the clipboard.
	function matchesSource( applied, sourceAttrs ) {
		for ( var k in applied ) {
			if ( applied[ k ] === undefined ) {
				continue;
			}
			if ( JSON.stringify( applied[ k ] ) !== JSON.stringify( sourceAttrs[ k ] ) ) {
				return false;
			}
		}
		return true;
	}

	var reading = false;

	function syncResponsive( targetIds, applied ) {
		if ( reading || ! navigator.clipboard || ! navigator.clipboard.readText ) {
			return;
		}
		reading = true;
		navigator.clipboard.readText().then( function ( text ) {
			reading = false;
			if ( ! text || text.indexOf( '<!-- wp:' ) === -1 ) {
				return;
			}
			var parsed = wp.blocks.parse( text );
			if ( ! parsed || ! parsed.length ) {
				return;
			}
			var sel = wp.data.select( STORE );
			var updates = [];
			targetIds.forEach( function ( id ) {
				var block = sel.getBlock( id );
				if ( ! block ) {
					return;
				}
				var src = findSourceBlock( parsed, block.name, indexPathFor( id ) );
				if ( ! src || ! matchesSource( applied, src.attributes ) ) {
					return;
				}
				var srcRc = src.attributes.responsiveControls;
				var tgtRc = block.attributes.responsiveControls;
				if ( JSON.stringify( srcRc || null ) === JSON.stringify( tgtRc || null ) ) {
					return;
				}
				if ( srcRc || tgtRc ) {
					updates.push( { id: id, rc: srcRc } );
				}
			} );
			if ( updates.length ) {
				var dispatch = wp.data.dispatch( STORE );
				updates.forEach( function ( u ) {
					dispatch.updateBlockAttributes( u.id, { responsiveControls: u.rc } );
				} );
			}
		} ).catch( function () {
			reading = false;
		} );
	}

	function patch() {
		var actions = wp.data.dispatch( STORE );
		if ( ! actions || actions.__rcmiPasteWrapped ) {
			return;
		}
		var orig = actions.updateBlockAttributes;
		actions.updateBlockAttributes = function ( clientIds, attributes ) {
			var result = orig.apply( this, arguments );
			try {
				if ( attributes && isStylePasteMap( attributes ) ) {
					var ids = Array.isArray( clientIds ) ? clientIds : [ clientIds ];
					var sel = wp.data.select( STORE );
					var targets = ids.filter( function ( id ) {
						var b = sel.getBlock( id );
						return b && isSpectra( b.name );
					} );
					if ( targets.length ) {
						syncResponsive( targets, attributes );
					}
				}
			} catch ( e ) {
				// Never break a paste (or any attribute update) on our sync.
			}
			return result;
		};
		actions.__rcmiPasteWrapped = true;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', patch );
	} else {
		patch();
	}
} )();
