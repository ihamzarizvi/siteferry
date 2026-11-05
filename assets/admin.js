/**
 * SiteFerry admin page: backs up, lists and restores through the siteferry/v1 REST API, and drives
 * the jobs, uploads and downloads. Vanilla JavaScript; all server data is inserted with textContent.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
( function () {
	'use strict';

	const cfg = window.SiteFerryConfig || {};
	const { __, _n, _x, sprintf } = wp.i18n;
	const $ = ( id ) => document.getElementById( id );
	const JOB_KEY = 'siteferry.job';
	const JOB_NAMES = {
		export: __( 'A backup', 'siteferry' ),
		import: __( 'A restore', 'siteferry' ),
		send: __( 'A transfer to storage', 'siteferry' ),
		fetch: __( 'A download from storage', 'siteferry' ),
		check: __( 'A check of a backup', 'siteferry' ),
	};
	const JOB_TYPES = {
		export: __( 'Backup', 'siteferry' ),
		import: __( 'Restore', 'siteferry' ),
		send: __( 'Send to storage', 'siteferry' ),
		fetch: __( 'Download from storage', 'siteferry' ),
		check: __( 'Check a copy', 'siteferry' ),
	};
	const JOB_STATES = {
		pending: [ __( 'Not started', 'siteferry' ), 'is-busy' ],
		running: [ __( 'Not finished', 'siteferry' ), 'is-busy' ],
		waiting: [ __( 'Waiting for confirmation', 'siteferry' ), 'is-warn' ],
		done: [ __( 'Done', 'siteferry' ), 'is-good' ],
		failed: [ __( 'Failed', 'siteferry' ), 'is-bad' ],
	};
	// Names of the job steps, as the legs of the route the progress box draws.
	const STEP_LABELS = {
		'export.scan': __( 'Find files', 'siteferry' ),
		'export.database': __( 'Database', 'siteferry' ),
		'export.pack': __( 'Pack files', 'siteferry' ),
		'export.finish': __( 'Seal', 'siteferry' ),
		'export.send': __( 'Send', 'siteferry' ),
		'send.upload': __( 'Send', 'siteferry' ),
		'fetch.download': __( 'Download', 'siteferry' ),
		'import.inspect': __( 'Check', 'siteferry' ),
		'import.confirm': __( 'Confirm', 'siteferry' ),
		'import.extract': __( 'Unpack', 'siteferry' ),
		'import.verify': __( 'Verify', 'siteferry' ),
		'import.database': __( 'Database', 'siteferry' ),
		'import.replace': __( 'Addresses', 'siteferry' ),
		'import.swap': __( 'Switch over', 'siteferry' ),
		'import.finish': __( 'Clean up', 'siteferry' ),
		'import.wpress': __( 'Read .wpress', 'siteferry' ),
		'export.wpress': __( 'Make .wpress', 'siteferry' ),
		'schedule.finish': __( 'Wrap up', 'siteferry' ),
		'import.safety': __( 'Safety backup', 'siteferry' ),
		'check.download': __( 'Download', 'siteferry' ),
		'check.verify': __( 'Verify', 'siteferry' ),
		upload: __( 'Upload', 'siteferry' ),
	};
	const DROP_INS = [ 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php' ];
	const WEEKDAYS = [
		__( 'Sunday', 'siteferry' ),
		__( 'Monday', 'siteferry' ),
		__( 'Tuesday', 'siteferry' ),
		__( 'Wednesday', 'siteferry' ),
		__( 'Thursday', 'siteferry' ),
		__( 'Friday', 'siteferry' ),
		__( 'Saturday', 'siteferry' ),
	];
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
			const error = new Error(
				( data && data.message ) ||
					sprintf(
						/* translators: %d: HTTP status code, such as 500 */
						__( 'Request failed (HTTP %d).', 'siteferry' ),
						response.status
					)
			);
			error.status = response.status;
			error.code = data && data.code ? data.code : '';
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

	/**
	 * Children for an element whose translated sentence holds a node (such as a file name in its
	 * own span): the sentence is made with NODE_MARK where the node goes.
	 */
	const NODE_MARK = '\u0001';
	function around( text, node ) {
		const [ before, after ] = text.split( NODE_MARK );
		return [ before, node, after ].filter( ( part ) => part );
	}

	/** Whether a backup is protected with a password. */
	const isEncrypted = ( item ) => !! item && ! item.unlocked && ( !! item.encrypted || /\.enc$/i.test( item.name || '' ) );

	/**
	 * Asks for the password of a protected backup in a dialog. Resolves to the password, or null
	 * when cancelled. The password stays in this page only for the action it is asked for.
	 */
	function askPassword( title, text, error ) {
		const dialog = $( 'siteferry-password-dialog' );
		const input = $( 'siteferry-password-dialog-input' );
		$( 'siteferry-password-dialog-title' ).textContent = title;
		$( 'siteferry-password-dialog-text' ).textContent = text;
		$( 'siteferry-password-dialog-error' ).textContent = error || '';
		$( 'siteferry-password-dialog-error' ).hidden = ! error;
		input.value = '';
		return new Promise( ( resolve ) => {
			const done = () => {
				dialog.removeEventListener( 'close', done );
				const value = dialog.returnValue === 'ok' && input.value !== '' ? input.value : null;
				input.value = '';
				resolve( value );
			};
			dialog.addEventListener( 'close', done );
			dialog.returnValue = '';
			dialog.showModal();
			input.focus();
		} );
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

	/** A message above the tabs; success messages go away by themselves. */
	function notice( message, type ) {
		const box = $( 'siteferry-notice' );
		box.className = 'sf-notice' + ( type === 'success' ? ' is-good' : type === 'error' ? ' is-bad' : '' );
		box.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		box.replaceChildren(
			el( 'p', { text: message } ),
			el( 'button', { type: 'button', class: 'sf-notice-close', 'aria-label': __( 'Dismiss this message', 'siteferry' ), text: '×', onclick: () => ( box.hidden = true ) } )
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
				index < names.length
					? sprintf(
							/* translators: 1: number of the current step, 2: number of steps, 3: name of the step, such as Database */
							__( 'Step %1$d of %2$d: %3$s', 'siteferry' ),
							index + 1,
							names.length,
							stepLabel( names[ index ] )
					  )
					: sprintf(
							/* translators: %d: number of steps */
							_n( '%d step done', '%d steps done', names.length, 'siteferry' ),
							names.length
					  );
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
				frame( 'running', p.message || __( 'Working...', 'siteferry' ) );
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
			const more = job.warnings.count - job.warnings.first.length;
			list.appendChild(
				el( 'li', {
					text: sprintf(
						/* translators: %d: number of further warnings not shown */
						_n( '... and %d more (see the Log tab).', '... and %d more (see the Log tab).', more, 'siteferry' ),
						more
					),
				} )
			);
		}
		return [
			el( 'p', {
				text: sprintf(
					/* translators: %d: number of warnings */
					_n( '%d warning:', '%d warnings:', job.warnings.count, 'siteferry' ),
					job.warnings.count
				),
			} ),
			list,
		];
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
				onProgress(
					null,
					sprintf(
						/* translators: %d: number of the retry attempt */
						__( 'Connection problem; retrying (%d)...', 'siteferry' ),
						failures
					)
				);
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
		if ( d >= start ) {
			if ( inSentence ) {
				/* translators: %s: a time of day, such as 9:00 AM; used inside a sentence */
				return sprintf( __( 'today, %s', 'siteferry' ), time );
			}
			/* translators: %s: a time of day, such as 7:21 PM */
			return sprintf( __( 'Today, %s', 'siteferry' ), time );
		}
		if ( d >= new Date( start.getTime() - DAY ) ) {
			if ( inSentence ) {
				/* translators: %s: a time of day, such as 9:02 AM; used inside a sentence */
				return sprintf( __( 'yesterday, %s', 'siteferry' ), time );
			}
			/* translators: %s: a time of day, such as 9:02 AM */
			return sprintf( __( 'Yesterday, %s', 'siteferry' ), time );
		}
		return d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
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
			/* translators: %s: a time of day, such as 9:00 AM; used inside a sentence */
			return sprintf( __( 'today, %s', 'siteferry' ), time );
		}
		if ( days === 1 ) {
			/* translators: %s: a time of day, such as 3:00 AM; used inside a sentence */
			return sprintf( __( 'tomorrow, %s', 'siteferry' ), time );
		}
		return sprintf(
			/* translators: 1: a date, such as Monday, Nov 9, 2: a time of day, such as 9:00 AM */
			__( '%1$s, %2$s', 'siteferry' ),
			d.toLocaleDateString( undefined, { weekday: 'long', month: 'short', day: 'numeric' } ),
			time
		);
	}

	/** The next scheduled run; with the site's own time when the viewer's time zone differs. */
	function nextRun() {
		const text = upcoming( schedule.next );
		const viewer = -new Date( schedule.next * 1000 ).getTimezoneOffset() * 60;
		return schedule.next_site && viewer !== schedule.next_offset
			? sprintf(
					/* translators: 1: date and time in the viewer's time zone, 2: the same moment in the site's time zone */
					__( '%1$s your time (%2$s site time)', 'siteferry' ),
					text,
					schedule.next_site
			  )
			: text;
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
						el( 'span', {
							class: 'sf-table-size',
							text: sprintf(
								/* translators: 1: number of rows, 2: size such as 12 MB */
								_n( '%1$d row, %2$s', '%1$d rows, %2$s', t.rows, 'siteferry' ),
								t.rows,
								bytes( t.data )
							),
						} ),
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
		/* translators: %d: number of database tables left out of the backup */
		badge.textContent = sprintf( _n( '%d left out', '%d left out', n, 'siteferry' ), n );
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
		const wpress = !! format && format.value === 'wpress';
		const row = $( 'siteferry-compress-row' );
		if ( row ) {
			row.hidden = wpress;
		}
		// SiteFerry protects only its own format with a password.
		if ( $( 'siteferry-encrypt-row' ) ) {
			$( 'siteferry-encrypt-row' ).hidden = wpress;
			$( 'siteferry-encrypt-fields' ).hidden = wpress || ! $( 'siteferry-encrypt' ).checked;
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
		if ( $( 'siteferry-encrypt' ) && $( 'siteferry-encrypt' ).checked && options.format !== 'wpress' ) {
			const password = $( 'siteferry-password' ).value;
			const problem =
				password.length < 8
					? __( 'The password needs at least 8 characters.', 'siteferry' )
					: password !== $( 'siteferry-password-again' ).value
					? __( 'The two passwords are not the same.', 'siteferry' )
					: '';
			if ( problem ) {
				toggleDrawer( 'options', true );
				notice( problem, 'error' );
				$( 'siteferry-password' ).focus();
				return;
			}
			options.password = password;
		}
		const target = destination( options.send_to );
		const view = progressView( 'siteferry-job-progress', [ __( 'This site', 'siteferry' ), target ? target.short : __( 'This server', 'siteferry' ) ] );
		const starters = [ $( 'siteferry-backup-now' ), ...form.querySelectorAll( 'button[type=submit]' ) ];
		starters.forEach( ( starter ) => ( starter.disabled = true ) );
		// The choices are made; close Options so the progress shows right under the header.
		toggleDrawer( 'options', false );
		view.start( __( 'Starting the backup...', 'siteferry' ) );
		try {
			const started = await api( 'POST', 'exports', { json: options } );
			remember( started.job, started.token );
			// A name, note and tags belong to this backup only.
			[ 'title', 'note', 'tags' ].forEach( ( field ) => {
				form.elements[ field ].value = '';
			} );
			await finishExport( started.job.id, started.token, view, $( 'siteferry-download-ready' ).checked );
		} catch ( error ) {
			view.finish( false, __( 'The backup was not made', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
		} finally {
			starters.forEach( ( starter ) => ( starter.disabled = false ) );
		}
	}

	async function finishExport( id, token, view, downloadWhenReady ) {
		const job = await driveJob( id, token, progressHandler( view ) );
		store.remove( JOB_KEY );
		if ( job.status !== 'done' ) {
			view.finish( false, __( 'The backup was not made', 'siteferry' ), [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
			return;
		}
		const r = job.result;
		if ( r.moved ) {
			view.finish(
				true,
				sprintf(
					/* translators: %s: where it went, such as Dropbox or another site's host name */
					__( 'Sent to %s', 'siteferry' ),
					hostOf( r.moved.to )
				),
				[
					el( 'p', {
						text: sprintf(
							/* translators: 1: size such as 12 MB, 2: address of the other site */
							__( 'This site (%1$s, encrypted with the key) is now on %2$s. Nothing on that site has changed yet.', 'siteferry' ),
							bytes( r.moved.size ),
							r.moved.to
						),
					} ),
					el( 'p', { text: __( 'To finish, open SiteFerry there and click "Move it here" under Move.', 'siteferry' ) } ),
					el( 'p', { class: 'sf-actions' }, [ el( 'a', { class: 'button button-primary', href: r.moved.to + '/wp-admin/tools.php?page=siteferry', target: '_blank', rel: 'noopener', text: __( 'Open SiteFerry there', 'siteferry' ) } ) ] ),
				]
			);
			loadBackups();
			return;
		}
		let stored;
		if ( ! r.sent ) {
			stored = sprintf(
				/* translators: 1: size such as 12 MB, 2: number of files */
				_n( '%1$s · %2$d file, stored on this server.', '%1$s · %2$d files, stored on this server.', r.files, 'siteferry' ),
				bytes( r.size ),
				r.files
			);
		} else if ( r.deleted_local ) {
			stored = sprintf(
				/* translators: 1: size such as 12 MB, 2: number of files, 3: name of the storage, such as Dropbox */
				_n( '%1$s · %2$d file, stored on %3$s.', '%1$s · %2$d files, stored on %3$s.', r.files, 'siteferry' ),
				bytes( r.size ),
				r.files,
				r.sent.where
			);
		} else {
			stored = sprintf(
				/* translators: 1: size such as 12 MB, 2: number of files, 3: name of the storage, such as Dropbox */
				_n( '%1$s · %2$d file, stored on this server and on %3$s.', '%1$s · %2$d files, stored on this server and on %3$s.', r.files, 'siteferry' ),
				bytes( r.size ),
				r.files,
				r.sent.where
			);
		}
		const nodes = [
			el( 'p', { text: stored } ),
			el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: r.name } ) ] ),
		];
		const actions = [];
		if ( ! r.deleted_local ) {
			actions.push( el( 'a', { class: 'button button-primary', href: downloadUrl( r.name ), text: __( 'Download to your computer', 'siteferry' ) } ) );
			destinations
				.filter( ( d ) => d.ready && ! ( r.sent && r.sent.destination === d.id ) )
				.forEach( ( d ) =>
					actions.push(
						button(
							sprintf(
								/* translators: %s: name of the storage, such as Dropbox */
								__( 'Also send to %s', 'siteferry' ),
								d.short
							),
							() => sendTo( d, r.name ),
							'button sf-quiet'
						)
					)
				);
		}
		actions.push( button( __( 'Name, note and tags', 'siteferry' ), () => editByName( r.sent && r.deleted_local ? r.sent.name : r.name ), 'button sf-quiet' ) );
		if ( actions.length ) {
			nodes.push( el( 'p', { class: 'sf-actions' }, actions ) );
		}
		view.finish( true, __( 'Backup ready', 'siteferry' ), [ ...nodes, ...warningList( job ) ] );
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
			notice(
				sprintf(
					/* translators: %s: error message */
					__( 'Cannot read the storage settings: %s', 'siteferry' ),
					error.message
				),
				'error'
			);
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
			facts.textContent = localBackups.length
				? sprintf(
						/* translators: 1: number of backups, 2: total size such as 12 MB */
						_n( '%1$d backup here, %2$s', '%1$d backups here, %2$s', localBackups.length, 'siteferry' ),
						localBackups.length,
						bytes( total )
				  )
				: __( 'No backups here yet', 'siteferry' );
			facts.hidden = false;
		}
		const ready = destinations.filter( ( d ) => d.ready );
		const storage = $( 'siteferry-facts-storage' );
		storage.textContent = ready.length
			? sprintf(
					/* translators: %s: comma-separated names of the storages, such as Dropbox, Amazon S3 */
					__( 'Storage: %s', 'siteferry' ),
					ready.map( ( d ) => d.short ).join( ', ' )
			  )
			: __( 'No storage set up', 'siteferry' );
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
			secretNote.textContent = set ? __( 'Saved (not shown). Leave this empty to keep it, or enter a new one.', 'siteferry' ) : __( 'Stored encrypted. It is never shown again.', 'siteferry' );
		}

		const card = form.closest( '.sf-dest' );
		const state = card.querySelector( '[data-role=state]' );
		const chip = card.querySelector( '[data-role=chip]' );
		let text = state.dataset.about;
		let label = __( 'Not set up', 'siteferry' );
		let tone = '';
		if ( d.oauth ) {
			if ( d.oauth.connected ) {
				[ text, label, tone ] = [ d.description, __( 'Connected', 'siteferry' ), 'is-good' ];
			} else if ( d.configured ) {
				[ text, label, tone ] = [ __( 'Saved, but not signed in yet.', 'siteferry' ), __( 'Not connected', 'siteferry' ), 'is-warn' ];
			}
		} else if ( d.configured ) {
			text = d.updated
				? sprintf(
						/* translators: 1: description of the storage, 2: date and time the settings were saved */
						__( '%1$s · saved %2$s', 'siteferry' ),
						d.description,
						date( d.updated )
				  )
				: d.description;
			[ label, tone ] = d.ready ? [ __( 'Ready', 'siteferry' ), 'is-good' ] : [ __( 'Incomplete', 'siteferry' ), 'is-warn' ];
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
			connect.textContent = connected ? __( 'Sign in again', 'siteferry' ) : connect.dataset.labelRelay;
		} else {
			connect.textContent = connected ? __( 'Connect again', 'siteferry' ) : connect.dataset.labelOwn;
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
		storageResult( form, null, [ el( 'p', { text: __( 'Opening the sign-in page...', 'siteferry' ) } ) ] );
		try {
			await api( 'POST', 'storage/' + id, { json: formData( form ) } );
			const result = await api( 'POST', 'storage/' + id + '/authorize' );
			window.location.assign( result.url );
		} catch ( error ) {
			connect.disabled = false;
			storageResult( form, false, [
				el( 'p', {
					text: sprintf(
						/* translators: %s: error message */
						__( 'Cannot sign in: %s', 'siteferry' ),
						error.message
					),
				} ),
			] );
		}
	}

	async function disconnectStorage( form ) {
		const d = destination( form.dataset.destination );
		// eslint-disable-next-line no-alert
		if (
			! d ||
			! window.confirm(
				sprintf(
					/* translators: %s: name of the storage, such as Dropbox */
					__( 'Disconnect %s? SiteFerry revokes its access and forgets the tokens; backups stored there stay.', 'siteferry' ),
					d.label
				)
			)
		) {
			return;
		}
		try {
			await api( 'POST', 'storage/' + d.id + '/disconnect' );
			await loadDestinations();
			storageResult( form, true, [ el( 'p', { text: __( 'Disconnected. Backups stored there stay.', 'siteferry' ) } ) ] );
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
			let reason;
			if ( failure === 'access_denied' ) {
				reason = __( 'Not connected: access was not allowed.', 'siteferry' );
			} else if ( failure ) {
				/* translators: %s: error message */
				reason = sprintf( __( 'Not connected: %s', 'siteferry' ), failure );
			} else {
				reason = __( 'Not connected: the answer had no authorization code.', 'siteferry' );
			}
			storageResult( form, false, [ el( 'p', { text: reason } ) ] );
			return true;
		}
		storageResult( form, null, [ el( 'p', { text: __( 'Finishing the connection...', 'siteferry' ) } ) ] );
		try {
			const d = await api( 'POST', 'storage/' + id + '/connect', { json: { code, state } } );
			await loadDestinations();
			storageResult( form, true, [
				el( 'p', {
					text: sprintf(
						/* translators: %s: description of the connected account */
						__( 'Connected: %s. Use "Test connection" to check it.', 'siteferry' ),
						d.description
					),
				} ),
			] );
		} catch ( error ) {
			storageResult( form, false, [
				el( 'p', {
					text: sprintf(
						/* translators: %s: error message */
						__( 'Not connected: %s', 'siteferry' ),
						error.message
					),
				} ),
			] );
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
				el( 'p', {
					text:
						d && d.oauth && ! d.oauth.connected
							? sprintf(
									/* translators: %s: label of the sign-in button, such as Connect Dropbox */
									__( 'Saved. Now click "%s" and allow access.', 'siteferry' ),
									form.querySelector( '[data-role=connect]' ).textContent
							  )
							: __( 'Saved. Use "Test connection" to check the settings.', 'siteferry' ),
				} ),
			] );
		} catch ( error ) {
			storageResult( form, false, [
				el( 'p', {
					text: sprintf(
						/* translators: %s: error message */
						__( 'Not saved: %s', 'siteferry' ),
						error.message
					),
				} ),
			] );
		} finally {
			submit.disabled = false;
		}
	}

	async function testStorage( form ) {
		const id = form.dataset.destination;
		const test = form.querySelector( '[data-role=test]' );
		test.disabled = true;
		storageResult( form, null, [ el( 'p', { text: __( 'Testing: writing, reading and deleting a small file...', 'siteferry' ) } ) ] );
		try {
			const result = await api( 'POST', 'storage/' + id + '/test' );
			storageResult( form, true, [ el( 'p', { text: __( 'The storage works.', 'siteferry' ) } ), el( 'ul', {}, result.lines.map( ( line ) => el( 'li', { text: line } ) ) ) ] );
		} catch ( error ) {
			storageResult( form, false, [ el( 'p', { text: error.message } ) ] );
		} finally {
			test.disabled = false;
		}
	}

	async function removeStorage( form ) {
		const d = destination( form.dataset.destination );
		// eslint-disable-next-line no-alert
		if (
			! d ||
			! window.confirm(
				sprintf(
					/* translators: %s: name of the storage, such as Dropbox */
					__( 'Remove the settings and key for %s? Backups already stored there are not deleted.', 'siteferry' ),
					d.label
				)
			)
		) {
			return;
		}
		try {
			await api( 'DELETE', 'storage/' + d.id );
			await loadDestinations();
			form.reset();
			fillStorageForm( form );
			storageResult( form, true, [ el( 'p', { text: __( 'Settings removed. Backups stored there stay.', 'siteferry' ) } ) ] );
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
			view.finish( false, __( 'Stopped', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
			return null;
		}
		store.remove( JOB_KEY );
		if ( job.type === 'check' ) {
			const checked = job.result && job.result.checked;
			if ( job.status === 'done' ) {
				view.finish( true, __( 'Intact', 'siteferry' ), [
					el( 'p', {
						text: sprintf(
							/* translators: 1: where the copy is, such as Dropbox, 2: summary of the check */
							__( 'The copy on %1$s is intact. %2$s', 'siteferry' ),
							checked.where,
							checked.summary
						),
					} ),
				] );
			} else {
				view.finish( false, checked && checked.ok === false ? __( 'Damaged', 'siteferry' ) : __( 'Not checked', 'siteferry' ), [
					el( 'p', { text: job.error } ),
					...( checked && checked.ok === false ? [ el( 'p', { text: __( 'Do not rely on this copy: make a new backup, or use another copy of this one.', 'siteferry' ) } ) ] : [] ),
				] );
			}
		} else if ( job.status !== 'done' ) {
			view.finish( false, job.type === 'send' ? __( 'Not sent', 'siteferry' ) : __( 'Not downloaded', 'siteferry' ), [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
		} else if ( job.type === 'send' ) {
			view.finish( true, __( 'Sent', 'siteferry' ), [
				el(
					'p',
					{},
					around(
						sprintf(
							/* translators: 1: file name of the backup, 2: name of the storage, such as Dropbox */
							__( '%1$s is now on %2$s.', 'siteferry' ),
							NODE_MARK,
							job.result.sent.where
						),
						el( 'span', { class: 'sf-result-name', text: job.result.sent.name } )
					)
				),
			] );
		} else {
			view.finish( true, __( 'Downloaded to this server', 'siteferry' ), [
				el(
					'p',
					{},
					around(
						sprintf(
							/* translators: %s: file name of the backup */
							__( '%s is now also on this server.', 'siteferry' ),
							NODE_MARK
						),
						el( 'span', { class: 'sf-result-name', text: job.result.fetched.name } )
					)
				),
			] );
		}
		loadBackups();
		return job;
	}

	async function startTransfer( path, body, message, ends, onLocked ) {
		try {
			const started = await api( 'POST', path, { json: body } );
			remember( started.job, started.token );
			return await runTransfer( started.job.id, started.token, message, ends );
		} catch ( error ) {
			if ( onLocked && error.code === 'siteferry_locked' ) {
				return onLocked( error.message ); // Asks for the password again.
			}
			const view = progressView( 'siteferry-job-progress' );
			view.start( message );
			view.finish( false, __( 'Could not start', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
			return null;
		}
	}

	function sendTo( d, name ) {
		return startTransfer(
			'storage/' + d.id + '/send',
			{ backup: name },
			sprintf(
				/* translators: 1: file name of the backup, 2: name of the storage, such as Dropbox */
				__( 'Sending %1$s to %2$s...', 'siteferry' ),
				name,
				d.label
			),
			[ __( 'This server', 'siteferry' ), d.short ]
		);
	}

	/** Reads one copy of a backup from start to end and compares it with its checksums. */
	async function checkCopy( item, place, error ) {
		const d = place === 'local' ? null : destination( place );
		const body = { name: item.name, place };
		if ( isEncrypted( item ) ) {
			const password = await askPassword(
				sprintf(
					/* translators: %s: name of the backup */
					__( 'Check %s', 'siteferry' ),
					called( item )
				),
				__( 'This backup is protected with a password. The check needs it to compare every file with its checksums.', 'siteferry' ),
				error
			);
			if ( null === password ) {
				return null;
			}
			body.password = password;
		}
		return startTransfer(
			'checks',
			body,
			d
				? sprintf(
						/* translators: 1: name of the backup, 2: name of the storage, such as Dropbox */
						__( 'Checking the copy of %1$s on %2$s; nothing changes...', 'siteferry' ),
						called( item ),
						d.label
				  )
				: sprintf(
						/* translators: %s: name of the backup */
						__( 'Checking the copy of %s on this server; nothing changes...', 'siteferry' ),
						called( item )
				  ),
			d ? [ d.short, __( 'Checked', 'siteferry' ) ] : [ __( 'Backup', 'siteferry' ), __( 'Checked', 'siteferry' ) ],
			( message ) => checkCopy( item, place, message )
		);
	}

	function fetchFrom( d, name ) {
		return startTransfer(
			'storage/' + d.id + '/fetch',
			{ name },
			sprintf(
				/* translators: 1: file name of the backup, 2: name of the storage, such as Dropbox */
				__( 'Downloading %1$s from %2$s...', 'siteferry' ),
				name,
				d.label
			),
			[ d.short, __( 'This server', 'siteferry' ) ]
		);
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
			notice(
				sprintf(
					/* translators: %s: error message */
					__( 'Cannot list the backups on this server: %s', 'siteferry' ),
					error.message
				),
				'error'
			);
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
				encrypted: !! b.encrypted,
				unlocked: !! b.unlocked,
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
				item.parts = item.parts || {};
				item.parts[ id ] = { count: b.parts || 1, complete: b.complete !== false };
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
			status.push(
				sprintf(
					/* translators: %s: comma-separated names of the storages, such as Dropbox, Amazon S3 */
					__( 'Looking on %s...', 'siteferry' ),
					loading.map( ( id ) => ( destination( id ) || { short: id } ).short ).join( ', ' )
				)
			);
		}
		failed.forEach( ( id ) =>
			status.push(
				sprintf(
					/* translators: 1: name of the storage, such as Dropbox, 2: error message */
					__( '%1$s: %2$s', 'siteferry' ),
					( destination( id ) || { short: id } ).short,
					remote[ id ].error
				)
			)
		);
		let counted = '';
		if ( filtering ) {
			counted = sprintf(
				/* translators: 1: number of backups shown, 2: number of backups in all */
				_n( '%1$d of %2$d backup', '%1$d of %2$d backups', all.length, 'siteferry' ),
				visible.length,
				all.length
			);
		} else if ( all.length ) {
			/* translators: %d: number of backups */
			counted = sprintf( _n( '%d backup', '%d backups', all.length, 'siteferry' ), all.length );
		}
		$( 'siteferry-backups-summary' ).textContent = status.length ? status.join( ' · ' ) : counted;

		const mine = all.filter( ours );
		const here = all.filter( ( item ) => item.local ).length;
		/* translators: %d: number of backups on this server */
		const counts = [ sprintf( _n( '%d on this server', '%d on this server', here, 'siteferry' ), here ) ];
		destinations
			.filter( ( d ) => d.ready && remote[ d.id ] && remote[ d.id ].backups )
			.forEach( ( d ) =>
				counts.push(
					sprintf(
						/* translators: 1: number of backups, 2: name of the storage, such as Dropbox */
						_n( '%1$d on %2$s', '%1$d on %2$s', remote[ d.id ].backups.length, 'siteferry' ),
						remote[ d.id ].backups.length,
						d.short
					)
				)
			);
		let summary = __( 'No backups yet. "Back up now" makes one of the whole site.', 'siteferry' );
		if ( all.length ) {
			summary = mine.length
				? sprintf(
						/* translators: 1: when the last backup was made, such as today, 7:21 PM, 2: how many backups are where, such as 3 on this server, 2 on Dropbox */
						__( 'Last backup %1$s · %2$s', 'siteferry' ),
						when( mine[ 0 ].time, true ),
						counts.join( ', ' )
				  )
				: counts.join( ', ' );
		}
		$( 'siteferry-summary' ).textContent = summary;

		$( 'siteferry-columns' ).hidden = ! visible.length;
		if ( null === localBackups ) {
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: __( 'Loading...', 'siteferry' ) } ) );
			return;
		}
		if ( ! all.length ) {
			list.replaceChildren(
				el( 'li', { class: 'sf-empty' }, [
					el( 'p', { text: loading.length ? __( 'Looking for backups on your storage...', 'siteferry' ) : __( 'No backups yet. Make one now, or upload one to restore it here.', 'siteferry' ) } ),
					el( 'p', { class: 'sf-actions sf-empty-actions' }, [
						button( __( 'Back up now', 'siteferry' ), () => $( 'siteferry-backup-now' ).click(), 'button button-primary' ),
						button( __( 'Upload a backup', 'siteferry' ), () => toggleDrawer( 'upload', true ), 'button' ),
					] ),
				] )
			);
			return;
		}
		if ( ! visible.length ) {
			list.replaceChildren(
				el( 'li', { class: 'sf-empty' }, [
					el( 'p', { text: __( 'No backups match this search.', 'siteferry' ) } ),
					el( 'p', { class: 'sf-actions sf-empty-actions' }, [ button( __( 'Show all backups', 'siteferry' ), clearFilter, 'button' ) ] ),
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
		if ( /\.tar\.gz\.enc$/i.test( name ) ) {
			return { ext: '.tar.gz.enc', note: __( 'SiteFerry, compressed, password', 'siteferry' ) };
		}
		if ( /\.tar\.enc$/i.test( name ) ) {
			return { ext: '.tar.enc', note: __( 'SiteFerry, password', 'siteferry' ) };
		}
		if ( /\.tar\.gz$/i.test( name ) ) {
			return { ext: '.tar.gz', note: __( 'SiteFerry, compressed', 'siteferry' ) };
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
		const when = sprintf(
			/* translators: 1: date and time of the check, 2: summary of the check */
			__( 'Checked %1$s: %2$s', 'siteferry' ),
			date( outcome.time ),
			outcome.summary
		);
		return el( 'span', { class: 'sf-badge is-place ' + ( outcome.ok ? 'is-checked' : 'is-bad' ), title: when }, [
			el( 'span', { class: 'sf-check-mark', 'aria-hidden': 'true', text: outcome.ok ? '✓' : '!' } ),
			text,
			el( 'span', { class: 'screen-reader-text', text: outcome.ok ? __( ', checked: intact', 'siteferry' ) : __( ', checked: damaged', 'siteferry' ) } ),
		] );
	}

	function row( item ) {
		const mine = ours( item );
		const title = item.title || ( mine ? when( item.time ) : hostOf( item.source ) || __( 'Another site', 'siteferry' ) );
		const meta = [];
		if ( ( item.title || ! mine ) && item.time ) {
			meta.push( el( 'span', { text: when( item.time ) } ) );
		}
		if ( item.title && ! mine && item.source ) {
			meta.push(
				el( 'span', {
					text: sprintf(
						/* translators: %s: address of the site the backup was made from */
						__( 'from %s', 'siteferry' ),
						hostOf( item.source )
					),
				} )
			);
		}
		if ( item.contents ) {
			meta.push( el( 'span', { text: item.contents } ) );
		}
		if ( item.wordpress ) {
			meta.push(
				el( 'span', {
					text: sprintf(
						/* translators: %s: WordPress version number */
						__( 'WordPress %s', 'siteferry' ),
						item.wordpress
					),
				} )
			);
		}
		// Shown in their own columns on wide screens, in the meta line on narrow ones.
		const format = formatOf( item.name );
		meta.push( el( 'span', { class: 'sf-narrow-only', text: format.ext } ), el( 'span', { class: 'sf-narrow-only', text: bytes( item.size ) } ) );

		const flags = [];
		if ( ! mine ) {
			flags.push( badge( __( 'From another site', 'siteferry' ), 'is-warn' ) );
		}
		if ( item.error ) {
			flags.push( badge( __( 'Cannot be restored', 'siteferry' ), 'is-bad' ) );
		}
		if ( isEncrypted( item ) ) {
			flags.push( badge( __( 'Password', 'siteferry' ), 'is-lock' ) );
		}

		const places = [];
		if ( item.local ) {
			places.push( placeBadge( item, 'local', __( 'This server', 'siteferry' ) ) );
		}
		item.places.forEach( ( id ) => {
			const set = item.parts && item.parts[ id ];
			const short = ( destination( id ) || { short: id } ).short;
			if ( set && ! set.complete ) {
				places.push(
					el( 'span', { class: 'sf-badge is-place is-bad', title: __( 'Some of its parts are missing there', 'siteferry' ) }, [
						sprintf(
							/* translators: %s: name of the storage, such as Dropbox */
							__( '%s: parts missing', 'siteferry' ),
							short
						),
					] )
				);
				return;
			}
			places.push(
				placeBadge(
					item,
					id,
					set && set.count > 1
						? sprintf(
								/* translators: 1: name of the storage, such as Dropbox, 2: number of parts */
								_n( '%1$s · %2$d part', '%1$s · %2$d parts', set.count, 'siteferry' ),
								short,
								set.count
						  )
						: short
				)
			);
		} );

		const actions = [];
		if ( ! item.error ) {
			actions.push( button( mine ? __( 'Restore', 'siteferry' ) : __( 'Move here', 'siteferry' ), () => openRestore( item ), 'button sf-quiet' ) );
		}
		actions.push( menu( item ) );

		const tags = ( item.tags || [] ).map( ( tag ) =>
			el( 'button', {
				type: 'button',
				class: 'sf-tag' + ( same( tag, filter.tag ) ? ' is-on' : '' ),
				/* translators: %s: a tag */
				title: sprintf( __( 'Show only backups tagged %s', 'siteferry' ), tag ),
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
		const fallback = ours( item ) ? when( item.time, true ) : hostOf( item.source );
		let shows = __( 'Left empty, the list shows the site address.', 'siteferry' );
		if ( ours( item ) || fallback ) {
			shows = sprintf(
				/* translators: %s: what the list shows instead of a name: a date and time, or a site address */
				__( 'Left empty, the list shows %s.', 'siteferry' ),
				fallback
			);
		}
		const title = el( 'input', { type: 'text', id: id + '-title', maxlength: '100', placeholder: __( 'Give this backup a name', 'siteferry' ) } );
		/* translators: example tags, separated with commas */
		const tags = el( 'input', { type: 'text', id: id + '-tags', maxlength: '200', placeholder: __( 'before-update, monthly', 'siteferry' ) } );
		const note = el( 'textarea', { id: id + '-note', rows: '2', maxlength: '1000' } );
		title.value = item.title || '';
		tags.value = ( item.tags || [] ).join( ', ' );
		note.value = item.note || '';
		const message = el( 'p', { class: 'sf-row-error', hidden: 'hidden' } );
		const cancel = () => {
			editing = '';
			renderList();
		};
		const form = el(
			'form',
			{
				class: 'sf-edit',
				'aria-label': sprintf(
					/* translators: %s: file name of the backup */
					__( 'Name, note and tags of %s', 'siteferry' ),
					item.name
				),
			},
			[
				el( 'div', { class: 'sf-edit-head' }, [
					el( 'span', { class: 'sf-edit-title', text: __( 'Name, note and tags', 'siteferry' ) } ),
					el( 'span', { class: 'sf-row-name', text: item.name } ),
				] ),
				el( 'div', { class: 'sf-label-fields' }, [
					el( 'p', { class: 'sf-field' }, [ el( 'label', { for: title.id, text: __( 'Name', 'siteferry' ) } ), title, el( 'span', { class: 'description', text: shows } ) ] ),
					el( 'p', { class: 'sf-field' }, [ el( 'label', { for: tags.id, text: __( 'Tags', 'siteferry' ) } ), tags, el( 'span', { class: 'description', text: __( 'Separate tags with commas.', 'siteferry' ) } ) ] ),
					el( 'p', { class: 'sf-field sf-field-wide' }, [ el( 'label', { for: note.id, text: __( 'Note', 'siteferry' ) } ), note ] ),
				] ),
				message,
				el( 'p', { class: 'sf-actions' }, [ el( 'button', { type: 'submit', class: 'button button-primary', text: __( 'Save', 'siteferry' ) } ), button( __( 'Cancel', 'siteferry' ), cancel, 'button sf-quiet' ) ] ),
			]
		);
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			form.querySelector( 'button[type=submit]' ).disabled = true;
			try {
				await api( 'POST', 'labels/' + encodeURIComponent( item.name ), { json: { title: title.value, note: note.value, tags: tags.value } } );
				editing = '';
				await Promise.all( [ loadLabels(), loadLocalBackups() ] );
				notice( __( 'Saved the name, note and tags.', 'siteferry' ), 'success' );
			} catch ( error ) {
				/* translators: %s: error message */
				message.textContent = sprintf( __( 'Not saved: %s', 'siteferry' ), error.message );
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
		const entries = [ menuItem( __( 'Name, note and tags', 'siteferry' ), () => openEditor( item ) ) ];
		const ready = destinations.filter( ( d ) => d.ready );
		if ( item.local ) {
			entries.push( menuItem( __( 'Look inside', 'siteferry' ), () => openContents( item ) ) );
			entries.push( el( 'a', { class: 'sf-menu-item', href: downloadUrl( item.name ), text: __( 'Download to your computer', 'siteferry' ), role: 'menuitem' } ) );
			if ( item.size > 100 * 1048576 ) {
				entries.push( menuItem( __( 'Download in parts', 'siteferry' ), () => openParts( item ) ) );
			}
			if ( ! item.error ) {
				ready
					.filter( ( d ) => item.places.indexOf( d.id ) === -1 )
					.forEach( ( d ) =>
						entries.push(
							menuItem(
								sprintf(
									/* translators: %s: name of the storage, such as Dropbox */
									__( 'Send to %s', 'siteferry' ),
									d.short
								),
								() => sendTo( d, item.name )
							)
						)
					);
			}
		}
		item.places.forEach( ( id ) => {
			const d = destination( id );
			if ( d && ! item.local ) {
				entries.push( menuItem( __( 'Download to this server', 'siteferry' ), () => fetchFrom( d, item.name ) ) );
			}
		} );
		if ( item.local ) {
			entries.push( menuItem( __( 'Check the copy on this server', 'siteferry' ), () => checkCopy( item, 'local' ) ) );
		}
		item.places.forEach( ( id ) => {
			const d = destination( id );
			if ( d && d.ready ) {
				entries.push(
					menuItem(
						sprintf(
							/* translators: %s: name of the storage, such as Dropbox */
							__( 'Check the copy on %s', 'siteferry' ),
							d.short
						),
						() => checkCopy( item, id )
					)
				);
			}
		} );
		if ( item.local ) {
			entries.push( menuItem( __( 'Delete from this server', 'siteferry' ), () => deleteBackup( item ), true ) );
		}
		item.places.forEach( ( id ) => {
			const d = destination( id );
			if ( d ) {
				entries.push(
					menuItem(
						sprintf(
							/* translators: %s: name of the storage, such as Dropbox */
							__( 'Delete from %s', 'siteferry' ),
							d.short
						),
						() => deleteRemote( d, item ),
						true
					)
				);
			}
		} );
		return el( 'details', { class: 'sf-menu' }, [
			el(
				'summary',
				{
					class: 'sf-icon-button',
					title: __( 'More actions', 'siteferry' ),
					'aria-label': sprintf(
						/* translators: %s: name of the backup */
						__( 'More actions for %s', 'siteferry' ),
						called( item )
					),
				},
				[ dotsIcon() ]
			),
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
	const called = ( item ) =>
		item.title ? '"' + item.title + '"' : item.name;

	async function deleteBackup( item ) {
		const last = ! item.places.length;
		const question = last
			? sprintf(
					/* translators: %s: name of the backup */
					__( 'Delete %s from this server? It is the only copy; this cannot be undone.', 'siteferry' ),
					called( item )
			  )
			: sprintf(
					/* translators: %s: name of the backup */
					__( 'Delete %s from this server? The copies on storage stay.', 'siteferry' ),
					called( item )
			  );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'backups/' + encodeURIComponent( item.name ) );
			if ( last ) {
				await forgetLabel( item );
			}
			/* translators: %s: name of the backup */
			notice( sprintf( __( 'Deleted %s from this server.', 'siteferry' ), called( item ) ), 'success' );
		} catch ( error ) {
			/* translators: %s: error message */
			notice( sprintf( __( 'Could not delete: %s', 'siteferry' ), error.message ), 'error' );
		}
		loadLocalBackups();
	}

	async function deleteRemote( d, item ) {
		const last = ! item.local && item.places.length === 1;
		const question = last
			? sprintf(
					/* translators: 1: name of the backup, 2: name of the storage, such as Dropbox */
					__( 'Delete %1$s from %2$s? It is the only copy; this cannot be undone.', 'siteferry' ),
					called( item ),
					d.label
			  )
			: sprintf(
					/* translators: 1: name of the backup, 2: name of the storage, such as Dropbox */
					__( 'Delete %1$s from %2$s?', 'siteferry' ),
					called( item ),
					d.label
			  );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'storage/' + d.id + '/backups/' + encodeURIComponent( item.name ) );
			if ( last ) {
				await forgetLabel( item );
			}
			notice(
				sprintf(
					/* translators: 1: name of the backup, 2: name of the storage, such as Dropbox */
					__( 'Deleted %1$s from %2$s.', 'siteferry' ),
					called( item ),
					d.label
				),
				'success'
			);
		} catch ( error ) {
			/* translators: %s: error message */
			notice( sprintf( __( 'Could not delete: %s', 'siteferry' ), error.message ), 'error' );
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
		const view = progressView( 'siteferry-upload-progress', [ __( 'Your computer', 'siteferry' ), __( 'This server', 'siteferry' ) ] );
		/* translators: %s: name of the file being uploaded */
		view.start( sprintf( __( 'Uploading %s...', 'siteferry' ), file.name ) );
		try {
			const name = await uploadFile( file, ( done, total ) =>
				view.update(
					done,
					total,
					sprintf(
						/* translators: 1: name of the file being uploaded, 2: size uploaded so far, such as 12 MB, 3: size of the file */
						__( 'Uploading %1$s: %2$s of %3$s', 'siteferry' ),
						file.name,
						bytes( done ),
						bytes( total )
					)
				)
			);
			await loadLocalBackups();
			const item = items().find( ( entry ) => entry.name === name );
			const nodes = [
				el(
					'p',
					{},
					around(
						sprintf(
							/* translators: %s: file name of the uploaded backup */
							__( '%s is in the list below. Nothing on this site has changed.', 'siteferry' ),
							NODE_MARK
						),
						el( 'span', { class: 'sf-result-name', text: name } )
					)
				),
			];
			if ( item && ! item.error ) {
				nodes.push( el( 'p', { class: 'sf-actions' }, [ button( ours( item ) ? __( 'Restore it', 'siteferry' ) : __( 'Move it here', 'siteferry' ), () => openRestore( item ), 'button button-primary' ) ] ) );
			} else if ( item && item.error ) {
				nodes.push( el( 'p', { text: item.error } ) );
			}
			view.finish( true, __( 'Uploaded and checked', 'siteferry' ), nodes );
		} catch ( error ) {
			view.finish( false, __( 'Upload stopped', 'siteferry' ), [
				el( 'p', {
					text: sprintf(
						/* translators: %s: error message */
						__( '%s Choose the same file again to continue where it stopped.', 'siteferry' ),
						error.message
					),
				} ),
			] );
		}
	}

	/** "Download in parts": links to each part of the backup on this server. */
	function openParts( item ) {
		closeMenus();
		const dialog = $( 'siteferry-parts-dialog' );
		const size = $( 'siteferry-parts-size' );
		const render = () => {
			const limit = Number( size.value ) * 1048576;
			const count = Math.max( 1, Math.ceil( item.size / limit ) );
			$( 'siteferry-parts-list' ).replaceChildren(
				...Array.from( { length: count }, ( _, i ) => {
					const number = String( i + 1 ).padStart( 3, '0' );
					const length = Math.min( limit, item.size - i * limit );
					return el( 'li', {}, [
						el( 'a', { href: url( 'backups/' + encodeURIComponent( item.name ) + '/download', { part: i + 1, part_mb: size.value, _wpnonce: cfg.nonce } ), text: item.name + '.' + number } ),
						el( 'span', { class: 'sf-space-size', text: bytes( length ) } ),
					] );
				} )
			);
		};
		size.onchange = render;
		/* translators: %s: name of the backup */
		$( 'siteferry-parts-dialog-title' ).textContent = sprintf( __( 'Download %s in parts', 'siteferry' ), called( item ) );
		render();
		dialog.showModal();
	}

	/**
	 * Files chosen or dropped for upload: one backup, or all parts of one (".001", ".002", ...),
	 * which are joined in order into one upload without reading them into memory.
	 */
	function chosenBackup( files ) {
		const list = Array.from( files || [] );
		if ( list.length < 2 ) {
			return list[ 0 ] || null;
		}
		const parts = list.map( ( file ) => /^(.+)\.(\d{3})$/.exec( file.name ) );
		const base = parts[ 0 ] && parts[ 0 ][ 1 ];
		if ( ! base || parts.some( ( match ) => ! match || match[ 1 ] !== base ) ) {
			notice( __( 'Choose one backup file, or all the parts of one backup (name.001, name.002, ...).', 'siteferry' ), 'error' );
			return null;
		}
		const sorted = list.map( ( file, i ) => [ Number( parts[ i ][ 2 ] ), file ] ).sort( ( a, b ) => a[ 0 ] - b[ 0 ] );
		const missing = sorted.findIndex( ( [ number ], i ) => number !== i + 1 );
		if ( missing !== -1 ) {
			notice(
				sprintf(
					/* translators: 1: number of the missing part, such as 002, 2: file name of the backup */
					__( 'Part %1$s of %2$s is missing: choose all its parts.', 'siteferry' ),
					String( missing + 1 ).padStart( 3, '0' ),
					base
				),
				'error'
			);
			return null;
		}
		return new File( sorted.map( ( [ , file ] ) => file ), base, { type: 'application/octet-stream' } );
	}
	function onFileChosen( event ) {
		const file = chosenBackup( event.target.files );
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
			const file = chosenBackup( event.dataTransfer && event.dataTransfer.files );
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
			notice( __( 'A restore is already under way.', 'siteferry' ), 'error' );
			$( 'siteferry-restore' ).scrollIntoView( { block: 'nearest' } );
			return;
		}
		if ( restore && restore.job && restore.phase === 'ready' ) {
			cancelJob( restore.job.id, restore.job.token );
		}
		closeMenus();
		restore = { item, mine: ours( item ), host: hostOf( item.source ), name: item.local ? item.name : null, job: null, phase: 'open', seq: 0, partial: partial || null, password: null };
		const paths = partial ? partial.options.restore_paths || [] : [];
		$( 'siteferry-safety' ).checked = true;
		$( 'siteferry-replace-url' ).value = '';
		// Must-use plugins and drop-ins ticked on purpose are copied.
		$( 'siteferry-include-mu' ).checked = paths.indexOf( 'mu-plugins' ) !== -1;
		$( 'siteferry-include-dropins' ).checked = paths.some( ( path ) => DROP_INS.indexOf( path ) !== -1 );
		$( 'siteferry-restore-what-chosen' ).hidden = ! partial;
		$( 'siteferry-restore-what-label' ).textContent = partial
			? sprintf(
					/* translators: %s: list of the parts ticked in "Look inside", such as the table wp_posts and the plugin akismet */
					__( 'Only what was ticked: %s', 'siteferry' ),
					partial.label
			  )
			: '';
		document.querySelector( 'input[name=siteferry_restore_what][value=' + ( partial ? 'chosen' : 'all' ) + ']' ).checked = true;
		$( 'siteferry-restore-advanced' ).open = !! partial;
		$( 'siteferry-restore-advanced' ).hidden = false;
		$( 'siteferry-inspection' ).hidden = true;
		$( 'siteferry-inspection-warnings' ).replaceChildren();
		$( 'siteferry-crossing' ).hidden = true;
		$( 'siteferry-import-cancel' ).hidden = false;
		$( 'siteferry-import-cancel' ).textContent = __( 'Cancel', 'siteferry' );
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
		let fromStorage = '';
		if ( item && ! item.local && item.places.length ) {
			const source = destination( item.places[ 0 ] );
			fromStorage =
				' ' +
				( source
					? sprintf(
							/* translators: %s: name of the storage, such as Dropbox */
							__( 'It is downloaded from %s to this server first.', 'siteferry' ),
							source.label
					  )
					: __( 'It is downloaded from the storage to this server first.', 'siteferry' ) );
		}
		const part = restoreWhat().label;
		let then = '';
		if ( item ) {
			then = item.title
				? sprintf(
						/* translators: 1: name given to the backup, 2: when it was made, such as today, 7:21 PM */
						__( '“%1$s” (%2$s)', 'siteferry' ),
						item.title,
						when( item.time, true )
				  )
				: when( item.time, true );
		}
		// The other site's address, or words for it when the backup does not say.
		/* translators: used inside sentences such as "Move the other site here" when the backup does not name its site */
		const name = host || __( 'the other site', 'siteferry' );
		if ( mine && part ) {
			$( 'siteferry-restore-title' ).textContent = item
				? sprintf(
						/* translators: %s: the backup, such as “Before the update” (today, 7:21 PM) */
						__( 'Restore part of this site from %s', 'siteferry' ),
						then
				  )
				: __( 'Restore part of a backup', 'siteferry' );
			$( 'siteferry-restore-lead' ).textContent =
				sprintf(
					/* translators: %s: what is restored, such as the database */
					__( 'This restores only %s, as it was then; everything else on this site stays as it is now.', 'siteferry' ),
					part
				) + fromStorage;
			$( 'siteferry-confirm' ).textContent = __( 'Restore now', 'siteferry' );
			$( 'siteferry-confirm-text' ).textContent = sprintf(
				/* translators: %s: what is restored, such as the database */
				__( 'I understand that this replaces %s on this site with the backup’s.', 'siteferry' ),
				part
			);
		} else if ( mine ) {
			$( 'siteferry-restore-title' ).textContent = item
				? sprintf(
						/* translators: %s: the backup, such as “Before the update” (today, 7:21 PM) */
						__( 'Restore this site to %s', 'siteferry' ),
						then
				  )
				: __( 'Restore a backup', 'siteferry' );
			$( 'siteferry-restore-lead' ).textContent =
				__( 'Posts, pages, settings, users, plugins, themes and media go back to how they were then; anything added or changed since is lost. SiteFerry and its storage settings stay as they are.', 'siteferry' ) + fromStorage;
			$( 'siteferry-confirm' ).textContent = __( 'Restore now', 'siteferry' );
			$( 'siteferry-confirm-text' ).textContent = __( 'I understand that this replaces this site’s content with the backup.', 'siteferry' );
		} else if ( part ) {
			$( 'siteferry-restore-title' ).textContent = sprintf(
				/* translators: %s: address of the other site, such as example.com */
				__( 'Copy part of %s here', 'siteferry' ),
				name
			);
			$( 'siteferry-restore-lead' ).textContent =
				sprintf(
					/* translators: 1: what is copied, such as the database, 2: address of the other site, 3: address of this site */
					__( 'This copies only %1$s from %2$s, replacing what this site has; everything else here stays as it is. Addresses in what is copied are changed to this site’s (%3$s).', 'siteferry' ),
					part,
					name,
					hostOf( cfg.home )
				) + fromStorage;
			$( 'siteferry-confirm' ).textContent = __( 'Copy here now', 'siteferry' );
			$( 'siteferry-confirm-text' ).textContent = sprintf(
				/* translators: 1: what is copied, such as the database, 2: address of the other site */
				__( 'I understand that this replaces %1$s on this site with %2$s’s.', 'siteferry' ),
				part,
				name
			);
		} else {
			$( 'siteferry-restore-title' ).textContent = sprintf(
				/* translators: %s: address of the other site, such as example.com */
				__( 'Move %s here', 'siteferry' ),
				name
			);
			$( 'siteferry-restore-lead' ).textContent =
				sprintf(
					/* translators: 1: address of the other site, 2: address of this site */
					__( 'This site’s content is replaced by the content of %1$s, and its addresses are changed to this site’s (%2$s). SiteFerry and its storage settings stay as they are. Afterwards you log in with that site’s users.', 'siteferry' ),
					name,
					hostOf( cfg.home )
				) + fromStorage;
			$( 'siteferry-confirm' ).textContent = __( 'Move here now', 'siteferry' );
			$( 'siteferry-confirm-text' ).textContent = sprintf(
				/* translators: %s: address of the other site, such as example.com */
				__( 'I understand that this replaces this site’s content with %s’s.', 'siteferry' ),
				name
			);
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
		const view = progressView( 'siteferry-import-progress', [ d ? d.short : __( 'Storage', 'siteferry' ), __( 'This server', 'siteferry' ) ] );
		view.start(
			d
				? sprintf(
						/* translators: %s: name of the storage, such as Dropbox */
						__( 'Downloading the backup from %s...', 'siteferry' ),
						d.label
				  )
				: __( 'Downloading the backup from the storage...', 'siteferry' )
		);
		try {
			const started = await api( 'POST', 'storage/' + d.id + '/fetch', { json: { name: restore.item.name } } );
			remember( started.job, started.token );
			restore.job = { id: started.job.id, token: started.token };
			const job = await driveJob( started.job.id, started.token, progressHandler( view ) );
			store.remove( JOB_KEY );
			restore.job = null;
			if ( job.status !== 'done' ) {
				restore.phase = 'failed';
				view.finish( false, __( 'The backup could not be downloaded', 'siteferry' ), [ el( 'p', { text: job.error } ) ] );
				return;
			}
			restore.name = job.result.fetched.name;
			loadLocalBackups();
			await startCheck();
		} catch ( error ) {
			restore.phase = 'failed';
			view.finish( false, __( 'The backup could not be downloaded', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
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
		if ( isEncrypted( { ...( restore.item || {} ), name: restore.name || '' } ) && ! restore.password ) {
			const password = await askPassword( __( 'Password of the backup', 'siteferry' ), __( 'This backup is protected with a password. Enter it to check and restore the backup.', 'siteferry' ), restore.locked || '' );
			if ( ! restore || seq !== restore.seq ) {
				return;
			}
			if ( null === password ) {
				closeRestore();
				return;
			}
			restore.password = password;
		}
		const view = progressView( 'siteferry-import-progress', [ __( 'Backup', 'siteferry' ), __( 'This site', 'siteferry' ) ] );
		view.start( __( 'Checking the backup; nothing on this site changes yet...', 'siteferry' ) );
		try {
			const started = await api( 'POST', 'imports', {
				json: {
					...( restore.password ? { password: restore.password } : {} ),
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
			if ( restore && seq === restore.seq && error.code === 'siteferry_locked' ) {
				// No password, or a wrong one: ask (again).
				restore.password = null;
				restore.locked = error.message;
				$( 'siteferry-import-progress' ).hidden = true;
				startCheck();
				return;
			}
			if ( restore && seq === restore.seq ) {
				restore.phase = 'failed';
				view.finish( false, __( 'This backup cannot be restored here', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
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
			view.finish( true, __( 'Checked: ready', 'siteferry' ), [ el( 'p', { text: __( 'Nothing on this site has changed yet.', 'siteferry' ) } ) ], false );
			return;
		}
		store.remove( JOB_KEY );
		$( 'siteferry-import-cancel' ).textContent = __( 'Close', 'siteferry' );
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
			let done;
			let title;
			if ( r.partial ) {
				// The server's words for the part ("Only the database"), lower-cased inside the sentence in English.
				const what = r.partial.replace( /^Only /, 'only ' );
				done = moved
					? sprintf(
							/* translators: 1: what was copied, such as only the database, 2: address of the other site */
							__( 'Copied %1$s from %2$s; everything else on this site stayed as it was.', 'siteferry' ),
							what,
							hostOf( r.from )
					  )
					: sprintf(
							/* translators: %s: what was restored, such as only the database */
							__( 'Restored %s; everything else on this site stayed as it was.', 'siteferry' ),
							what
					  );
				title = moved
					? sprintf(
							/* translators: %s: address of the other site, such as example.com */
							__( 'Copied part of %s here', 'siteferry' ),
							hostOf( r.from )
					  )
					: __( 'Restored part of the site', 'siteferry' );
			} else if ( moved ) {
				done = sprintf(
					/* translators: 1: address of the other site, 2: address of this site */
					__( 'This site now has the content of %1$s, at %2$s.', 'siteferry' ),
					hostOf( r.from ),
					hostOf( r.to )
				);
				title = sprintf(
					/* translators: %s: address of the other site, such as example.com */
					__( 'Moved %s here', 'siteferry' ),
					hostOf( r.from )
				);
			} else {
				done = __( 'This site is back to how it was when the backup was made.', 'siteferry' );
				title = __( 'Restored', 'siteferry' );
			}
			view.finish( true, title, [
				el( 'p', { text: done } ),
				...( safety
					? [
							el( 'p', {
								text: sprintf(
									/* translators: %s: size such as 12 MB */
									__( 'A safety backup of the site as it was before (%s) is in the list. To undo, use "Undo this restore" on the Backups tab, or restore that backup.', 'siteferry' ),
									bytes( safety.size )
								),
							} ),
					  ]
					: [] ),
				el( 'p', { text: moved ? __( 'Log in with one of that site’s users.', 'siteferry' ) : __( 'You may need to log in again.', 'siteferry' ) } ),
				el( 'p', { class: 'sf-actions' }, [ el( 'a', { class: 'button button-primary', href: cfg.loginUrl, text: __( 'Log in', 'siteferry' ) } ) ] ),
				...warningList( job ),
			] );
			return;
		}
		restore.phase = 'failed';
		const rolledBack = ! ( job.result && job.result.rollback_errors );
		view.finish( false, restore.mine ? __( 'The restore did not finish', 'siteferry' ) : __( 'The move did not finish', 'siteferry' ), [
			el( 'p', { text: job.error } ),
			el( 'p', { text: rolledBack ? __( 'This site was left as it was before.', 'siteferry' ) : __( 'Putting the previous site back did not fully succeed; see the Activity tab.', 'siteferry' ) } ),
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
		$( 'siteferry-crossing' ).replaceChildren( end( __( 'From', 'siteferry' ), info.from ), el( 'span', { class: 'sf-crossing-line', 'aria-hidden': 'true' } ), end( __( 'Into', 'siteferry' ), info.to ) );
		$( 'siteferry-crossing' ).hidden = mine;
		const rows = [
			[ __( 'Backup made', 'siteferry' ), date( info.created ) ],
			[
				__( 'Made with', 'siteferry' ),
				sprintf(
					/* translators: 1: WordPress version, 2: PHP version, 3: MySQL version */
					__( 'WordPress %1$s, PHP %2$s, MySQL %3$s', 'siteferry' ),
					info.wordpress,
					info.php,
					info.mysql
				),
			],
			[
				__( 'Contents', 'siteferry' ),
				sprintf(
					/* translators: 1: number of tables, such as 12 tables, 2: number of files, such as 3400 files, 3: size such as 12 MB */
					__( '%1$s, %2$s, %3$s', 'siteferry' ),
					/* translators: %d: number of database tables */
					sprintf( _n( '%d table', '%d tables', info.tables, 'siteferry' ), info.tables ),
					/* translators: %d: a number */
					sprintf( _n( '%d file', '%d files', info.files, 'siteferry' ), info.files ),
					bytes( info.bytes )
				),
			],
			[ __( 'File', 'siteferry' ), info.backup ],
		];
		if ( ! mine ) {
			rows.splice( 3, 0, [
				__( 'Address changes', 'siteferry' ),
				sprintf(
					/* translators: %d: number of search/replace pairs for the site addresses */
					_n( '%d search/replace pair', '%d search/replace pairs', info.replacements, 'siteferry' ),
					info.replacements
				),
			] );
		}
		if ( info.restores ) {
			rows.unshift( [ __( 'Restores', 'siteferry' ), info.restores ] );
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
		const view = progressView( 'siteferry-import-progress', [ __( 'Backup', 'siteferry' ), __( 'This site', 'siteferry' ) ] );
		const safety = $( 'siteferry-safety' ).checked;
		$( 'siteferry-confirm' ).hidden = true;
		$( 'siteferry-confirm-row' ).hidden = true;
		$( 'siteferry-safety-row' ).hidden = true;
		$( 'siteferry-import-cancel' ).hidden = true;
		$( 'siteferry-restore-advanced' ).hidden = true;
		$( 'siteferry-inspection' ).hidden = true;
		view.start( safety ? __( 'Making a safety backup of this site first...', 'siteferry' ) : restore.mine ? __( 'Restoring...', 'siteferry' ) : __( 'Moving the site here...', 'siteferry' ) );
		try {
			await api( 'POST', 'jobs/' + importJob.id + '/confirm', { token: importJob.token, json: { safety } } );
			await continueImport( view );
		} catch ( error ) {
			view.finish( false, __( 'Stopped', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
		}
	}

	/** The parts to restore, from "What to restore": import options and words for them ('' for everything). */
	function restoreWhat() {
		const checked = document.querySelector( 'input[name=siteferry_restore_what]:checked' );
		const what = checked ? checked.value : 'all';
		if ( what === 'database' ) {
			/* translators: what is restored; used inside sentences such as "This restores only the database, as it was then" */
			return { options: { restore_files: false }, label: __( 'the database', 'siteferry' ) };
		}
		if ( what === 'files' ) {
			/* translators: what is restored; used inside sentences such as "This restores only the files, as it was then" */
			return { options: { restore_database: false }, label: __( 'the files', 'siteferry' ) };
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
				/* translators: %s: error message */
				notice( sprintf( __( 'Could not cancel: %s', 'siteferry' ), error.message ), 'error' );
			}
			return false;
		}
		store.remove( JOB_KEY );
		return true;
	}

	// ------------------------------------------------------------ space report

	let space = null;
	let spaceLoading = false;

	/** Measures the site (in a few requests for a big one), then shows the estimate. */
	async function loadSpace( fresh ) {
		if ( spaceLoading ) {
			return;
		}
		spaceLoading = true;
		const line = $( 'siteferry-space-line' );
		try {
			for ( let round = 0; round < 200; round++ ) {
				const data = await api( 'GET', 'space', { query: fresh && round === 0 ? { fresh: 1 } : {} } );
				if ( data.complete ) {
					space = data.report;
					break;
				}
				line.textContent = sprintf(
					/* translators: 1: number of files measured so far, 2: size such as 12 MB */
					_n( 'Measuring the site... %1$d file, %2$s so far.', 'Measuring the site... %1$d files, %2$s so far.', data.files, 'siteferry' ),
					data.files,
					bytes( data.bytes )
				);
			}
		} catch ( error ) {
			/* translators: %s: error message */
			line.textContent = sprintf( __( 'The site could not be measured: %s', 'siteferry' ), error.message );
		} finally {
			spaceLoading = false;
		}
		renderSpace();
	}

	/** About how big a backup with the current choices will be (before compression). */
	function estimate( form ) {
		const checked = ( name ) => {
			const input = form.querySelector( 'input[name=' + name + ']' );
			return !! input && input.checked;
		};
		const f = space.files;
		let files = f.bytes;
		if ( checked( 'exclude_media' ) ) {
			files -= f.parts.uploads.bytes;
		}
		files -= checked( 'exclude_plugins' ) ? f.parts.plugins.bytes : checked( 'exclude_inactive_plugins' ) ? f.parts.plugins.inactive : 0;
		files -= checked( 'exclude_themes' ) ? f.parts.themes.bytes : checked( 'exclude_inactive_themes' ) ? f.parts.themes.inactive : 0;
		if ( checked( 'exclude_cache' ) ) {
			files -= f.parts.cache.bytes;
		}
		const db = space.database;
		const left = Array.from( form.querySelectorAll( 'input[name=exclude_tables]:checked' ) ).map( ( input ) => input.value );
		let database = db.bytes - db.tables.filter( ( t ) => left.indexOf( t.name ) !== -1 ).reduce( ( sum, t ) => sum + t.bytes, 0 );
		if ( checked( 'exclude_revisions' ) ) {
			database -= db.revisions.bytes;
		}
		if ( checked( 'exclude_spam_comments' ) ) {
			database -= db.spam.bytes;
		}
		return { files: Math.max( 0, files ), database: Math.max( 0, database ) };
	}

	function renderSpace() {
		const line = $( 'siteferry-space-line' );
		if ( ! space ) {
			return;
		}
		const form = $( 'siteferry-export-form' );
		const e = estimate( form );
		const total = e.files + e.database;
		const whole = space.files.bytes + space.database.bytes;
		const format = form.querySelector( 'input[name=format]:checked' );
		const wpress = !! format && format.value === 'wpress';
		const compress = form.querySelector( 'input[name=compress]' );
		// The archive is written next to the backups; a .wpress file is made from a TAR first.
		const needed = total * ( wpress ? 2 : 1 ) * 1.02;
		const compressed = compress && compress.checked && ! wpress;
		let sentence;
		if ( total < whole ) {
			sentence = compressed
				? /* translators: 1: estimated size of the backup, 2: size of the database, 3: size of the files, 4: size of the whole site; sizes such as 12 MB */
				  __( '%1$s (database %2$s, files %3$s; the whole site is %4$s), less once compressed.', 'siteferry' )
				: /* translators: 1: estimated size of the backup, 2: size of the database, 3: size of the files, 4: size of the whole site; sizes such as 12 MB */
				  __( '%1$s (database %2$s, files %3$s; the whole site is %4$s).', 'siteferry' );
		} else {
			sentence = compressed
				? /* translators: 1: estimated size of the backup, 2: size of the database, 3: size of the files; sizes such as 12 MB */
				  __( '%1$s (database %2$s, files %3$s), less once compressed.', 'siteferry' )
				: /* translators: 1: estimated size of the backup, 2: size of the database, 3: size of the files; sizes such as 12 MB */
				  __( '%1$s (database %2$s, files %3$s).', 'siteferry' );
		}
		const parts = around(
			sprintf( sentence, NODE_MARK, bytes( e.database ), bytes( e.files ), bytes( whole ) ),
			el( 'strong', {
				text: sprintf(
					/* translators: %s: estimated size of the backup, such as 12 MB */
					__( 'This backup: about %s', 'siteferry' ),
					bytes( total )
				),
			} )
		);
		if ( null !== space.free ) {
			parts.push(
				' ' +
					sprintf(
						/* translators: %s: free disk space, such as 12 GB */
						__( '%s free on this server.', 'siteferry' ),
						bytes( space.free )
					)
			);
			if ( space.free < needed ) {
				parts.push( ' ', el( 'strong', { class: 'sf-space-short', text: __( 'That is not enough room for it.', 'siteferry' ) } ) );
			}
		}
		line.replaceChildren( ...parts );
		$( 'siteferry-space-details' ).hidden = false;
		renderSpaceDetails();
	}

	function renderSpaceDetails() {
		const f = space.files;
		const db = space.database;
		const whole = f.bytes + db.bytes;
		const bar = ( label, value, note ) =>
			el( 'div', { class: 'sf-space-row' }, [
				el( 'span', { class: 'sf-space-label', text: label } ),
				el( 'span', { class: 'sf-space-bar', 'aria-hidden': 'true' }, [ el( 'span', { style: 'width:' + ( whole ? Math.max( 0.5, ( 100 * value ) / whole ) : 0 ).toFixed( 1 ) + '%' } ) ] ),
				el( 'span', { class: 'sf-space-size', text: bytes( value ) + ( note ? ' · ' + note : '' ) } ),
			] );
		const list = ( title, rows ) =>
			rows.length
				? el( 'div', { class: 'sf-space-list' }, [
						el( 'h4', { text: title } ),
						el( 'ol', {}, rows.map( ( [ name, value, note ] ) => el( 'li', {}, [ el( 'span', { class: 'sf-space-name', text: name } ), el( 'span', { class: 'sf-space-size', text: bytes( value ) + ( note ? ' · ' + note : '' ) } ) ] ) ) ),
				  ] )
				: document.createTextNode( '' );
		const p = f.parts;
		/* translators: %d: a number */
		const files = ( n ) => sprintf( _n( '%d file', '%d files', n, 'siteferry' ), n );
		/* translators: %d: a number */
		const rowCount = ( n ) => sprintf( _n( '%d row', '%d rows', n, 'siteferry' ), n );
		/* translators: %d: number of database tables */
		const tableCount = sprintf( _n( '%d table', '%d tables', db.tables.length, 'siteferry' ), db.tables.length );
		/* translators: %s: size of the inactive plugins or themes, such as 12 MB */
		const inactive = ( n ) => ( n ? sprintf( __( '%s inactive', 'siteferry' ), bytes( n ) ) : '' );
		const revisions = db.revisions.count
			? sprintf(
					/* translators: 1: number of post revisions, 2: their size, such as 12 MB */
					_n( '%1$d post revision (%2$s)', '%1$d post revisions (%2$s)', db.revisions.count, 'siteferry' ),
					db.revisions.count,
					bytes( db.revisions.bytes )
			  )
			: __( 'No post revisions', 'siteferry' );
		const spam = db.spam.count
			? sprintf(
					/* translators: 1: number of spam comments, 2: their size, such as 12 MB */
					_n( '%1$d spam comment (%2$s)', '%1$d spam comments (%2$s)', db.spam.count, 'siteferry' ),
					db.spam.count,
					bytes( db.spam.bytes )
			  )
			: __( 'no spam comments', 'siteferry' );
		const body = [
			bar( __( 'Database', 'siteferry' ), db.bytes, tableCount ),
			bar( __( 'Media (uploads)', 'siteferry' ), p.uploads.bytes, files( p.uploads.files ) ),
			bar( __( 'Plugins', 'siteferry' ), p.plugins.bytes, inactive( p.plugins.inactive ) ),
			bar( __( 'Themes', 'siteferry' ), p.themes.bytes, inactive( p.themes.inactive ) ),
			bar( __( 'Cache', 'siteferry' ), p.cache.bytes, __( 'left out by default', 'siteferry' ) ),
			bar( __( 'Everything else', 'siteferry' ), p.other.bytes, files( p.other.files ) ),
			el( 'div', { class: 'sf-space-lists' }, [
				list( __( 'Largest files', 'siteferry' ), f.largest.map( ( x ) => [ x.path, x.bytes ] ) ),
				list( __( 'Media by year', 'siteferry' ), f.years.slice( 0, 10 ).map( ( x ) => [ x.name, x.bytes ] ) ),
				list( __( 'Largest plugins', 'siteferry' ), f.plugins.slice( 0, 10 ).map( ( x ) => [ x.name, x.bytes, x.active ? '' : __( 'inactive', 'siteferry' ) ] ) ),
				list( __( 'Largest tables', 'siteferry' ), db.tables.slice( 0, 10 ).map( ( x ) => [ x.name, x.bytes, rowCount( x.rows ) ] ) ),
				list( __( 'Largest themes', 'siteferry' ), f.themes.slice( 0, 5 ).map( ( x ) => [ x.name, x.bytes, x.active ? '' : __( 'inactive', 'siteferry' ) ] ) ),
				list( __( 'Largest folders in wp-content', 'siteferry' ), f.items.slice( 0, 10 ).map( ( x ) => [ x.name, x.bytes ] ) ),
			] ),
			el( 'p', { class: 'description' }, [
				sprintf(
					/* translators: 1: post revisions, such as 12 post revisions (3 MB) or No post revisions, 2: spam comments, such as 4 spam comments (1 MB) or no spam comments */
					__( '%1$s and %2$s.', 'siteferry' ),
					revisions,
					spam
				) +
					' ' +
					sprintf(
						/* translators: %s: size such as 12 MB */
						__( 'Backups on this server take %s.', 'siteferry' ),
						bytes( space.backups )
					) +
					' ' +
					__( 'Sizes are of the files as they are, before any compression.', 'siteferry' ) +
					' ' +
					sprintf(
						/* translators: %s: when the site was measured, such as today, 7:21 PM */
						__( 'Measured %s.', 'siteferry' ),
						when( space.time, true )
					) +
					' ',
				button( __( 'Measure again', 'siteferry' ), () => loadSpace( true ), 'button-link' ),
			] ),
		];
		$( 'siteferry-space-body' ).replaceChildren( ...body );
	}

	// ------------------------------------------------------------ move to another server

	let receivePoll = null;

	/** Receiving: the key (when just made), and what has arrived. */
	async function loadReceive( made ) {
		const box = $( 'siteferry-receive' );
		let data = made;
		if ( ! data ) {
			try {
				data = await api( 'GET', 'receive' );
			} catch ( error ) {
				box.replaceChildren( el( 'p', { class: 'sf-row-error', text: error.message } ) );
				return;
			}
		}
		clearTimeout( receivePoll );
		const make = button( data.state === 'none' || data.state === 'expired' ? __( 'Make a key', 'siteferry' ) : __( 'Make a new key', 'siteferry' ), makeKey, 'button button-primary' );
		const nodes = [];
		if ( data.key ) {
			const field = el( 'textarea', { class: 'sf-move-key', rows: '3', readonly: 'readonly', spellcheck: 'false' } );
			field.value = data.key;
			nodes.push( field, el( 'p', { class: 'sf-actions' }, [
				button( __( 'Copy the key', 'siteferry' ), async () => {
					field.select();
					try {
						await navigator.clipboard.writeText( data.key );
						notice( __( 'The key is copied. Paste it on the old site, under Move.', 'siteferry' ), 'success' );
					} catch ( e ) {
						document.execCommand( 'copy' );
					}
				}, 'button button-primary' ),
			] ) );
		}
		if ( data.state === 'waiting' ) {
			nodes.push(
				el( 'p', {
					text: sprintf(
						/* translators: %s: date and time the key expires */
						__( 'Waiting for the old site. The key works until %s.', 'siteferry' ),
						date( data.expires )
					),
				} )
			);
		} else if ( data.state === 'receiving' ) {
			nodes.push(
				el( 'p', {
					text: sprintf(
						/* translators: 1: size received so far, 2: size of the whole backup; sizes such as 12 MB */
						__( 'Arriving: %1$s of %2$s.', 'siteferry' ),
						bytes( data.progress.offset ),
						bytes( data.progress.size )
					),
				} )
			);
		} else if ( data.state === 'expired' ) {
			nodes.push( el( 'p', { text: __( 'The last key has expired.', 'siteferry' ) } ) );
		} else if ( data.state === 'received' ) {
			const name = data.received.name;
			nodes.push(
				el( 'p', {
					text: sprintf(
						/* translators: 1: address of the old site, 2: size such as 12 MB */
						__( 'The site from %1$s has arrived (%2$s). Nothing on this site has changed yet.', 'siteferry' ),
						hostOf( data.received.from ),
						bytes( data.received.size )
					),
				} ),
				el( 'p', { class: 'sf-actions' }, [ button( __( 'Move it here', 'siteferry' ), () => {
					const item = items().find( ( entry ) => entry.name === name );
					if ( item ) {
						toggleDrawer( 'move', false );
						openRestore( item );
					}
				}, 'button button-primary' ) ] )
			);
			loadBackups();
		}
		if ( data.state === 'waiting' || data.state === 'receiving' ) {
			nodes.push( el( 'p', { class: 'sf-actions' }, [ button( __( 'Cancel the key', 'siteferry' ), async () => loadReceive( await api( 'DELETE', 'receive' ) ), 'button sf-quiet' ) ] ) );
			receivePoll = setTimeout( () => ! $( 'siteferry-move' ).hidden && loadReceive( data.key ? { ...data, key: data.key } : null ), 5000 );
		} else {
			nodes.push( el( 'p', { class: 'sf-actions' }, [ make ] ) );
		}
		box.replaceChildren( ...nodes );
	}

	async function makeKey() {
		try {
			loadReceive( await api( 'POST', 'receive', { json: {} } ) );
		} catch ( error ) {
			/* translators: %s: error message */
			notice( sprintf( __( 'No key was made: %s', 'siteferry' ), error.message ), 'error' );
		}
	}

	/** Sending: what the key's site says, then the move. */
	async function checkMoveKey() {
		const target = $( 'siteferry-move-target' );
		const key = $( 'siteferry-move-key' ).value.trim();
		target.hidden = false;
		target.replaceChildren( el( 'p', { text: __( 'Asking the other site...', 'siteferry' ) } ) );
		let hello;
		try {
			hello = await api( 'POST', 'move/check', { json: { key } } );
		} catch ( error ) {
			target.replaceChildren( el( 'p', { class: 'sf-row-error', text: error.message } ) );
			return;
		}
		const keep = el( 'input', { type: 'checkbox' } );
		target.replaceChildren(
			el( 'table', { class: 'sf-facts-table' }, [
				el( 'tbody', {}, [
					[
						__( 'Moving to', 'siteferry' ),
						hello.name
							? sprintf(
									/* translators: 1: address of the other site, 2: its title */
									__( '%1$s (%2$s)', 'siteferry' ),
									hello.site,
									hello.name
							  )
							: hello.site,
					],
					[
						__( 'Runs', 'siteferry' ),
						sprintf(
							/* translators: 1: WordPress version, 2: PHP version, 3: SiteFerry version */
							__( 'WordPress %1$s, PHP %2$s, SiteFerry %3$s', 'siteferry' ),
							hello.wordpress,
							hello.php,
							hello.siteferry
						),
					],
					[ __( 'Free there', 'siteferry' ), null === hello.free ? __( 'unknown', 'siteferry' ) : bytes( hello.free ) ],
				].map( ( [ k, v ] ) => el( 'tr', {}, [ el( 'th', { scope: 'row', text: k } ), el( 'td', { text: v } ) ] ) ) ),
			] ),
			el( 'p', {}, [ el( 'label', {}, [ keep, ' ' + __( 'Also keep the backup on this server', 'siteferry' ) ] ) ] ),
			el( 'p', { class: 'sf-actions' }, [ button( __( 'Move this site there', 'siteferry' ), () => startMove( key, keep.checked, hello.site ), 'button button-primary' ) ] )
		);
	}

	async function startMove( key, keepLocal, site ) {
		toggleDrawer( 'move', false );
		const view = progressView( 'siteferry-job-progress', [ __( 'This site', 'siteferry' ), hostOf( site ) ] );
		view.start( __( 'Backing up this site for the move...', 'siteferry' ) );
		try {
			const started = await api( 'POST', 'move/send', { json: { key, keep_local: keepLocal } } );
			remember( started.job, started.token );
			$( 'siteferry-move-key' ).value = '';
			$( 'siteferry-move-target' ).hidden = true;
			await finishExport( started.job.id, started.token, view, false );
		} catch ( error ) {
			view.finish( false, __( 'The site was not moved', 'siteferry' ), [ el( 'p', { text: error.message } ) ] );
		}
	}

	// ------------------------------------------------------------ look inside a backup

	/**
	 * The contents panel: one folder of a backup at a time, single files to download, and ticks for
	 * the parts a partial restore can bring back on their own: the whole database or single tables;
	 * all files, a top-level item of wp-content, or one plugin or theme.
	 */
	let inside = null;

	async function openContents( item, error ) {
		closeMenus();
		let unlock = '';
		if ( isEncrypted( item ) ) {
			const password = await askPassword(
				sprintf(
					/* translators: %s: name of the backup */
					__( 'Look inside %s', 'siteferry' ),
					called( item )
				),
				__( 'This backup is protected with a password. Enter it to see what is inside.', 'siteferry' ),
				error
			);
			if ( null === password ) {
				return;
			}
			try {
				unlock = ( await api( 'POST', 'backups/' + encodeURIComponent( item.name ) + '/unlock', { json: { password } } ) ).unlock;
			} catch ( failure ) {
				if ( failure.code === 'siteferry_locked' ) {
					openContents( item, failure.message );
				} else {
					notice( failure.message, 'error' );
				}
				return;
			}
		}
		inside = { item, dir: '', seq: 0, tables: null, hasDatabase: false, picks: new Map(), unlock };
		/* translators: %s: name of the backup */
		$( 'siteferry-contents-title' ).textContent = sprintf( __( 'Inside %s', 'siteferry' ), called( item ) );
		$( 'siteferry-contents-crumbs' ).replaceChildren();
		$( 'siteferry-contents-body' ).replaceChildren( el( 'p', { class: 'sf-contents-wait', text: __( 'Reading the list of files in the backup...', 'siteferry' ) } ) );
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
				data = await api( 'GET', 'backups/' + encodeURIComponent( inside.item.name ) + '/contents', { query: inside.unlock ? { dir, unlock: inside.unlock } : { dir } } );
			} catch ( error ) {
				if ( inside && seq === inside.seq ) {
					body.replaceChildren(
						el( 'p', {
							class: 'sf-row-error',
							text: sprintf(
								/* translators: %s: error message */
								__( 'The backup cannot be read: %s', 'siteferry' ),
								error.message
							),
						} )
					);
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
			body.replaceChildren(
				el( 'p', {
					class: 'sf-contents-wait',
					text: sprintf(
						/* translators: 1: percentage read, 2: number of entries found so far */
						_n( 'Reading the list of files in the backup... %1$d%% (%2$d entry so far)', 'Reading the list of files in the backup... %1$d%% (%2$d entries so far)', data.count, 'siteferry' ),
						share,
						data.count
					),
				} )
			);
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
		const nodes = [ crumb( __( 'Backup', 'siteferry' ), '', ! parts.length ) ];
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
			nodes.push( el( 'p', { class: 'description', text: __( 'This folder is empty.', 'siteferry' ) } ) );
		}
		$( 'siteferry-contents-body' ).replaceChildren( ...nodes );
	}

	function databaseBlock() {
		const tables = inside.tables;
		const rows = tables ? tables.reduce( ( n, t ) => n + t.rows, 0 ) : 0;
		const block = el( 'div', { class: 'sf-contents-db' }, [
			el( 'label', { class: 'sf-contents-db-all' }, [
				/* translators: a part of a backup; used inside sentences such as "Restore the whole database" */
				pickBox( 'db', __( 'the whole database', 'siteferry' ) ),
				el( 'span', { class: 'dashicons dashicons-database', 'aria-hidden': 'true' } ),
				el( 'strong', { text: __( 'Database', 'siteferry' ) } ),
				el( 'span', {
					class: 'sf-tree-what',
					text: tables
						? sprintf(
								/* translators: 1: number of tables, such as 12 tables, 2: number of rows, such as 3400 rows */
								_x( '%1$s, %2$s', 'tables and rows', 'siteferry' ),
								/* translators: %d: number of database tables */
								sprintf( _n( '%d table', '%d tables', tables.length, 'siteferry' ), tables.length ),
								/* translators: %d: a number */
								sprintf( _n( '%d row', '%d rows', rows, 'siteferry' ), rows )
						  )
						: __( 'its tables are listed when the backup is checked for restoring', 'siteferry' ),
				} ),
			] ),
		] );
		if ( tables && tables.length ) {
			block.appendChild(
				el( 'details', { class: 'sf-more' }, [
					el( 'summary', { text: __( 'Single tables', 'siteferry' ) } ),
					el(
						'div',
						{ class: 'sf-tables' },
						tables.map( ( t ) =>
							el( 'label', {}, [
								/* translators: %s: name of a database table; used inside sentences such as "Restore table wp_posts" */
								pickBox( 't:' + t.name, sprintf( __( 'table %s', 'siteferry' ), t.name ) ),
								' ' + t.name + ' ',
								/* translators: %d: a number */
								el( 'span', { class: 'sf-table-size', text: sprintf( _n( '%d row', '%d rows', t.rows, 'siteferry' ), t.rows ) } ),
							] )
						)
					),
				] )
			);
		}
		return block;
	}

	function fileUrl( name, path ) {
		const query = { path, _wpnonce: cfg.nonce };
		if ( inside && inside.unlock ) {
			query.unlock = inside.unlock; // Stands for the key for 15 minutes; not the password.
		}
		return url( 'backups/' + encodeURIComponent( name ) + '/file', query );
	}

	function entryRow( entry ) {
		const dir = entry.type === 'dir';
		const key = pickKey( entry.path );
		const name = dir
			? el( 'button', { type: 'button', class: 'sf-tree-open', text: entry.name, onclick: () => browse( entry.path ) } )
			: el( 'span', { class: 'sf-tree-file', text: entry.name } );
		const action =
			entry.type === 'file'
				? el( 'a', {
						class: 'sf-tree-download',
						href: fileUrl( inside.item.name, entry.path ),
						text: __( 'Download', 'siteferry' ),
						/* translators: %s: name of a file in the backup */
						'aria-label': sprintf( __( 'Download %s', 'siteferry' ), entry.name ),
				  } )
				: document.createTextNode( '' );
		let what;
		if ( dir ) {
			/* translators: %d: a number */
			what = sprintf( _n( '%d file', '%d files', entry.files, 'siteferry' ), entry.files );
		} else {
			/* translators: a symbolic link in a list of files */
			what = entry.type === 'link' ? __( 'link', 'siteferry' ) : date( entry.mtime );
		}
		return el( 'tr', { class: dir ? 'is-dir' : 'is-file' }, [
			/* translators: a part of a backup; used inside sentences such as "Restore all files" */
			el( 'td', { class: 'sf-tree-pick' }, [ key ? pickBox( key, key === 'files' ? __( 'all files', 'siteferry' ) : entry.path ) : document.createTextNode( '' ) ] ),
			el( 'td', { class: 'sf-tree-name' }, [ el( 'span', { class: 'dashicons ' + ( dir ? 'dashicons-category' : 'dashicons-media-default' ), 'aria-hidden': 'true' } ), name ] ),
			el( 'td', { class: 'sf-tree-what', text: what } ),
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
		/* translators: %s: a part of the backup, such as the whole database, table wp_posts or wp-content/plugins/akismet */
		const box = el( 'input', { type: 'checkbox', 'data-key': key, 'aria-label': sprintf( __( 'Restore %s', 'siteferry' ), label ) } );
		box.addEventListener( 'change', () => setPick( key, box.checked, label ) );
		syncBox( box );
		return box;
	}

	function syncBox( box ) {
		const wider = coveredBy( box.dataset.key );
		box.checked = wider !== '' || inside.picks.has( box.dataset.key );
		box.disabled = wider !== '';
		box.title = wider
			? sprintf(
					/* translators: %s: a wider part of the backup that is ticked, such as the whole database or all files */
					__( 'Included in %s', 'siteferry' ),
					inside.picks.get( wider )
			  )
			: '';
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
		if ( more > 0 ) {
			return sprintf(
				/* translators: 1: a comma-separated list of names, 2: how many more are not listed */
				_n( '%1$s and %2$d more', '%1$s and %2$d more', more, 'siteferry' ),
				shown.join( ', ' ),
				more
			);
		}
		return shown.join( ', ' );
	}

	function renderPicks() {
		const count = inside ? inside.picks.size : 0;
		$( 'siteferry-contents-foot' ).hidden = ! count;
		$( 'siteferry-contents-picked' ).textContent = count
			? sprintf(
					/* translators: %s: list of the ticked parts of the backup, such as the whole database, wp-content/plugins/akismet */
					__( 'To restore: %s.', 'siteferry' ),
					describePicks( inside.picks )
			  )
			: '';
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
		form.elements.email.placeholder = schedule.admin_email
			? sprintf(
					/* translators: %s: the site's admin email address */
					__( '%s (the site\'s admin email)', 'siteferry' ),
					schedule.admin_email
			  )
			: '';
		if ( form.elements.password ) {
			form.elements.password.value = '';
			form.elements.password.placeholder = schedule.has_password ? __( 'Saved; leave empty to keep it', 'siteferry' ) : '';
		}
		// WordPress names a plain offset "+05:00"; show it as UTC+05:00 (and "+00:00" as UTC).
		const zone = /^[+-]\d{2}:\d{2}$/.test( schedule.timezone ) ? 'UTC' + ( schedule.timezone === '+00:00' ? '' : schedule.timezone ) : schedule.timezone;
		$( 'siteferry-schedule-zone' ).textContent = sprintf(
			/* translators: %s: the site's time zone, such as Europe/Berlin or UTC+05:00 */
			__( 'In the site\'s time zone (%s, set under Settings, General). WordPress starts scheduled tasks when the site gets a visit, so on a quiet site a backup can start a little late.', 'siteferry' ),
			zone
		);
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
		const encrypt = form.querySelector( '[data-role=encrypt]' );
		if ( encrypt ) {
			encrypt.hidden = !! format && format.value === 'wpress';
			form.querySelector( '[data-role=encrypt-password]' ).hidden = encrypt.hidden || ! form.elements.encrypt.checked;
		}
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
			} else if ( input.type === 'password' ) {
				if ( input.value !== '' ) {
					data[ input.name ] = input.value; // Empty: the saved password stays.
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

	/** "every Monday at 03:00, to Dropbox, keeping the newest 7." */
	function describeSchedule( s ) {
		let when;
		if ( s.frequency === 'weekly' ) {
			/* translators: 1: day of the week, such as Monday, 2: time of day, such as 03:00 */
			when = sprintf( __( 'every %1$s at %2$s', 'siteferry' ), WEEKDAYS[ s.weekday ], s.time );
		} else if ( s.frequency === 'monthly' ) {
			/* translators: 1: day of the month, such as 15, 2: time of day, such as 03:00 */
			when = sprintf( __( 'on day %1$s of every month at %2$s', 'siteferry' ), s.monthday, s.time );
		} else {
			/* translators: %s: time of day, such as 03:00 */
			when = sprintf( __( 'every day at %s', 'siteferry' ), s.time );
		}
		const target = s.send_to ? destination( s.send_to ) : null;
		let where = __( 'on this server', 'siteferry' );
		if ( s.send_to ) {
			where = s.keep_local
				? /* translators: %s: name of the storage, such as Dropbox */
				  sprintf( __( 'to %s and this server', 'siteferry' ), target ? target.short : s.send_to )
				: /* translators: %s: name of the storage, such as Dropbox */
				  sprintf( __( 'to %s', 'siteferry' ), target ? target.short : s.send_to );
		}
		const keep =
			s.retain > 0
				? /* translators: %d: number of scheduled backups kept */
				  sprintf( _n( 'keeping the newest %d', 'keeping the newest %d', s.retain, 'siteferry' ), s.retain )
				: __( 'keeping them all', 'siteferry' );
		return sprintf(
			/* translators: 1: how often, such as every Monday at 03:00, 2: where, such as to Dropbox, 3: how many are kept, such as keeping the newest 7 */
			__( '%1$s, %2$s, %3$s.', 'siteferry' ),
			when,
			where,
			keep
		);
	}

	/** The line under "Back up now": the schedule, the next and the last run. */
	function renderScheduleLine() {
		const line = $( 'siteferry-schedule-line' );
		const s = schedule.settings;
		const open = () => toggleDrawer( 'schedule', true );
		const parts = [];
		if ( ! s.enabled ) {
			parts.push( el( 'span', { class: 'sf-chip', text: __( 'Automatic backups off', 'siteferry' ) } ), ' ', button( __( 'Set up a schedule', 'siteferry' ), open, 'button sf-quiet sf-quiet-small' ) );
		} else {
			parts.push( el( 'span', { class: 'sf-chip is-good', text: __( 'Automatic backups on', 'siteferry' ) } ), ' ' + describeSchedule( s ) );
			if ( schedule.next ) {
				/* translators: %s: when the next scheduled backup runs, such as tomorrow, 3:00 AM */
				parts.push( ' ' + sprintf( __( 'Next: %s.', 'siteferry' ), nextRun() ) );
			}
			if ( schedule.retry ) {
				parts.push(
					' ' +
						sprintf(
							/* translators: %s: when the next try is, such as today, 9:00 AM */
							__( 'Waiting for another job to finish; trying again %s.', 'siteferry' ),
							upcoming( schedule.retry )
						)
				);
			}
			parts.push( ' ', button( __( 'Change', 'siteferry' ), open, 'button sf-quiet sf-quiet-small' ) );
		}
		if ( s.before_updates ) {
			parts.push( el( 'span', { class: 'sf-schedule-last', text: __( 'A backup is also made before WordPress, a plugin or a theme updates.', 'siteferry' ) } ) );
		}
		const last = schedule.last;
		if ( last ) {
			const failed = last.status !== 'done';
			const at = when( last.time, true );
			let text;
			if ( failed && last.status === 'skipped' ) {
				/* translators: 1: when, such as today, 3:00 AM, 2: the reason */
				text = sprintf( __( 'Last scheduled backup %1$s: skipped. %2$s', 'siteferry' ), at, last.message );
			} else if ( failed ) {
				/* translators: 1: when, such as today, 3:00 AM, 2: error message */
				text = sprintf( __( 'Last scheduled backup %1$s: failed. %2$s', 'siteferry' ), at, last.message );
			} else if ( last.where ) {
				/* translators: 1: when, such as today, 3:00 AM, 2: where it is stored, such as Dropbox */
				text = sprintf( __( 'Last scheduled backup %1$s: done, stored on %2$s.', 'siteferry' ), at, last.where );
			} else {
				/* translators: %s: when, such as today, 3:00 AM */
				text = sprintf( __( 'Last scheduled backup %s: done.', 'siteferry' ), at );
			}
			parts.push( el( 'span', { class: 'sf-schedule-last' + ( failed ? ' is-bad' : '' ), text } ) );
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
			/* translators: %s: when the next scheduled backup runs, such as tomorrow, 3:00 AM */
			bits.push( sprintf( __( 'Next backup: %s.', 'siteferry' ), nextRun() ) );
		}
		if ( schedule.kept ) {
			/* translators: %d: number of scheduled backups kept */
			bits.push( sprintf( _n( '%d scheduled backup kept so far.', '%d scheduled backups kept so far.', schedule.kept, 'siteferry' ), schedule.kept ) );
		}
		box.textContent = bits.join( ' ' );

		const update = schedule.last_update;
		const line = $( 'siteferry-update-last' );
		line.hidden = ! update;
		if ( update ) {
			const at = when( update.time, true );
			let text;
			if ( update.status === 'done' ) {
				/* translators: 1: what was updated, such as the plugin Akismet, 2: when, such as today, 3:00 AM, 3: size such as 12 MB */
				text = sprintf( __( 'Last backup before an update: %1$s, %2$s, made (%3$s).', 'siteferry' ), update.what, at, bytes( update.size ) );
			} else if ( update.status === 'skipped' ) {
				/* translators: 1: what was updated, such as the plugin Akismet, 2: when, such as today, 3:00 AM, 3: the reason */
				text = sprintf( __( 'Last backup before an update: %1$s, %2$s, skipped: %3$s.', 'siteferry' ), update.what, at, update.message );
			} else {
				/* translators: 1: what was updated, such as the plugin Akismet, 2: when, such as today, 3:00 AM, 3: error message */
				text = sprintf( __( 'Last backup before an update: %1$s, %2$s, failed: %3$s.', 'siteferry' ), update.what, at, update.message );
			}
			if ( schedule.update_kept ) {
				text +=
					' ' +
					sprintf(
						/* translators: %d: number of backups made before updates that are kept */
						_n( '%d backup made before updates is kept.', '%d backups made before updates are kept.', schedule.update_kept, 'siteferry' ),
						schedule.update_kept
					);
			}
			line.textContent = text;
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
			scheduleResult(
				true,
				schedule.settings.enabled
					? sprintf(
							/* translators: %s: when the next scheduled backup runs, such as tomorrow, 3:00 AM */
							__( 'Saved. Next backup: %s.', 'siteferry' ),
							nextRun()
					  )
					: __( 'Saved. Automatic backups are off.', 'siteferry' )
			);
			return true;
		} catch ( error ) {
			/* translators: %s: error message */
			scheduleResult( false, sprintf( __( 'Not saved: %s', 'siteferry' ), error.message ) );
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
			/* translators: %s: error message */
			scheduleResult( false, sprintf( __( 'Not started: %s', 'siteferry' ), error.message ) );
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
		const view = progressView( 'siteferry-job-progress', [ __( 'This site', 'siteferry' ), target ? target.short : __( 'This server', 'siteferry' ) ] );
		view.start( __( 'Scheduled backup running...', 'siteferry' ) );
		const banner = $( 'siteferry-active' );
		$( 'siteferry-active-resume' ).hidden = true;
		$( 'siteferry-active-cancel' ).textContent = __( 'Stop it', 'siteferry' );
		$( 'siteferry-active-cancel' ).onclick = async () => {
			// eslint-disable-next-line no-alert
			if ( window.confirm( __( 'Stop the scheduled backup? What it has done so far is removed; earlier backups stay.', 'siteferry' ) ) && ( await cancelJob( job.id, null ) ) ) {
				notice( __( 'The scheduled backup was stopped.', 'siteferry' ), 'success' );
			}
		};
		let failures = 0;
		while ( watching === job.id ) {
			const message = job.progress && job.progress.message;
			$( 'siteferry-active-text' ).textContent = message
				? sprintf(
						/* translators: %s: what the backup is doing, such as Packing files */
						__( 'A scheduled backup is running (%s). It runs by itself on the server; you can leave this page.', 'siteferry' ),
						message
				  )
				: __( 'A scheduled backup is running (starting). It runs by itself on the server; you can leave this page.', 'siteferry' );
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
					view.finish( false, __( 'Lost track of the scheduled backup', 'siteferry' ), [
						el( 'p', {
							text: sprintf(
								/* translators: %s: error message */
								__( '%s It may still be running; reload the page to check.', 'siteferry' ),
								error.message
							),
						} ),
					] );
					watching = '';
					banner.hidden = true;
					return;
				}
			}
		}
		watching = '';
		banner.hidden = true;
		$( 'siteferry-active-cancel' ).textContent = __( 'Cancel it', 'siteferry' );
		if ( job.status === 'done' ) {
			const r = job.result;
			let stored;
			if ( ! r.sent ) {
				/* translators: %s: size such as 12 MB */
				stored = sprintf( __( '%s · stored on this server.', 'siteferry' ), bytes( r.size ) );
			} else if ( r.deleted_local ) {
				/* translators: 1: size such as 12 MB, 2: name of the storage, such as Dropbox */
				stored = sprintf( __( '%1$s · stored on %2$s.', 'siteferry' ), bytes( r.size ), r.sent.where );
			} else {
				/* translators: 1: size such as 12 MB, 2: name of the storage, such as Dropbox */
				stored = sprintf( __( '%1$s · stored on this server and on %2$s.', 'siteferry' ), bytes( r.size ), r.sent.where );
			}
			const deleted = r.schedule && r.schedule.deleted ? r.schedule.deleted.length : 0;
			if ( deleted ) {
				stored +=
					' ' +
					sprintf(
						/* translators: %d: number of older scheduled backups deleted */
						_n( '%d older scheduled backup was deleted.', '%d older scheduled backups were deleted.', deleted, 'siteferry' ),
						deleted
					);
			}
			view.finish( true, __( 'Scheduled backup ready', 'siteferry' ), [
				el( 'p', { text: stored } ),
				el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: r.name } ) ] ),
				...warningList( job ),
			] );
		} else {
			view.finish( false, /^cancel/i.test( job.error || '' ) ? __( 'The scheduled backup was stopped', 'siteferry' ) : __( 'The scheduled backup failed', 'siteferry' ), [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
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
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: __( 'Nothing has run yet. Backups, restores and transfers show up here, each with its log.', 'siteferry' ) } ) );
			return;
		}
		const origins = {
			schedule: __( 'Scheduled backup', 'siteferry' ),
			safety: __( 'Safety backup', 'siteferry' ),
			update: __( 'Backup before update', 'siteferry' ),
		};
		list.replaceChildren(
			...jobs.map( ( job ) => {
				const cancelled = job.status === 'failed' && /^cancel/i.test( job.error || '' );
				const [ label, tone ] = cancelled ? [ __( 'Cancelled', 'siteferry' ), '' ] : JOB_STATES[ job.status ] || [ job.status, '' ];
				const row = el( 'li', { class: 'sf-row sf-job' } );
				const view = button( __( 'View log', 'siteferry' ), () => toggleLog( row, job.id, view ), 'button sf-quiet' );
				row.append(
					el( 'span', { class: 'sf-job-when', text: date( job.created ) } ),
					el( 'div', { class: 'sf-job-what' }, [
						el( 'span', { class: 'sf-job-type', text: origins[ job.origin ] || JOB_TYPES[ job.type ] || job.type } ),
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
			toggle.textContent = __( 'View log', 'siteferry' );
			return;
		}
		const view = el( 'pre', { class: 'sf-log', text: __( 'Loading...', 'siteferry' ) } );
		row.appendChild( view );
		toggle.textContent = __( 'Hide log', 'siteferry' );
		try {
			const result = await api( 'GET', 'jobs/' + id + '/log' );
			view.textContent = ( result.truncated ? __( '[earlier lines omitted]', 'siteferry' ) + '\n' : '' ) + result.log;
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
			move: [ 'siteferry-move', 'siteferry-move-toggle' ],
		};
		Object.keys( drawers ).forEach( ( key ) => {
			const [ panel, toggle ] = drawers[ key ];
			const open = key === which ? ( force === undefined ? $( panel ).hidden : force ) : false;
			$( panel ).hidden = ! open;
			$( toggle ).setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			$( toggle ).classList.toggle( 'is-open', open );
		} );
		if ( which === 'move' && ! $( 'siteferry-move' ).hidden ) {
			loadReceive();
		}
		if ( which === 'options' && ! $( 'siteferry-export-form' ).hidden && ! space ) {
			loadSpace( false ); // Measured once the options are opened, not on every page load.
		}
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
				el( 'strong', {
					text: moved
						? sprintf(
								/* translators: 1: address of the other site, 2: when, such as today, 7:21 PM */
								__( 'Moved %1$s here %2$s.', 'siteferry' ),
								hostOf( undo.from ),
								when( undo.time, true )
						  )
						: sprintf(
								/* translators: %s: when, such as today, 7:21 PM */
								__( 'Restored %s.', 'siteferry' ),
								when( undo.time, true )
						  ),
				} ),
				' ' + __( 'A safety backup of the site as it was just before is kept. Changed your mind?', 'siteferry' ),
			] ),
			el( 'p', { class: 'sf-actions' }, [
				button( __( 'Undo this restore', 'siteferry' ), () => undoRestore( undo ), 'button button-primary' ),
				button( __( 'Keep it as it is', 'siteferry' ), dismissUndo, 'button sf-quiet' ),
			] )
		);
		box.hidden = false;
	}

	/** Opens the restore panel for the safety backup made before the last restore. */
	function undoRestore( undo ) {
		showTab( 'backups' );
		openRestore( {
			name: undo.safety,
			local: true,
			places: [],
			source: cfg.home,
			time: undo.time,
			/* translators: name of the safety backup; used inside sentences such as Restore this site to “the site before the last restore” */
			title: __( 'the site before the last restore', 'siteferry' ),
			size: 0,
		} );
	}

	async function dismissUndo() {
		try {
			await api( 'POST', 'undo/dismiss' );
			$( 'siteferry-undo' ).hidden = true;
			notice( __( 'Kept as it is. The safety backup stays in the list until you delete it.', 'siteferry' ), 'success' );
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
			/* translators: %s: error message */
			notice( sprintf( __( 'SiteFerry cannot reach its REST API: %s', 'siteferry' ), error.message ), 'error' );
			return;
		}
		renderUndo( status.undo );
		const unfinished = status.uploads || [];
		if ( unfinished.length ) {
			const box = $( 'siteferry-unfinished-uploads' );
			box.textContent = sprintf(
				/* translators: %s: comma-separated list of unfinished uploads, such as backup.tar (12 MB of 40 MB) */
				__( 'Unfinished upload: %s. Choose the same file to continue.', 'siteferry' ),
				unfinished
					.map( ( u ) =>
						sprintf(
							/* translators: 1: file name, 2: size uploaded so far, 3: size of the file; sizes such as 12 MB */
							__( '%1$s (%2$s of %3$s)', 'siteferry' ),
							u.name,
							bytes( u.offset ),
							bytes( u.size )
						)
					)
					.join( ', ' )
			);
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
		const what = JOB_NAMES[ active.type ] || __( 'A job', 'siteferry' );
		$( 'siteferry-active-text' ).textContent =
			sprintf(
				/* translators: 1: the kind of job, such as A backup, 2: date and time it started, 3: what it was doing */
				__( '%1$s started %2$s is not finished (%3$s).', 'siteferry' ),
				what,
				date( active.created ),
				active.progress.message || active.status
			) + ( token ? '' : ' ' + __( 'This browser did not start it, so it can only be cancelled here.', 'siteferry' ) );
		$( 'siteferry-active' ).hidden = false;
		$( 'siteferry-active-resume' ).hidden = ! token;
		$( 'siteferry-active-resume' ).onclick = async () => {
			$( 'siteferry-active' ).hidden = true;
			showTab( 'backups' );
			if ( active.type === 'send' || active.type === 'fetch' || active.type === 'check' ) {
				await runTransfer( active.id, token, __( 'Resuming...', 'siteferry' ) );
			} else if ( active.type === 'export' ) {
				const view = progressView( 'siteferry-job-progress' );
				view.start( __( 'Resuming the backup...', 'siteferry' ) );
				await finishExport( active.id, token, view, false );
			} else {
				importJob = { id: active.id, token };
				restore = { item: null, mine: true, name: null, job: importJob, phase: active.status === 'waiting' ? 'check' : 'running', seq: 0 };
				setWording( true, '' );
				$( 'siteferry-restore-title' ).textContent = active.status === 'waiting' ? __( 'Restore a backup', 'siteferry' ) : __( 'Finishing the restore', 'siteferry' );
				$( 'siteferry-restore-advanced' ).hidden = true;
				$( 'siteferry-import-cancel' ).hidden = active.status !== 'waiting';
				setConfirm( false );
				$( 'siteferry-restore' ).hidden = false;
				const view = progressView( 'siteferry-import-progress', [ __( 'Backup', 'siteferry' ), __( 'This site', 'siteferry' ) ] );
				view.start( __( 'Resuming...', 'siteferry' ) );
				await continueImport( view, 0 );
			}
		};
		$( 'siteferry-active-cancel' ).onclick = async () => {
			if ( await cancelJob( active.id, token ) ) {
				// (A restore stopped here also stops its safety backup and removes it.)
				$( 'siteferry-active' ).hidden = true;
				/* translators: %s: the kind of job, such as A backup */
				notice( sprintf( __( '%s was cancelled and its work undone.', 'siteferry' ), what ), 'success' );
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
		$( 'siteferry-export-form' ).addEventListener( 'change', () => space && renderSpace() );
		$( 'siteferry-options-toggle' ).addEventListener( 'click', () => toggleDrawer( 'options' ) );
		$( 'siteferry-options-close' ).addEventListener( 'click', () => {
			toggleDrawer( 'options', false );
			$( 'siteferry-options-toggle' ).focus();
		} );
		$( 'siteferry-upload-toggle' ).addEventListener( 'click', () => toggleDrawer( 'upload' ) );
		$( 'siteferry-schedule-toggle' ).addEventListener( 'click', () => toggleDrawer( 'schedule' ) );
		$( 'siteferry-move-toggle' ).addEventListener( 'click', () => toggleDrawer( 'move' ) );
		$( 'siteferry-move-check' ).addEventListener( 'click', checkMoveKey );
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
		if ( $( 'siteferry-encrypt' ) ) {
			$( 'siteferry-encrypt' ).addEventListener( 'change', syncFormat );
		}
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
						storageResult( form, null, [ el( 'p', { text: __( 'Saving or signing in this way replaces the current connection.', 'siteferry' ) } ) ] );
					}
				} );
			}
			const copy = form.querySelector( '[data-role=copy]' );
			if ( copy ) {
				copy.addEventListener( 'click', () => {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( copy.dataset.copy ).then( () => {
							copy.textContent = __( 'Copied', 'siteferry' );
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
