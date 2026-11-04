/**
 * SiteFerry admin page: drives export and import jobs, uploads and downloads through the
 * siteferry/v1 REST API. Vanilla JavaScript; all server data is inserted with textContent.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
( function () {
	'use strict';

	const cfg = window.SiteFerryConfig || {};
	const $ = ( id ) => document.getElementById( id );
	const JOB_KEY = 'siteferry.job';
	const JOB_NAMES = { export: 'An export', import: 'An import', send: 'A transfer to storage', fetch: 'A download from storage' };
	const JOB_TYPES = { export: 'Export', import: 'Import', send: 'Send to storage', fetch: 'Download from storage' };
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
		upload: 'Upload',
	};
	const IMPORT_STEPS = [ 'choose', 'check', 'import' ];
	let selectedBackup = null;
	let importJob = null;
	let destinations = [];
	let localBackups = null;
	let noticeTimer = null;

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
		const response = await fetch( url( path ), { method, headers, body, credentials: 'same-origin' } );
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
					if ( ! ok ) {
						part( 'sf-progress-percent' ).textContent = '';
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

	// ------------------------------------------------------------ export

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

	async function startExport( event ) {
		event.preventDefault();
		const form = event.target;
		const options = { exclude_tables: [] };
		form.querySelectorAll( 'input[type=checkbox]' ).forEach( ( input ) => {
			if ( input.name === 'exclude_tables' ) {
				if ( input.checked ) {
					options.exclude_tables.push( input.value );
				}
			} else {
				options[ input.name ] = input.checked;
			}
		} );
		const choice = form.querySelector( 'input[name=send_to]:checked' );
		options.send_to = choice ? choice.value : '';
		if ( ! options.send_to ) {
			options.delete_after_send = false;
		}
		const target = destination( options.send_to );
		const view = progressView( 'siteferry-export-progress', [ 'This site', target ? target.short : 'Backup on this server' ] );
		const submit = form.querySelector( 'button[type=submit]' );
		submit.disabled = true;
		view.start( 'Starting the backup...' );
		try {
			const started = await api( 'POST', 'exports', { json: options } );
			remember( started.job, started.token );
			await finishExport( started.job.id, started.token, view );
		} catch ( error ) {
			view.finish( false, 'The backup was not made', [ el( 'p', { text: error.message } ) ] );
		} finally {
			submit.disabled = false;
		}
	}

	async function finishExport( id, token, view ) {
		const job = await driveJob( id, token, progressHandler( view ) );
		store.remove( JOB_KEY );
		if ( job.status !== 'done' ) {
			view.finish( false, 'The backup was not made', [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
			return;
		}
		const r = job.result;
		const nodes = [
			el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: r.name } ) ] ),
			el( 'p', { text: bytes( r.size ) + ' · ' + plural( r.files, 'file', 'files' ) } ),
		];
		if ( r.sent ) {
			nodes.push( el( 'p', { text: 'Sent to ' + r.sent.where + '.' + ( r.deleted_local ? ' The copy on this server was deleted.' : '' ) } ) );
		}
		const actions = [];
		if ( ! r.deleted_local ) {
			actions.push( el( 'a', { class: 'button button-primary', href: downloadUrl( r.name ), text: 'Download' } ) );
		}
		actions.push( button( 'Show all backups', () => showTab( 'backups' ), 'button' ) );
		nodes.push( el( 'p', { class: 'sf-actions' }, actions ) );
		view.finish( true, 'Backup ready', [ ...nodes, ...warningList( job ) ] );
		loadLocalBackups();
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
			const count = $( 'siteferry-tab-count' );
			count.textContent = String( localBackups.length );
			count.hidden = localBackups.length === 0;
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
			connect.textContent = connected ? 'Sign in again' : 'Sign in with Google';
		} else {
			connect.textContent = connected ? 'Connect again' : 'Connect to Google Drive';
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

	/** Runs a send or fetch job with the progress box on the Backups tab. */
	async function runTransfer( id, token, message, ends ) {
		const view = progressView( 'siteferry-transfer-progress', ends );
		view.start( message );
		let job;
		try {
			job = await driveJob( id, token, progressHandler( view ) );
		} catch ( error ) {
			view.finish( false, 'Stopped', [ el( 'p', { text: error.message } ) ] );
			return;
		}
		store.remove( JOB_KEY );
		if ( job.status !== 'done' ) {
			view.finish( false, job.type === 'send' ? 'Not sent' : 'Not downloaded', [ el( 'p', { text: job.error } ), ...warningList( job ) ] );
		} else if ( job.type === 'send' ) {
			view.finish( true, 'Sent', [ el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: job.result.sent.name } ), ' is now on ' + job.result.sent.where + '.' ] ) ] );
		} else {
			const name = job.result.fetched.name;
			view.finish( true, 'Downloaded to this server', [
				el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: name } ) ] ),
				el( 'p', { class: 'sf-actions' }, [ button( 'Import it', () => { selectBackup( name ); showTab( 'import' ); }, 'button' ) ] ),
			] );
		}
		loadBackups();
	}

	async function startTransfer( path, body, message, ends ) {
		try {
			const started = await api( 'POST', path, { json: body } );
			remember( started.job, started.token );
			await runTransfer( started.job.id, started.token, message, ends );
		} catch ( error ) {
			const view = progressView( 'siteferry-transfer-progress' );
			view.start( message );
			view.finish( false, 'Could not start', [ el( 'p', { text: error.message } ) ] );
		}
	}

	/** One card per storage that is ready, listing the SiteFerry backups stored there. */
	function loadRemote() {
		const box = $( 'siteferry-remote' );
		box.replaceChildren(
			...destinations
				.filter( ( d ) => d.ready )
				.map( ( d ) => {
					const list = el( 'ul', { class: 'sf-list' }, [ el( 'li', { class: 'sf-empty', text: 'Loading...' } ) ] );
					const where = el( 'span', { class: 'sf-list-where', text: d.description } );
					fillRemote( d, list, where );
					return el( 'div', { class: 'sf-card sf-card-flush sf-remote' }, [
						el( 'div', { class: 'sf-list-head' }, [
							el( 'div', {}, [ el( 'h2', { class: 'sf-card-title', text: 'On ' + d.label } ), where ] ),
							button( 'Refresh', () => fillRemote( d, list, where ) ),
						] ),
						list,
					] );
				} )
		);
	}

	async function fillRemote( d, list, where ) {
		try {
			const result = await api( 'GET', 'storage/' + d.id + '/backups' );
			where.textContent = result.where;
			if ( ! result.backups.length ) {
				list.replaceChildren( el( 'li', { class: 'sf-empty', text: 'No SiteFerry backups there yet. Send one from the list above, or choose it under "Where it goes" when you create a backup.' } ) );
				return;
			}
			list.replaceChildren(
				...result.backups.map( ( b ) =>
					el( 'li', { class: 'sf-row' }, [
						el( 'div', {}, [
							el( 'span', { class: 'sf-row-name', text: b.name } ),
							el( 'div', { class: 'sf-row-meta' }, [ el( 'span', { text: bytes( b.size ) } ), el( 'span', { text: 'stored ' + date( b.modified ) } ) ] ),
						] ),
						el( 'div', { class: 'sf-row-actions' }, [
							button( 'Download to this server', () => startTransfer( 'storage/' + d.id + '/fetch', { name: b.name }, 'Downloading ' + b.name + '...', [ d.short, 'This server' ] ) ),
							button( 'Delete', () => deleteRemote( d, b.name, list, where ), 'button-link button-link-delete' ),
						] ),
					] )
				)
			);
		} catch ( error ) {
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: error.message } ) );
		}
	}

	async function deleteRemote( d, name, list, where ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Delete ' + name + ' from ' + d.label + '? This cannot be undone.' ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'storage/' + d.id + '/backups/' + encodeURIComponent( name ) );
			notice( 'Deleted ' + name + ' from ' + d.label + '.', 'success' );
		} catch ( error ) {
			notice( 'Could not delete: ' + error.message, 'error' );
		}
		fillRemote( d, list, where );
	}

	function downloadUrl( name ) {
		return url( 'backups/' + encodeURIComponent( name ) + '/download', { _wpnonce: cfg.nonce } );
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
			view.finish( true, 'Uploaded and checked', [ el( 'p', {}, [ el( 'span', { class: 'sf-result-name', text: name } ) ] ) ] );
			await loadLocalBackups();
			selectBackup( name );
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

	// ------------------------------------------------------------ import

	function setImportStep( step ) {
		const at = step === 'done' ? IMPORT_STEPS.length : IMPORT_STEPS.indexOf( step );
		document.querySelectorAll( '#siteferry-import-steps li' ).forEach( ( li, i ) => {
			li.className = i < at ? 'is-done' : i === at ? 'is-current' : '';
			if ( i === at ) {
				li.setAttribute( 'aria-current', 'step' );
			} else {
				li.removeAttribute( 'aria-current' );
			}
		} );
	}

	function showChooser( show ) {
		$( 'siteferry-import-choose' ).hidden = ! show;
		if ( show ) {
			$( 'siteferry-inspection' ).hidden = true;
			$( 'siteferry-import-start' ).disabled = ! selectedBackup;
		}
	}

	function selectBackup( name ) {
		selectedBackup = name;
		$( 'siteferry-import-selected' ).textContent = name;
		$( 'siteferry-import-selected-row' ).hidden = false;
		const pick = $( 'siteferry-import-pick' );
		pick.value = Array.from( pick.options ).some( ( option ) => option.value === name ) ? name : '';
		setImportStep( 'choose' );
		showChooser( true );
	}

	function fillImportPick() {
		const pick = $( 'siteferry-import-pick' );
		const usable = ( localBackups || [] ).filter( ( b ) => ! b.error );
		pick.replaceChildren( pick.options[ 0 ], ...usable.map( ( b ) => el( 'option', { value: b.name, text: b.name + ' (' + bytes( b.size ) + ', ' + date( b.created || b.modified ) + ')' } ) ) );
		pick.value = selectedBackup && usable.some( ( b ) => b.name === selectedBackup ) ? selectedBackup : '';
		pick.disabled = usable.length === 0;
	}

	async function startImport() {
		const view = progressView( 'siteferry-import-progress', [ 'Backup', 'This site' ] );
		$( 'siteferry-import-start' ).disabled = true;
		setImportStep( 'check' );
		view.start( 'Checking the backup...' );
		try {
			const started = await api( 'POST', 'imports', {
				json: {
					backup: selectedBackup,
					replace_url: $( 'siteferry-replace-url' ).value.trim(),
					include_mu_plugins: $( 'siteferry-include-mu' ).checked,
					include_drop_ins: $( 'siteferry-include-dropins' ).checked,
				},
			} );
			importJob = { id: started.job.id, token: started.token };
			remember( started.job, started.token );
			await continueImport( view );
		} catch ( error ) {
			view.finish( false, 'The import did not start', [ el( 'p', { text: error.message } ) ] );
			setImportStep( 'choose' );
			$( 'siteferry-import-start' ).disabled = false;
		}
	}

	async function continueImport( view ) {
		const job = await driveJob( importJob.id, importJob.token, progressHandler( view ) );
		if ( job.status === 'waiting' ) {
			showInspection( job.result.inspection );
			view.finish( true, 'Backup checked', [ el( 'p', { text: 'Nothing on this site has changed yet. Look over the details below, then confirm.' } ) ], false );
			return;
		}
		store.remove( JOB_KEY );
		$( 'siteferry-inspection' ).hidden = true;
		if ( job.status === 'done' ) {
			const r = job.result.imported;
			setImportStep( 'done' );
			view.finish( true, 'Import finished', [
				el( 'p', { text: 'Imported ' + r.from + ' into ' + r.to + ': ' + plural( r.tables, 'table', 'tables' ) + ', ' + plural( r.files, 'file', 'files' ) + '.' } ),
				el( 'p', { text: 'The users of the imported site are now active, so log in again with one of them.' } ),
				el( 'p', { class: 'sf-actions' }, [ el( 'a', { class: 'button button-primary', href: cfg.loginUrl, text: 'Log in again' } ) ] ),
				...warningList( job ),
			] );
			return;
		}
		const rolledBack = ! ( job.result && job.result.rollback_errors );
		setImportStep( 'choose' );
		showChooser( true );
		view.finish( false, 'Import failed', [
			el( 'p', { text: job.error } ),
			el( 'p', { text: rolledBack ? 'This site was left as it was before the import.' : 'Restoring the previous site did not fully succeed; see the Log tab.' } ),
			...warningList( job ),
		] );
	}

	function showInspection( info ) {
		const end = ( label, value ) => el( 'div', { class: 'sf-crossing-end' }, [ el( 'small', { text: label } ), el( 'code', { text: value } ) ] );
		$( 'siteferry-crossing' ).replaceChildren( end( 'From', info.from ), el( 'span', { class: 'sf-crossing-line', 'aria-hidden': 'true' } ), end( 'Into', info.to ) );
		const rows = [
			[ 'Backup', info.backup + ', made ' + date( info.created ) ],
			[ 'Made with', 'WordPress ' + info.wordpress + ', PHP ' + info.php + ', MySQL ' + info.mysql ],
			[ 'Contents', plural( info.tables, 'table', 'tables' ) + ' (prefix ' + info.prefix + '), ' + plural( info.files, 'file', 'files' ) + ', ' + bytes( info.bytes ) ],
			[ 'Address changes', plural( info.replacements, 'search/replace pair', 'search/replace pairs' ) ],
		];
		$( 'siteferry-inspection-rows' ).replaceChildren( ...rows.map( ( [ k, v ] ) => el( 'tr', {}, [ el( 'th', { scope: 'row', text: k } ), el( 'td', { text: v } ) ] ) ) );
		$( 'siteferry-inspection-warnings' ).replaceChildren( ...( info.warnings || [] ).map( ( w ) => el( 'li', { text: w } ) ) );
		$( 'siteferry-confirm-check' ).checked = false;
		$( 'siteferry-confirm' ).disabled = true;
		showChooser( false );
		$( 'siteferry-inspection' ).hidden = false;
		setImportStep( 'check' );
	}

	async function confirmImport() {
		const view = progressView( 'siteferry-import-progress', [ 'Backup', 'This site' ] );
		$( 'siteferry-confirm' ).disabled = true;
		$( 'siteferry-inspection' ).hidden = true;
		setImportStep( 'import' );
		view.start( 'Importing...' );
		try {
			await api( 'POST', 'jobs/' + importJob.id + '/confirm', { token: importJob.token } );
			await continueImport( view );
		} catch ( error ) {
			view.finish( false, 'Import stopped', [ el( 'p', { text: error.message } ) ] );
		}
	}

	async function cancelJob( id, token ) {
		try {
			await api( 'POST', 'jobs/' + id + '/cancel', { token } );
		} catch ( error ) {
			notice( 'Could not cancel: ' + error.message, 'error' );
			return false;
		}
		store.remove( JOB_KEY );
		return true;
	}

	// ------------------------------------------------------------ backups and log

	function loadBackups() {
		loadLocalBackups();
		loadRemote();
	}

	async function loadLocalBackups() {
		const list = $( 'siteferry-backups' );
		try {
			localBackups = await api( 'GET', 'backups' );
		} catch ( error ) {
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: error.message } ) );
			return;
		}
		updateFacts();
		fillImportPick();
		const total = localBackups.reduce( ( sum, b ) => sum + ( b.size || 0 ), 0 );
		$( 'siteferry-backups-summary' ).textContent = localBackups.length ? plural( localBackups.length, 'backup', 'backups' ) + ', ' + bytes( total ) : '';
		if ( ! localBackups.length ) {
			list.replaceChildren(
				el( 'li', { class: 'sf-empty' }, [ el( 'p', { text: 'No backups on this server yet.' } ), button( 'Create a backup', () => showTab( 'export' ), 'button button-primary' ) ] )
			);
			return;
		}
		const targets = destinations.filter( ( d ) => d.ready );
		list.replaceChildren( ...localBackups.map( ( b ) => backupRow( b, targets ) ) );
	}

	function backupRow( b, targets ) {
		const meta = [ el( 'span', { text: bytes( b.size ) } ), el( 'span', { text: date( b.created || b.modified ) } ) ];
		if ( b.error ) {
			meta.push( el( 'span', { class: 'is-bad', text: b.error } ) );
		} else {
			meta.push( el( 'span', { text: 'from ' + b.source } ), el( 'span', { text: 'WordPress ' + b.wordpress } ) );
		}
		const actions = [ el( 'a', { class: 'button button-small', href: downloadUrl( b.name ), text: 'Download' } ) ];
		if ( ! b.error ) {
			actions.push(
				button( 'Import', () => {
					selectBackup( b.name );
					showTab( 'import' );
				} ),
				...targets.map( ( d ) => button( 'Send to ' + d.short, () => startTransfer( 'storage/' + d.id + '/send', { backup: b.name }, 'Sending ' + b.name + ' to ' + d.label + '...', [ 'This server', d.short ] ) ) )
			);
		}
		actions.push( button( 'Delete', () => deleteBackup( b.name ), 'button-link button-link-delete' ) );
		return el( 'li', { class: 'sf-row' }, [ el( 'div', {}, [ el( 'span', { class: 'sf-row-name', text: b.name } ), el( 'div', { class: 'sf-row-meta' }, meta ) ] ), el( 'div', { class: 'sf-row-actions' }, actions ) ] );
	}

	async function deleteBackup( name ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( 'Delete the backup ' + name + '? This cannot be undone.' ) ) {
			return;
		}
		try {
			await api( 'DELETE', 'backups/' + encodeURIComponent( name ) );
			notice( 'Deleted ' + name + '.', 'success' );
		} catch ( error ) {
			notice( 'Could not delete: ' + error.message, 'error' );
		}
		loadLocalBackups();
	}

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
			list.replaceChildren( el( 'li', { class: 'sf-empty', text: 'Nothing has run yet. Backups, imports and transfers show up here, each with its log.' } ) );
			return;
		}
		list.replaceChildren(
			...jobs.map( ( job ) => {
				const cancelled = job.status === 'failed' && /^cancel/i.test( job.error || '' );
				const [ label, tone ] = cancelled ? [ 'Cancelled', '' ] : JOB_STATES[ job.status ] || [ job.status, '' ];
				const row = el( 'li', { class: 'sf-row sf-job' } );
				const view = button( 'View log', () => toggleLog( row, job.id, view ) );
				row.append(
					el( 'span', { class: 'sf-job-when', text: date( job.created ) } ),
					el( 'div', { class: 'sf-job-what' }, [
						el( 'span', { class: 'sf-job-type', text: JOB_TYPES[ job.type ] || job.type } ),
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
		if ( name === 'log' ) {
			loadJobs();
		}
		if ( window.location.hash !== '#' + name ) {
			window.history.replaceState( null, '', '#' + name );
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
		const saved = store.get( JOB_KEY );
		const token = saved && saved.id === active.id ? saved.token : null;
		const what = JOB_NAMES[ active.type ] || 'A job';
		$( 'siteferry-active-text' ).textContent =
			what + ' started ' + date( active.created ) + ' is not finished (' + ( active.progress.message || active.status ) + ').' + ( token ? '' : ' This browser did not start it, so it can only be cancelled here.' );
		$( 'siteferry-active' ).hidden = false;
		$( 'siteferry-active-resume' ).hidden = ! token;
		$( 'siteferry-active-resume' ).onclick = async () => {
			$( 'siteferry-active' ).hidden = true;
			if ( active.type === 'send' || active.type === 'fetch' ) {
				showTab( 'backups' );
				await runTransfer( active.id, token, 'Resuming...' );
			} else if ( active.type === 'export' ) {
				showTab( 'export' );
				const view = progressView( 'siteferry-export-progress' );
				view.start( 'Resuming the backup...' );
				await finishExport( active.id, token, view );
			} else {
				showTab( 'import' );
				importJob = { id: active.id, token };
				const view = progressView( 'siteferry-import-progress', [ 'Backup', 'This site' ] );
				view.start( 'Resuming the import...' );
				await continueImport( view );
			}
		};
		$( 'siteferry-active-cancel' ).onclick = async () => {
			if ( await cancelJob( active.id, token ) ) {
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
		$( 'siteferry-send-options' ).addEventListener( 'change', syncDeleteAfter );
		$( 'siteferry-tables' ).addEventListener( 'change', countLeftOutTables );
		$( 'siteferry-file' ).addEventListener( 'change', onFileChosen );
		setUpDropZone();
		$( 'siteferry-import-pick' ).addEventListener( 'change', ( event ) => {
			if ( event.target.value ) {
				selectBackup( event.target.value );
			}
		} );
		$( 'siteferry-import-start' ).addEventListener( 'click', startImport );
		$( 'siteferry-confirm-check' ).addEventListener( 'change', ( event ) => {
			$( 'siteferry-confirm' ).disabled = ! event.target.checked;
		} );
		$( 'siteferry-confirm' ).addEventListener( 'click', confirmImport );
		$( 'siteferry-import-cancel' ).addEventListener( 'click', async () => {
			if ( importJob && ( await cancelJob( importJob.id, importJob.token ) ) ) {
				importJob = null;
				setImportStep( 'choose' );
				showChooser( true );
				const view = progressView( 'siteferry-import-progress' );
				view.start( 'Import cancelled' );
				view.finish( true, 'Import cancelled', [ el( 'p', { text: 'Nothing on this site was changed.' } ) ], false );
			}
		} );
		$( 'siteferry-jobs-refresh' ).addEventListener( 'click', loadJobs );

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

		const tabs = [ 'export', 'import', 'backups', 'storage', 'log' ];
		const hash = window.location.hash.replace( '#', '' );
		loadDestinations()
			.then( () => finishOauth() )
			.then( ( fromOauth ) => {
				const first = fromOauth ? 'storage' : tabs.indexOf( hash ) === -1 ? 'export' : hash;
				showTab( first );
				if ( first !== 'backups' ) {
					loadLocalBackups(); // For the header line, the tab count and the import picker.
				}
			} );
		loadTables();
		checkActiveJob();
	}

	// For tests and other scripts: the upload and hashing functions.
	window.SiteFerry = { uploadFile, sha256, sha256Fallback, selectBackup };

	document.addEventListener( 'DOMContentLoaded', init );
} )();
