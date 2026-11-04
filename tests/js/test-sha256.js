// SPDX-License-Identifier: GPL-2.0-or-later
// Compares the SHA-256 fallback in assets/admin.js (used where crypto.subtle is missing, such as
// plain http on another host) with Node's own crypto.
//
// Run: node tests/js/test-sha256.js
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );
const crypto = require( 'crypto' );

const sandbox = {
	window: {},
	document: { addEventListener() {}, getElementById() { return null; } },
	TextEncoder,
};
const source = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'admin.js' ), 'utf8' );
vm.runInNewContext( source, sandbox, { filename: 'admin.js' } );
const fallback = sandbox.window.SiteFerry.sha256Fallback;

console.log( 'SHA-256 fallback tests' );
let failed = 0;
const abc = fallback( new TextEncoder().encode( 'abc' ) );
if ( abc !== 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad' ) {
	failed++;
	console.log( '  FAIL  FIPS 180-4 "abc" vector: ' + abc );
}
// Lengths around the 55/56/64-byte padding edges, and one larger than an upload chunk.
for ( const n of [ 0, 1, 3, 55, 56, 57, 63, 64, 65, 119, 120, 1000, 65536, 5 * 1048576 + 7 ] ) {
	const data = crypto.randomBytes( n );
	const expected = crypto.createHash( 'sha256' ).update( data ).digest( 'hex' );
	if ( fallback( new Uint8Array( data ) ) !== expected ) {
		failed++;
		console.log( '  FAIL  length ' + n );
	}
}
console.log( failed ? 'js/test-sha256: ' + failed + ' failed' : 'js/test-sha256: all passed on Node ' + process.version );
process.exit( failed ? 1 : 0 );
