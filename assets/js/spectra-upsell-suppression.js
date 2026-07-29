/**
 * Spectra upsell suppression — JavaScript.
 *
 * Hides elements by text content that can't be targeted by CSS alone:
 * - "Upgrade Now" buttons in Design Library
 * - "Premium" filter toggle button
 * - "Get Access" / "PREMIUM" badges on locked blocks
 * - "Get premium addons" promotional text on admin dashboard
 *
 * Uses MutationObserver to catch dynamically-rendered elements.
 */

( function () {
	'use strict';

	/** Text patterns to hide (case-insensitive exact match on element's own text). */
	var UPSELL_TEXTS = [
		'Upgrade Now',
		'Upgrade now',
		'Get Access',
		'PREMIUM',
		'Get Spectra Pro',
		'Free vs Pro',
	];

	/** Substring patterns to hide (matches anywhere in element's own text). */
	var UPSELL_SUBSTRINGS = [
		'Get premium addons',
		'Premium pre-built templates',
		'Upgrade to unlock',
	];

	/**
	 * Check if an element's OWN text (not children) matches upsell patterns.
	 */
	function isUpsellElement( el ) {
		var ownText = '';
		el.childNodes.forEach( function ( node ) {
			if ( node.nodeType === 3 ) { // Text node
				ownText += node.textContent;
			}
		} );
		ownText = ownText.trim();
		if ( ! ownText ) {
			return false;
		}

		// Exact match check.
		for ( var i = 0; i < UPSELL_TEXTS.length; i++ ) {
			if ( ownText === UPSELL_TEXTS[ i ] ) {
				return true;
			}
		}

		// Substring match check.
		for ( var j = 0; j < UPSELL_SUBSTRINGS.length; j++ ) {
			if ( ownText.indexOf( UPSELL_SUBSTRINGS[ j ] ) !== -1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hide an element and its nearest reasonable container.
	 * For buttons/links, hide the element itself.
	 * For text in a <p>, hide the <p>.
	 */
	function hideUpsell( el ) {
		if ( ! el || el.dataset.rcmiUpsellHidden === '1' ) {
			return;
		}

		// For buttons and links, hide the element itself.
		if ( el.tagName === 'BUTTON' || el.tagName === 'A' || el.tagName === 'SPAN' ) {
			// If it's a span inside a button, hide the parent button.
			var target = el;
			if ( el.tagName === 'SPAN' && el.parentElement && el.parentElement.tagName === 'BUTTON' ) {
				target = el.parentElement;
			}
			target.style.display = 'none';
			target.dataset.rcmiUpsellHidden = '1';
			return;
		}

		// For paragraphs and divs, hide the element.
		el.style.display = 'none';
		el.dataset.rcmiUpsellHidden = '1';
	}

	/**
	 * Scan the document (or a subtree) for upsell elements and hide them.
	 */
	function scanForUpsells( root ) {
		var elements = ( root || document ).querySelectorAll( '*' );
		elements.forEach( function ( el ) {
			if ( isUpsellElement( el ) ) {
				hideUpsell( el );
			}
		} );

		// Also hide "Premium" filter button in Design Library by its size class.
		var premiumBtns = document.querySelectorAll(
			'.ast-block-templates-lightbox button.w-\\[216px\\]'
		);
		premiumBtns.forEach( function ( btn ) {
			if ( btn.textContent.trim() === 'Premium' ) {
				btn.style.display = 'none';
				btn.dataset.rcmiUpsellHidden = '1';
			}
		} );
	}

	/**
	 * Set up MutationObserver to catch dynamically-added elements.
	 */
	function setupObserver() {
		if ( ! window.MutationObserver ) {
			return;
		}
		var observer = new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 ) { // Element node
						scanForUpsells( node );
					}
				} );
			} );
		} );
		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}

	// Run on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			scanForUpsells();
			setupObserver();
		} );
	} else {
		scanForUpsells();
		setupObserver();
	}

	// Also run periodically for late-loading modals (Spectra Design Library loads via AJAX).
	setTimeout( function () { scanForUpsells(); }, 2000 );
	setTimeout( function () { scanForUpsells(); }, 5000 );
} )();
