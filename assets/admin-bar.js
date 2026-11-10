/**
 * SiteFerry: keeps the admin bar's job item ("SiteFerry 42%") current while a job runs.
 *
 * Loaded only on pages where a job was running when the page was made. Asks the /bar route every
 * three seconds while the tab is visible, and stops once the job has ended (the item then shows
 * the outcome until the next page load).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
( function () {
	'use strict';

	const cfg = window.SiteFerryBar;
	if ( ! cfg || ! window.fetch ) {
		return;
	}
	const EVERY = 3000;
	let timer = null;
	let stopped = false;

	function item() {
		return document.getElementById( cfg.node );
	}

	function show( shown ) {
		const node = item();
		if ( ! node ) {
			return;
		}
		if ( ! shown ) {
			node.hidden = true; // Ended long ago, or nothing to show.
			stopped = true;
			return;
		}
		node.hidden = false;
		node.className = node.className.replace( /\bis-[a-z]+\b/g, '' ).trim() + ' is-' + shown.state;
		const label = node.querySelector( '.sf-ab-label' );
		if ( label ) {
			label.textContent = shown.label;
		}
		if ( shown.state !== 'running' && shown.state !== 'waiting' ) {
			stopped = true;
		}
	}

	function ask() {
		timer = null;
		if ( stopped || document.hidden ) {
			return;
		}
		window
			.fetch( cfg.url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( ( response ) => ( response.ok ? response.json() : Promise.reject( response.status ) ) )
			.then( ( data ) => show( data && data.shown ? data.shown : null ) )
			.catch( () => {} ) // Try again next time; the bar is only a hint.
			.then( () => {
				if ( ! stopped && ! document.hidden ) {
					timer = window.setTimeout( ask, EVERY );
				}
			} );
	}

	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden && ! stopped && null === timer ) {
			ask();
		}
	} );
	timer = window.setTimeout( ask, EVERY );
} )();
