( function () {
	'use strict';

	if ( 'undefined' === typeof window.rcmiAnalyticsEvents ) {
		return;
	}
	var config = window.rcmiAnalyticsEvents;
	if ( ! config.endpoint || ( ! config.trackCtas && ! config.trackDownloads ) ) {
		return;
	}

	var DOWNLOAD_EXTENSIONS = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'txt', 'rtf' ];
	var CTA_SELECTOR = 'a[data-rcmi-analytics="cta"], a.btn, a.wp-block-button__link, a.wp-block-spectra-button, a.uagb-buttons-repeater, a.role-card';
	var IGNORE_SELECTOR = '#wpadminbar, #rcmi-tickets-app, [data-rcmi-analytics="ignore"]';

	function getLabel( link ) {
		var label = link.getAttribute( 'data-rcmi-analytics-label' ) || link.getAttribute( 'aria-label' ) || '';
		if ( ! label ) {
			var heading = link.querySelector( '.role-title,h1,h2,h3,h4' );
			if ( heading ) {
				label = heading.textContent || '';
			}
		}
		if ( ! label ) {
			label = link.textContent || '';
		}
		label = label.replace( /\s+/g, ' ' ).trim();
		if ( label.length > 160 ) {
			label = label.substring( 0, 160 );
		}
		return label;
	}

	function isDownload( link, url ) {
		if ( link.hasAttribute( 'download' ) ) {
			return true;
		}
		var match = url.pathname.toLowerCase().match( /\.([a-z0-9]+)$/ );
		return null !== match && -1 !== DOWNLOAD_EXTENSIONS.indexOf( match[1] );
	}

	function send( eventType, label, url ) {
		var params = new URLSearchParams();
		params.append( 'event_type', eventType );
		params.append( 'event_label', label );
		params.append( 'target_url', url.href );
		params.append( 'source_path', window.location.pathname );
		params.append( 'page_type', config.pageType || 'other' );
		params.append( 'object_id', String( config.objectId || 0 ) );
		params.append( 'referrer', document.referrer || '' );

		var sent = false;
		if ( navigator.sendBeacon ) {
			sent = navigator.sendBeacon( config.endpoint, params );
		}
		if ( ! sent && window.fetch ) {
			window.fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				body: params
			} ).catch( function () {} );
		}
	}

	function onClick( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		var link = target.closest( 'a[href]' );
		if ( ! link ) {
			return;
		}
		if ( link.closest( IGNORE_SELECTOR ) ) {
			return;
		}
		var rawHref = ( link.getAttribute( 'href' ) || '' ).trim();
		if ( '#' === rawHref ) {
			return;
		}
		var url;
		try {
			url = new URL( link.href, window.location.href );
		} catch ( e ) {
			return;
		}
		if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
			return;
		}

		var eventType = null;
		if ( config.trackDownloads && isDownload( link, url ) ) {
			eventType = 'resource_download';
		} else if ( config.trackCtas && link.matches( CTA_SELECTOR ) ) {
			eventType = 'cta_click';
		}
		if ( ! eventType ) {
			return;
		}
		send( eventType, getLabel( link ), url );
	}

	document.addEventListener( 'click', onClick );
} )();
