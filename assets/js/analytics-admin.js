( function () {
	'use strict';

	var dataEl = document.getElementById( 'rcmi-analytics-report-data' );
	var canvas = document.getElementById( 'rcmi-analytics-chart' );
	var exportBtn = document.getElementById( 'rcmi-analytics-export-pdf' );
	var statusEl = document.getElementById( 'rcmi-analytics-export-status' );

	function setStatus( text ) {
		if ( statusEl ) {
			statusEl.textContent = text;
		}
	}

	var rangeSelect = document.getElementById( 'rcmi-analytics-range' );
	var datesWrap   = document.getElementById( 'rcmi-analytics-dates' );
	var fromInput   = document.getElementById( 'rcmi-analytics-from' );
	var toInput     = document.getElementById( 'rcmi-analytics-to' );

	function syncCustomDates() {
		if ( datesWrap ) {
			datesWrap.classList.toggle( 'is-hidden', ! rangeSelect || 'custom' !== rangeSelect.value );
		}
	}

	if ( rangeSelect && datesWrap ) {
		rangeSelect.addEventListener( 'change', syncCustomDates );
		[ fromInput, toInput ].forEach( function ( input ) {
			if ( input ) {
				input.addEventListener( 'change', function () {
					if ( input.value ) {
						rangeSelect.value = 'custom';
					}
					syncCustomDates();
				} );
			}
		} );
		syncCustomDates();
	}

	var rolePicker = document.querySelector( '.rcmi-analytics-roles' );
	if ( rolePicker ) {
		var roleCount = rolePicker.querySelector( '.rcmi-role-count' );
		rolePicker.addEventListener( 'change', function () {
			var n = rolePicker.querySelectorAll( 'input[name="roles[]"]:checked' ).length;
			roleCount.textContent = n ? String( n ) : 'All';
		} );
	}

	if ( ! dataEl || ! canvas || ! exportBtn ) {
		setStatus( 'Report data unavailable.' );
		return;
	}

	var report;
	try {
		report = JSON.parse( dataEl.textContent || '{}' );
	} catch ( e ) {
		setStatus( 'Report data could not be read.' );
		return;
	}

	if ( 'undefined' === typeof window.Chart ) {
		setStatus( 'Chart library unavailable.' );
		return;
	}

	function makeChartConfig( responsive ) {
		return {
			type: 'line',
			data: {
				labels: report.chart.labels,
				datasets: [
					{
						label: 'Page views',
						data: report.chart.views,
						borderColor: '#C8102E',
						backgroundColor: 'rgba(200,16,46,0.10)',
						fill: true,
						borderWidth: 2,
						pointStyle: 'circle',
						pointRadius: 3,
						tension: 0
					},
					{
						label: 'Visitor-days',
						data: report.chart.visitorDays,
						borderColor: '#007a66',
						borderDash: [ 6, 4 ],
						fill: false,
						borderWidth: 2,
						pointStyle: 'rectRot',
						pointRadius: 3,
						tension: 0
					}
				]
			},
			options: {
				responsive: responsive,
				maintainAspectRatio: false,
				animation: false,
				interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: true },
					tooltip: {
						callbacks: {
							title: function ( items ) {
								var index = items && items.length ? items[0].dataIndex : 0;
								return report.chart.fullLabels[ index ] || '';
							}
						}
					}
				},
				scales: {
					x: {
						grid: { display: false },
						ticks: { autoSkip: true, maxRotation: 0 }
					},
					y: {
						beginAtZero: true,
						ticks: { precision: 0 }
					}
				}
			}
		};
	}

	var chart = new window.Chart( canvas.getContext( '2d' ), makeChartConfig( true ) );

	var piePalette = [ '#C8102E', '#007A66', '#00B388', '#264653', '#E76F51', '#F4A261', '#54585A', '#6BA4B8', '#8E5EA2', '#D9B310' ];
	var pieConfig = [
		{ id: 'rcmi-pie-browsers', key: 'browsers' },
		{ id: 'rcmi-pie-devices', key: 'devices' },
		{ id: 'rcmi-pie-os', key: 'os' }
	];
	if ( report.pie ) {
		pieConfig.forEach( function ( cfg ) {
			var el = document.getElementById( cfg.id );
			var data = report.pie[ cfg.key ];
			if ( ! el || ! data || ! data.length ) {
				return;
			}
			var total = data.reduce( function ( n, r ) { return n + r.value; }, 0 );
			new window.Chart( el.getContext( '2d' ), {
				type: 'doughnut',
				data: {
					labels: data.map( function ( r ) { return r.label; } ),
					datasets: [ {
						data: data.map( function ( r ) { return r.value; } ),
						backgroundColor: data.map( function ( r, i ) { return piePalette[ i % piePalette.length ]; } ),
						borderWidth: 1
					} ]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					animation: false,
					plugins: {
						legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
						tooltip: {
							callbacks: {
								label: function ( item ) {
									var v = item.parsed;
									var pct = total ? Math.round( ( v / total ) * 1000 ) / 10 : 0;
									return ' ' + item.label + ': ' + v + ' (' + pct + '%)';
								}
							}
						}
					}
				}
			} );
		} );
	}

	if ( ! window.jspdf || ! window.jspdf.jsPDF ) {
		setStatus( 'PDF export unavailable.' );
		return;
	}
	exportBtn.disabled = false;

	// Fixed-size offscreen chart so the PDF image is stable and sharp
	// regardless of the browser viewport.
	function pdfChartImage() {
		var pdfCanvas = document.createElement( 'canvas' );
		pdfCanvas.width = 1200;
		pdfCanvas.height = 420;
		var pdfChart = new window.Chart( pdfCanvas.getContext( '2d' ), makeChartConfig( false ) );
		pdfChart.resize( 1200, 420 );
		pdfChart.update( 'none' );
		var image = pdfChart.toBase64Image();
		pdfChart.destroy();
		return image;
	}

	function safeText( value ) {
		var text = String( null === value || 'undefined' === typeof value ? '' : value );
		text = text.replace( /[\x00-\x1F\x7F]/g, ' ' ).trim();
		if ( text.length > 500 ) {
			text = text.substring( 0, 500 );
		}
		return text;
	}

	function isoDate( d ) {
		var m = d.getMonth() + 1;
		var day = d.getDate();
		return d.getFullYear() + '-' + ( m < 10 ? '0' + m : m ) + '-' + ( day < 10 ? '0' + day : day );
	}

	function pdfPieImage( data ) {
		var pieCanvas = document.createElement( 'canvas' );
		pieCanvas.width = 480;
		pieCanvas.height = 480;
		var pieChart = new window.Chart( pieCanvas.getContext( '2d' ), {
			type: 'doughnut',
			data: {
				labels: data.map( function ( r ) { return r.label; } ),
				datasets: [ {
					data: data.map( function ( r ) { return r.value; } ),
					backgroundColor: data.map( function ( r, i ) { return piePalette[ i % piePalette.length ]; } ),
					borderColor: '#ffffff',
					borderWidth: 2
				} ]
			},
			options: {
				responsive: false,
				animation: false,
				plugins: { legend: { display: false }, tooltip: { enabled: false } }
			}
		} );
		pieChart.update( 'none' );
		var image = pieChart.toBase64Image();
		pieChart.destroy();
		return image;
	}

	function generatePdf() {
		try {
			var doc = new window.jspdf.jsPDF( { unit: 'pt', format: 'letter', orientation: 'portrait' } );
			var pageW = doc.internal.pageSize.getWidth();
			var pageH = doc.internal.pageSize.getHeight();
			var margin = 48;
			var contentW = pageW - margin * 2;
			var y = margin + 4;
			var i, j;
			var RED = [ 200, 16, 46 ], TEAL = [ 0, 122, 102 ], DARK = [ 29, 35, 39 ],
				GRAY = [ 100, 105, 112 ], LIGHT = [ 246, 247, 247 ], LINE = [ 224, 224, 224 ];

			function ensureSpace( needed ) {
				if ( y + needed > pageH - margin ) {
					doc.addPage();
					y = margin;
				}
			}

			function hairline( yy ) {
				doc.setDrawColor( LINE[ 0 ], LINE[ 1 ], LINE[ 2 ] );
				doc.setLineWidth( 0.6 );
				doc.line( margin, yy, pageW - margin, yy );
			}

			function sectionHeading( text ) {
				doc.setFillColor( RED[ 0 ], RED[ 1 ], RED[ 2 ] );
				doc.rect( margin, y - 9, 3, 11, 'F' );
				doc.setFont( 'helvetica', 'bold' );
				doc.setFontSize( 12 );
				doc.setTextColor( DARK[ 0 ], DARK[ 1 ], DARK[ 2 ] );
				doc.text( safeText( text ), margin + 9, y );
				y += 16;
			}

			// Header: brand rule, title, meta box.
			doc.setFillColor( RED[ 0 ], RED[ 1 ], RED[ 2 ] );
			doc.rect( 0, 0, pageW, 6, 'F' );
			doc.setFont( 'helvetica', 'bold' );
			doc.setFontSize( 18 );
			doc.setTextColor( DARK[ 0 ], DARK[ 1 ], DARK[ 2 ] );
			doc.text( safeText( report.title ), margin, y );
			y += 14;

			doc.setFillColor( LIGHT[ 0 ], LIGHT[ 1 ], LIGHT[ 2 ] );
			doc.setDrawColor( LINE[ 0 ], LINE[ 1 ], LINE[ 2 ] );
			doc.roundedRect( margin, y, contentW, 34, 3, 3, 'FD' );
			doc.setFont( 'helvetica', 'normal' );
			doc.setFontSize( 9 );
			doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
			doc.text( safeText( report.range + '  ·  ' + report.rangeLabel + '  ·  ' + report.traffic ), margin + 10, y + 14 );
			doc.text( safeText( 'Timezone: ' + report.timezone + '   Generated: ' + report.generatedAt ), margin + 10, y + 26 );
			y += 34 + 20;

			function drawKpiGroup( title, items, accent, cols ) {
				if ( ! items || ! items.length ) {
					return;
				}
				ensureSpace( 22 );
				doc.setFont( 'helvetica', 'bold' );
				doc.setFontSize( 9 );
				doc.setCharSpace( 0.6 );
				doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
				doc.text( safeText( title ).toUpperCase(), margin, y );
				doc.setCharSpace( 0 );
				y += 8;
				var gap = 10;
				var cardW = ( contentW - ( cols - 1 ) * gap ) / cols;
				var cardH = 62;
				var rows = Math.ceil( items.length / cols );
				ensureSpace( rows * ( cardH + gap ) );
				for ( i = 0; i < items.length; i++ ) {
					var x = margin + ( i % cols ) * ( cardW + gap );
					var yy = y + Math.floor( i / cols ) * ( cardH + gap );
					doc.setDrawColor( LINE[ 0 ], LINE[ 1 ], LINE[ 2 ] );
					doc.setFillColor( LIGHT[ 0 ], LIGHT[ 1 ], LIGHT[ 2 ] );
					doc.roundedRect( x, yy, cardW, cardH, 3, 3, 'FD' );
					doc.setFillColor( accent[ 0 ], accent[ 1 ], accent[ 2 ] );
					doc.rect( x, yy, 3, cardH, 'F' );
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 7 );
					doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
					var labelLines = doc.splitTextToSize( safeText( items[ i ].label ).toUpperCase(), cardW - 18 ).slice( 0, 2 );
					doc.text( labelLines, x + 11, yy + 14 );
					doc.setFont( 'helvetica', 'bold' );
					doc.setFontSize( 16 );
					doc.setTextColor( DARK[ 0 ], DARK[ 1 ], DARK[ 2 ] );
					doc.text( safeText( items[ i ].value ), x + 11, yy + 14 + labelLines.length * 8 + 12 );
					var detailLines = doc.splitTextToSize( safeText( items[ i ].detail ), cardW - 18 ).slice( 0, 2 );
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 8 );
					doc.setTextColor( TEAL[ 0 ], TEAL[ 1 ], TEAL[ 2 ] );
					for ( j = 0; j < detailLines.length; j++ ) {
						doc.text( detailLines[ j ], x + 11, yy + cardH - 7 - ( detailLines.length - 1 - j ) * 9 );
					}
				}
				y += rows * ( cardH + gap ) + 6;
			}

			drawKpiGroup( 'Traffic', report.metrics, RED, 4 );
			drawKpiGroup( 'Interactions', report.interactions, TEAL, 2 );

			// Trend chart image (fixed 1200×420 render, aspect preserved).
			var imgH = contentW * 420 / 1200;
			ensureSpace( imgH + 34 );
			sectionHeading( 'Traffic trend · ' + report.range );
			doc.addImage( pdfChartImage(), 'PNG', margin, y, contentW, imgH );
			y += imgH + 24;

			// Report sections as ruled tables; the technology trio renders as
			// doughnuts below instead when pie data is present.
			var techTitles = { 'Browsers': 1, 'Devices': 1, 'Operating systems': 1 };
			var sections = report.sections || [];
			for ( i = 0; i < sections.length; i++ ) {
				var section = sections[ i ];
				if ( report.pie && techTitles[ section.title ] ) {
					continue;
				}
				ensureSpace( 52 );
				sectionHeading( section.title );
				var rows = section.rows || [];
				if ( ! rows.length ) {
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 10 );
					doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
					doc.text( 'No data.', margin, y );
					y += 20;
					continue;
				}
				doc.setFont( 'helvetica', 'normal' );
				doc.setFontSize( 7 );
				doc.setCharSpace( 0.5 );
				doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
				doc.text( 'ITEM', margin + 2, y );
				doc.text( 'COUNT', pageW - margin - 2, y, { align: 'right' } );
				doc.setCharSpace( 0 );
				y += 5;
				hairline( y );
				y += 10;
				for ( j = 0; j < rows.length; j++ ) {
					var labelLines = doc.splitTextToSize( safeText( rows[ j ].label ), contentW - 84 );
					var detailText = safeText( rows[ j ].detail );
					var detailLines = detailText ? doc.splitTextToSize( detailText, contentW - 96 ) : [];
					var rowH = labelLines.length * 12 + detailLines.length * 10 + 12;
					ensureSpace( rowH );
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 10 );
					doc.setTextColor( DARK[ 0 ], DARK[ 1 ], DARK[ 2 ] );
					doc.text( labelLines, margin + 2, y );
					doc.setFont( 'helvetica', 'bold' );
					doc.text( safeText( rows[ j ].count ), pageW - margin - 2, y, { align: 'right' } );
					y += labelLines.length * 12;
					if ( detailLines.length ) {
						doc.setFont( 'helvetica', 'normal' );
						doc.setFontSize( 8 );
						doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
						doc.text( detailLines, margin + 12, y );
						y += detailLines.length * 10;
					}
					y += 5;
					doc.setDrawColor( 238, 238, 238 );
					doc.setLineWidth( 0.4 );
					doc.line( margin + 2, y, pageW - margin - 2, y );
					y += 7;
				}
				y += 12;
			}

			if ( report.pie ) {
				var pieDefs = [
					{ key: 'browsers', label: 'Browsers' },
					{ key: 'devices', label: 'Devices' },
					{ key: 'os', label: 'Operating systems' }
				];
				var colGap = 16;
				var colW = ( contentW - colGap * 2 ) / 3;
				var pieW = colW * 0.78;
				var colHeights = pieDefs.map( function ( def ) {
					var data = report.pie[ def.key ] || [];
					var h = 16 + pieW + 10;
					data.forEach( function ( r ) {
						h += doc.splitTextToSize( safeText( r.label ), colW - 16 ).length * 10 + 2;
					} );
					return h;
				} );
				var blockH = Math.max.apply( null, colHeights.concat( [ 60 ] ) );
				ensureSpace( blockH + 20 );
				sectionHeading( 'Technology' );
				for ( i = 0; i < pieDefs.length; i++ ) {
					var x = margin + i * ( colW + colGap );
					var data = report.pie[ pieDefs[ i ].key ] || [];
					var yy = y;
					doc.setFont( 'helvetica', 'bold' );
					doc.setFontSize( 9 );
					doc.setTextColor( DARK[ 0 ], DARK[ 1 ], DARK[ 2 ] );
					doc.text( pieDefs[ i ].label, x + colW / 2, yy, { align: 'center' } );
					yy += 8;
					if ( ! data.length ) {
						doc.setFont( 'helvetica', 'normal' );
						doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
						doc.text( 'No data.', x + colW / 2, yy + 20, { align: 'center' } );
						continue;
					}
					doc.addImage( pdfPieImage( data ), 'PNG', x + ( colW - pieW ) / 2, yy, pieW, pieW );
					yy += pieW + 10;
					var total = data.reduce( function ( n, r ) { return n + r.value; }, 0 );
					for ( j = 0; j < data.length; j++ ) {
						var pct = total ? Math.round( ( data[ j ].value / total ) * 1000 ) / 10 : 0;
						var line = safeText( data[ j ].label ) + ' — ' + data[ j ].value + ' (' + pct + '%)';
						var lines = doc.splitTextToSize( line, colW - 16 );
						doc.setFillColor.apply( doc, piePalette[ j % piePalette.length ].match( /\w\w/g ).map( function ( h ) { return parseInt( h, 16 ); } ) );
						doc.circle( x + 3, yy - 2.6, 2.4, 'F' );
						doc.setFont( 'helvetica', 'normal' );
						doc.setFontSize( 8 );
						doc.setTextColor( DARK[ 0 ], DARK[ 1 ], DARK[ 2 ] );
						doc.text( lines, x + 12, yy );
						yy += lines.length * 10 + 2;
					}
				}
				y += blockH + 8;
			}

			// Footer on every page.
			var pageCount = doc.internal.getNumberOfPages();
			for ( i = 1; i <= pageCount; i++ ) {
				doc.setPage( i );
				doc.setDrawColor( LINE[ 0 ], LINE[ 1 ], LINE[ 2 ] );
				doc.setLineWidth( 0.6 );
				doc.line( margin, pageH - 32, pageW - margin, pageH - 32 );
				doc.setFont( 'helvetica', 'normal' );
				doc.setFontSize( 8 );
				doc.setTextColor( GRAY[ 0 ], GRAY[ 1 ], GRAY[ 2 ] );
				doc.text( safeText( report.title ), margin, pageH - 20 );
				doc.text( 'Page ' + i + ' of ' + pageCount, pageW - margin, pageH - 20, { align: 'right' } );
			}

			var fullLabels = report.chart.fullLabels || [];
			var endDate = fullLabels.length ? String( fullLabels[ fullLabels.length - 1 ] ).slice( -10 ) : '';
			if ( ! /^\d{4}-\d{2}-\d{2}$/.test( endDate ) ) {
				endDate = isoDate( new Date() );
			}
			doc.save( 'rcmi-analytics-' + endDate + '.pdf' );
			setStatus( 'PDF downloaded.' );
		} catch ( e ) {
			setStatus( 'PDF export failed. Please try again.' );
		}
		exportBtn.disabled = false;
	}

	// Let the status text paint before the synchronous jsPDF work starts.
	function requestPdf() {
		exportBtn.disabled = true;
		setStatus( 'Creating PDF…' );
		window.setTimeout( generatePdf, 0 );
	}
	exportBtn.addEventListener( 'click', requestPdf );
} )();
