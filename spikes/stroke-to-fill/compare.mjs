// Spike for #12: render upstream Lucide and the outlined icons, diff the coverage.
//
// Usage: node compare.mjs [--from-core] [--tol 16] [--max 2] [--ss 32]
//   --from-core  compare out/core-icons.json (markup returned by wp_get_icon) instead of
//                out/icons.json (builder output).
// Writes out/compare.json and report/index.html.

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { Resvg } from '@resvg/resvg-js';
import { PNG } from 'pngjs';

const require = createRequire( import.meta.url );
const args = process.argv.slice( 2 );
const opt = ( name, d ) => {
	const i = args.indexOf( name );
	return i >= 0 ? Number( args[ i + 1 ] ) : d;
};

const FROM_CORE = args.includes( '--from-core' );
// Pass rule at 24px: at most MAX pixels whose alpha differs by more than TOL (0 to 255).
const TOL = opt( '--tol', 16 );
const MAX = opt( '--max', 2 );
const SIZES = [ 24, 48, 96 ];

const outlined = JSON.parse(
	readFileSync( new URL( FROM_CORE ? './out/core-icons.json' : './out/icons.json', import.meta.url ), 'utf8' )
);
const upstream = ( name ) => readFileSync( require.resolve( `lucide-static/icons/${ name }.svg` ), 'utf8' );

// wp_get_icon() output carries width and height, which would fight fitTo. It also has
// viewBox lowercased by wp_kses; an HTML parser restores the case for inline SVG, but
// resvg parses XML, so restore it here the way a browser would.
const clean = ( svg ) => svg.replace( /\s(width|height)="[^"]*"/g, '' ).replace( ' viewbox=', ' viewBox=' );

// Coverage at `size`, measured by rendering at SS times the size and box filtering down.
// A direct render is not a fair reference: resvg, like Skia, flattens stroke curves with a
// tolerance tied to the output scale, so at 24px the upstream strokes are themselves
// approximated coarsely. Supersampling measures the geometry, not one renderer's shortcut.
// The canvas is capped at 768px because resvg's native buffers are not freed promptly.
const SS = opt( '--ss', 32 );
function alpha( svg, size ) {
	const ss = Math.max( 1, Math.min( SS, Math.floor( 768 / size ) ) );
	const big = size * ss;
	const px = new Resvg( svg, {
		fitTo: { mode: 'width', value: big },
		background: 'rgba(0,0,0,0)',
		font: { loadSystemFonts: false },
	} ).render().pixels;
	const a = new Uint8Array( size * size );
	for ( let y = 0; y < size; y++ ) {
		for ( let x = 0; x < size; x++ ) {
			let sum = 0;
			for ( let dy = 0; dy < ss; dy++ ) {
				const row = ( y * ss + dy ) * big + x * ss;
				for ( let dx = 0; dx < ss; dx++ ) {
					sum += px[ ( row + dx ) * 4 + 3 ];
				}
			}
			a[ y * size + x ] = Math.round( sum / ( ss * ss ) );
		}
	}
	return a;
}

function diff( a, b ) {
	let max = 0, over = 0, sum = 0;
	for ( let i = 0; i < a.length; i++ ) {
		const d = Math.abs( a[ i ] - b[ i ] );
		sum += d;
		if ( d > max ) {
			max = d;
		}
		if ( d > TOL ) {
			over++;
		}
	}
	return { max, over, mean: +( sum / a.length / 255 ).toFixed( 5 ) };
}

// Red where only upstream paints, blue where only the outline paints, grey where both.
function diffPng( a, b, size ) {
	const png = new PNG( { width: size, height: size } );
	for ( let i = 0; i < a.length; i++ ) {
		const both = Math.min( a[ i ], b[ i ] );
		const onlyA = a[ i ] - both, onlyB = b[ i ] - both;
		png.data[ i * 4 ] = 255 - Math.round( both * 0.6 ) - onlyB;
		png.data[ i * 4 + 1 ] = 255 - Math.round( both * 0.6 ) - onlyA - onlyB;
		png.data[ i * 4 + 2 ] = 255 - Math.round( both * 0.6 ) - onlyA;
		png.data[ i * 4 + 3 ] = 255;
	}
	return 'data:image/png;base64,' + PNG.sync.write( png ).toString( 'base64' );
}

// Diff in child processes of CHUNK icons each: resvg's native buffers are only reliably
// released when the process exits.
const CHUNK = 300;
const names = Object.keys( outlined ).sort();
const chunkArg = args.indexOf( '--chunk' );

