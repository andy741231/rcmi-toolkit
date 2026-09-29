( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var Modal = wp.components.Modal;
	var Button = wp.components.Button;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var NumberControl = wp.components.NumberControl || wp.components.__experimentalNumberControl;
	var Notice = wp.components.Notice;
	var __ = wp.i18n.__;

	// [rcmi-cell-helpers-start]
	// Pure string helpers — no DOM, no wp.* (extractable for Node tests).

	// Tags that inline-only editing (RichText) cannot represent faithfully.
	var RCMI_CELL_BLOCK_TAG_RE = /<\s*\/?\s*(address|article|aside|blockquote|dd|details|div|dl|dt|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul)\b/i;

	// Elements that must never survive into stored cell content.
	var RCMI_CELL_DROP_TAG_RE = /<(script|style|iframe|object|embed|form|input|button|textarea|select|option|link|meta|noscript|template)\b[^>]*>[\s\S]*?<\/\1\s*>/gi;
	var RCMI_CELL_DROP_TAG_RE2 = /<\/?(script|style|iframe|object|embed|form|input|button|textarea|select|option|link|meta|noscript|template)\b[^>]*>/gi;
	var RCMI_CELL_TABLE_TAG_RE = /<\/?table\b(?:[^>"']|"[^"]*"|'[^']*')*>/gi;

	function rcmiCellIsStructured( html ) {
		if ( ! html ) {
			return false;
		}
		return RCMI_CELL_BLOCK_TAG_RE.test( html );
	}

	// Deepest table-nesting level found (0 = none, 1 = a flat inner table).
	function rcmiCellMaxTableDepth( html ) {
		var depth = 0, max = 0, m;
		RCMI_CELL_TABLE_TAG_RE.lastIndex = 0;
		while ( ( m = RCMI_CELL_TABLE_TAG_RE.exec( html || '' ) ) ) {
			if ( /^<\//.test( m[ 0 ] ) ) {
				depth = Math.max( 0, depth - 1 );
			} else {
				depth++;
				if ( depth > max ) {
					max = depth;
				}
			}
		}
		return max;
	}

	// Strip markup that must never be stored or rendered back.
	function rcmiCellSanitizeHtml( html ) {
		var out = html || '';
		out = out.replace( RCMI_CELL_DROP_TAG_RE, '' );
		out = out.replace( RCMI_CELL_DROP_TAG_RE2, '' );
		out = out.replace( /\s+on[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '' );
		out = out.replace( /(href|src|xlink:href|background|action|formaction)\s*=\s*(["'])\s*(javascript|data|vbscript):[^"']*\2/gi, '$1="#"');
		out = out.replace( /(href|src|xlink:href|background|action|formaction)\s*=\s*(javascript|data|vbscript):[^\s>]*/gi, '$1="#"');
		return out;
	}

	// Give every table that is not inside another table the inner-table class.
	function rcmiCellNormalizeInnerTables( html ) {
		var out = '', cursor = 0, depth = 0, m, tag;
		RCMI_CELL_TABLE_TAG_RE.lastIndex = 0;
		while ( ( m = RCMI_CELL_TABLE_TAG_RE.exec( html ) ) ) {
			tag = m[ 0 ];
			out += html.slice( cursor, m.index );
			cursor = m.index + tag.length;
			if ( /^<\//.test( tag ) ) {
				depth = Math.max( 0, depth - 1 );
				out += tag;
				continue;
			}
			if ( 0 === depth && ! /\bclass\s*=\s*(["'])[^"']*\brcmi-cell-table\b/i.test( tag ) ) {
				if ( /\bclass\s*=\s*"([^"]*)"/i.test( tag ) ) {
					tag = tag.replace( /\bclass\s*=\s*"([^"]*)"/i, function ( f, cls ) {
						return 'class="' + ( cls + ' rcmi-cell-table' ).trim() + '"';
					} );
				} else if ( /\bclass\s*=\s*'([^']*)'/i.test( tag ) ) {
					tag = tag.replace( /\bclass\s*=\s*'([^']*)'/i, function ( f, cls ) {
						return "class='" + ( cls + ' rcmi-cell-table' ).trim() + "'";
					} );
				} else {
					tag = tag.replace( /(\/?)>$/, ' class="rcmi-cell-table"$1>' );
				}
			}
			out += tag;
			depth++;
		}
		out += html.slice( cursor );
		return out;
	}

	// Apply-time normalization for the editor draft. Returns
	// { ok:true, html } or { ok:false, error } so callers can block Apply.
	function rcmiCellNormalizeDraft( html ) {
		var clean = rcmiCellSanitizeHtml( html );
		if ( rcmiCellMaxTableDepth( clean ) > 1 ) {
			return { ok: false, error: 'table-depth' };
		}
		return { ok: true, html: rcmiCellNormalizeInnerTables( clean ) };
	}

	function rcmiCellEscapeHtml( s ) {
		return String( s || '' ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	// rowCount is the TOTAL row count — the header row counts as one.
	function rcmiCellBuildTableHtml( rowCount, colCount, headerRow, caption ) {
		var h = '<table class="rcmi-cell-table">';
		if ( caption ) {
			h += '<caption>' + rcmiCellEscapeHtml( caption ) + '</caption>';
		}
		var bodyRows = headerRow ? Math.max( 0, rowCount - 1 ) : rowCount;
		if ( headerRow ) {
			h += '<thead><tr>';
			for ( var c = 0; c < colCount; c++ ) {
				h += '<th scope="col"><p><br></p></th>';
			}
			h += '</tr></thead>';
		}
		if ( bodyRows > 0 ) {
			h += '<tbody>';
			for ( var r = 0; r < bodyRows; r++ ) {
				h += '<tr>';
				for ( var c2 = 0; c2 < colCount; c2++ ) {
					h += '<td><p><br></p></td>';
				}
				h += '</tr>';
			}
			h += '</tbody>';
		}
		h += '</table><p><br></p>';
		return h;
	}
	// [rcmi-cell-helpers-end]

	// ---------------------------------------------------------------
	// Structured-cell preview: parse untrusted HTML into React elements
	// through a narrow tag/attribute allowlist (no innerHTML injection).
	// ---------------------------------------------------------------

	var PREVIEW_TAGS = {
		p: 1, div: 1, br: 1, ul: 1, ol: 1, li: 1, dl: 1, dt: 1, dd: 1,
		table: 1, thead: 1, tbody: 1, tfoot: 1, tr: 1, th: 1, td: 1, caption: 1,
		span: 1, a: 1, strong: 1, b: 1, em: 1, i: 1, u: 1, s: 1, del: 1, ins: 1,
		code: 1, pre: 1, sub: 1, sup: 1, small: 1, mark: 1, abbr: 1, q: 1, cite: 1,
		blockquote: 1, hr: 1, img: 1, figure: 1, figcaption: 1, wbr: 1
	};
	var PREVIEW_DROP = {
		script: 1, style: 1, iframe: 1, object: 1, embed: 1, form: 1, input: 1,
		button: 1, textarea: 1, select: 1, option: 1, link: 1, meta: 1,
		noscript: 1, template: 1
	};
	var PREVIEW_STYLE_PROPS = {
		'margin-left': 'marginLeft', 'margin-right': 'marginRight',
		'margin-top': 'marginTop', 'margin-bottom': 'marginBottom',
		'padding-left': 'paddingLeft', 'padding-right': 'paddingRight',
		'text-align': 'textAlign', 'vertical-align': 'verticalAlign',
		'font-style': 'fontStyle', 'font-weight': 'fontWeight',
		'font-size': 'fontSize', 'font-family': 'fontFamily',
		'line-height': 'lineHeight', 'letter-spacing': 'letterSpacing',
		'text-decoration': 'textDecoration',
		'color': 'color', 'background-color': 'backgroundColor'
	};
	var PREVIEW_CLASS_RE = /^(rcmi-|wp-|has-|is-|align|mce-|mso-)[a-z0-9_-]*$/i;
	var PREVIEW_SAFE_SCHEME_RE = /^(https?:|mailto:|tel:)$/i;

	function rcmiCellSafeUrl( url ) {
		url = ( url || '' ).trim();
		if ( '' === url ) {
			return '';
		}
		var m = url.match( /^([a-zA-Z][a-zA-Z0-9+.\-]*):/ );
		if ( m && ! PREVIEW_SAFE_SCHEME_RE.test( m[ 1 ] + ':' ) ) {
			return '';
		}
		if ( /[\u0000-\u001F\u007F<>"']/.test( url ) ) {
			return '';
		}
		return url;
	}

	function rcmiCellSafeStyle( styleText ) {
		var out = {};
		( styleText || '' ).split( ';' ).forEach( function ( decl ) {
			var kv = decl.split( ':' );
			if ( kv.length < 2 ) {
				return;
			}
			var prop = kv[ 0 ].trim().toLowerCase();
			var val = kv.slice( 1 ).join( ':' ).trim();
			var key = PREVIEW_STYLE_PROPS[ prop ];
			if ( ! key || ! val ) {
				return;
			}
			if ( /url\s*\(|expression\s*\(|javascript:|vbscript:|@import|-moz-binding/i.test( val ) ) {
				return;
			}
			out[ key ] = val;
		} );
		return out;
	}

	function rcmiCellSafeAttrs( node ) {
		var tag = node.tagName.toLowerCase();
		var props = {};
		var isVoid = ( 'br' === tag || 'hr' === tag || 'img' === tag || 'wbr' === tag );
		for ( var i = 0; i < node.attributes.length; i++ ) {
			var a = node.attributes[ i ];
			var name = a.name.toLowerCase();
			var val = a.value;
			if ( 'on' === name.slice( 0, 2 ) ) {
				continue;
			}
			if ( 'class' === name ) {
				var cls = val.split( /\s+/ ).filter( function ( t ) {
					return PREVIEW_CLASS_RE.test( t );
				} );
				if ( cls.length ) {
					props.className = cls.join( ' ' );
				}
			} else if ( 'style' === name ) {
				var st = rcmiCellSafeStyle( val );
				if ( Object.keys( st ).length ) {
					props.style = st;
				}
			} else if ( 'a' === tag && 'href' === name ) {
				var href = rcmiCellSafeUrl( val );
				if ( href ) {
					props.href = href;
				}
			} else if ( 'a' === tag && 'title' === name ) {
				props.title = val;
			} else if ( 'a' === tag && 'target' === name && '_blank' === val ) {
				props.target = '_blank';
				props.rel = 'noopener noreferrer';
			} else if ( 'img' === tag && 'src' === name ) {
				var src = rcmiCellSafeUrl( val );
				if ( src ) {
					props.src = src;
				}
			} else if ( 'img' === tag && ( 'alt' === name || 'title' === name ) ) {
				props[ name ] = val;
			} else if ( ( 'td' === tag || 'th' === tag ) && ( 'colspan' === name || 'rowspan' === name ) ) {
				var span = parseInt( val, 10 );
				if ( span > 1 && span <= 100 ) {
					props[ 'colspan' === name ? 'colSpan' : 'rowSpan' ] = span;
				}
			} else if ( 'th' === tag && 'scope' === name && /^(col|row|colgroup|rowgroup)$/.test( val ) ) {
				props.scope = val;
			} else if ( 'ol' === tag && 'start' === name ) {
				var start = parseInt( val, 10 );
				if ( start >= 0 ) {
					props.start = start;
				}
			} else if ( 'abbr' === tag && 'title' === name ) {
				props.title = val;
			}
		}
		if ( 'a' === tag && ! props.href ) {
			props.href = '#';
		}
		return { props: props, isVoid: isVoid };
	}

	function rcmiCellInScrollWrapper( node ) {
		var p = node.parentNode;
		return !! ( p && 'DIV' === p.nodeName && /(^|\s)rcmi-cell-table-scroll(\s|$)/.test( p.className || '' ) );
	}

	function rcmiCellNodeToReact( node, key, insideTable ) {
		if ( 3 === node.nodeType ) {
			return node.nodeValue;
		}
		if ( 1 !== node.nodeType ) {
			return null;
		}
		var tag = node.tagName.toLowerCase();
		if ( PREVIEW_DROP[ tag ] ) {
			return null;
		}
		if ( ! PREVIEW_TAGS[ tag ] ) {
			// Unknown wrapper: keep safe children, drop the tag itself.
			return el( Fragment, { key: key }, rcmiCellChildrenToReact( node, insideTable ) );
		}
		var cleaned = rcmiCellSafeAttrs( node );
		cleaned.props.key = key;
		var out = cleaned.isVoid
			? el( tag, cleaned.props )
			: el( tag, cleaned.props, rcmiCellChildrenToReact( node, insideTable || 'table' === tag ) );
		if ( 'table' === tag && ! insideTable && ! rcmiCellInScrollWrapper( node ) ) {
			return el( 'div', {
				key: key + '-scroll',
				className: 'rcmi-cell-table-scroll',
				tabIndex: 0,
				role: 'region',
				'aria-label': __( 'Nested table', 'rcmi-toolkit' )
			}, out );
		}
		return out;
	}

	function rcmiCellChildrenToReact( node, insideTable ) {
		var out = [];
		for ( var i = 0; i < node.childNodes.length; i++ ) {
			var r = rcmiCellNodeToReact( node.childNodes[ i ], 'rcmi-cell-node-' + i, insideTable );
			if ( null === r || undefined === r || false === r || '' === r ) {
				continue;
			}
			out.push( r );
		}
		return out.length ? out : null;
	}

	function rcmiCellRenderPreview( html ) {
		if ( 'undefined' === typeof DOMParser || ! html ) {
			return null;
		}
		var doc = new DOMParser().parseFromString( '<div>' + html + '</div>', 'text/html' );
		return rcmiCellChildrenToReact( doc.body.firstChild, false );
	}

	// ---------------------------------------------------------------
	// Inner-table DOM helpers (operate inside the TinyMCE document).
	// ---------------------------------------------------------------
	// [rcmi-cell-dom-helpers-start]
	// DOM-shape helpers — take element-like objects; extractable for
	// Node tests with stub elements (children/nodeName/parentNode only).

	function rcmiCellClosestInBody( node, body, names ) {
		var n = node;
		while ( n && n !== body && n.parentNode ) {
			if ( names.indexOf( n.nodeName ) !== -1 ) {
				return n;
			}
			n = n.parentNode;
		}
		return null;
	}

	function rcmiCellClosestTable( node, body ) {
		return rcmiCellClosestInBody( node, body, [ 'TABLE' ] );
	}

	function rcmiCellClosestCell( node, body ) {
		return rcmiCellClosestInBody( node, body, [ 'TD', 'TH' ] );
	}

	// Direct rows only — a deeper table inside a cell must not leak its rows
	// into structure math for the table being operated on.
	function rcmiCellTableRows( table ) {
		var rows = [];
		var groups = [ 'THEAD', 'TBODY', 'TFOOT' ];
		for ( var i = 0; i < table.children.length; i++ ) {
			var g = table.children[ i ];
			if ( 'TR' === g.nodeName ) {
				rows.push( g );
			} else if ( groups.indexOf( g.nodeName ) !== -1 ) {
				for ( var j = 0; j < g.children.length; j++ ) {
					if ( 'TR' === g.children[ j ].nodeName ) {
						rows.push( g.children[ j ] );
					}
				}
			}
		}
		return rows;
	}

	function rcmiCellRowCells( row ) {
		return Array.prototype.slice.call( row.children ).filter( function ( c ) {
			return 'TD' === c.nodeName || 'TH' === c.nodeName;
		} );
	}

	function rcmiCellTableCols( table ) {
		var rows = rcmiCellTableRows( table );
		var cols = 0;
		rows.forEach( function ( row ) {
			cols = Math.max( cols, rcmiCellRowCells( row ).length );
		} );
		return cols;
	}

	function rcmiCellTableHasSpans( table ) {
		var cells = table.querySelectorAll( 'td[colspan], td[rowspan], th[colspan], th[rowspan]' );
		for ( var i = 0; i < cells.length; i++ ) {
			if ( parseInt( cells[ i ].getAttribute( 'colspan' ) || '1', 10 ) > 1
				|| parseInt( cells[ i ].getAttribute( 'rowspan' ) || '1', 10 ) > 1 ) {
				return true;
			}
		}
		return false;
	}

	// Ragged tables (rows with different cell counts) can't take rectangular
	// row/column ops safely.
	function rcmiCellTableIsRagged( table ) {
		var rows = rcmiCellTableRows( table );
		var cols = -1;
		for ( var i = 0; i < rows.length; i++ ) {
			var n = rcmiCellRowCells( rows[ i ] ).length;
			if ( cols === -1 ) {
				cols = n;
			} else if ( n !== cols ) {
				return true;
			}
		}
		return false;
	}

	function rcmiCellNewCell( doc, header ) {
		var c = doc.createElement( header ? 'th' : 'td' );
		if ( header ) {
			c.setAttribute( 'scope', 'col' );
		}
		var p = doc.createElement( 'p' );
		p.innerHTML = '<br>';
		c.appendChild( p );
		return c;
	}

	function rcmiCellInsertRow( table, idx ) {
		var rows = rcmiCellTableRows( table );
		if ( ! rows.length ) {
			return;
		}
		var doc = table.ownerDocument;
		var cols = rcmiCellTableCols( table );
		var ref = rows[ idx ] || null;
		var parent = ref ? ref.parentNode : ( rows[ rows.length - 1 ].parentNode );
		var inHead = 'THEAD' === parent.nodeName;
		var tr = doc.createElement( 'tr' );
		for ( var c = 0; c < cols; c++ ) {
			tr.appendChild( rcmiCellNewCell( doc, inHead ) );
		}
		parent.insertBefore( tr, ref );
	}

	function rcmiCellInsertCol( table, idx ) {
		var rows = rcmiCellTableRows( table );
		var doc = table.ownerDocument;
		rows.forEach( function ( row ) {
			var cells = rcmiCellRowCells( row );
			if ( ! cells.length ) {
				return;
			}
			var header = 'THEAD' === row.parentNode.nodeName;
			var ref = cells[ idx ] || null;
			row.insertBefore( rcmiCellNewCell( doc, header ), ref );
		} );
	}

	function rcmiCellDeleteRow( table, idx ) {
		var rows = rcmiCellTableRows( table );
		if ( rows.length <= 1 || ! rows[ idx ] ) {
			return;
		}
		rows[ idx ].parentNode.removeChild( rows[ idx ] );
	}

	function rcmiCellDeleteCol( table, idx ) {
		var rows = rcmiCellTableRows( table );
		if ( rcmiCellTableCols( table ) <= 1 ) {
			return;
		}
		rows.forEach( function ( row ) {
			var cells = rcmiCellRowCells( row );
			if ( cells[ idx ] ) {
				row.removeChild( cells[ idx ] );
			}
		} );
	}

	// Removes the table; only collapses the dedicated scroll wrapper if that
	// is what held it — never an unrelated container.
	function rcmiCellRemoveTable( table ) {
		var parent = table.parentNode;
		parent.removeChild( table );
		if ( 'DIV' === parent.nodeName
			&& /(^|\s)rcmi-cell-table-scroll(\s|$)/.test( parent.className || '' )
			&& 0 === parent.childNodes.length
			&& parent.parentNode ) {
			parent.parentNode.removeChild( parent );
		}
	}
	// [rcmi-cell-dom-helpers-end]

	// ---------------------------------------------------------------
	// Modal: TinyMCE visual editor + nested-table controls.
	// ---------------------------------------------------------------

	var editorSeq = 0;

	function rcmiCellEditorApi() {
		if ( wp.editor && wp.editor.initialize ) {
			return wp.editor;
		}
		if ( wp.oldEditor && wp.oldEditor.initialize ) {
			return wp.oldEditor;
		}
		return null;
	}

	var CellEditorModal = function ( props ) {
		var idRef = useRef( 'rcmi-cell-editor-' + ( ++editorSeq ) );
		var id = idRef.current;
		var editorRef = useRef( null );
		var bookmarkRef = useRef( null );
		var readyState = useState( false );
		var ready = readyState[ 0 ], setReady = readyState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ], setError = errorState[ 1 ];
		var tableState = useState( null );
		var tableInfo = tableState[ 0 ], setTableInfo = tableState[ 1 ];
		var rowsState = useState( 3 );
		var nRows = rowsState[ 0 ], setNRows = rowsState[ 1 ];
		var colsState = useState( 3 );
		var nCols = colsState[ 0 ], setNCols = colsState[ 1 ];
		var headState = useState( true );
		var nHeader = headState[ 0 ], setNHeader = headState[ 1 ];
		var capState = useState( '' );
		var nCaption = capState[ 0 ], setNCaption = capState[ 1 ];

		var analyzeSelection = function () {
			var ed = editorRef.current;
			if ( ! ed || ed.removed ) {
				setTableInfo( null );
				return;
			}
			var node = ed.selection.getNode();
			var table = rcmiCellClosestTable( node, ed.getBody() );
			if ( ! table ) {
				setTableInfo( null );
				return;
			}
			var cell = rcmiCellClosestCell( node, ed.getBody() );
			var rows = rcmiCellTableRows( table );
			var rIdx = cell ? rows.indexOf( cell.parentNode ) : -1;
			var cIdx = cell ? rcmiCellRowCells( cell.parentNode ).indexOf( cell ) : -1;
			setTableInfo( {
				hasSpans: rcmiCellTableHasSpans( table ),
				ragged: rcmiCellTableIsRagged( table ),
				rows: rows.length,
				cols: rcmiCellTableCols( table ),
				r: rIdx,
				c: cIdx
			} );
		};

		useEffect( function () {
			var alive = true;
			var api = rcmiCellEditorApi();
			if ( ! api || ! window.tinymce || ! window.tinymce.get ) {
				setError( __( 'The visual editor could not be loaded.', 'rcmi-toolkit' ) );
				return;
			}
			var l10n = ( window.wpEditorL10n && window.wpEditorL10n.tinymce ) || {};
			var pre = window.tinyMCEPreInit || {};
			var mgr = window.tinymce.EditorManager;
			var prevDefaults = mgr && mgr.defaultSettings;
			var prevBaseURL = mgr && mgr.baseURL;
			var prevSuffix = mgr && mgr.suffix;
			if ( mgr ) {
				mgr.overrideDefaults( {
					base_url: l10n.baseURL || pre.baseURL,
					suffix: undefined !== l10n.suffix ? l10n.suffix : ( pre.suffix || '' )
				} );
			}
			var settings = Object.assign( {}, l10n.settings || {}, {
				wpautop: false,
				menubar: false,
				indent_use_margin: true,
				indentation: '2em',
				toolbar1: 'bold,italic,underline,strikethrough,link,unlink,bullist,numlist,outdent,indent,undo,redo',
				toolbar2: '',
				toolbar3: '',
				toolbar4: '',
				invalid_elements: 'script,style,iframe,object,embed,form,input,button,textarea,select,option,link,meta,noscript,template',
				valid_elements: '@[id|accesskey|class|dir|lang|style|tabindex|title|data-*|aria-label|aria-hidden|aria-describedby],a[href|target|rel|name],p[align],div,span,br,img[src|alt|width|height|border|hspace|vspace|longdesc|usemap],ul[start|type],ol[start|type|reversed],li[value],dl,dt,dd,table[summary|width|cellpadding|cellspacing|border|align|frame|rules],caption[align],thead,tbody,tfoot,tr[align],th[colspan|rowspan|scope|abbr|axis|headers|width|height|nowrap|valign|align|char|charoff],td[colspan|rowspan|headers|width|height|nowrap|valign|align|char|charoff],strong,b,em,i,u,s,strike,del,ins[cite|datetime],code,pre,kbd,samp,var,sub,sup,small,mark,abbr,q[cite],cite,dfn,time[datetime],blockquote[cite],hr,figure,figcaption,wbr,address,bdo[dir],bdi',
				setup: function ( ed ) {
					editorRef.current = ed;
					ed.on( 'init', function () {
						if ( alive ) {
							setReady( true );
							analyzeSelection();
						}
					} );
					ed.on( 'NodeChange', function () {
						if ( alive ) {
							analyzeSelection();
						}
					} );
					ed.on( 'focus', function () {
						window.wpActiveEditor = id;
					} );
					ed.on( 'blur', function () {
						if ( ! alive ) {
							return;
						}
						try {
							bookmarkRef.current = ed.selection.getBookmark( 2, true );
						} catch ( e ) {}
					} );
				}
			} );
			api.initialize( id, { tinymce: settings, quicktags: false } );
			return function () {
				alive = false;
				editorRef.current = null;
				try {
					api.remove( id );
				} catch ( e ) {}
				// overrideDefaults mutates the shared EditorManager — restore
				// so other editors/modals on the page are unaffected.
				if ( mgr ) {
					if ( prevDefaults ) {
						mgr.defaultSettings = prevDefaults;
					}
					if ( prevBaseURL ) {
						mgr.baseURL = prevBaseURL;
					}
					if ( undefined !== prevSuffix ) {
						mgr.suffix = prevSuffix;
					}
				}
			};
			// eslint-disable-next-line react-hooks/exhaustive-deps
		}, [] );

		// Re-derive the selected inner table each render so the ref stays
		// usable after ops mutate the DOM outside React.
		var currentEditor = function () {
			var ed = editorRef.current;
			return ( ed && ! ed.removed ) ? ed : null;
		};

		var restoreSelection = function ( ed ) {
			if ( bookmarkRef.current ) {
				ed.focus();
				try {
					ed.selection.moveToBookmark( bookmarkRef.current );
				} catch ( e ) {}
			}
		};

		var currentInnerTable = function ( ed ) {
			restoreSelection( ed );
			var table = rcmiCellClosestTable( ed.selection.getNode(), ed.getBody() );
			return table && table.parentNode ? table : null;
		};

		var runTableOp = function ( fn ) {
			var ed = currentEditor();
			if ( ! ed ) {
				return;
			}
			ed.undoManager.transact( function () {
				fn( ed );
			} );
			ed.nodeChanged();
			analyzeSelection();
		};

		var onInsertTable = function () {
			var ed = currentEditor();
			if ( ! ed || tableInfo ) {
				return;
			}
			restoreSelection( ed );
			if ( rcmiCellClosestTable( ed.selection.getNode(), ed.getBody() ) ) {
				return;
			}
			var html = rcmiCellBuildTableHtml(
				Math.min( 10, Math.max( 1, parseInt( nRows, 10 ) || 1 ) ),
				Math.min( 10, Math.max( 1, parseInt( nCols, 10 ) || 1 ) ),
				nHeader,
				( nCaption || '' ).trim()
			);
			ed.execCommand( 'mceInsertContent', false, html );
			bookmarkRef.current = null;
			analyzeSelection();
		};

		var onTableOp = function ( kind ) {
			runTableOp( function ( ed ) {
				var table = currentInnerTable( ed );
				if ( ! table ) {
					return;
				}
				var info = tableInfo || {};
				var r = info.r < 0 ? 0 : ( info.r || 0 );
				var c = info.c < 0 ? 0 : ( info.c || 0 );
				if ( 'row-before' === kind ) {
					rcmiCellInsertRow( table, r );
				} else if ( 'row-after' === kind ) {
					rcmiCellInsertRow( table, r + 1 );
				} else if ( 'col-before' === kind ) {
					rcmiCellInsertCol( table, c );
				} else if ( 'col-after' === kind ) {
					rcmiCellInsertCol( table, c + 1 );
				} else if ( 'del-row' === kind ) {
					rcmiCellDeleteRow( table, r );
				} else if ( 'del-col' === kind ) {
					rcmiCellDeleteCol( table, c );
				} else if ( 'remove-table' === kind ) {
					rcmiCellRemoveTable( table );
				}
			} );
		};

		var onApply = function () {
			// Never fall back to an empty string: if the editor did not
			// initialize there is nothing safe to write into the cell.
			var ed = currentEditor();
			if ( ! ed || ! ed.initialized ) {
				setError( __( 'The visual editor is not ready.', 'rcmi-toolkit' ) );
				return;
			}
			var res = rcmiCellNormalizeDraft( ed.getContent() );
			if ( ! res.ok ) {
				setError( __( 'Only one inner-table level is supported. Remove the deeper table before applying.', 'rcmi-toolkit' ) );
				return;
			}
			props.onApply( res.html );
		};

		var numField = function ( label, value, onChange ) {
			if ( NumberControl ) {
				return el( NumberControl, {
					label: label,
					value: value,
					min: 1,
					max: 10,
					onChange: function ( v ) { onChange( Math.min( 10, Math.max( 1, parseInt( v, 10 ) || 1 ) ) ); }
				} );
			}
			return el( TextControl, {
				label: label,
				type: 'number',
				min: 1,
				max: 10,
				value: value,
				onChange: function ( v ) { onChange( Math.min( 10, Math.max( 1, parseInt( v, 10 ) || 1 ) ) ); }
			} );
		};

		var structureDisabled = ! ready || ! tableInfo || tableInfo.hasSpans || tableInfo.ragged;

		return el( Modal, {
			title: __( 'Edit cell content', 'rcmi-toolkit' ),
			className: 'rcmi-cell-editor-modal',
			onRequestClose: props.onClose,
			shouldCloseOnClickOutside: false
		},
			el( 'div', { className: 'rcmi-cell-editor-body' },
				el( 'textarea', {
					id: id,
					className: 'rcmi-cell-editor-textarea',
					defaultValue: props.content || ''
				} ),
				error ? el( Notice, {
					status: 'error',
					isDismissible: false,
					className: 'rcmi-cell-editor-error'
				}, error ) : null,
				el( 'div', { className: 'rcmi-cell-table-controls' },
					el( 'h3', null, __( 'Nested table', 'rcmi-toolkit' ) ),
					tableInfo && ( tableInfo.hasSpans || tableInfo.ragged ) ? el( 'p', { className: 'rcmi-cell-table-note' },
						__( 'This table has merged cells or uneven rows. Row and column changes are disabled; you can still edit text or remove the table.', 'rcmi-toolkit' )
					) : null,
					tableInfo ? el( 'div', { className: 'rcmi-cell-table-row' },
						el( Button, { variant: 'secondary', onClick: function () { onTableOp( 'row-before' ); }, disabled: structureDisabled }, __( 'Row before', 'rcmi-toolkit' ) ),
						el( Button, { variant: 'secondary', onClick: function () { onTableOp( 'row-after' ); }, disabled: structureDisabled }, __( 'Row after', 'rcmi-toolkit' ) ),
						el( Button, { variant: 'secondary', onClick: function () { onTableOp( 'col-before' ); }, disabled: structureDisabled }, __( 'Column before', 'rcmi-toolkit' ) ),
						el( Button, { variant: 'secondary', onClick: function () { onTableOp( 'col-after' ); }, disabled: structureDisabled }, __( 'Column after', 'rcmi-toolkit' ) ),
						el( Button, { variant: 'secondary', onClick: function () { onTableOp( 'del-row' ); }, disabled: structureDisabled || tableInfo.rows <= 1 }, __( 'Delete row', 'rcmi-toolkit' ) ),
						el( Button, { variant: 'secondary', onClick: function () { onTableOp( 'del-col' ); }, disabled: structureDisabled || tableInfo.cols <= 1 }, __( 'Delete column', 'rcmi-toolkit' ) ),
						el( Button, { variant: 'secondary', isDestructive: true, onClick: function () { onTableOp( 'remove-table' ); }, disabled: ! ready }, __( 'Remove table', 'rcmi-toolkit' ) )
					) : null,
					el( 'div', { className: 'rcmi-cell-table-insert' },
						numField( __( 'Rows', 'rcmi-toolkit' ), nRows, setNRows ),
						numField( __( 'Columns', 'rcmi-toolkit' ), nCols, setNCols ),
						el( ToggleControl, {
							label: __( 'First row is a header', 'rcmi-toolkit' ),
							checked: nHeader,
							onChange: setNHeader
						} ),
						el( TextControl, {
							label: __( 'Caption (optional)', 'rcmi-toolkit' ),
							value: nCaption,
							onChange: setNCaption
						} ),
						el( Button, {
							variant: 'primary',
							onClick: onInsertTable,
							disabled: ! ready || !! tableInfo
						}, __( 'Insert table at caret', 'rcmi-toolkit' ) ),
						tableInfo ? el( 'p', { className: 'rcmi-cell-table-note' },
							__( 'Move the caret outside this inner table to insert another. Only one inner-table level is supported.', 'rcmi-toolkit' )
						) : null
					)
				),
				el( 'div', { className: 'rcmi-cell-editor-actions' },
					el( Button, { variant: 'tertiary', onClick: props.onClose }, __( 'Cancel', 'rcmi-toolkit' ) ),
					el( Button, { variant: 'primary', onClick: onApply, disabled: ! ready }, __( 'Apply', 'rcmi-toolkit' ) )
				)
			)
		);
	};

	window.RcmiTableCellEditor = {
		Component: CellEditorModal,
		isStructured: rcmiCellIsStructured,
		renderPreview: rcmiCellRenderPreview
	};
} )( window.wp );
