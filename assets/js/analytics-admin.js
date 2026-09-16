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

	function generatePdf() {
		try {
			var doc = new window.jspdf.jsPDF( { unit: 'pt', format: 'letter', orientation: 'portrait' } );
			var pageW = doc.internal.pageSize.getWidth();
			var pageH = doc.internal.pageSize.getHeight();
			var margin = 48;
			var contentW = pageW - margin * 2;
			var y = margin + 8;
			var i, j;

			function ensureSpace( needed ) {
				if ( y + needed > pageH - margin ) {
					doc.addPage();
					y = margin;
				}
			}

			// Brand rule + title + report meta.
			doc.setFillColor( 200, 16, 46 );
			doc.rect( 0, 0, pageW, 6, 'F' );
			doc.setFont( 'helvetica', 'bold' );
			doc.setFontSize( 18 );
			doc.setTextColor( 29, 35, 39 );
			doc.text( safeText( report.title ), margin, y );
			y += 18;
			doc.setFont( 'helvetica', 'normal' );
			doc.setFontSize( 10 );
			doc.setTextColor( 85, 85, 85 );
			doc.text(
				safeText( 'Range: ' + report.range + ' (' + report.rangeLabel + ')  ·  Traffic: ' + report.traffic ),
				margin,
				y
			);
			y += 14;
			doc.text(
				safeText( 'Timezone: ' + report.timezone + '  ·  Generated: ' + report.generatedAt ),
				margin,
				y
			);
			y += 24;

			function drawKpiCards( items ) {
				var cols = 2;
				var gap = 12;
				var cardW = ( contentW - gap ) / cols;
				var cardH = 72;
				var rows = Math.ceil( items.length / cols );
				ensureSpace( rows * ( cardH + gap ) );
				for ( i = 0; i < items.length; i++ ) {
					var x = margin + ( i % cols ) * ( cardW + gap );
					var yy = y + Math.floor( i / cols ) * ( cardH + gap );
					doc.setDrawColor( 224, 224, 224 );
					doc.setFillColor( 250, 250, 250 );
					doc.rect( x, yy, cardW, cardH, 'FD' );
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 9 );
					doc.setTextColor( 85, 85, 85 );
					doc.text( safeText( items[ i ].label ).toUpperCase(), x + 10, yy + 16 );
					doc.setFont( 'helvetica', 'bold' );
					doc.setFontSize( 18 );
					doc.setTextColor( 29, 35, 39 );
					doc.text( safeText( items[ i ].value ), x + 10, yy + 38 );
					var detailLines = doc.splitTextToSize( safeText( items[ i ].detail ), cardW - 20 ).slice( 0, 2 );
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 9 );
					doc.setTextColor( 0, 122, 102 );
					for ( j = 0; j < detailLines.length; j++ ) {
						doc.text( detailLines[ j ], x + 10, yy + cardH - 8 - ( detailLines.length - 1 - j ) * 10 );
					}
				}
				y += rows * ( cardH + gap );
			}

			drawKpiCards( report.metrics || [] );
			drawKpiCards( report.interactions || [] );

			// Trend chart image (fixed 1200×420 render, aspect preserved).
			var imgH = contentW * 420 / 1200;
			ensureSpace( imgH + 30 );
			doc.setFont( 'helvetica', 'bold' );
			doc.setFontSize( 12 );
			doc.setTextColor( 29, 35, 39 );
			doc.text( safeText( 'Traffic trend · ' + report.range ), margin, y );
			y += 10;
			doc.addImage( pdfChartImage(), 'PNG', margin, y, contentW, imgH );
			y += imgH + 20;

			// Report sections.
			var sections = report.sections || [];
			for ( i = 0; i < sections.length; i++ ) {
				var section = sections[ i ];
				ensureSpace( 34 );
				doc.setFont( 'helvetica', 'bold' );
				doc.setFontSize( 12 );
				doc.setTextColor( 29, 35, 39 );
				doc.text( safeText( section.title ), margin, y );
				y += 16;
				var rows = section.rows || [];
				if ( ! rows.length ) {
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 10 );
					doc.setTextColor( 119, 119, 119 );
					doc.text( 'No data.', margin, y );
					y += 18;
					continue;
				}
				for ( j = 0; j < rows.length; j++ ) {
					var labelLines = doc.splitTextToSize( safeText( rows[ j ].label ), contentW - 70 );
					var detailText = safeText( rows[ j ].detail );
					var detailLines = detailText ? doc.splitTextToSize( detailText, contentW - 70 ) : [];
					ensureSpace( labelLines.length * 12 + detailLines.length * 11 + 6 );
					doc.setFont( 'helvetica', 'normal' );
					doc.setFontSize( 10 );
					doc.setTextColor( 29, 35, 39 );
					doc.text( labelLines, margin, y );
					doc.text( safeText( rows[ j ].count ), pageW - margin, y, { align: 'right' } );
					y += labelLines.length * 12;
					if ( detailLines.length ) {
						doc.setFontSize( 9 );
						doc.setTextColor( 100, 105, 112 );
						doc.text( detailLines, margin, y );
						y += detailLines.length * 11;
					}
					y += 6;
				}
				y += 12;
			}

			// Footer on every page.
			var pageCount = doc.internal.getNumberOfPages();
			for ( i = 1; i <= pageCount; i++ ) {
				doc.setPage( i );
				doc.setFont( 'helvetica', 'normal' );
				doc.setFontSize( 9 );
				doc.setTextColor( 119, 119, 119 );
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