function measure( name ) {
	const up = upstream( name ), ours = clean( outlined[ name ] );
	const r = { name };
	for ( const s of SIZES ) {
		r[ s ] = diff( alpha( up, s ), alpha( ours, s ) );
	}
	r.pass = r[ 24 ].over <= MAX;
	return r;
}

if ( chunkArg >= 0 ) {
	const [ from, to ] = args[ chunkArg + 1 ].split( ':' ).map( Number );
	// Synchronous write: process.exit() would truncate an async write to a pipe.
	writeFileSync( 1, JSON.stringify( names.slice( from, to ).map( measure ) ) );
	process.exit( 0 );
}

const results = [];
for ( let from = 0; from < names.length; from += CHUNK ) {
	const child = spawnSync(
		process.execPath,
		[ fileURLToPath( import.meta.url ), ...args, '--chunk', `${ from }:${ from + CHUNK }` ],
		{ encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
	);
	if ( child.status !== 0 ) {
		throw new Error( `chunk ${ from } failed (${ child.status }): ${ child.stderr }` );
	}
	results.push( ...JSON.parse( child.stdout ) );
}

const failing = results.filter( ( r ) => ! r.pass );
const hist = {};
for ( const r of results ) {
	const k = r[ 24 ].max;
	const bucket = k === 0 ? '0' : k <= 4 ? '1-4' : k <= 16 ? '5-16' : k <= 64 ? '17-64' : '65+';
	hist[ bucket ] = ( hist[ bucket ] || 0 ) + 1;
}
const summary = {
	source: FROM_CORE ? 'wp_get_icon' : 'builder',
	rule: `24px: at most ${ MAX } px with alpha delta > ${ TOL }/255`,
	total: results.length,
	pass: results.length - failing.length,
	fail: failing.length,
	max24Histogram: hist,
	worstMean96: Math.max( ...results.map( ( r ) => r[ 96 ].mean ) ),
	failing: failing.map( ( r ) => ( { name: r.name, over24: r[ 24 ].over, max24: r[ 24 ].max } ) ),
};
writeFileSync( new URL( './out/compare.json', import.meta.url ), JSON.stringify( { summary, results }, null, '\t' ) );

// Report: worst icons first. Inline SVGs are drawn by the browser, a second renderer on
// top of resvg, so eyeballing the report also covers Chrome, Safari or Firefox.
const worst = [ ...results ].sort( ( x, y ) => y[ 24 ].over - x[ 24 ].over || y[ 24 ].max - x[ 24 ].max ).slice( 0, 60 );
const row = ( r ) => {
	const up = upstream( r.name ).replace( /<!--.*?-->/s, '' ), ours = clean( outlined[ r.name ] );
	return `<tr class="${ r.pass ? 'ok' : 'bad' }">
<td><code>${ r.name }</code><br><small>24px: ${ r[ 24 ].over } px over, max ${ r[ 24 ].max }<br>96px: ${ r[ 96 ].over } px over, mean ${ r[ 96 ].mean }</small></td>
<td class="i24">${ up }</td><td class="i24">${ ours }</td>
<td><img class="px" src="${ diffPng( alpha( upstream( r.name ), 24 ), alpha( ours, 24 ), 24 ) }"></td>
<td class="i96">${ up }</td><td class="i96">${ ours }</td>
<td><img src="${ diffPng( alpha( upstream( r.name ), 96 ), alpha( ours, 96 ), 96 ) }"></td></tr>`;
};
mkdirSync( new URL( './report/', import.meta.url ), { recursive: true } );
writeFileSync(
	new URL( './report/index.html', import.meta.url ),
	`<!doctype html><meta charset="utf-8"><title>Stroke to fill report</title>
<style>
body{font:14px system-ui;margin:24px;color:#111;background:#fff}
table{border-collapse:collapse}td{border-bottom:1px solid #ddd;padding:6px 10px;vertical-align:middle}
.i24 svg{width:24px;height:24px}.i96 svg{width:96px;height:96px}
img.px{width:96px;height:96px;image-rendering:pixelated}
tr.bad td:first-child{border-left:4px solid #c00}pre{background:#f4f4f4;padding:12px}
</style>
<h1>Stroke to fill: Lucide (${ summary.source })</h1>
<pre>${ JSON.stringify( { ...summary, failing: summary.failing.length }, null, 2 ) }</pre>
<p>Columns: upstream 24px, outlined 24px, diff at 24px (scaled up), upstream 96px, outlined 96px, diff at 96px.
Diff colours: red is painted only upstream, blue only in the outline, grey in both.</p>
<table>${ worst.map( row ).join( '' ) }</table>`
);

console.log( JSON.stringify( { ...summary, failing: summary.failing.slice( 0, 20 ) }, null, 2 ) );
