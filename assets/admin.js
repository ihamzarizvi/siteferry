/**
 * SiteFerry admin page: backs up, lists and restores through the siteferry/v1 REST API, and drives
 * the jobs, uploads and downloads. Vanilla JavaScript; all server data is inserted with textContent.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
( function () {
	'use strict';

	const cfg = window.SiteFerryConfig || {};
	const $ = ( id ) => document.getElementById( id );
	const JOB_KEY = 'siteferry.job';
	const JOB_NAMES = { export: 'A backup', import: 'A restore', send: 'A transfer to storage', fetch: 'A download from storage', check: 'A check of a backup' };
	const JOB_TYPES = { export: 'Backup', import: 'Restore', send: 'Send to storage', fetch: 'Download from storage', check: 'Check a copy' };
	const JOB_STATES = {
		pending: [ 'Not started', 'is-busy' ],
		running: [ 'Not finished', 'is-busy' ],
		waiting: [ 'Waiting for confirmation', 'is-warn' ],
		done: [ 'Done', 'is-good' ],
		failed: [ 'Failed', 'is-bad' ],
	};
	// Names of the job steps, as the legs of the route the progress box draws.
	const STEP_LABELS = {
		'export.scan': 'Find files',
		'export.database': 'Database',
		'export.pack': 'Pack files',
		'export.finish': 'Seal',
		'export.send': 'Send',
		'send.upload': 'Send',
		'fetch.download': 'Download',
		'import.inspect': 'Check',
		'import.confirm': 'Confirm',
		'import.extract': 'Unpack',
		'import.verify': 'Verify',
		'import.database': 'Database',
		'import.replace': 'Addresses',
		'import.swap': 'Switch over',
		'import.finish': 'Clean up',
		'import.wpress': 'Read .wpress',
		'export.wpress': 'Make .wpress',
		'schedule.finish': 'Wrap up',
		'import.safety': 'Safety backup',
		'check.download': 'Download',
		'check.verify': 'Verify',
		upload: 'Upload',
	};
	const DROP_INS = [ 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php' ];
	const WEEKDAYS = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
	const TABS = [ 'backups', 'storage', 'activity' ];
	// Older links to the page still land somewhere sensible.
	const OLD_TABS = { export: 'backups', import: 'backups', log: 'activity' };
	let importJob = null;
	let destinations = [];
	let localBackups = null;
	let remote = {};
	let labels = {};
	let checks = {};
	let filter = { text: '', tag: '' };
	let editing = '';
	let restore = null;
	let noticeTimer = null;
	let schedule = null;
	let watching = '';

	// ---------------------------------------------------------------- helpers

	const store = {
		get( key ) {
			try {
				return JSON.parse( window.localStorage.getItem( key ) );
			} catch ( e ) {
				return null;
			}
		},
		set( key, value ) {
			try {
				window.localStorage.setItem( key, JSON.stringify( value ) );
			} catch ( e ) {}
		},
		remove( key ) {
			try {
				window.localStorage.removeItem( key );
			} catch ( e ) {}
		},
	};

	const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	function url( path, query ) {
		let u = cfg.root + path;
		if ( query ) {
			u += ( u.indexOf( '?' ) === -1 ? '?' : '&' ) + new URLSearchParams( query ).toString();
		}
		return u;
	}

	/**
	 * Calls the REST API. Job requests send the job token instead of the login nonce, so they keep
	 * working after an import has replaced the users table.
	 */
	async function api( method, path, options = {} ) {
		const headers = Object.assign( {}, options.headers || {} );
		if ( options.nonce !== false ) {
			headers[ 'X-WP-Nonce' ] = cfg.nonce;
		}
		if ( options.token ) {
			headers[ 'X-SiteFerry-Job' ] = options.token;
		}
		let body;
		if ( options.json !== undefined ) {
			headers[ 'Content-Type' ] = 'application/json';
			body = JSON.stringify( options.json );
		} else if ( options.raw !== undefined ) {
			headers[ 'Content-Type' ] = 'application/octet-stream';
			body = options.raw;
		}
		const response = await fetch( url( path, options.query ), { method, headers, body, credentials: 'same-origin' } );
		let data = null;
		try {
			data = await response.json();
		} catch ( e ) {}
		if ( ! response.ok ) {
			const error = new Error( ( data && data.message ) || 'Request failed (HTTP ' + response.status + ').' );
			error.status = response.status;
			throw error;
		}
		return data;
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key === 'onclick' ) {
				node.addEventListener( 'click', value );
			} else {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( ( child ) => node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child ) );
		return node;
	}

	function button( text, onclick, className ) {
		return el( 'button', { type: 'button', class: className || 'button button-small', text, onclick } );
	}

	function bytes( n ) {
		const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		let i = 0;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i++;
		}
		return ( i === 0 ? n : n.toFixed( 1 ) ) + ' ' + units[ i ];
	}

	function date( value ) {
		const d = typeof value === 'number' ? new Date( value * 1000 ) : new Date( value );
		return isNaN( d ) ? '' : d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
	}

	const plural = ( n, one, many ) => n + ' ' + ( n === 1 ? one : many );

	/** A message above the tabs; success messages go away by themselves. */
	function notice( message, type ) {
		const box = $( 'siteferry-notice' );
		box.className = 'sf-notice' + ( type === 'success' ? ' is-good' : type === 'error' ? ' is-bad' : '' );
		box.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		box.replaceChildren(
			el( 'p', { text: message } ),
			el( 'button', { type: 'button', class: 'sf-notice-close', 'aria-label': 'Dismiss this message', text: '×', onclick: () => ( box.hidden = true ) } )
		);
		box.hidden = false;
		clearTimeout( noticeTimer );
		if ( type === 'success' ) {
			noticeTimer = setTimeout( () => ( box.hidden = true ), 8000 );
		}
	}

	// ------------------------------------------------------------ the route

	function ferryNode() {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 32 20' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		[ 'M9 3h9l3 5H6z', 'M1 9h30l-4 8H5z' ].forEach( ( d ) => {
			const path = document.createElementNS( ns, 'path' );
			path.setAttribute( 'd', d );
			svg.appendChild( path );
		} );
		return el( 'span', { class: 'sf-ferry' }, [ svg ] );
	}

	const stepLabel = ( name ) => STEP_LABELS[ name ] || name;

	/**
	 * Progress box: a title, the job's steps drawn as the legs of a route with the ferry where the
	 * job is, and the outcome below. `ends` names where the crossing starts and ends.
	 */
	function progressView( id, ends ) {
		const box = $( id );
		let leg = null;
		const part = ( name ) => box.querySelector( '.' + name );

		function frame( state, title ) {
			if ( ! part( 'sf-progress-head' ) ) {
				box.replaceChildren(
					el( 'div', { class: 'sf-progress-head' }, [ el( 'p', { class: 'sf-progress-title' } ), el( 'span', { class: 'sf-progress-percent' } ) ] ),
					el( 'div', { class: 'sf-route-wrap' } ),
					el( 'p', { class: 'sf-route-caption' } ),
					el( 'div', { class: 'sf-progress-body' } )
				);
			}
			box.className = 'sf-progress is-' + state;
			if ( title !== undefined ) {
				part( 'sf-progress-title' ).textContent = title;
			}
			box.hidden = false;
		}

		function draw( names, index, fraction, arrived ) {
			leg = { names, index, fraction };
			const wrap = part( 'sf-route-wrap' );
			const key = names.join( ' ' );
			let route = wrap.querySelector( '.sf-route' );
			if ( ! route || route.dataset.key !== key ) {
				route = el( 'div', { class: 'sf-route', 'data-key': key, style: '--legs:' + names.length, 'aria-hidden': 'true' }, [
					...names.map( ( name ) => el( 'div', { class: 'sf-leg' }, [ el( 'span', { class: 'sf-leg-track' } ), el( 'span', { class: 'sf-leg-fill' } ), el( 'span', { class: 'sf-leg-label', text: stepLabel( name ) } ) ] ) ),
					ferryNode(),
				] );
				const top = ends ? [ el( 'div', { class: 'sf-route-ends', 'aria-hidden': 'true' }, [ el( 'span', { text: ends[ 0 ] } ), el( 'span', { text: ends[ 1 ] } ) ] ) ] : [];
				wrap.replaceChildren( ...top, route );
			}
			route.classList.toggle( 'is-arrived', !! arrived );
			route.querySelectorAll( '.sf-leg' ).forEach( ( node, i ) => {
				node.className = 'sf-leg' + ( i < index ? ' is-done' : '' ) + ( i === index ? ' is-current' : '' );
				node.style.setProperty( '--fill', ( i === index ? Math.round( fraction * 100 ) : 0 ) + '%' );
			} );
			const at = Math.min( 1, ( index + fraction ) / names.length );
			route.querySelector( '.sf-ferry' ).style.setProperty( '--at', ( at * 100 ).toFixed( 2 ) + '%' );
			part( 'sf-progress-percent' ).textContent = Math.round( at * 100 ) + '%';
			part( 'sf-route-caption' ).textContent =
				index < names.length ? 'Step ' + ( index + 1 ) + ' of ' + names.length + ': ' + stepLabel( names[ index ] ) : plural( names.length, 'step', 'steps' ) + ' done';
		}

		return {
			start( title ) {
				leg = null;
				box.replaceChildren();
				frame( 'running', title );
				box.scrollIntoView( { block: 'nearest' } );
			},
			job( job ) {
				const p = job.progress || {};
				const step = job.step || {};
				const names = step.names && step.names.length ? step.names : [ step.name || job.type ];
				const index = Math.min( step.index || 0, names.length );
				const fraction = index < names.length && p.total > 0 ? Math.min( 1, ( p.done || 0 ) / p.total ) : 0;
				frame( 'running', p.message || 'Working...' );
				draw( names, index, fraction, false );
			},
			note( message ) {
				frame( 'running', message );
			},
			update( done, total, message ) {
				frame( 'running', message );
				draw( [ 'upload' ], 0, total > 0 ? Math.min( 1, done / total ) : 0, false );
			},
			/** Ends the crossing: arrived (every leg done), or stopped where the job is. */
			finish( ok, title, nodes, arrived = ok ) {
				frame( ok ? 'done' : 'failed', title );
				if ( leg ) {
					if ( arrived ) {
						draw( leg.names, leg.names.length, 0, true );
					} else {
						draw( leg.names, leg.index, leg.fraction, false );
					}
					if ( ! ok || ! arrived ) {
						part( 'sf-progress-percent' ).textContent = ''; // Stopped, or waiting for a decision.
					}
				}
				part( 'sf-progress-body' ).replaceChildren( ...( nodes || [] ) );
			},
			hide() {
				box.hidden = true;
			},
		};
	}

	function warningList( job ) {
		if ( ! job.warnings || ! job.warnings.count ) {
			return [];
		}
		const list = el( 'ul', { class: 'sf-warnings' } );
		job.warnings.first.forEach( ( text ) => list.appendChild( el( 'li', { text } ) ) );
		if ( job.warnings.count > job.warnings.first.length ) {
			list.appendChild( el( 'li', { text: '... and ' + ( job.warnings.count - job.warnings.first.length ) + ' more (see the Log tab).' } ) );
		}
		return [ el( 'p', { text: plural( job.warnings.count, 'warning', 'warnings' ) + ':' } ), list ];
	}

	// ------------------------------------------------------------ SHA-256

	/**
	 * SHA-256 for pages without crypto.subtle (plain http on a domain). Round constants are
	 * derived from the primes as the standard defines them (FIPS 180-4).
	 */
	const sha256Fallback = ( function () {
		const primes = [];
		for ( let n = 2; primes.length < 64; n++ ) {
			if ( primes.every( ( p ) => n % p !== 0 ) ) {
				primes.push( n );
			}
		}
		const frac = ( x ) => Math.floor( ( x - Math.floor( x ) ) * 0x100000000 ) >>> 0;
		const K = primes.map( ( p ) => frac( Math.cbrt( p ) ) );
		const H0 = primes.slice( 0, 8 ).map( ( p ) => frac( Math.sqrt( p ) ) );
		const ror = ( x, n ) => ( x >>> n ) | ( x << ( 32 - n ) );

		return function ( input ) {
			const length = input.length;
			const padded = new Uint8Array( ( ( length + 9 + 63 ) >> 6 ) << 6 );
			padded.set( input );
			padded[ length ] = 0x80;
			const view = new DataView( padded.buffer );
			view.setUint32( padded.length - 8, Math.floor( length / 0x20000000 ) );
			view.setUint32( padded.length - 4, ( length * 8 ) >>> 0 );

			const H = H0.slice();
			const W = new Uint32Array( 64 );
			for ( let offset = 0; offset < padded.length; offset += 64 ) {
				for ( let i = 0; i < 16; i++ ) {
					W[ i ] = view.getUint32( offset + i * 4 );
				}
				for ( let i = 16; i < 64; i++ ) {
					const s0 = ror( W[ i - 15 ], 7 ) ^ ror( W[ i - 15 ], 18 ) ^ ( W[ i - 15 ] >>> 3 );
					const s1 = ror( W[ i - 2 ], 17 ) ^ ror( W[ i - 2 ], 19 ) ^ ( W[ i - 2 ] >>> 10 );
					W[ i ] = ( W[ i - 16 ] + s0 + W[ i - 7 ] + s1 ) >>> 0;
				}
				let [ a, b, c, d, e, f, g, h ] = H;
				for ( let i = 0; i < 64; i++ ) {
					const t1 = ( h + ( ror( e, 6 ) ^ ror( e, 11 ) ^ ror( e, 25 ) ) + ( ( e & f ) ^ ( ~e & g ) ) + K[ i ] + W[ i ] ) >>> 0;
					const t2 = ( ( ror( a, 2 ) ^ ror( a, 13 ) ^ ror( a, 22 ) ) + ( ( a & b ) ^ ( a & c ) ^ ( b & c ) ) ) >>> 0;
					h = g;
					g = f;
					f = e;
					e = ( d + t1 ) >>> 0;
					d = c;
					c = b;
					b = a;
					a = ( t1 + t2 ) >>> 0;
				}
				[ a, b, c, d, e, f, g, h ].forEach( ( value, i ) => {
					H[ i ] = ( H[ i ] + value ) >>> 0;
				} );
			}
			return H.map( ( x ) => x.toString( 16 ).padStart( 8, '0' ) ).join( '' );
		};
	} )();

	async function sha256( buffer ) {
		if ( window.crypto && window.crypto.subtle ) {
			const digest = await window.crypto.subtle.digest( 'SHA-256', buffer );
			return Array.from( new Uint8Array( digest ), ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
		}
		return sha256Fallback( new Uint8Array( buffer ) );
	}

	// ------------------------------------------------------------ jobs

	function remember( job, token ) {
		store.set( JOB_KEY, { id: job.id, type: job.type, token } );
	}

	/**
	 * Runs slices until the job finishes or waits for confirmation. Busy answers and network
	 * errors are retried with growing pauses, so a dropped connection does not lose the job.
	 */
	async function driveJob( id, token, onProgress ) {
		let failures = 0;
		for ( ;; ) {
			let job;
			try {
				job = await api( 'POST', 'jobs/' + id + '/run', { token, nonce: false } );
				failures = 0;
			} catch ( error ) {
				if ( error.status === 409 ) {
					await sleep( 2000 );
					continue;
				}
				if ( error.status === 403 || error.status === 404 || ++failures > 6 ) {
					throw error;
				}
				onProgress( null, 'Connection problem; retrying (' + failures + ')...' );
				await sleep( Math.min( 30000, 1000 * 2 ** failures ) );
				continue;
			}
			onProgress( job );
			if ( job.status === 'done' || job.status === 'failed' || job.status === 'waiting' ) {
				return job;
			}
		}
	}

	function progressHandler( view ) {
		return ( job, message ) => ( job ? view.job( job ) : view.note( message ) );
	}

	// ------------------------------------------------------------ dates and sites

	const DAY = 86400000;

	/** "Today, 7:21 PM", "Yesterday, 9:02 AM" or "Nov 2, 2026, 3:05 AM"; lower-cased inside a sentence. */
	function when( seconds, inSentence ) {
		if ( ! seconds ) {
			return '';
		}
		const d = new Date( seconds * 1000 );
		const time = d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
		const start = new Date();
		start.setHours( 0, 0, 0, 0 );
		let text;
		if ( d >= start ) {
			text = 'Today, ' + time;
		} else if ( d >= new Date( start.getTime() - DAY ) ) {
			text = 'Yesterday, ' + time;
		} else {
			return d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
		}
		return inSentence ? text.charAt( 0 ).toLowerCase() + text.slice( 1 ) : text;
	}

	/** A time to come: "today, 9:00 AM", "tomorrow, 3:00 AM" or "Monday, Nov 9, 9:00 AM". */
	function upcoming( seconds ) {
		if ( ! seconds ) {
			return '';
		}
		const d = new Date( seconds * 1000 );
		const time = d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
		const start = new Date();
		start.setHours( 0, 0, 0, 0 );
		const days = Math.floor( ( new Date( d ).setHours( 0, 0, 0, 0 ) - start.getTime() ) / DAY );
		if ( days === 0 ) {
			return 'today, ' + time;
		}
		if ( days === 1 ) {
			return 'tomorrow, ' + time;
		}
		return d.toLocaleDateString( undefined, { weekday: 'long', month: 'short', day: 'numeric' } ) + ', ' + time;
	}

	/** The next scheduled run; with the site's own time when the viewer's time zone differs. */
	function nextRun() {
		const text = upcoming( schedule.next );
		const viewer = -new Date( schedule.next * 1000 ).getTimezoneOffset() * 60;
		return schedule.next_site && viewer !== schedule.next_offset ? text + ' your time (' + schedule.next_site + ' site time)' : text;
	}

	/** A site address without scheme, "www." or trailing slash, for comparing sites. */
	function siteKey( address ) {
		return String( address || '' ).toLowerCase().replace( /^[a-z]+:\/\//, '' ).replace( /^www\./, '' ).replace( /\/+$/, '' );
	}

	function hostOf( address ) {
		return String( address || '' ).replace( /^[a-z]+:\/\//i, '' ).replace( /\/+$/, '' );
	}

	/** The start of the file names SiteFerry gives this site's backups (as Export::archive_name). */
	const OWN_LABEL = ( function () {
		try {
			const u = new URL( cfg.home );
			const label = ( u.hostname + ( u.port ? '-' + u.port : '' ) + ( u.pathname && u.pathname !== '/' ? '-' + u.pathname : '' ) )
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '-' )
				.replace( /^-+|-+$/g, '' );
			return ( label || 'site' ).slice( 0, 40 ).replace( /-+$/, '' );
		} catch ( e ) {
			return '';
		}
	} )();

	/** Whether a backup was made from this site (its manifest says so, or its file name does). */
	function ours( item ) {
		if ( item.source ) {
			return siteKey( item.source ) === siteKey( cfg.home );
		}
		return '' !== OWN_LABEL && new RegExp( '^' + OWN_LABEL.replace( /[-]/g, '\\-' ) + '-\\d{4}-\\d{2}-\\d{2}-[a-f0-9]{12}\\.' ).test( item.name );
	}

	// ------------------------------------------------------------ back up

	async function loadTables() {
		const box = $( 'siteferry-tables' );
		try {
			const tables = await api( 'GET', 'tables' );
			box.replaceChildren(
				...tables.map( ( t ) =>
					el( 'label', {}, [
						el( 'input', { type: 'checkbox', name: 'exclude_tables', value: t.name } ),
						' ' + t.name + ' ',
						el( 'span', { class: 'sf-table-size', text: plural( t.rows, 'row', 'rows' ) + ', ' + bytes( t.data ) } ),
					] )
				)
			);
		} catch ( error ) {
			box.textContent = error.message;
		}
	}

	function countLeftOutTables() {
		const n = document.querySelectorAll( '#siteferry-tables input:checked' ).length;
		const badge = $( 'siteferry-tables-count' );
		badge.textContent = n + ' left out';
		badge.hidden = n === 0;
	}

	/** "Where it goes": this server, or one of the storages that are ready. */
	function renderSendOptions() {
		const box = $( 'siteferry-send-options' );
		const checked = box.querySelector( 'input:checked' );
		const current = checked ? checked.value : '';
		const ready = destinations.filter( ( d ) => d.ready );
		box.replaceChildren(
			box.querySelector( '.sf-choice' ),
			...ready.map( ( d ) =>
				el( 'label', { class: 'sf-choice' }, [
					el( 'input', { type: 'radio', name: 'send_to', value: d.id } ),
					el( 'span', { class: 'sf-choice-name', text: d.label } ),
					el( 'span', { class: 'sf-choice-note', text: d.description } ),
				] )
			)
		);
		const keep = Array.from( box.querySelectorAll( 'input' ) ).find( ( input ) => input.value === current ) || box.querySelector( 'input' );
		keep.checked = true;
		$( 'siteferry-no-storage' ).hidden = ready.length > 0;
		syncDeleteAfter();
	}

	function syncDeleteAfter() {
		const choice = $( 'siteferry-export-form' ).querySelector( 'input[name=send_to]:checked' );
		$( 'siteferry-delete-after' ).hidden = ! choice || ! choice.value;
	}

	/** Compression applies to SiteFerry's own format only. */
	function syncFormat() {
		const format = $( 'siteferry-export-form' ).querySelector( 'input[name=format]:checked' );
		const row = $( 'siteferry-compress-row' );
		if ( row ) {
			row.hidden = !! format && format.value === 'wpress';
		}
	}

	/** Back up now: the choices in Options (closed or open) go to the export. */
	async function startExport( event ) {
		event.preventDefault();
		const form = event.target;
		const options = { exclude_tables: [] };
		form.querySelectorAll( 'input[type=checkbox]' ).forEach( ( input ) => {
			if ( ! input.name ) {
				return; // Page-only choices, such as downloading when ready.
			}
			if ( input.name === 'exclude_tables' ) {
				if ( input.checked ) {
					options.exclude_tables.push( input.value );
				}
			} else {
				options[ input.name ] = input.checked;
			}
		} );
		const format = form.querySelector( 'input[name=format]:checked' );
		options.format = format ? format.value : 'tar';
		if ( options.format === 'wpress' ) {
			options.compress = false;
		}
		const choice = form.querySelector( 'input[name=send_to]:checked' );
		options.send_to = choice ? choice.value : '';
		if ( ! options.send_to ) {
			options.delete_after_send = false;
		}
		options.title = form.elements.title.value;
		options.note = form.elements.note.value;
		options.tags = form.elements.tags.value;
		const target = destination( options.send_to );
		const view = progressView( 'siteferry-job-progress', [ 'This site', target ? target.short : 'This server' ] );
		const starters = [ $( 'siteferry-backup-now' ), ...form.querySelectorAll( 'button[type=submit]' ) ];
		starters.forEach( ( starter ) => ( starter.disabled = true ) );
		// The choices are made; close Options so the progress shows right under the header.
		toggleDrawer( 'options', false );
		view.start( 'Starting the backup...' );
		try {
			const started = await api( 'POST', 'exports', { json: options } );
			remember( started.job, started.token );
			// A name, note and tags belong to this backup only.
			[ 'title', 'note', 'tags' ].forEach( ( field ) => {
				form.elements[ field ].value = '';
			} );
			await finishExport( started.job.id, started.token, view, $( 'siteferry-download-ready' ).checked );
		} catch ( error ) {
			view.finish( false, 'The backup was not made', [ el( 'p', { text: error.message } ) ] );
		} finally {
			starters.forEach( ( starter ) => ( starter.disabled = false ) );
		}
	}

	async function finishExport( id, token, view, downloadWhenReady ) {
		const job = await driveJob( id, token, progressHandler( view ) );
		store.remove( JOB_KEY );
		if ( job.status !== 'done' ) {
			view.finish( false, 'The backup was not made', [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
			return;
		}
		const r = job.result;
		const where = r.sent ? ( r.deleted_local ? 'on ' + r.sent.where : 'on this server and on ' + r.sent.where ) : 'on this server';
		const nodes = [
			el( 'p', { text: bytes( r.size ) + ' · ' + plural( r.files, 'file', 'files' ) + ', stored ' + where + '.' } ),
			el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: r.name } ) ] ),
		];
		const actions = [];
		if ( ! r.deleted_local ) {
			actions.push( el( 'a', { class: 'button button-primary', href: downloadUrl( r.name ), text: 'Download to your computer' } ) );
			destinations
				.filter( ( d ) => d.ready && ! ( r.sent && r.sent.destination === d.id ) )
				.forEach( ( d ) => actions.push( button( 'Also send to ' + d.short, () => sendTo( d, r.name ), 'button sf-quiet' ) ) );
		}
		actions.push( button( 'Name, note and tags', () => editByName( r.sent && r.deleted_local ? r.sent.name : r.name ), 'button sf-quiet' ) );
		if ( actions.length ) {
			nodes.push( el( 'p', { class: 'sf-actions' }, actions ) );
		}
		view.finish( true, 'Backup ready', [ ...nodes, ...warningList( job ) ] );
		loadBackups();
		if ( downloadWhenReady && ! r.deleted_local ) {
			window.location.assign( downloadUrl( r.name ) );
		}
	}

	// ------------------------------------------------------------ storage

	async function loadDestinations() {
		try {
			destinations = await api( 'GET', 'storage' );
		} catch ( error ) {
			destinations = [];
			notice( 'Cannot read the storage settings: ' + error.message, 'error' );
		}
		renderSendOptions();
		if ( schedule ) {
			renderScheduleWhere( false );
			renderScheduleLine();
		}
		document.querySelectorAll( '.siteferry-storage-form' ).forEach( ( form ) => fillStorageForm( form ) );
		updateFacts();
		return destinations.filter( ( d ) => d.ready );
	}

	function destination( id ) {
		return destinations.find( ( d ) => d.id === id ) || null;
	}

	/** The header line: this site, its backups and where they can go. */
	function updateFacts() {
		if ( localBackups ) {
			const total = localBackups.reduce( ( sum, b ) => sum + ( b.size || 0 ), 0 );
			const facts = $( 'siteferry-facts-backups' );
			facts.textContent = localBackups.length ? plural( localBackups.length, 'backup', 'backups' ) + ' here, ' + bytes( total ) : 'No backups here yet';
			facts.hidden = false;
		}
		const ready = destinations.filter( ( d ) => d.ready );
		const storage = $( 'siteferry-facts-storage' );
		storage.textContent = ready.length ? 'Storage: ' + ready.map( ( d ) => d.short ).join( ', ' ) : 'No storage set up';
		storage.classList.toggle( 'is-good', ready.length > 0 );
		storage.hidden = false;
	}

	/**
	 * Shows a destination's saved settings in its form and its state on its card. Password fields
	 * always stay empty; unsaved checkboxes and selects take their data-default.
	 */
	function fillStorageForm( form ) {
		const d = destination( form.dataset.destination );
		if ( ! d ) {
			return;
		}
		const settings = ( d.configured && d.settings ) || {};
		Array.from( form.elements ).forEach( ( input ) => {
			if ( ! input.name ) {
				return;
			}
			const saved = settings[ input.name ];
			if ( input.type === 'password' ) {
				input.value = '';
			} else if ( input.type === 'checkbox' ) {
				input.checked = saved !== undefined ? !! saved : input.dataset.default === '1';
			} else if ( input.tagName === 'SELECT' ) {
				if ( saved !== undefined ) {
					input.value = saved;
				} else if ( input.dataset.default ) {
					input.value = input.dataset.default;
				}
			} else {
				input.value = saved !== undefined ? String( saved ) : '';
			}
		} );
		if ( form.elements.preset ) {
			applyPreset( form, d.configured ? settings.path_style : null );
		}
		const names = Object.keys( d.secrets_set || {} );
		const set = names.length > 0 && names.every( ( name ) => d.secrets_set[ name ] );
		const secretNote = form.querySelector( '[data-role=secret-note]' );
		if ( secretNote ) {
			secretNote.textContent = set ? 'Saved (not shown). Leave this empty to keep it, or enter a new one.' : 'Stored encrypted. It is never shown again.';
		}

		const card = form.closest( '.sf-dest' );
		const state = card.querySelector( '[data-role=state]' );
		const chip = card.querySelector( '[data-role=chip]' );
		let text = state.dataset.about;
		let label = 'Not set up';
		let tone = '';
		if ( d.oauth ) {
			if ( d.oauth.connected ) {
				[ text, label, tone ] = [ d.description, 'Connected', 'is-good' ];
			} else if ( d.configured ) {
				[ text, label, tone ] = [ 'Saved, but not signed in yet.', 'Not connected', 'is-warn' ];
			}
		} else if ( d.configured ) {
			text = d.description + ( d.updated ? ' · saved ' + date( d.updated ) : '' );
			[ label, tone ] = d.ready ? [ 'Ready', 'is-good' ] : [ 'Incomplete', 'is-warn' ];
		}
		state.textContent = text;
		chip.textContent = label;
		chip.className = 'sf-chip' + ( tone ? ' ' + tone : '' );

		form.querySelector( '[data-role=test]' ).disabled = ! d.ready;
		form.querySelector( '[data-role=remove]' ).hidden = ! d.configured;
		if ( form.querySelector( '[data-role=disconnect]' ) ) {
			form.querySelector( '[data-role=disconnect]' ).hidden = ! ( d.oauth && d.oauth.connected );
		}
		applyMode( form );
	}

	/** Shows the fields of the chosen sign-in mode (data-mode) and names the sign-in button. */
	function applyMode( form ) {
		if ( ! form.elements.mode ) {
			return;
		}
		const mode = form.elements.mode.value;
		form.querySelectorAll( '[data-mode]' ).forEach( ( part ) => {
			part.hidden = part.dataset.mode !== mode;
		} );
		const d = destination( form.dataset.destination );
		const connected = d && d.oauth && d.oauth.connected;
		const connect = form.querySelector( '[data-role=connect]' );
		if ( mode === 'relay' ) {
			connect.textContent = connected ? 'Sign in again' : connect.dataset.labelRelay;
		} else {
			connect.textContent = connected ? 'Connect again' : connect.dataset.labelOwn;
		}
	}

	function formData( form ) {
		const data = {};
		Array.from( form.elements ).forEach( ( input ) => {
			if ( input.name ) {
				data[ input.name ] = input.type === 'checkbox' ? input.checked : input.value.trim();
			}
		} );
		return data;
	}

	/**
	 * Saves the form, then sends the browser to the consent page (through SiteFerry's sign-in
	 * service in one-click mode). The one-time state stays on the server.
	 */
	async function connectStorage( form ) {
		const id = form.dataset.destination;
		const connect = form.querySelector( '[data-role=connect]' );
		connect.disabled = true;
		storageResult( form, null, [ el( 'p', { text: 'Opening the sign-in page...' } ) ] );
		try {
			await api( 'POST', 'storage/' + id, { json: formData( form ) } );
			const result = await api( 'POST', 'storage/' + id + '/authorize' );
			window.location.assign( result.url );
		} catch ( error ) {
			connect.disabled = false;
			storageResult( form, false, [ el( 'p', { text: 'Cannot sign in: ' + error.message } ) ] );
		}
	}

	async function disconnectStorage( form ) {
		const d = destination( form.dataset.destination );
		// eslint-disable-next-line no-alert
		if ( ! d || ! window.confirm( 'Disconnect ' + d.label + '? SiteFerry revokes its access and forgets the tokens; backups stored there stay.' ) ) {
			return;
		}
		try {
			await api( 'POST', 'storage/' + d.id + '/disconnect' );
			await loadDestinations();
			storageResult( form, true, [ el( 'p', { text: 'Disconnected. Backups stored there stay.' } ) ] );
		} catch ( error ) {
			storageResult( form, false, [ el( 'p', { text: error.message } ) ] );
		}
	}

	/**
	 * Back from the provider's consent page (?oauth=<id>&code=...&state=...): the GET changed
	 * nothing; the code and state are handed to the REST API with the nonce, then removed from
	 * the address bar.
	 */
	async function finishOauth() {
		const params = new URLSearchParams( window.location.search );
		const id = params.get( 'oauth' );
		if ( ! id ) {
			return false;
		}
		const code = params.get( 'code' );
		const state = params.get( 'state' );
		const failure = params.get( 'error' ) || cfg.oauthError;
		window.history.replaceState( null, '', window.location.pathname + '?page=siteferry#storage' );
		const form = document.querySelector( '.siteferry-storage-form[data-destination="' + CSS.escape( id ) + '"]' );
		if ( ! form ) {
			return true;
		}
		form.closest( '.sf-dest' ).open = true;
		if ( failure || ! code || ! state ) {
			storageResult( form, false, [ el( 'p', { text: 'Not connected: ' + ( failure === 'access_denied' ? 'access was not allowed.' : failure || 'the answer had no authorization code.' ) } ) ] );
			return true;
		}
		storageResult( form, null, [ el( 'p', { text: 'Finishing the connection...' } ) ] );
		try {
			const d = await api( 'POST', 'storage/' + id + '/connect', { json: { code, state } } );
			await loadDestinations();
			storageResult( form, true, [ el( 'p', { text: 'Connected: ' + d.description + '. Use "Test connection" to check it.' } ) ] );
		} catch ( error ) {
			storageResult( form, false, [ el( 'p', { text: 'Not connected: ' + error.message } ) ] );
		}
		return true;
	}

	/** Hint, placeholders and path-style default of the chosen provider. */
	function applyPreset( form, pathStyle ) {
		const option = form.elements.preset.selectedOptions[ 0 ];
		const region = form.elements.region.value.trim() || option.dataset.region;
		form.querySelector( '[data-role=help]' ).textContent = option.dataset.help;
		form.elements.region.placeholder = option.dataset.region;
		form.elements.endpoint.placeholder = option.dataset.endpoint.replace( '{region}', region );
		form.elements.folder.placeholder = 'siteferry';
		form.elements.path_style.checked = pathStyle === null || pathStyle === undefined ? option.dataset.pathStyle === '1' : !! pathStyle;
	}

	/** The message box at the bottom of a storage form: ok (true), failed (false) or busy (null). */
	function storageResult( form, ok, nodes ) {
		const box = form.querySelector( '[data-role=result]' );
		box.className = 'sf-result' + ( ok === true ? ' is-done' : ok === false ? ' is-failed' : '' );
		box.replaceChildren( ...nodes );
		box.hidden = false;
	}

	async function saveStorage( event ) {
		event.preventDefault();
		const form = event.target;
		const id = form.dataset.destination;
		const submit = form.querySelector( 'button[type=submit]' );
		submit.disabled = true;
		try {
			await api( 'POST', 'storage/' + id, { json: formData( form ) } );
			form.querySelectorAll( 'input[type=password]' ).forEach( ( input ) => {
				input.value = '';
			} );
			await loadDestinations();
			const d = destination( id );
			storageResult( form, true, [
				el( 'p', { text: d && d.oauth && ! d.oauth.connected ? 'Saved. Now click "' + form.querySelector( '[data-role=connect]' ).textContent + '" and allow access.' : 'Saved. Use "Test connection" to check the settings.' } ),
			] );
		} catch ( error ) {
			storageResult( form, false, [ el( 'p', { text: 'Not saved: ' + error.message } ) ] );
		} finally {
			submit.disabled = false;
		}
	}

	async function testStorage( form ) {
		const id = form.dataset.destination;
		const test = form.querySelector( '[data-role=test]' );
		test.disabled = true;
		storageResult( form, null, [ el( 'p', { text: 'Testing: writing, reading and deleting a small file...' } ) ] );
		try {
			const result = await api( 'POST', 'storage/' + id + '/test' );
			storageResult( form, true, [ el( 'p', { text: 'The storage works.' } ), el( 'ul', {}, result.lines.map( ( line ) => el( 'li', { text: line } ) ) ) ] );
		} catch ( error ) {
			storageResult( form, false, [ el( 'p', { text: error.message } ) ] );
		} finally {
			test.disabled = false;
		}
	}

	async function removeStorage( form ) {
		const d = destination( form.dataset.destination );
		// eslint-disable-next-line no-alert
		if ( ! d || ! window.confirm( 'Remove the settings and key for ' + d.label + '? Backups already stored there are not deleted.' ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'storage/' + d.id );
			await loadDestinations();
			form.reset();
			fillStorageForm( form );
			storageResult( form, true, [ el( 'p', { text: 'Settings removed. Backups stored there stay.' } ) ] );
		} catch ( error ) {
			storageResult( form, false, [ el( 'p', { text: error.message } ) ] );
		}
	}

	// ------------------------------------------------------------ transfers

	/** Runs a send or fetch job in the progress box above the list. */
	async function runTransfer( id, token, message, ends ) {
		const view = progressView( 'siteferry-job-progress', ends );
		view.start( message );
		let job;
		try {
			job = await driveJob( id, token, progressHandler( view ) );
		} catch ( error ) {
			view.finish( false, 'Stopped', [ el( 'p', { text: error.message } ) ] );
			return null;
		}
		store.remove( JOB_KEY );
		if ( job.type === 'check' ) {
			const checked = job.result && job.result.checked;
			if ( job.status === 'done' ) {
				view.finish( true, 'Intact', [ el( 'p', { text: 'The copy on ' + checked.where + ' is intact. ' + checked.summary } ) ] );
			} else {
				view.finish( false, checked && checked.ok === false ? 'Damaged' : 'Not checked', [
					el( 'p', { text: job.error } ),
					...( checked && checked.ok === false ? [ el( 'p', { text: 'Do not rely on this copy: make a new backup, or use another copy of this one.' } ) ] : [] ),
				] );
			}
		} else if ( job.status !== 'done' ) {
			view.finish( false, job.type === 'send' ? 'Not sent' : 'Not downloaded', [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
		} else if ( job.type === 'send' ) {
			view.finish( true, 'Sent', [ el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: job.result.sent.name } ), ' is now on ' + job.result.sent.where + '.' ] ) ] );
		} else {
			view.finish( true, 'Downloaded to this server', [ el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: job.result.fetched.name } ), ' is now also on this server.' ] ) ] );
		}
		loadBackups();
		return job;
	}

	async function startTransfer( path, body, message, ends ) {
		try {
			const started = await api( 'POST', path, { json: body } );
			remember( started.job, started.token );
			return await runTransfer( started.job.id, started.token, message, ends );
		} catch ( error ) {
			const view = progressView( 'siteferry-job-progress' );
			view.start( message );
			view.finish( false, 'Could not start', [ el( 'p', { text: error.message } ) ] );
			return null;
		}
	}

	function sendTo( d, name ) {
		return startTransfer( 'storage/' + d.id + '/send', { backup: name }, 'Sending ' + name + ' to ' + d.label + '...', [ 'This server', d.short ] );
	}

	/** Reads one copy of a backup from start to end and compares it with its checksums. */
	function checkCopy( item, place ) {
		const d = place === 'local' ? null : destination( place );
		return startTransfer(
			'checks',
			{ name: item.name, place },
			'Checking the copy of ' + called( item ) + ' on ' + ( d ? d.label : 'this server' ) + '; nothing changes...',
			d ? [ d.short, 'Checked' ] : [ 'Backup', 'Checked' ]
		);
	}

	function fetchFrom( d, name ) {
		return startTransfer( 'storage/' + d.id + '/fetch', { name }, 'Downloading ' + name + ' from ' + d.label + '...', [ d.short, 'This server' ] );
	}

	function downloadUrl( name ) {
		return url( 'backups/' + encodeURIComponent( name ) + '/download', { _wpnonce: cfg.nonce } );
	}

	// ------------------------------------------------------------ the list

	function loadBackups() {
		loadLocalBackups();
		loadRemote();
		loadLabels();
		loadChecks();
	}

	/** Outcomes of the checks of backup copies: name => place => { time, ok, size, summary }. */
	async function loadChecks() {
		try {
			const data = await api( 'GET', 'checks' );
			checks = data && ! Array.isArray( data ) ? data : {};
		} catch ( error ) {
			checks = {};
		}
		renderList();
	}

	/** Names, notes and tags by file name (also for backups that are only on a storage). */
	async function loadLabels() {
		try {
			const data = await api( 'GET', 'labels' );
			labels = data && ! Array.isArray( data ) ? data : {};
		} catch ( error ) {
			labels = {};
		}
		renderList();
	}

	async function loadLocalBackups() {
		try {
			localBackups = await api( 'GET', 'backups' );
		} catch ( error ) {
			localBackups = localBackups || [];
			notice( 'Cannot list the backups on this server: ' + error.message, 'error' );
		}
		updateFacts();
		renderList();
	}

	/** Lists every storage that is ready; each one's backups join the list as they arrive. */
	function loadRemote() {
		const ready = destinations.filter( ( d ) => d.ready );
		remote = Object.fromEntries( ready.map( ( d ) => [ d.id, remote[ d.id ] && remote[ d.id ].backups ? Object.assign( {}, remote[ d.id ], { loading: true } ) : { loading: true } ] ) );
		renderList();
		ready.forEach( async ( d ) => {
			try {
				const result = await api( 'GET', 'storage/' + d.id + '/backups' );
				remote[ d.id ] = { backups: result.backups };
			} catch ( error ) {
				remote[ d.id ] = { error: error.message };
			}
			renderList();
		} );
	}

	/** Backups on this server and on every storage, merged by file name, newest first. */
	function items() {
		const map = new Map();
		( localBackups || [] ).forEach( ( b ) => {
			map.set( b.name, {
				name: b.name,
				size: b.size,
				time: b.created ? Math.round( Date.parse( b.created ) / 1000 ) : b.modified,
				source: b.source,
				wordpress: b.wordpress,
				format: b.format,
				contents: b.contents,
				error: b.error,
				title: b.title || '',
				note: b.note || '',
				tags: b.tags || [],
				local: true,
				places: [],
				sizes: { local: b.size },
			} );
		} );
		Object.keys( remote ).forEach( ( id ) => {
			( remote[ id ].backups || [] ).forEach( ( b ) => {
				let item = map.get( b.name );
				if ( ! item ) {
					const label = labels[ b.name ] || {};
					item = {
						name: b.name,
						size: b.size,
						time: b.modified,
						format: /\.wpress$/.test( b.name ) ? 'wpress' : 'siteferry',
						title: label.title || '',
						note: label.note || '',
						tags: label.tags || [],
						local: false,
						places: [],
						sizes: {},
					};
					map.set( b.name, item );
				}
				item.places.push( id );
				item.sizes[ id ] = b.size;
			} );
		} );
		return Array.from( map.values() ).sort( ( a, b ) => ( b.time || 0 ) - ( a.time || 0 ) );
	}

	function renderList() {
		const list = $( 'siteferry-backups' );
		const all = items();
		const loading = Object.keys( remote ).filter( ( id ) => remote[ id ].loading );
		const failed = Object.keys( remote ).filter( ( id ) => remote[ id ].error );

		const count = $( 'siteferry-tab-count' );
		count.textContent = String( all.length );
		count.hidden = all.length === 0;

		const tags = allTags( all );
		if ( filter.tag && ! tags.some( ( tag ) => same( tag, filter.tag ) ) ) {
			filter.tag = '';
		}
		const visible = all.filter( matches );
		const filtering = '' !== filter.text || '' !== filter.tag;
		$( 'siteferry-filter' ).hidden = all.length < 2 && ! filtering;
		renderTagFilter( tags );

		const status = [];
		if ( loading.length ) {
			status.push( 'Looking on ' + loading.map( ( id ) => ( destination( id ) || { short: id } ).short ).join( ', ' ) + '...' );
		}
		failed.forEach( ( id ) => status.push( ( destination( id ) || { short: id } ).short + ': ' + remote[ id ].error ) );
		const counted = filtering ? visible.length + ' of ' + plural( all.length, 'backup', 'backups' ) : all.length ? plural( all.length, 'backup', 'backups' ) : '';
		$( 'siteferry-backups-summary' ).textContent = status.length ? status.join( ' · ' ) : counted;

		const mine = all.filter( ours );
		const here = all.filter( ( item ) => item.local ).length;
		const counts = [ here + ' on this server' ];
		destinations.filter( ( d ) => d.ready && remote[ d.id ] && remote[ d.id ].backups ).forEach( ( d ) => counts.push( remote[ d.id ].backups.length + ' on ' + d.short ) );
		$( 'siteferry-summary' ).textContent = all.length
			? ( mine.length ? 'Last backup ' + when( mine[ 0 ].time, true ) + ' · ' : '' ) + counts.join( ', ' )
			: 'No backups yet. "Back up now" makes one of the whole site.';

		$( 'siteferry-columns' ).hidden = ! visible.length;
		if ( null === localBackups ) {
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: 'Loading...' } ) );
			return;
		}
		if ( ! all.length ) {
			list.replaceChildren(
				el( 'li', { class: 'sf-empty' }, [
					el( 'p', { text: loading.length ? 'Looking for backups on your storage...' : 'No backups yet. Make one now, or upload one to restore it here.' } ),
					el( 'p', { class: 'sf-actions sf-empty-actions' }, [
						button( 'Back up now', () => $( 'siteferry-backup-now' ).click(), 'button button-primary' ),
						button( 'Upload a backup', () => toggleDrawer( 'upload', true ), 'button' ),
					] ),
				] )
			);
			return;
		}
		if ( ! visible.length ) {
			list.replaceChildren(
				el( 'li', { class: 'sf-empty' }, [
					el( 'p', { text: 'No backups match this search.' } ),
					el( 'p', { class: 'sf-actions sf-empty-actions' }, [ button( 'Show all backups', clearFilter, 'button' ) ] ),
				] )
			);
			return;
		}
		// A row being edited stays as it is while the list around it updates.
		const open = editing ? list.querySelector( '[data-name="' + CSS.escape( editing ) + '"]' ) : null;
		list.replaceChildren( ...visible.map( ( item ) => ( open && item.name === editing ? open : row( item ) ) ) );
	}

	const same = ( a, b ) => String( a ).toLowerCase() === String( b ).toLowerCase();

	/** Every tag in use, first spelling wins, in alphabetical order. */
	function allTags( all ) {
		const tags = [];
		all.forEach( ( item ) => ( item.tags || [] ).forEach( ( tag ) => {
			if ( ! tags.some( ( known ) => same( known, tag ) ) ) {
				tags.push( tag );
			}
		} ) );
		return tags.sort( ( a, b ) => a.localeCompare( b ) );
	}

	function matches( item ) {
		if ( filter.tag && ! ( item.tags || [] ).some( ( tag ) => same( tag, filter.tag ) ) ) {
			return false;
		}
		if ( ! filter.text ) {
			return true;
		}
		const haystack = [ item.title, item.note, ( item.tags || [] ).join( ' ' ), item.name, item.source, when( item.time ) ].join( ' ' ).toLowerCase();
		return filter.text.toLowerCase().split( /\s+/ ).filter( Boolean ).every( ( word ) => haystack.indexOf( word ) !== -1 );
	}

	function renderTagFilter( tags ) {
		const box = $( 'siteferry-tag-filter' );
		box.hidden = ! tags.length;
		box.replaceChildren(
			...tags.map( ( tag ) =>
				el( 'button', {
					type: 'button',
					class: 'sf-tag' + ( same( tag, filter.tag ) ? ' is-on' : '' ),
					'aria-pressed': same( tag, filter.tag ) ? 'true' : 'false',
					text: tag,
					onclick: () => setTagFilter( tag ),
				} )
			)
		);
	}

	function setTagFilter( tag ) {
		filter.tag = same( tag, filter.tag ) ? '' : tag;
		renderList();
	}

	function clearFilter() {
		filter = { text: '', tag: '' };
		$( 'siteferry-search' ).value = '';
		renderList();
	}

	/** The file format from the name: SiteFerry .tar or .tar.gz, or .wpress. */
	function formatOf( name ) {
		if ( /\.tar\.gz$/i.test( name ) ) {
			return { ext: '.tar.gz', note: 'SiteFerry, compressed' };
		}
		if ( /\.wpress$/i.test( name ) ) {
			return { ext: '.wpress', note: 'All-in-One WP Migration' };
		}
		return { ext: '.tar', note: 'SiteFerry' };
	}

	function badge( text, tone ) {
		return el( 'span', { class: 'sf-badge' + ( tone ? ' ' + tone : '' ), text } );
	}

	/** Where a copy is, with the outcome of its last check (if it is about this very copy). */
	function placeBadge( item, place, text ) {
		const outcome = checks[ item.name ] && checks[ item.name ][ place ];
		if ( ! outcome || ( item.sizes && item.sizes[ place ] !== undefined && item.sizes[ place ] !== outcome.size ) ) {
			return badge( text, 'is-place' );
		}
		const when = 'Checked ' + date( outcome.time ) + ': ' + outcome.summary;
		return el( 'span', { class: 'sf-badge is-place ' + ( outcome.ok ? 'is-checked' : 'is-bad' ), title: when }, [
			el( 'span', { class: 'sf-check-mark', 'aria-hidden': 'true', text: outcome.ok ? '✓' : '!' } ),
			text,
			el( 'span', { class: 'screen-reader-text', text: outcome.ok ? ', checked: intact' : ', checked: damaged' } ),
		] );
	}

	function row( item ) {
		const mine = ours( item );
		const title = item.title || ( mine ? when( item.time ) : hostOf( item.source ) || 'Another site' );
		const meta = [];
		if ( ( item.title || ! mine ) && item.time ) {
			meta.push( el( 'span', { text: when( item.time ) } ) );
		}
		if ( item.title && ! mine && item.source ) {
			meta.push( el( 'span', { text: 'from ' + hostOf( item.source ) } ) );
		}
		if ( item.contents ) {
			meta.push( el( 'span', { text: item.contents } ) );
		}
		if ( item.wordpress ) {
			meta.push( el( 'span', { text: 'WordPress ' + item.wordpress } ) );
		}
		// Shown in their own columns on wide screens, in the meta line on narrow ones.
		const format = formatOf( item.name );
		meta.push( el( 'span', { class: 'sf-narrow-only', text: format.ext } ), el( 'span', { class: 'sf-narrow-only', text: bytes( item.size ) } ) );

		const flags = [];
		if ( ! mine ) {
			flags.push( badge( 'From another site', 'is-warn' ) );
		}
		if ( item.error ) {
			flags.push( badge( 'Cannot be restored', 'is-bad' ) );
		}

		const places = [];
		if ( item.local ) {
			places.push( placeBadge( item, 'local', 'This server' ) );
		}
		item.places.forEach( ( id ) => places.push( placeBadge( item, id, ( destination( id ) || { short: id } ).short ) ) );

		const actions = [];
		if ( ! item.error ) {
			actions.push( button( mine ? 'Restore' : 'Move here', () => openRestore( item ), 'button sf-quiet' ) );
		}
		actions.push( menu( item ) );

		const tags = ( item.tags || [] ).map( ( tag ) =>
			el( 'button', {
				type: 'button',
				class: 'sf-tag' + ( same( tag, filter.tag ) ? ' is-on' : '' ),
				title: 'Show only backups tagged ' + tag,
				text: tag,
				onclick: () => setTagFilter( tag ),
			} )
		);

		const icon = item.error ? 'dashicons-warning' : mine ? 'dashicons-backup' : 'dashicons-migrate';
		return el( 'li', { class: 'sf-row sf-backup' + ( mine ? '' : ' is-foreign' ) + ( item.error ? ' is-broken' : '' ), 'data-name': item.name }, [
			el( 'span', { class: 'sf-backup-icon dashicons ' + icon, 'aria-hidden': 'true' } ),
			el( 'div', { class: 'sf-backup-main' }, [
				el( 'div', { class: 'sf-backup-headline' }, [ el( 'span', { class: 'sf-row-title', text: title } ), ...flags ] ),
				meta.length ? el( 'div', { class: 'sf-row-meta' }, meta ) : document.createTextNode( '' ),
				item.note ? el( 'p', { class: 'sf-row-note', text: item.note } ) : document.createTextNode( '' ),
				tags.length ? el( 'div', { class: 'sf-badges' }, tags ) : document.createTextNode( '' ),
				item.error ? el( 'p', { class: 'sf-row-error', text: item.error } ) : el( 'span', { class: 'sf-row-name', text: item.name } ),
			] ),
			el( 'div', { class: 'sf-backup-where' }, places ),
			el( 'div', { class: 'sf-backup-format' + ( format.ext === '.wpress' ? ' is-wpress' : '' ) }, [
				el( 'span', { class: 'sf-format-name', text: format.ext } ),
				el( 'span', { class: 'sf-format-note', text: format.note } ),
			] ),
			el( 'div', { class: 'sf-backup-size', text: bytes( item.size ) } ),
			el( 'div', { class: 'sf-row-actions' }, actions ),
		] );
	}

	/** Opens the name, note and tags editor in a backup's row. */
	function editByName( name ) {
		const item = items().find( ( entry ) => entry.name === name );
		if ( item ) {
			clearFilter();
			openEditor( item );
		}
	}

	function openEditor( item ) {
		closeMenus();
		const li = $( 'siteferry-backups' ).querySelector( '[data-name="' + CSS.escape( item.name ) + '"]' );
		if ( ! li ) {
			return;
		}
		editing = item.name;
		const id = 'siteferry-edit-' + Math.random().toString( 36 ).slice( 2, 8 );
		const fallback = ours( item ) ? when( item.time, true ) : hostOf( item.source ) || 'the site address';
		const title = el( 'input', { type: 'text', id: id + '-title', maxlength: '100', placeholder: 'Give this backup a name' } );
		const tags = el( 'input', { type: 'text', id: id + '-tags', maxlength: '200', placeholder: 'before-update, monthly' } );
		const note = el( 'textarea', { id: id + '-note', rows: '2', maxlength: '1000' } );
		title.value = item.title || '';
		tags.value = ( item.tags || [] ).join( ', ' );
		note.value = item.note || '';
		const message = el( 'p', { class: 'sf-row-error', hidden: 'hidden' } );
		const cancel = () => {
			editing = '';
			renderList();
		};
		const form = el( 'form', { class: 'sf-edit', 'aria-label': 'Name, note and tags of ' + item.name }, [
			el( 'div', { class: 'sf-edit-head' }, [
				el( 'span', { class: 'sf-edit-title', text: 'Name, note and tags' } ),
				el( 'span', { class: 'sf-row-name', text: item.name } ),
			] ),
			el( 'div', { class: 'sf-label-fields' }, [
				el( 'p', { class: 'sf-field' }, [
					el( 'label', { for: title.id, text: 'Name' } ),
					title,
					el( 'span', { class: 'description', text: 'Left empty, the list shows ' + fallback + '.' } ),
				] ),
				el( 'p', { class: 'sf-field' }, [ el( 'label', { for: tags.id, text: 'Tags' } ), tags, el( 'span', { class: 'description', text: 'Separate tags with commas.' } ) ] ),
				el( 'p', { class: 'sf-field sf-field-wide' }, [ el( 'label', { for: note.id, text: 'Note' } ), note ] ),
			] ),
			message,
			el( 'p', { class: 'sf-actions' }, [ el( 'button', { type: 'submit', class: 'button button-primary', text: 'Save' } ), button( 'Cancel', cancel, 'button sf-quiet' ) ] ),
		] );
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			form.querySelector( 'button[type=submit]' ).disabled = true;
			try {
				await api( 'POST', 'labels/' + encodeURIComponent( item.name ), { json: { title: title.value, note: note.value, tags: tags.value } } );
				editing = '';
				await Promise.all( [ loadLabels(), loadLocalBackups() ] );
				notice( 'Saved the name, note and tags.', 'success' );
			} catch ( error ) {
				message.textContent = 'Not saved: ' + error.message;
				message.hidden = false;
				form.querySelector( 'button[type=submit]' ).disabled = false;
			}
		} );
		form.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				cancel();
			}
		} );
		li.classList.add( 'is-editing' );
		li.replaceChildren( form );
		title.focus();
	}

	/** The "..." menu of a row: look inside, download, send, check, delete. */
	function menu( item ) {
		const entries = [ menuItem( 'Name, note and tags', () => openEditor( item ) ) ];
		const ready = destinations.filter( ( d ) => d.ready );
		if ( item.local ) {
			entries.push( menuItem( 'Look inside', () => openContents( item ) ) );
			entries.push( el( 'a', { class: 'sf-menu-item', href: downloadUrl( item.name ), text: 'Download to your computer', role: 'menuitem' } ) );
			if ( ! item.error ) {
				ready.filter( ( d ) => item.places.indexOf( d.id ) === -1 ).forEach( ( d ) => entries.push( menuItem( 'Send to ' + d.short, () => sendTo( d, item.name ) ) ) );
			}
		}
		item.places.forEach( ( id ) => {
			const d = destination( id );
			if ( d && ! item.local ) {
				entries.push( menuItem( 'Download to this server', () => fetchFrom( d, item.name ) ) );
			}
		} );
		if ( item.local ) {
			entries.push( menuItem( 'Check the copy on this server', () => checkCopy( item, 'local' ) ) );
		}
		item.places.forEach( ( id ) => {
			const d = destination( id );
			if ( d && d.ready ) {
				entries.push( menuItem( 'Check the copy on ' + d.short, () => checkCopy( item, id ) ) );
			}
		} );
		if ( item.local ) {
			entries.push( menuItem( 'Delete from this server', () => deleteBackup( item ), true ) );
		}
		item.places.forEach( ( id ) => {
			const d = destination( id );
			if ( d ) {
				entries.push( menuItem( 'Delete from ' + d.short, () => deleteRemote( d, item ), true ) );
			}
		} );
		return el( 'details', { class: 'sf-menu' }, [
			el( 'summary', { class: 'sf-icon-button', title: 'More actions', 'aria-label': 'More actions for ' + called( item ) }, [ dotsIcon() ] ),
			el( 'div', { class: 'sf-menu-list', role: 'menu' }, entries ),
		] );
	}

	/** Three dots, drawn centred (the icon font's glyph sits low). */
	function dotsIcon() {
		const ns = 'http://www.w3.org/2000/svg';
		const svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 16 4' );
		svg.setAttribute( 'width', '16' );
		svg.setAttribute( 'height', '4' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		[ 2, 8, 14 ].forEach( ( x ) => {
			const dot = document.createElementNS( ns, 'circle' );
			dot.setAttribute( 'cx', String( x ) );
			dot.setAttribute( 'cy', '2' );
			dot.setAttribute( 'r', '1.6' );
			svg.appendChild( dot );
		} );
		return svg;
	}

	function menuItem( text, action, danger ) {
		return el( 'button', {
			type: 'button',
			class: 'sf-menu-item' + ( danger ? ' is-danger' : '' ),
			role: 'menuitem',
			text,
			onclick: ( event ) => {
				event.target.closest( 'details' ).open = false;
				action();
			},
		} );
	}

	function closeMenus( except ) {
		document.querySelectorAll( '.sf-menu[open]' ).forEach( ( open ) => {
			if ( open !== except ) {
				open.open = false;
			}
		} );
	}

	/** The display name of a backup in messages. */
	const called = ( item ) => ( item.title ? '"' + item.title + '"' : item.name );

	async function deleteBackup( item ) {
		const last = ! item.places.length;
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Delete ' + called( item ) + ' from this server?' + ( last ? ' It is the only copy; this cannot be undone.' : ' The copies on storage stay.' ) ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'backups/' + encodeURIComponent( item.name ) );
			if ( last ) {
				await forgetLabel( item );
			}
			notice( 'Deleted ' + called( item ) + ' from this server.', 'success' );
		} catch ( error ) {
			notice( 'Could not delete: ' + error.message, 'error' );
		}
		loadLocalBackups();
	}

	async function deleteRemote( d, item ) {
		const last = ! item.local && item.places.length === 1;
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Delete ' + called( item ) + ' from ' + d.label + '?' + ( last ? ' It is the only copy; this cannot be undone.' : '' ) ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'storage/' + d.id + '/backups/' + encodeURIComponent( item.name ) );
			if ( last ) {
				await forgetLabel( item );
			}
			notice( 'Deleted ' + called( item ) + ' from ' + d.label + '.', 'success' );
		} catch ( error ) {
			notice( 'Could not delete: ' + error.message, 'error' );
		}
		loadRemote();
	}

	/** With the last copy gone, its name, note and tags go too. */
	async function forgetLabel( item ) {
		if ( item.title || item.note || ( item.tags && item.tags.length ) || labels[ item.name ] ) {
			try {
				await api( 'POST', 'labels/' + encodeURIComponent( item.name ), { json: { title: '', note: '', tags: [] } } );
			} catch ( error ) {}
			delete labels[ item.name ];
		}
	}

	// ------------------------------------------------------------ uploads

	/**
	 * Uploads a file in chunks. Each chunk carries its offset and SHA-256; the server accepts it
	 * only at the current end of the file. After a reload, choosing the same file again resumes.
	 */
	async function uploadFile( file, onProgress ) {
		const key = 'siteferry.upload.' + [ file.name, file.size, file.lastModified ].join( '|' );
		let session = null;
		const savedId = store.get( key );
		if ( savedId ) {
			try {
				session = await api( 'GET', 'uploads/' + savedId );
			} catch ( error ) {
				session = null;
			}
		}
		if ( ! session || session.size !== file.size ) {
			session = await api( 'POST', 'uploads/new', { json: { name: file.name, size: file.size } } );
			store.set( key, session.id );
		}

		let offset = session.offset;
		let failures = 0;
		while ( offset < file.size ) {
			const buffer = await file.slice( offset, Math.min( file.size, offset + cfg.chunk ) ).arrayBuffer();
			const hash = await sha256( buffer );
			try {
				const result = await api( 'POST', 'uploads/' + session.id + '/chunk', {
					raw: buffer,
					headers: { 'X-SiteFerry-Offset': String( offset ), 'X-SiteFerry-Sha256': hash },
				} );
				offset = result.offset;
				failures = 0;
				onProgress( offset, file.size );
			} catch ( error ) {
				if ( error.status === 409 ) {
					offset = ( await api( 'GET', 'uploads/' + session.id ) ).offset;
					continue;
				}
				if ( error.status === 403 || ++failures > 6 ) {
					throw error;
				}
				await sleep( Math.min( 30000, 1000 * 2 ** failures ) );
			}
		}
		const done = await api( 'POST', 'uploads/' + session.id + '/finish' );
		store.remove( key );
		return done.backup;
	}

	async function uploadChosen( file ) {
		const view = progressView( 'siteferry-upload-progress', [ 'Your computer', 'This server' ] );
		view.start( 'Uploading ' + file.name + '...' );
		try {
			const name = await uploadFile( file, ( done, total ) => view.update( done, total, 'Uploading ' + file.name + ': ' + bytes( done ) + ' of ' + bytes( total ) ) );
			await loadLocalBackups();
			const item = items().find( ( entry ) => entry.name === name );
			const nodes = [ el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: name } ), ' is in the list below. Nothing on this site has changed.' ] ) ];
			if ( item && ! item.error ) {
				nodes.push( el( 'p', { class: 'sf-actions' }, [ button( ours( item ) ? 'Restore it' : 'Move it here', () => openRestore( item ), 'button button-primary' ) ] ) );
			} else if ( item && item.error ) {
				nodes.push( el( 'p', { text: item.error } ) );
			}
			view.finish( true, 'Uploaded and checked', nodes );
		} catch ( error ) {
			view.finish( false, 'Upload stopped', [ el( 'p', { text: error.message + ' Choose the same file again to continue where it stopped.' } ) ] );
		}
	}

	function onFileChosen( event ) {
		const file = event.target.files[ 0 ];
		event.target.value = '';
		if ( file ) {
			uploadChosen( file );
		}
	}

	/** The drop zone takes a file dragged onto it like one chosen with the file picker. */
	function setUpDropZone() {
		const zone = $( 'siteferry-drop' );
		const over = ( on ) => ( event ) => {
			event.preventDefault();
			zone.classList.toggle( 'is-over', on );
		};
		zone.addEventListener( 'dragenter', over( true ) );
		zone.addEventListener( 'dragover', over( true ) );
		zone.addEventListener( 'dragleave', over( false ) );
		zone.addEventListener( 'drop', ( event ) => {
			over( false )( event );
			const file = event.dataTransfer && event.dataTransfer.files[ 0 ];
			if ( file ) {
				uploadChosen( file );
			}
		} );
	}

	// ------------------------------------------------------------ restore

	/**
	 * Opens the restore panel for a backup and checks it straight away (nothing on the site changes
	 * until "Restore now"). A backup that is only on a storage is downloaded to this server first.
	 */
	/**
	 * Opens the restore dialog for a backup. `partial` ({ options, label }) restores only the parts
	 * ticked in "Look inside".
	 */
	function openRestore( item, partial ) {
		if ( restore && ( restore.phase === 'running' || restore.phase === 'fetch' ) ) {
			notice( 'A restore is already under way.', 'error' );
			$( 'siteferry-restore' ).scrollIntoView( { block: 'nearest' } );
			return;
		}
		if ( restore && restore.job && restore.phase === 'ready' ) {
			cancelJob( restore.job.id, restore.job.token );
		}
		closeMenus();
		restore = { item, mine: ours( item ), host: hostOf( item.source ), name: item.local ? item.name : null, job: null, phase: 'open', seq: 0, partial: partial || null };
		const paths = partial ? partial.options.restore_paths || [] : [];
		$( 'siteferry-safety' ).checked = true;
		$( 'siteferry-replace-url' ).value = '';
		// Must-use plugins and drop-ins ticked on purpose are copied.
		$( 'siteferry-include-mu' ).checked = paths.indexOf( 'mu-plugins' ) !== -1;
		$( 'siteferry-include-dropins' ).checked = paths.some( ( path ) => DROP_INS.indexOf( path ) !== -1 );
		$( 'siteferry-restore-what-chosen' ).hidden = ! partial;
		$( 'siteferry-restore-what-label' ).textContent = partial ? 'Only what was ticked: ' + partial.label : '';
		document.querySelector( 'input[name=siteferry_restore_what][value=' + ( partial ? 'chosen' : 'all' ) + ']' ).checked = true;
		$( 'siteferry-restore-advanced' ).open = !! partial;
		$( 'siteferry-restore-advanced' ).hidden = false;
		$( 'siteferry-inspection' ).hidden = true;
		$( 'siteferry-inspection-warnings' ).replaceChildren();
		$( 'siteferry-crossing' ).hidden = true;
		$( 'siteferry-import-cancel' ).hidden = false;
		$( 'siteferry-import-cancel' ).textContent = 'Cancel';
		setWording( restore.mine, restore.host );
		setConfirm( false );
		const panel = $( 'siteferry-restore' );
		panel.hidden = false;
		panel.scrollIntoView( { block: 'start' } );
		panel.focus( { preventScroll: true } );
		if ( restore.name ) {
			startCheck();
		} else {
			fetchForRestore();
		}
	}

	/** Title, explanation and button text: restoring this site, or moving another one here. */
	function setWording( mine, host ) {
		const item = restore.item;
		const fromStorage = item && ! item.local && item.places.length ? ' It is downloaded from ' + ( destination( item.places[ 0 ] ) || { label: 'the storage' } ).label + ' to this server first.' : '';
		const part = restoreWhat().label;
		const then = item ? ( item.title ? '“' + item.title + '” (' + when( item.time, true ) + ')' : when( item.time, true ) ) : '';
		if ( mine && part ) {
			$( 'siteferry-restore-title' ).textContent = item ? 'Restore part of this site from ' + then : 'Restore part of a backup';
			$( 'siteferry-restore-lead' ).textContent = 'This restores only ' + part + ', as it was then; everything else on this site stays as it is now.' + fromStorage;
			$( 'siteferry-confirm' ).textContent = 'Restore now';
			$( 'siteferry-confirm-text' ).textContent = 'I understand that this replaces ' + part + ' on this site with the backup’s.';
		} else if ( mine ) {
			$( 'siteferry-restore-title' ).textContent = item ? 'Restore this site to ' + then : 'Restore a backup';
			$( 'siteferry-restore-lead' ).textContent =
				'Posts, pages, settings, users, plugins, themes and media go back to how they were then; anything added or changed since is lost. SiteFerry and its storage settings stay as they are.' + fromStorage;
			$( 'siteferry-confirm' ).textContent = 'Restore now';
			$( 'siteferry-confirm-text' ).textContent = 'I understand that this replaces this site’s content with the backup.';
		} else if ( part ) {
			const name = host || 'the other site';
			$( 'siteferry-restore-title' ).textContent = 'Copy part of ' + name + ' here';
			$( 'siteferry-restore-lead' ).textContent =
				'This copies only ' + part + ' from ' + name + ', replacing what this site has; everything else here stays as it is. Addresses in what is copied are changed to this site’s (' + hostOf( cfg.home ) + ').' + fromStorage;
			$( 'siteferry-confirm' ).textContent = 'Copy here now';
			$( 'siteferry-confirm-text' ).textContent = 'I understand that this replaces ' + part + ' on this site with ' + name + '’s.';
		} else {
			const name = host || 'the other site';
			$( 'siteferry-restore-title' ).textContent = 'Move ' + name + ' here';
			$( 'siteferry-restore-lead' ).textContent =
				'This site’s content is replaced by the content of ' + name + ', and its addresses are changed to this site’s (' + hostOf( cfg.home ) + '). SiteFerry and its storage settings stay as they are. Afterwards you log in with that site’s users.' + fromStorage;
			$( 'siteferry-confirm' ).textContent = 'Move here now';
			$( 'siteferry-confirm-text' ).textContent = 'I understand that this replaces this site’s content with ' + name + '’s.';
		}
	}

	/** Shows the confirm button; moving another site here also needs the tick. */
	function setConfirm( ready ) {
		const tick = $( 'siteferry-confirm-check' );
		$( 'siteferry-safety-row' ).hidden = ! ready;
		$( 'siteferry-confirm-row' ).hidden = ! ready || restore.mine;
		tick.checked = false;
		$( 'siteferry-confirm' ).hidden = false;
		$( 'siteferry-confirm' ).disabled = ! ready || ! restore.mine;
	}

	async function fetchForRestore() {
		const d = destination( restore.item.places[ 0 ] );
		restore.phase = 'fetch';
		const view = progressView( 'siteferry-import-progress', [ d ? d.short : 'Storage', 'This server' ] );
		view.start( 'Downloading the backup from ' + ( d ? d.label : 'the storage' ) + '...' );
		try {
			const started = await api( 'POST', 'storage/' + d.id + '/fetch', { json: { name: restore.item.name } } );
			remember( started.job, started.token );
			restore.job = { id: started.job.id, token: started.token };
			const job = await driveJob( started.job.id, started.token, progressHandler( view ) );
			store.remove( JOB_KEY );
			restore.job = null;
			if ( job.status !== 'done' ) {
				restore.phase = 'failed';
				view.finish( false, 'The backup could not be downloaded', [ el( 'p', { text: job.error } ) ] );
				return;
			}
			restore.name = job.result.fetched.name;
			loadLocalBackups();
			await startCheck();
		} catch ( error ) {
			restore.phase = 'failed';
			view.finish( false, 'The backup could not be downloaded', [ el( 'p', { text: error.message } ) ] );
		}
	}

	/** Starts (or restarts, after an Advanced change) the check: an import job that stops before changing anything. */
	async function startCheck() {
		const seq = ++restore.seq;
		const previous = restore.job;
		restore.job = null;
		restore.phase = 'check';
		setConfirm( false );
		$( 'siteferry-inspection' ).hidden = true;
		$( 'siteferry-inspection-warnings' ).replaceChildren();
		if ( previous ) {
			await cancelJob( previous.id, previous.token );
		}
		const view = progressView( 'siteferry-import-progress', [ 'Backup', 'This site' ] );
		view.start( 'Checking the backup; nothing on this site changes yet...' );
		try {
			const started = await api( 'POST', 'imports', {
				json: {
					backup: restore.name,
					replace_url: $( 'siteferry-replace-url' ).value.trim(),
					include_mu_plugins: $( 'siteferry-include-mu' ).checked,
					include_drop_ins: $( 'siteferry-include-dropins' ).checked,
					...restoreWhat().options,
				},
			} );
			if ( ! restore || seq !== restore.seq ) {
				await cancelJob( started.job.id, started.token );
				return;
			}
			restore.job = { id: started.job.id, token: started.token };
			importJob = restore.job;
			remember( started.job, started.token );
			await continueImport( view, seq );
		} catch ( error ) {
			if ( restore && seq === restore.seq ) {
				restore.phase = 'failed';
				view.finish( false, 'This backup cannot be restored here', [ el( 'p', { text: error.message } ) ] );
			}
		}
	}

	let recheckTimer = null;
	function recheckSoon() {
		if ( ! restore || ( restore.phase !== 'ready' && restore.phase !== 'check' ) ) {
			return;
		}
		clearTimeout( recheckTimer );
		recheckTimer = setTimeout( startCheck, 700 );
	}

	async function continueImport( view, seq ) {
		let job;
		try {
			job = await driveJob( importJob.id, importJob.token, progressHandler( view ) );
		} catch ( error ) {
			if ( restore && seq !== undefined && seq !== restore.seq ) {
				return;
			}
			throw error;
		}
		if ( restore && seq !== undefined && seq !== restore.seq ) {
			return; // An older check, replaced by a newer one.
		}
		if ( job.status === 'waiting' ) {
			restore.phase = 'ready';
			showInspection( job.result.inspection );
			view.finish( true, 'Checked: ready', [ el( 'p', { text: 'Nothing on this site has changed yet.' } ) ], false );
			return;
		}
		store.remove( JOB_KEY );
		$( 'siteferry-import-cancel' ).textContent = 'Close';
		$( 'siteferry-import-cancel' ).hidden = false;
		$( 'siteferry-confirm' ).hidden = true;
		$( 'siteferry-confirm-row' ).hidden = true;
		$( 'siteferry-safety-row' ).hidden = true;
		$( 'siteferry-restore-advanced' ).hidden = true;
		if ( job.status === 'done' ) {
			const r = job.result.imported;
			const safety = job.result.safety;
			restore.phase = 'done';
			const moved = siteKey( r.from ) !== siteKey( r.to );
			const done = r.partial
				? ( moved ? 'Copied ' : 'Restored ' ) + r.partial.replace( /^Only /, 'only ' ) + ( moved ? ' from ' + hostOf( r.from ) : '' ) + '; everything else on this site stayed as it was.'
				: moved
				? 'This site now has the content of ' + hostOf( r.from ) + ', at ' + hostOf( r.to ) + '.'
				: 'This site is back to how it was when the backup was made.';
			view.finish( true, r.partial ? ( moved ? 'Copied part of ' + hostOf( r.from ) + ' here' : 'Restored part of the site' ) : moved ? 'Moved ' + hostOf( r.from ) + ' here' : 'Restored', [
				el( 'p', { text: done } ),
				...( safety ? [ el( 'p', { text: 'A safety backup of the site as it was before (' + bytes( safety.size ) + ') is in the list. To undo, use "Undo this restore" on the Backups tab, or restore that backup.' } ) ] : [] ),
				el( 'p', { text: moved ? 'Log in with one of that site’s users.' : 'You may need to log in again.' } ),
				el( 'p', { class: 'sf-actions' }, [ el( 'a', { class: 'button button-primary', href: cfg.loginUrl, text: 'Log in' } ) ] ),
				...warningList( job ),
			] );
			return;
		}
		restore.phase = 'failed';
		const rolledBack = ! ( job.result && job.result.rollback_errors );
		view.finish( false, restore.mine ? 'The restore did not finish' : 'The move did not finish', [
			el( 'p', { text: job.error } ),
			el( 'p', { text: rolledBack ? 'This site was left as it was before.' : 'Putting the previous site back did not fully succeed; see the Activity tab.' } ),
			...warningList( job ),
		] );
	}

	/** The check's findings, and whether this is the same site or a move (decided by the backup itself). */
	function showInspection( info ) {
		const mine = siteKey( info.from ) === siteKey( info.to );
		restore.host = hostOf( info.from );
		if ( mine !== restore.mine ) {
			restore.mine = mine;
			setWording( mine, restore.host );
		}
		const end = ( label, value ) => el( 'div', { class: 'sf-crossing-end' }, [ el( 'small', { text: label } ), el( 'code', { text: value } ) ] );
		$( 'siteferry-crossing' ).replaceChildren( end( 'From', info.from ), el( 'span', { class: 'sf-crossing-line', 'aria-hidden': 'true' } ), end( 'Into', info.to ) );
		$( 'siteferry-crossing' ).hidden = mine;
		const rows = [
			[ 'Backup made', date( info.created ) ],
			[ 'Made with', 'WordPress ' + info.wordpress + ', PHP ' + info.php + ', MySQL ' + info.mysql ],
			[ 'Contents', plural( info.tables, 'table', 'tables' ) + ', ' + plural( info.files, 'file', 'files' ) + ', ' + bytes( info.bytes ) ],
			[ 'File', info.backup ],
		];
		if ( ! mine ) {
			rows.splice( 3, 0, [ 'Address changes', plural( info.replacements, 'search/replace pair', 'search/replace pairs' ) ] );
		}
		if ( info.restores ) {
			rows.unshift( [ 'Restores', info.restores ] );
		}
		$( 'siteferry-inspection-rows' ).replaceChildren( ...rows.map( ( [ k, v ] ) => el( 'tr', {}, [ el( 'th', { scope: 'row', text: k } ), el( 'td', { text: v } ) ] ) ) );
		$( 'siteferry-inspection' ).hidden = false;
		$( 'siteferry-inspection-warnings' ).replaceChildren( ...( info.warnings || [] ).map( ( w ) => el( 'li', { text: w } ) ) );
		setConfirm( true );
	}

	async function confirmImport() {
		if ( ! restore || restore.phase !== 'ready' ) {
			return;
		}
		restore.phase = 'running';
		const view = progressView( 'siteferry-import-progress', [ 'Backup', 'This site' ] );
		const safety = $( 'siteferry-safety' ).checked;
		$( 'siteferry-confirm' ).hidden = true;
		$( 'siteferry-confirm-row' ).hidden = true;
		$( 'siteferry-safety-row' ).hidden = true;
		$( 'siteferry-import-cancel' ).hidden = true;
		$( 'siteferry-restore-advanced' ).hidden = true;
		$( 'siteferry-inspection' ).hidden = true;
		view.start( safety ? 'Making a safety backup of this site first...' : restore.mine ? 'Restoring...' : 'Moving the site here...' );
		try {
			await api( 'POST', 'jobs/' + importJob.id + '/confirm', { token: importJob.token, json: { safety } } );
			await continueImport( view );
		} catch ( error ) {
			view.finish( false, 'Stopped', [ el( 'p', { text: error.message } ) ] );
		}
	}

	/** The parts to restore, from "What to restore": import options and words for them ('' for everything). */
	function restoreWhat() {
		const checked = document.querySelector( 'input[name=siteferry_restore_what]:checked' );
		const what = checked ? checked.value : 'all';
		if ( what === 'database' ) {
			return { options: { restore_files: false }, label: 'the database' };
		}
		if ( what === 'files' ) {
			return { options: { restore_database: false }, label: 'the files' };
		}
		if ( what === 'chosen' && restore && restore.partial ) {
			return restore.partial;
		}
		return { options: {}, label: '' };
	}

	/** Cancel (before anything changed) or Close (afterwards). */
	async function closeRestore() {
		if ( restore && restore.phase === 'running' ) {
			return;
		}
		clearTimeout( recheckTimer );
		const open = restore;
		restore = null;
		$( 'siteferry-restore' ).hidden = true;
		if ( open && open.job && ( open.phase === 'ready' || open.phase === 'check' || open.phase === 'fetch' ) ) {
			await cancelJob( open.job.id, open.job.token );
		}
	}

	async function cancelJob( id, token ) {
		try {
			await api( 'POST', 'jobs/' + id + '/cancel', { token } );
		} catch ( error ) {
			if ( error.status !== 404 ) {
				notice( 'Could not cancel: ' + error.message, 'error' );
			}
			return false;
		}
		store.remove( JOB_KEY );
		return true;
	}

	// ------------------------------------------------------------ look inside a backup

	/**
	 * The contents panel: one folder of a backup at a time, single files to download, and ticks for
	 * the parts a partial restore can bring back on their own: the whole database or single tables;
	 * all files, a top-level item of wp-content, or one plugin or theme.
	 */
	let inside = null;

	function openContents( item ) {
		closeMenus();
		inside = { item, dir: '', seq: 0, tables: null, hasDatabase: false, picks: new Map() };
		$( 'siteferry-contents-title' ).textContent = 'Inside ' + called( item );
		$( 'siteferry-contents-crumbs' ).replaceChildren();
		$( 'siteferry-contents-body' ).replaceChildren( el( 'p', { class: 'sf-contents-wait', text: 'Reading the list of files in the backup...' } ) );
		renderPicks();
		const panel = $( 'siteferry-contents' );
		panel.hidden = false;
		panel.scrollIntoView( { block: 'start' } );
		panel.focus( { preventScroll: true } );
		browse( '' );
	}

	function closeContents() {
		inside = null;
		$( 'siteferry-contents' ).hidden = true;
	}

	/** Shows one folder; the first call may need several requests while the backup is read. */
	async function browse( dir ) {
		if ( ! inside ) {
			return;
		}
		const seq = ++inside.seq;
		const body = $( 'siteferry-contents-body' );
		let data;
		for ( ;; ) {
			try {
				data = await api( 'GET', 'backups/' + encodeURIComponent( inside.item.name ) + '/contents', { query: { dir } } );
			} catch ( error ) {
				if ( inside && seq === inside.seq ) {
					body.replaceChildren( el( 'p', { class: 'sf-row-error', text: 'The backup cannot be read: ' + error.message } ) );
				}
				return;
			}
			if ( ! inside || seq !== inside.seq ) {
				return;
			}
			if ( data.complete ) {
				break;
			}
			const share = data.size ? Math.min( 99, Math.floor( ( 100 * data.read ) / data.size ) ) : 0;
			body.replaceChildren( el( 'p', { class: 'sf-contents-wait', text: 'Reading the list of files in the backup... ' + share + '% (' + plural( data.count, 'entry', 'entries' ) + ' so far)' } ) );
		}
		inside.dir = data.dir;
		if ( data.dir === '' ) {
			inside.tables = data.tables;
			inside.hasDatabase = data.tables !== null || data.entries.some( ( entry ) => entry.path === 'database.sql' );
		}
		renderCrumbs( data.dir );
		renderFolder( data );
	}

	function renderCrumbs( dir ) {
		const parts = dir ? dir.split( '/' ) : [];
		const crumb = ( text, target, current ) =>
			current ? el( 'span', { class: 'sf-crumb is-current', 'aria-current': 'location', text } ) : el( 'button', { type: 'button', class: 'sf-crumb', text, onclick: () => browse( target ) } );
		const nodes = [ crumb( 'Backup', '', ! parts.length ) ];
		parts.forEach( ( part, i ) => {
			nodes.push( el( 'span', { class: 'sf-crumb-sep', 'aria-hidden': 'true', text: '/' } ), crumb( part, parts.slice( 0, i + 1 ).join( '/' ), i === parts.length - 1 ) );
		} );
		$( 'siteferry-contents-crumbs' ).replaceChildren( ...nodes );
	}

	function renderFolder( data ) {
		const nodes = [];
		if ( data.dir === '' && inside.hasDatabase ) {
			nodes.push( databaseBlock() );
		}
		if ( data.entries.length ) {
			nodes.push( el( 'table', { class: 'sf-tree' }, [ el( 'tbody', {}, data.entries.map( entryRow ) ) ] ) );
		} else {
			nodes.push( el( 'p', { class: 'description', text: 'This folder is empty.' } ) );
		}
		$( 'siteferry-contents-body' ).replaceChildren( ...nodes );
	}

	function databaseBlock() {
		const tables = inside.tables;
		const rows = tables ? tables.reduce( ( n, t ) => n + t.rows, 0 ) : 0;
		const block = el( 'div', { class: 'sf-contents-db' }, [
			el( 'label', { class: 'sf-contents-db-all' }, [
				pickBox( 'db', 'the whole database' ),
				el( 'span', { class: 'dashicons dashicons-database', 'aria-hidden': 'true' } ),
				el( 'strong', { text: 'Database' } ),
				el( 'span', { class: 'sf-tree-what', text: tables ? plural( tables.length, 'table', 'tables' ) + ', ' + plural( rows, 'row', 'rows' ) : 'its tables are listed when the backup is checked for restoring' } ),
			] ),
		] );
		if ( tables && tables.length ) {
			block.appendChild(
				el( 'details', { class: 'sf-more' }, [
					el( 'summary', { text: 'Single tables' } ),
					el(
						'div',
						{ class: 'sf-tables' },
						tables.map( ( t ) => el( 'label', {}, [ pickBox( 't:' + t.name, 'table ' + t.name ), ' ' + t.name + ' ', el( 'span', { class: 'sf-table-size', text: plural( t.rows, 'row', 'rows' ) } ) ] ) )
					),
				] )
			);
		}
		return block;
	}

	function fileUrl( name, path ) {
		return url( 'backups/' + encodeURIComponent( name ) + '/file', { path, _wpnonce: cfg.nonce } );
	}

	function entryRow( entry ) {
		const dir = entry.type === 'dir';
		const key = pickKey( entry.path );
		const name = dir
			? el( 'button', { type: 'button', class: 'sf-tree-open', text: entry.name, onclick: () => browse( entry.path ) } )
			: el( 'span', { class: 'sf-tree-file', text: entry.name } );
		const action =
			entry.type === 'file' ? el( 'a', { class: 'sf-tree-download', href: fileUrl( inside.item.name, entry.path ), text: 'Download', 'aria-label': 'Download ' + entry.name } ) : document.createTextNode( '' );
		return el( 'tr', { class: dir ? 'is-dir' : 'is-file' }, [
			el( 'td', { class: 'sf-tree-pick' }, [ key ? pickBox( key, key === 'files' ? 'all files' : entry.path ) : document.createTextNode( '' ) ] ),
			el( 'td', { class: 'sf-tree-name' }, [ el( 'span', { class: 'dashicons ' + ( dir ? 'dashicons-category' : 'dashicons-media-default' ), 'aria-hidden': 'true' } ), name ] ),
			el( 'td', { class: 'sf-tree-what', text: dir ? plural( entry.files, 'file', 'files' ) : entry.type === 'link' ? 'link' : date( entry.mtime ) } ),
			el( 'td', { class: 'sf-tree-size', text: bytes( entry.size ) } ),
			el( 'td', { class: 'sf-tree-action' }, [ action ] ),
		] );
	}

	/** The pick a path in the backup stands for on its own ('files' or 'p:<path in wp-content>'), or ''. */
	function pickKey( path ) {
		if ( path === 'wp-content' ) {
			return 'files';
		}
		if ( path.indexOf( 'wp-content/' ) !== 0 ) {
			return '';
		}
		const rel = path.slice( 'wp-content/'.length );
		const parts = rel.split( '/' );
		if ( rel === 'plugins/siteferry' || parts[ 0 ].indexOf( 'siteferry-' ) === 0 ) {
			return ''; // SiteFerry itself and its storage folder are never replaced.
		}
		return parts.length === 1 || ( parts.length === 2 && ( parts[ 0 ] === 'plugins' || parts[ 0 ] === 'themes' ) ) ? 'p:' + rel : '';
	}

	/** The wider pick that already includes this one (the whole database, all files, a whole folder), or ''. */
	function coveredBy( key ) {
		const picks = inside.picks;
		if ( key.indexOf( 't:' ) === 0 ) {
			return picks.has( 'db' ) ? 'db' : '';
		}
		if ( key.indexOf( 'p:' ) === 0 ) {
			if ( picks.has( 'files' ) ) {
				return 'files';
			}
			const top = 'p:' + key.slice( 2 ).split( '/' )[ 0 ];
			return top !== key && picks.has( top ) ? top : '';
		}
		return '';
	}

	function pickBox( key, label ) {
		const box = el( 'input', { type: 'checkbox', 'data-key': key, 'aria-label': 'Restore ' + label } );
		box.addEventListener( 'change', () => setPick( key, box.checked, label ) );
		syncBox( box );
		return box;
	}

	function syncBox( box ) {
		const wider = coveredBy( box.dataset.key );
		box.checked = wider !== '' || inside.picks.has( box.dataset.key );
		box.disabled = wider !== '';
		box.title = wider ? 'Included in ' + inside.picks.get( wider ) : '';
	}

	function setPick( key, on, label ) {
		const picks = inside.picks;
		if ( on ) {
			picks.set( key, label );
			// A wider pick replaces the narrower ones it includes.
			[ ...picks.keys() ].forEach( ( other ) => {
				const narrower =
					( key === 'db' && other.indexOf( 't:' ) === 0 ) || ( key === 'files' && other.indexOf( 'p:' ) === 0 ) || ( key.indexOf( 'p:' ) === 0 && other.indexOf( key + '/' ) === 0 );
				if ( narrower ) {
					picks.delete( other );
				}
			} );
		} else {
			picks.delete( key );
		}
		document.querySelectorAll( '#siteferry-contents-body input[data-key]' ).forEach( syncBox );
		renderPicks();
	}

	function clearPicks() {
		if ( inside ) {
			inside.picks.clear();
			document.querySelectorAll( '#siteferry-contents-body input[data-key]' ).forEach( syncBox );
			renderPicks();
		}
	}

	/** "the whole database, wp-content/plugins/akismet and 2 more". */
	function describePicks( picks ) {
		const names = [ ...picks.values() ];
		const shown = names.slice( 0, 6 );
		const more = names.length - shown.length;
		return shown.join( ', ' ) + ( more > 0 ? ' and ' + more + ' more' : '' );
	}

	function renderPicks() {
		const count = inside ? inside.picks.size : 0;
		$( 'siteferry-contents-foot' ).hidden = ! count;
		$( 'siteferry-contents-picked' ).textContent = count ? 'To restore: ' + describePicks( inside.picks ) + '.' : '';
	}

	/** The import options for the picks. */
	function partialOptions( picks ) {
		const keys = [ ...picks.keys() ];
		const tables = keys.filter( ( k ) => k.indexOf( 't:' ) === 0 ).map( ( k ) => k.slice( 2 ) );
		const paths = keys.filter( ( k ) => k.indexOf( 'p:' ) === 0 ).map( ( k ) => k.slice( 2 ) );
		return {
			restore_database: picks.has( 'db' ) || tables.length > 0,
			restore_tables: picks.has( 'db' ) ? [] : tables,
			restore_files: picks.has( 'files' ) || paths.length > 0,
			restore_paths: picks.has( 'files' ) ? [] : paths,
		};
	}

	function restorePicked() {
		if ( ! inside || ! inside.picks.size ) {
			return;
		}
		const item = inside.item;
		const partial = { options: partialOptions( inside.picks ), label: describePicks( inside.picks ) };
		closeContents();
		openRestore( item, partial );
	}

	// ------------------------------------------------------------ schedule

	async function loadSchedule() {
		try {
			schedule = await api( 'GET', 'schedule' );
		} catch ( error ) {
			$( 'siteferry-schedule-line' ).hidden = true;
			return;
		}
		fillScheduleForm();
		renderScheduleLine();
		if ( schedule.running ) {
			watchScheduled( schedule.running );
		}
	}

	/** Puts the saved schedule into the form. */
	function fillScheduleForm() {
		const form = $( 'siteferry-schedule-form' );
		const s = schedule.settings;
		Array.from( form.elements ).forEach( ( input ) => {
			if ( ! input.name || ! ( input.name in s ) ) {
				return;
			}
			if ( input.type === 'checkbox' ) {
				input.checked = !! s[ input.name ];
			} else if ( input.type === 'radio' ) {
				input.checked = input.value === String( s[ input.name ] );
			} else {
				input.value = String( s[ input.name ] );
			}
		} );
		form.elements.email.placeholder = schedule.admin_email ? schedule.admin_email + ' (the site\'s admin email)' : '';
		// WordPress names a plain offset "+05:00"; show it as UTC+05:00 (and "+00:00" as UTC).
		const zone = /^[+-]\d{2}:\d{2}$/.test( schedule.timezone ) ? 'UTC' + ( schedule.timezone === '+00:00' ? '' : schedule.timezone ) : schedule.timezone;
		$( 'siteferry-schedule-zone' ).textContent = 'In the site\'s time zone (' + zone + ', set under Settings, General). WordPress starts scheduled tasks when the site gets a visit, so on a quiet site a backup can start a little late.';
		$( 'siteferry-schedule-cron-off' ).hidden = ! schedule.cron_disabled;
		renderScheduleWhere( true );
		syncScheduleForm();
		renderScheduleStatus();
	}

	/** "Where it goes": this server, or a storage that is ready. */
	function renderScheduleWhere( fromSaved ) {
		const box = $( 'siteferry-schedule-where' );
		const checked = box.querySelector( 'input:checked' );
		const current = fromSaved || ! checked ? schedule.settings.send_to : checked.value;
		const ready = destinations.filter( ( d ) => d.ready );
		box.replaceChildren(
			box.querySelector( '.sf-choice' ),
			...ready.map( ( d ) =>
				el( 'label', { class: 'sf-choice' }, [
					el( 'input', { type: 'radio', name: 'send_to', value: d.id } ),
					el( 'span', { class: 'sf-choice-name', text: d.label } ),
					el( 'span', { class: 'sf-choice-note', text: d.description } ),
				] )
			)
		);
		const pick = Array.from( box.querySelectorAll( 'input' ) ).find( ( input ) => input.value === current ) || box.querySelector( 'input' );
		pick.checked = true;
		$( 'siteferry-schedule-no-storage' ).hidden = ready.length > 0;
		syncScheduleForm();
	}

	/** Shows the fields that apply: the day for weekly or monthly, keeping a copy, compression. */
	function syncScheduleForm() {
		const form = $( 'siteferry-schedule-form' );
		const frequency = form.elements.frequency.value;
		form.querySelectorAll( '[data-when]' ).forEach( ( field ) => {
			field.hidden = field.dataset.when !== frequency;
		} );
		const where = form.querySelector( 'input[name=send_to]:checked' );
		$( 'siteferry-schedule-keep-local' ).hidden = ! where || ! where.value;
		const format = form.querySelector( 'input[name=format]:checked' );
		const compress = form.querySelector( '[data-role=compress]' );
		if ( compress ) {
			compress.hidden = !! format && format.value === 'wpress';
		}
		form.classList.toggle( 'is-off', ! form.elements.enabled.checked );
	}

	/** The form's values, as POST /schedule takes them. */
	function collectSchedule() {
		const form = $( 'siteferry-schedule-form' );
		const data = {};
		Array.from( form.elements ).forEach( ( input ) => {
			if ( ! input.name ) {
				return;
			}
			if ( input.type === 'checkbox' ) {
				data[ input.name ] = input.checked;
			} else if ( input.type === 'radio' ) {
				if ( input.checked ) {
					data[ input.name ] = input.value;
				}
			} else {
				data[ input.name ] = input.value.trim();
			}
		} );
		[ 'weekday', 'monthday', 'retain', 'update_retain' ].forEach( ( key ) => {
			data[ key ] = data[ key ] === '' ? NaN : Number( data[ key ] );
		} );
		return data;
	}

	/** "every Monday at 03:00, to Dropbox, keeping the newest 7". */
	function describeSchedule( s ) {
		const at = ' at ' + s.time;
		const when = s.frequency === 'weekly' ? 'every ' + WEEKDAYS[ s.weekday ] + at : s.frequency === 'monthly' ? 'on day ' + s.monthday + ' of every month' + at : 'every day' + at;
		const target = s.send_to ? destination( s.send_to ) : null;
		const where = s.send_to ? 'to ' + ( target ? target.short : s.send_to ) + ( s.keep_local ? ' and this server' : '' ) : 'on this server';
		const keep = s.retain > 0 ? 'keeping the newest ' + s.retain : 'keeping them all';
		return when + ', ' + where + ', ' + keep;
	}

	/** The line under "Back up now": the schedule, the next and the last run. */
	function renderScheduleLine() {
		const line = $( 'siteferry-schedule-line' );
		const s = schedule.settings;
		const open = () => toggleDrawer( 'schedule', true );
		const parts = [];
		if ( ! s.enabled ) {
			parts.push( el( 'span', { class: 'sf-chip', text: 'Automatic backups off' } ), ' ', button( 'Set up a schedule', open, 'button sf-quiet sf-quiet-small' ) );
		} else {
			parts.push( el( 'span', { class: 'sf-chip is-good', text: 'Automatic backups on' } ), ' ' + describeSchedule( s ) + '.' );
			if ( schedule.next ) {
				parts.push( ' Next: ' + nextRun() + '.' );
			}
			if ( schedule.retry ) {
				parts.push( ' Waiting for another job to finish; trying again ' + upcoming( schedule.retry ) + '.' );
			}
			parts.push( ' ', button( 'Change', open, 'button sf-quiet sf-quiet-small' ) );
		}
		if ( s.before_updates ) {
			parts.push( el( 'span', { class: 'sf-schedule-last', text: 'A backup is also made before WordPress, a plugin or a theme updates.' } ) );
		}
		const last = schedule.last;
		if ( last ) {
			const failed = last.status !== 'done';
			parts.push(
				el( 'span', { class: 'sf-schedule-last' + ( failed ? ' is-bad' : '' ), text: 'Last scheduled backup ' + when( last.time, true ) + ': ' + ( failed ? ( last.status === 'skipped' ? 'skipped. ' : 'failed. ' ) + last.message : 'done' + ( last.where ? ', stored on ' + last.where : '' ) + '.' ) } )
			);
		}
		line.replaceChildren( ...parts );
		line.hidden = false;
	}

	/** Next and last run inside the drawer. */
	function renderScheduleStatus() {
		const box = $( 'siteferry-schedule-status' );
		const s = schedule.settings;
		const bits = [];
		if ( s.enabled && schedule.next ) {
			bits.push( 'Next backup: ' + nextRun() + '.' );
		}
		if ( schedule.kept ) {
			bits.push( plural( schedule.kept, 'scheduled backup', 'scheduled backups' ) + ' kept so far.' );
		}
		box.textContent = bits.join( ' ' );

		const update = schedule.last_update;
		const line = $( 'siteferry-update-last' );
		line.hidden = ! update;
		if ( update ) {
			const outcome = update.status === 'done' ? 'made (' + bytes( update.size ) + ')' : update.status === 'skipped' ? 'skipped: ' + update.message : 'failed: ' + update.message;
			line.textContent = 'Last backup before an update: ' + update.what + ', ' + when( update.time, true ) + ', ' + outcome + '.' + ( schedule.update_kept ? ' ' + plural( schedule.update_kept, 'backup made before updates is', 'backups made before updates are' ) + ' kept.' : '' );
		}
	}

	function scheduleResult( ok, text ) {
		const box = $( 'siteferry-schedule-result' );
		box.className = 'sf-result' + ( ok === true ? ' is-done' : ok === false ? ' is-failed' : '' );
		box.replaceChildren( el( 'p', { text } ) );
		box.hidden = false;
	}

	async function saveSchedule( event ) {
		if ( event ) {
			event.preventDefault();
		}
		const form = $( 'siteferry-schedule-form' );
		const submit = form.querySelector( 'button[type=submit]' );
		submit.disabled = true;
		try {
			schedule = await api( 'POST', 'schedule', { json: collectSchedule() } );
			fillScheduleForm();
			renderScheduleLine();
			scheduleResult( true, schedule.settings.enabled ? 'Saved. Next backup: ' + nextRun() + '.' : 'Saved. Automatic backups are off.' );
			return true;
		} catch ( error ) {
			scheduleResult( false, 'Not saved: ' + error.message );
			return false;
		} finally {
			submit.disabled = false;
		}
	}

	/** Saves the form, then starts the scheduled backup now; it runs by itself, the page watches. */
	async function runScheduleNow() {
		const run = $( 'siteferry-schedule-run' );
		run.disabled = true;
		try {
			if ( ! ( await saveSchedule() ) ) {
				return;
			}
			const result = await api( 'POST', 'schedule/run' );
			schedule = result;
			toggleDrawer( 'schedule', false );
			watchScheduled( result.job );
		} catch ( error ) {
			scheduleResult( false, 'Not started: ' + error.message );
		} finally {
			run.disabled = false;
		}
	}

	/**
	 * Shows a scheduled backup that runs by itself on the server: its route, live, and a way to stop
	 * it. The page only reads its state; leaving the page does not stop it.
	 */
	async function watchScheduled( job ) {
		if ( watching === job.id ) {
			return;
		}
		watching = job.id;
		const s = schedule ? schedule.settings : { send_to: '' };
		const target = destination( s.send_to );
		const view = progressView( 'siteferry-job-progress', [ 'This site', target ? target.short : 'This server' ] );
		view.start( 'Scheduled backup running...' );
		const banner = $( 'siteferry-active' );
		$( 'siteferry-active-resume' ).hidden = true;
		$( 'siteferry-active-cancel' ).textContent = 'Stop it';
		$( 'siteferry-active-cancel' ).onclick = async () => {
			// eslint-disable-next-line no-alert
			if ( window.confirm( 'Stop the scheduled backup? What it has done so far is removed; earlier backups stay.' ) && ( await cancelJob( job.id, null ) ) ) {
				notice( 'The scheduled backup was stopped.', 'success' );
			}
		};
		let failures = 0;
		while ( watching === job.id ) {
			$( 'siteferry-active-text' ).textContent = 'A scheduled backup is running (' + ( ( job.progress && job.progress.message ) || 'starting' ) + '). It runs by itself on the server; you can leave this page.';
			banner.hidden = false;
			view.job( job );
			if ( job.status === 'done' || job.status === 'failed' ) {
				break;
			}
			await sleep( 3000 );
			try {
				job = await api( 'GET', 'jobs/' + job.id );
				failures = 0;
			} catch ( error ) {
				if ( ++failures > 5 ) {
					view.finish( false, 'Lost track of the scheduled backup', [ el( 'p', { text: error.message + ' It may still be running; reload the page to check.' } ) ] );
					watching = '';
					banner.hidden = true;
					return;
				}
			}
		}
		watching = '';
		banner.hidden = true;
		$( 'siteferry-active-cancel' ).textContent = 'Cancel it';
		if ( job.status === 'done' ) {
			const r = job.result;
			const where = r.sent ? ( r.deleted_local ? 'on ' + r.sent.where : 'on this server and on ' + r.sent.where ) : 'on this server';
			const deleted = r.schedule && r.schedule.deleted ? r.schedule.deleted.length : 0;
			view.finish( true, 'Scheduled backup ready', [
				el( 'p', { text: bytes( r.size ) + ' · stored ' + where + '.' + ( deleted ? ' ' + plural( deleted, 'older scheduled backup was', 'older scheduled backups were' ) + ' deleted.' : '' ) } ),
				el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: r.name } ) ] ),
				...warningList( job ),
			] );
		} else {
			view.finish( false, /^cancel/i.test( job.error || '' ) ? 'The scheduled backup was stopped' : 'The scheduled backup failed', [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
		}
		loadBackups();
		loadSchedule();
	}

	// ------------------------------------------------------------ activity

	async function loadJobs() {
		const list = $( 'siteferry-jobs' );
		let jobs;
		try {
			jobs = await api( 'GET', 'jobs' );
		} catch ( error ) {
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: error.message } ) );
			return;
		}
		if ( ! jobs.length ) {
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: 'Nothing has run yet. Backups, restores and transfers show up here, each with its log.' } ) );
			return;
		}
		list.replaceChildren(
			...jobs.map( ( job ) => {
				const cancelled = job.status === 'failed' && /^cancel/i.test( job.error || '' );
				const [ label, tone ] = cancelled ? [ 'Cancelled', '' ] : JOB_STATES[ job.status ] || [ job.status, '' ];
				const row = el( 'li', { class: 'sf-row sf-job' } );
				const view = button( 'View log', () => toggleLog( row, job.id, view ), 'button sf-quiet' );
				row.append(
					el( 'span', { class: 'sf-job-when', text: date( job.created ) } ),
					el( 'div', { class: 'sf-job-what' }, [
						el( 'span', { class: 'sf-job-type', text: { schedule: 'Scheduled backup', safety: 'Safety backup', update: 'Backup before update' }[ job.origin ] || JOB_TYPES[ job.type ] || job.type } ),
						el( 'span', { class: 'sf-chip ' + tone, text: label } ),
						el( 'span', { class: 'sf-job-detail', text: job.error || ( job.progress && job.progress.message ) || '' } ),
					] ),
					el( 'div', { class: 'sf-row-actions' }, [ view ] )
				);
				return row;
			} )
		);
	}

	async function toggleLog( row, id, toggle ) {
		const open = row.querySelector( '.sf-log' );
		if ( open ) {
			open.remove();
			toggle.textContent = 'View log';
			return;
		}
		const view = el( 'pre', { class: 'sf-log', text: 'Loading...' } );
		row.appendChild( view );
		toggle.textContent = 'Hide log';
		try {
			const result = await api( 'GET', 'jobs/' + id + '/log' );
			view.textContent = ( result.truncated ? '[earlier lines omitted]\n' : '' ) + result.log;
		} catch ( error ) {
			view.textContent = error.message;
		}
	}

	// ------------------------------------------------------------ page

	function showTab( name ) {
		document.querySelectorAll( '.siteferry .nav-tab' ).forEach( ( tab ) => {
			const active = tab.dataset.tab === name;
			tab.classList.toggle( 'nav-tab-active', active );
			if ( active ) {
				tab.setAttribute( 'aria-current', 'page' );
			} else {
				tab.removeAttribute( 'aria-current' );
			}
		} );
		document.querySelectorAll( '.siteferry-panel' ).forEach( ( panel ) => {
			panel.hidden = panel.dataset.panel !== name;
		} );
		if ( name === 'backups' ) {
			loadBackups();
		}
		if ( name === 'activity' ) {
			loadJobs();
		}
		if ( window.location.hash !== '#' + name ) {
			window.history.replaceState( null, '', '#' + name );
		}
	}

	/** Opens or closes Options or Upload a backup (one at a time). */
	function toggleDrawer( which, force ) {
		const drawers = {
			options: [ 'siteferry-export-form', 'siteferry-options-toggle' ],
			upload: [ 'siteferry-upload', 'siteferry-upload-toggle' ],
			schedule: [ 'siteferry-schedule-form', 'siteferry-schedule-toggle' ],
		};
		Object.keys( drawers ).forEach( ( key ) => {
			const [ panel, toggle ] = drawers[ key ];
			const open = key === which ? ( force === undefined ? $( panel ).hidden : force ) : false;
			$( panel ).hidden = ! open;
			$( toggle ).setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			$( toggle ).classList.toggle( 'is-open', open );
		} );
	}

	/** After a restore with a safety backup: offers to undo it by restoring that backup. */
	function renderUndo( undo ) {
		const box = $( 'siteferry-undo' );
		if ( ! undo ) {
			box.hidden = true;
			return;
		}
		const moved = siteKey( undo.from ) !== siteKey( undo.to );
		box.replaceChildren(
			el( 'p', {}, [
				el( 'strong', { text: ( moved ? 'Moved ' + hostOf( undo.from ) + ' here ' : 'Restored ' ) + when( undo.time, true ) + '.' } ),
				' A safety backup of the site as it was just before is kept. Changed your mind?',
			] ),
			el( 'p', { class: 'sf-actions' }, [
				button( 'Undo this restore', () => undoRestore( undo ), 'button button-primary' ),
				button( 'Keep it as it is', dismissUndo, 'button sf-quiet' ),
			] )
		);
		box.hidden = false;
	}

	/** Opens the restore panel for the safety backup made before the last restore. */
	function undoRestore( undo ) {
		showTab( 'backups' );
		openRestore( { name: undo.safety, local: true, places: [], source: cfg.home, time: undo.time, title: 'the site before the last restore', size: 0 } );
	}

	async function dismissUndo() {
		try {
			await api( 'POST', 'undo/dismiss' );
			$( 'siteferry-undo' ).hidden = true;
			notice( 'Kept as it is. The safety backup stays in the list until you delete it.', 'success' );
		} catch ( error ) {
			notice( error.message, 'error' );
		}
	}

	/** Offers to resume (or cancel) a job that was running when the page was left. */
	async function checkActiveJob() {
		let status;
		try {
			status = await api( 'GET', 'status' );
		} catch ( error ) {
			notice( 'SiteFerry cannot reach its REST API: ' + error.message, 'error' );
			return;
		}
		renderUndo( status.undo );
		const unfinished = status.uploads || [];
		if ( unfinished.length ) {
			const box = $( 'siteferry-unfinished-uploads' );
			box.textContent = 'Unfinished upload: ' + unfinished.map( ( u ) => u.name + ' (' + bytes( u.offset ) + ' of ' + bytes( u.size ) + ')' ).join( ', ' ) + '. Choose the same file to continue.';
			box.hidden = false;
		}
		const active = status.active;
		if ( ! active ) {
			store.remove( JOB_KEY );
			return;
		}
		if ( active.origin === 'schedule' ) {
			watchScheduled( active ); // It runs by itself; the page only shows it.
			return;
		}
		const saved = store.get( JOB_KEY );
		const token = saved && saved.id === active.id ? saved.token : null;
		const what = JOB_NAMES[ active.type ] || 'A job';
		$( 'siteferry-active-text' ).textContent =
			what + ' started ' + date( active.created ) + ' is not finished (' + ( active.progress.message || active.status ) + ').' + ( token ? '' : ' This browser did not start it, so it can only be cancelled here.' );
		$( 'siteferry-active' ).hidden = false;
		$( 'siteferry-active-resume' ).hidden = ! token;
		$( 'siteferry-active-resume' ).onclick = async () => {
			$( 'siteferry-active' ).hidden = true;
			showTab( 'backups' );
			if ( active.type === 'send' || active.type === 'fetch' || active.type === 'check' ) {
				await runTransfer( active.id, token, 'Resuming...' );
			} else if ( active.type === 'export' ) {
				const view = progressView( 'siteferry-job-progress' );
				view.start( 'Resuming the backup...' );
				await finishExport( active.id, token, view, false );
			} else {
				importJob = { id: active.id, token };
				restore = { item: null, mine: true, name: null, job: importJob, phase: active.status === 'waiting' ? 'check' : 'running', seq: 0 };
				setWording( true, '' );
				$( 'siteferry-restore-title' ).textContent = active.status === 'waiting' ? 'Restore a backup' : 'Finishing the restore';
				$( 'siteferry-restore-advanced' ).hidden = true;
				$( 'siteferry-import-cancel' ).hidden = active.status !== 'waiting';
				setConfirm( false );
				$( 'siteferry-restore' ).hidden = false;
				const view = progressView( 'siteferry-import-progress', [ 'Backup', 'This site' ] );
				view.start( 'Resuming...' );
				await continueImport( view, 0 );
			}
		};
		$( 'siteferry-active-cancel' ).onclick = async () => {
			if ( await cancelJob( active.id, token ) ) {
				// (A restore stopped here also stops its safety backup and removes it.)
				$( 'siteferry-active' ).hidden = true;
				notice( what + ' was cancelled and its work undone.', 'success' );
			}
		};
	}

	function init() {
		if ( ! $( 'siteferry' ) ) {
			return;
		}
		document.querySelectorAll( '.siteferry .nav-tab, .siteferry [data-goto]' ).forEach( ( link ) =>
			link.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				showTab( link.dataset.tab || link.dataset.goto );
			} )
		);
		$( 'siteferry-export-form' ).addEventListener( 'submit', startExport );
		$( 'siteferry-options-toggle' ).addEventListener( 'click', () => toggleDrawer( 'options' ) );
		$( 'siteferry-options-close' ).addEventListener( 'click', () => {
			toggleDrawer( 'options', false );
			$( 'siteferry-options-toggle' ).focus();
		} );
		$( 'siteferry-upload-toggle' ).addEventListener( 'click', () => toggleDrawer( 'upload' ) );
		$( 'siteferry-schedule-toggle' ).addEventListener( 'click', () => toggleDrawer( 'schedule' ) );
		$( 'siteferry-schedule-close' ).addEventListener( 'click', () => {
			toggleDrawer( 'schedule', false );
			$( 'siteferry-schedule-toggle' ).focus();
		} );
		$( 'siteferry-schedule-form' ).addEventListener( 'submit', saveSchedule );
		$( 'siteferry-schedule-form' ).addEventListener( 'change', syncScheduleForm );
		$( 'siteferry-schedule-run' ).addEventListener( 'click', runScheduleNow );
		$( 'siteferry-send-options' ).addEventListener( 'change', syncDeleteAfter );
		$( 'siteferry-export-form' ).querySelectorAll( 'input[name=format]' ).forEach( ( input ) => input.addEventListener( 'change', syncFormat ) );
		$( 'siteferry-tables' ).addEventListener( 'change', countLeftOutTables );
		$( 'siteferry-file' ).addEventListener( 'change', onFileChosen );
		setUpDropZone();
		$( 'siteferry-backups-refresh' ).addEventListener( 'click', loadBackups );
		$( 'siteferry-search' ).addEventListener( 'input', ( event ) => {
			filter.text = event.target.value.trim();
			renderList();
		} );

		$( 'siteferry-confirm-check' ).addEventListener( 'change', ( event ) => {
			$( 'siteferry-confirm' ).disabled = ! event.target.checked;
		} );
		$( 'siteferry-confirm' ).addEventListener( 'click', confirmImport );
		$( 'siteferry-import-cancel' ).addEventListener( 'click', closeRestore );
		$( 'siteferry-restore-close' ).addEventListener( 'click', closeRestore );
		[ 'siteferry-replace-url', 'siteferry-include-mu', 'siteferry-include-dropins' ].forEach( ( id ) => {
			$( id ).addEventListener( 'change', recheckSoon );
		} );
		$( 'siteferry-restore-what' ).addEventListener( 'change', () => {
			if ( restore ) {
				setWording( restore.mine, restore.host );
				recheckSoon();
			}
		} );
		$( 'siteferry-contents-close' ).addEventListener( 'click', closeContents );
		$( 'siteferry-contents-clear' ).addEventListener( 'click', clearPicks );
		$( 'siteferry-contents-restore' ).addEventListener( 'click', restorePicked );
		$( 'siteferry-jobs-refresh' ).addEventListener( 'click', loadJobs );

		// A restore check nobody confirmed would block the next backup: leaving the page cancels it.
		window.addEventListener( 'pagehide', () => {
			if ( restore && restore.job && ( restore.phase === 'check' || restore.phase === 'ready' ) ) {
				try {
					fetch( url( 'jobs/' + restore.job.id + '/cancel' ), {
						method: 'POST',
						keepalive: true,
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.nonce, 'X-SiteFerry-Job': restore.job.token },
					} );
				} catch ( error ) {}
				store.remove( JOB_KEY );
			}
		} );

		// One open "..." menu at a time; a click elsewhere closes it.
		document.addEventListener( 'toggle', ( event ) => {
			if ( event.target.classList && event.target.classList.contains( 'sf-menu' ) && event.target.open ) {
				closeMenus( event.target );
			}
		}, true );
		document.addEventListener( 'click', ( event ) => {
			if ( ! event.target.closest( '.sf-menu' ) ) {
				closeMenus();
			}
		} );
		document.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'Escape' ) {
				closeMenus();
			}
		} );

		document.querySelectorAll( '.siteferry-storage-form' ).forEach( ( form ) => {
			form.addEventListener( 'submit', saveStorage );
			if ( form.elements.preset ) {
				form.elements.preset.addEventListener( 'change', () => applyPreset( form, null ) );
				form.elements.region.addEventListener( 'input', () => applyPreset( form, form.elements.path_style.checked ) );
			}
			form.querySelector( '[data-role=test]' ).addEventListener( 'click', () => testStorage( form ) );
			form.querySelector( '[data-role=remove]' ).addEventListener( 'click', () => removeStorage( form ) );
			const connect = form.querySelector( '[data-role=connect]' );
			if ( connect ) {
				connect.addEventListener( 'click', () => connectStorage( form ) );
				form.querySelector( '[data-role=disconnect]' ).addEventListener( 'click', () => disconnectStorage( form ) );
			}
			if ( form.elements.mode ) {
				form.elements.mode.addEventListener( 'change', () => {
					applyMode( form );
					const d = destination( form.dataset.destination );
					if ( d && d.oauth && d.oauth.connected && d.settings && d.settings.mode !== form.elements.mode.value ) {
						storageResult( form, null, [ el( 'p', { text: 'Saving or signing in this way replaces the current connection.' } ) ] );
					}
				} );
			}
			const copy = form.querySelector( '[data-role=copy]' );
			if ( copy ) {
				copy.addEventListener( 'click', () => {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( copy.dataset.copy ).then( () => {
							copy.textContent = 'Copied';
						} );
					}
				} );
			}
		} );

		const hash = window.location.hash.replace( '#', '' );
		const asked = OLD_TABS[ hash ] || hash;
		loadDestinations()
			.then( () => loadSchedule() )
			.then( () => finishOauth() )
			.then( ( fromOauth ) => {
				const first = fromOauth ? 'storage' : TABS.indexOf( asked ) === -1 ? 'backups' : asked;
				showTab( first );
				if ( first !== 'backups' ) {
					loadLocalBackups(); // For the header line and the tab count.
				}
				if ( hash === 'import' ) {
					toggleDrawer( 'upload', true );
				}
			} );
		loadTables();
		checkActiveJob();
	}

	// For tests and other scripts: the upload and hashing functions.
	window.SiteFerry = { uploadFile, sha256, sha256Fallback, openRestore };

	document.addEventListener( 'DOMContentLoaded', init );
} )();
