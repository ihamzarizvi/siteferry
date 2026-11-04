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
	let selectedBackup = null;
	let importJob = null;

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
		( children || [] ).forEach( ( child ) => node.appendChild( child ) );
		return node;
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
		return isNaN( d ) ? '' : d.toLocaleString();
	}

	function notice( message, type ) {
		const box = $( 'siteferry-notice' );
		box.className = 'notice notice-' + ( type || 'info' ) + ' siteferry-notice';
		box.replaceChildren( el( 'p', { text: message } ) );
		box.hidden = false;
	}

	/** Progress box: a <progress> bar, a message and warnings. */
	function progressView( id ) {
		const box = $( id );
		return {
			start( message ) {
				box.className = 'siteferry-progress';
				box.replaceChildren( el( 'progress', {} ), el( 'p', { text: message } ) );
				box.hidden = false;
			},
			update( done, total, message ) {
				const bar = box.querySelector( 'progress' );
				if ( total > 0 ) {
					bar.max = total;
					bar.value = Math.min( done, total );
				} else {
					bar.removeAttribute( 'value' );
				}
				box.querySelector( 'p' ).textContent = message;
			},
			finish( ok, nodes ) {
				box.className = 'siteferry-progress ' + ( ok ? 'is-done' : 'is-failed' );
				box.replaceChildren( ...nodes );
			},
		};
	}

	function warningList( job ) {
		if ( ! job.warnings || ! job.warnings.count ) {
			return [];
		}
		const list = el( 'ul', { class: 'siteferry-warnings' } );
		job.warnings.first.forEach( ( text ) => list.appendChild( el( 'li', { text } ) ) );
		if ( job.warnings.count > job.warnings.first.length ) {
			list.appendChild( el( 'li', { text: '... and ' + ( job.warnings.count - job.warnings.first.length ) + ' more (see the Log tab).' } ) );
		}
		return [ el( 'p', { text: job.warnings.count + ' warning(s):' } ), list ];
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
		return ( job, message ) => {
			if ( ! job ) {
				view.update( 0, 0, message );
				return;
			}
			const p = job.progress || {};
			const step = job.step ? ' (step ' + Math.min( job.step.index + 1, job.step.count ) + ' of ' + job.step.count + ')' : '';
			view.update( p.done || 0, p.total || 0, ( p.message || 'Working...' ) + step );
		};
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
						document.createTextNode( ' ' + t.name + ' (' + t.rows + ' rows, ' + bytes( t.data ) + ')' ),
					] )
				)
			);
		} catch ( error ) {
			box.textContent = error.message;
		}
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
		const view = progressView( 'siteferry-export-progress' );
		const button = form.querySelector( 'button[type=submit]' );
		button.disabled = true;
		view.start( 'Starting the export...' );
		try {
			const started = await api( 'POST', 'exports', { json: options } );
			remember( started.job, started.token );
			await finishExport( started.job.id, started.token, view );
		} catch ( error ) {
			view.finish( false, [ el( 'p', { text: 'Export failed: ' + error.message } ) ] );
		} finally {
			button.disabled = false;
		}
	}

	async function finishExport( id, token, view ) {
		const job = await driveJob( id, token, progressHandler( view ) );
		store.remove( JOB_KEY );
		if ( job.status !== 'done' ) {
			view.finish( false, [ el( 'p', { text: 'Export failed: ' + job.error } ), ...warningList( job ) ] );
			return;
		}
		const r = job.result;
		view.finish( true, [
			el( 'p', { text: 'Backup ready: ' + r.name + ' (' + bytes( r.size ) + ', ' + r.files + ' files).' } ),
			el( 'p', {}, [ el( 'a', { class: 'button', href: downloadUrl( r.name ), text: 'Download' } ) ] ),
			...warningList( job ),
		] );
		loadBackups();
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

	async function onFileChosen( event ) {
		const file = event.target.files[ 0 ];
		if ( ! file ) {
			return;
		}
		const view = progressView( 'siteferry-upload-progress' );
		view.start( 'Uploading ' + file.name + '...' );
		try {
			const name = await uploadFile( file, ( done, total ) => view.update( done, total, 'Uploaded ' + bytes( done ) + ' of ' + bytes( total ) ) );
			view.finish( true, [ el( 'p', { text: 'Uploaded and checked: ' + name } ) ] );
			selectBackup( name );
			loadBackups();
		} catch ( error ) {
			view.finish( false, [ el( 'p', { text: 'Upload failed: ' + error.message + ' Choose the same file again to continue where it stopped.' } ) ] );
		}
	}

	// ------------------------------------------------------------ import

	function selectBackup( name ) {
		selectedBackup = name;
		$( 'siteferry-import-selected' ).textContent = name;
		$( 'siteferry-import-start' ).disabled = false;
		$( 'siteferry-inspection' ).hidden = true;
	}

	async function startImport() {
		const view = progressView( 'siteferry-import-progress' );
		$( 'siteferry-import-start' ).disabled = true;
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
			view.finish( false, [ el( 'p', { text: 'Import stopped: ' + error.message } ) ] );
			$( 'siteferry-import-start' ).disabled = false;
		}
	}

	async function continueImport( view ) {
		const job = await driveJob( importJob.id, importJob.token, progressHandler( view ) );
		if ( job.status === 'waiting' ) {
			showInspection( job.result.inspection );
			view.finish( true, [ el( 'p', { text: 'The backup was checked. Nothing on this site has changed yet.' } ) ] );
			return;
		}
		store.remove( JOB_KEY );
		$( 'siteferry-inspection' ).hidden = true;
		$( 'siteferry-import-start' ).disabled = false;
		if ( job.status === 'done' ) {
			const r = job.result.imported;
			view.finish( true, [
				el( 'p', { text: 'Imported ' + r.from + ' into ' + r.to + ': ' + r.tables + ' tables, ' + r.files + ' files.' } ),
				el( 'p', {}, [
					document.createTextNode( 'The users of the imported site are now active. ' ),
					el( 'a', { href: cfg.loginUrl, text: 'Log in again' } ),
				] ),
				...warningList( job ),
			] );
			return;
		}
		const rolledBack = ! ( job.result && job.result.rollback_errors );
		view.finish( false, [
			el( 'p', { text: 'Import failed: ' + job.error } ),
			el( 'p', { text: rolledBack ? 'This site was left as it was before the import.' : 'Restoring the previous site did not fully succeed; see the Log tab.' } ),
			...warningList( job ),
		] );
	}

	function showInspection( info ) {
		const rows = [
			[ 'Backup', info.backup + ' (made ' + date( info.created ) + ')' ],
			[ 'From site', info.from + ' (WordPress ' + info.wordpress + ', PHP ' + info.php + ', MySQL ' + info.mysql + ')' ],
			[ 'Into site', info.to ],
			[ 'Contents', info.tables + ' tables (prefix ' + info.prefix + '), ' + info.files + ' files, ' + bytes( info.bytes ) ],
			[ 'Address changes', info.replacements + ' search/replace pairs' ],
		];
		$( 'siteferry-inspection-rows' ).replaceChildren( ...rows.map( ( [ k, v ] ) => el( 'tr', {}, [ el( 'th', { text: k } ), el( 'td', { text: v } ) ] ) ) );
		$( 'siteferry-inspection-warnings' ).replaceChildren( ...( info.warnings || [] ).map( ( w ) => el( 'li', { text: w } ) ) );
		$( 'siteferry-confirm-check' ).checked = false;
		$( 'siteferry-confirm' ).disabled = true;
		$( 'siteferry-inspection' ).hidden = false;
	}

	async function confirmImport() {
		const view = progressView( 'siteferry-import-progress' );
		$( 'siteferry-confirm' ).disabled = true;
		$( 'siteferry-inspection' ).hidden = true;
		view.start( 'Importing...' );
		try {
			await api( 'POST', 'jobs/' + importJob.id + '/confirm', { token: importJob.token } );
			await continueImport( view );
		} catch ( error ) {
			view.finish( false, [ el( 'p', { text: 'Import stopped: ' + error.message } ) ] );
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

	async function loadBackups() {
		const body = $( 'siteferry-backups' );
		try {
			const backups = await api( 'GET', 'backups' );
			if ( ! backups.length ) {
				body.replaceChildren( el( 'tr', {}, [ el( 'td', { colspan: '5', text: 'No backups yet.' } ) ] ) );
				return;
			}
			body.replaceChildren(
				...backups.map( ( b ) =>
					el( 'tr', {}, [
						el( 'td', { text: b.name } ),
						el( 'td', { text: bytes( b.size ) } ),
						el( 'td', { text: date( b.created || b.modified ) } ),
						el( 'td', { text: b.error ? b.error : b.source + ' (WordPress ' + b.wordpress + ')' } ),
						el( 'td', {}, [
							el( 'a', { class: 'button button-small', href: downloadUrl( b.name ), text: 'Download' } ),
							document.createTextNode( ' ' ),
							el( 'button', { type: 'button', class: 'button button-small', text: 'Import', onclick: () => { selectBackup( b.name ); showTab( 'import' ); } } ),
							document.createTextNode( ' ' ),
							el( 'button', { type: 'button', class: 'button-link button-link-delete', text: 'Delete', onclick: () => deleteBackup( b.name ) } ),
						] ),
					] )
				)
			);
		} catch ( error ) {
			body.replaceChildren( el( 'tr', {}, [ el( 'td', { colspan: '5', text: error.message } ) ] ) );
		}
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
		loadBackups();
	}

	async function loadJobs() {
		const body = $( 'siteferry-jobs' );
		try {
			const jobs = await api( 'GET', 'jobs' );
			body.replaceChildren(
				...jobs.map( ( job ) =>
					el( 'tr', {}, [
						el( 'td', { text: date( job.created ) } ),
						el( 'td', { text: job.type } ),
						el( 'td', { text: job.status } ),
						el( 'td', { text: job.error || ( job.progress && job.progress.message ) || '' } ),
						el( 'td', {}, [ el( 'button', { type: 'button', class: 'button button-small', text: 'View log', onclick: () => showLog( job.id ) } ) ] ),
					] )
				)
			);
		} catch ( error ) {
			body.replaceChildren( el( 'tr', {}, [ el( 'td', { colspan: '5', text: error.message } ) ] ) );
		}
	}

	async function showLog( id ) {
		const view = $( 'siteferry-log-view' );
		view.hidden = false;
		view.textContent = 'Loading...';
		try {
			const result = await api( 'GET', 'jobs/' + id + '/log' );
			view.textContent = ( result.truncated ? '[earlier lines omitted]\n' : '' ) + result.log;
		} catch ( error ) {
			view.textContent = error.message;
		}
	}

	// ------------------------------------------------------------ page

	function showTab( name ) {
		document.querySelectorAll( '.siteferry .nav-tab' ).forEach( ( tab ) => tab.classList.toggle( 'nav-tab-active', tab.dataset.tab === name ) );
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
		$( 'siteferry-active-text' ).textContent = 'An ' + active.type + ' started ' + date( active.created ) + ' is not finished (' + ( active.progress.message || active.status ) + ').';
		$( 'siteferry-active' ).hidden = false;
		$( 'siteferry-active-resume' ).hidden = ! token;
		$( 'siteferry-active-resume' ).onclick = async () => {
			$( 'siteferry-active' ).hidden = true;
			if ( active.type === 'export' ) {
				showTab( 'export' );
				const view = progressView( 'siteferry-export-progress' );
				view.start( 'Resuming the export...' );
				await finishExport( active.id, token, view );
			} else {
				showTab( 'import' );
				importJob = { id: active.id, token };
				const view = progressView( 'siteferry-import-progress' );
				view.start( 'Resuming the import...' );
				await continueImport( view );
			}
		};
		$( 'siteferry-active-cancel' ).onclick = async () => {
			if ( await cancelJob( active.id, token ) ) {
				$( 'siteferry-active' ).hidden = true;
				notice( 'The ' + active.type + ' was cancelled and its work undone.', 'success' );
			}
		};
	}

	function init() {
		if ( ! $( 'siteferry' ) ) {
			return;
		}
		document.querySelectorAll( '.siteferry .nav-tab' ).forEach( ( tab ) =>
			tab.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				showTab( tab.dataset.tab );
			} )
		);
		$( 'siteferry-export-form' ).addEventListener( 'submit', startExport );
		$( 'siteferry-file' ).addEventListener( 'change', onFileChosen );
		$( 'siteferry-import-start' ).addEventListener( 'click', startImport );
		$( 'siteferry-confirm-check' ).addEventListener( 'change', ( event ) => {
			$( 'siteferry-confirm' ).disabled = ! event.target.checked;
		} );
		$( 'siteferry-confirm' ).addEventListener( 'click', confirmImport );
		$( 'siteferry-import-cancel' ).addEventListener( 'click', async () => {
			if ( importJob && ( await cancelJob( importJob.id, importJob.token ) ) ) {
				$( 'siteferry-inspection' ).hidden = true;
				$( 'siteferry-import-start' ).disabled = false;
				progressView( 'siteferry-import-progress' ).finish( true, [ el( 'p', { text: 'Import cancelled. Nothing was changed.' } ) ] );
			}
		} );

		const tabs = [ 'export', 'import', 'backups', 'storage', 'log' ];
		const hash = window.location.hash.replace( '#', '' );
		showTab( tabs.indexOf( hash ) === -1 ? 'export' : hash );
		loadTables();
		checkActiveJob();
	}

	// For tests and other scripts: the upload and hashing functions.
	window.SiteFerry = { uploadFile, sha256, sha256Fallback, selectBackup };

	document.addEventListener( 'DOMContentLoaded', init );
} )();
