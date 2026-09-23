// Spike for #12: outline Lucide's stroked icons into a single filled path that survives
// core's SVG allowlist (svg, path, polygon; no stroke, no clip-rule).
//
// Usage: node outline.mjs [--only name,name] [fixtures]
// Writes out/icons.json ({ name: svg }) and out/outline-stats.json.

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import CanvasKitInit from 'canvaskit-wasm';

const require = createRequire( import.meta.url );
const CK = await CanvasKitInit();

// Lucide draws everything with these root attributes. A real source config would read
// them per set.
// precision is Skia's resScale: icons are drawn in a 24 unit box, so the default of 1
// flattens curves far too coarsely. 16 matched a 32x supersampled reference best (see #12).
const STROKE = {
	width: 2,
	cap: CK.StrokeCap.Round,
	join: CK.StrokeJoin.Round,
	miter_limit: 4,
	precision: Number( process.env.II_RES_SCALE ?? 16 ),
};
const PRECISION = Number( process.env.II_DECIMALS ?? 2 );

const num = ( v, d = 0 ) => ( v === undefined ? d : parseFloat( v ) );

// Build the geometry of one element as a Skia path. Returns null for unknown tags.
function elementPath( tag, a ) {
	const b = new CK.PathBuilder();
	switch ( tag ) {
		case 'path': {
			const p = CK.Path.MakeFromSVGString( a.d );
			b.addPath( p );
			p.delete();
			break;
		}
		case 'circle':
			b.addCircle( num( a.cx ), num( a.cy ), num( a.r ) );
			break;
		case 'ellipse': {
			const cx = num( a.cx ), cy = num( a.cy ), rx = num( a.rx ), ry = num( a.ry );
			b.addOval( CK.LTRBRect( cx - rx, cy - ry, cx + rx, cy + ry ) );
			break;
		}
		case 'rect': {
			const x = num( a.x ), y = num( a.y ), w = num( a.width ), h = num( a.height );
			// SVG: a missing rx or ry takes the other's value, both clamp to half the side.
			let rx = a.rx ?? a.ry, ry = a.ry ?? a.rx;
			rx = Math.min( num( rx ), w / 2 );
			ry = Math.min( num( ry ), h / 2 );
			const r = CK.LTRBRect( x, y, x + w, y + h );
			if ( rx > 0 && ry > 0 ) {
				b.addRRect( CK.RRectXY( r, rx, ry ) );
			} else {
				b.addRect( r );
			}
			break;
		}
		case 'line':
			b.moveTo( num( a.x1 ), num( a.y1 ) );
			b.lineTo( num( a.x2 ), num( a.y2 ) );
			break;
		case 'polyline':
		case 'polygon': {
			const pts = a.points.trim().split( /[\s,]+/ ).map( Number );
			b.addPolygon( pts, tag === 'polygon' );
			break;
		}
		default:
			b.delete();
			return null;
	}
	return b.detachAndDelete();
}

// Union two paths, consuming both. Also works as "simplify" when one is empty.
function union( a, b ) {
	const r = CK.Path.MakeFromOp( a, b, CK.PathOp.Union );
	a.delete();
	b.delete();
	if ( ! r ) {
		throw new Error( 'PathOp union failed' );
	}
	return r;
}

function round( d ) {
	return d
		.replace( /-?(?:\d+\.?\d*|\.\d+)(?:e-?\d+)?/gi, ( m ) => {
			let s = String( +parseFloat( m ).toFixed( PRECISION ) );
			if ( s === '-0' ) {
				s = '0';
			}
			return s.replace( /^(-?)0\./, '$1.' );
		} )
		.replace( / ?([MLQCZ]) ?/g, '$1' )
		.replace( / -/g, '-' );
}

// Outline one icon given as [[tag, attrs], ...]. Root is Lucide's: fill none, stroke on.
export function outlineIcon( nodes, stroke = STROKE ) {
	let acc = new CK.Path();
	const unsupported = [];
	for ( const [ tag, attrs ] of nodes ) {
		const geom = elementPath( tag, attrs );
		if ( ! geom ) {
			unsupported.push( tag );
			continue;
		}
		// Painted region of the element: its stroke, plus its interior if it is filled.
		const stroked = geom.makeStroked( stroke );
		if ( stroked ) {
			acc = union( acc, stroked );
		}
		if ( attrs.fill && attrs.fill !== 'none' ) {
			acc = union( acc, geom.copy() );
		}
		geom.delete();
	}
	const evenodd = acc.getFillType() === CK.FillType.EvenOdd;
	const d = round( acc.toSVGString() );
	acc.delete();
	const rule = evenodd ? ' fill-rule="evenodd"' : '';
	return {
		svg: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor"${ rule } d="${ d }"></path></svg>`,
		unsupported,
	};
}

// Main.
if ( fileURLToPath( import.meta.url ) === process.argv[ 1 ] ) {
	const args = process.argv.slice( 2 );
	const onlyIdx = args.indexOf( '--only' );
	const only = onlyIdx >= 0 ? new Set( args[ onlyIdx + 1 ].split( ',' ) ) : null;

	const nodes = require( 'lucide-static/icon-nodes.json' );
	const names = Object.keys( nodes ).filter( ( n ) => ! only || only.has( n ) ).sort();

	mkdirSync( new URL( './out/', import.meta.url ), { recursive: true } );
	const out = {};
	const stats = { count: 0, failed: [], unsupported: {}, bytesIn: 0, bytesOut: 0, ms: 0 };
	const t0 = performance.now();
	for ( const name of names ) {
		try {
			const { svg, unsupported } = outlineIcon( nodes[ name ] );
			out[ name ] = svg;
			if ( unsupported.length ) {
				stats.unsupported[ name ] = unsupported;
			}
			stats.bytesOut += svg.length;
			stats.bytesIn += readFileSync(
				require.resolve( `lucide-static/icons/${ name }.svg` ),
				'utf8'
			).length;
			stats.count++;
		} catch ( e ) {
			stats.failed.push( { name, error: String( e.message || e ) } );
		}
	}
	stats.ms = Math.round( performance.now() - t0 );
	writeFileSync( new URL( './out/icons.json', import.meta.url ), JSON.stringify( out, null, '\t' ) );
	writeFileSync( new URL( './out/outline-stats.json', import.meta.url ), JSON.stringify( stats, null, '\t' ) );
	console.log(
		`outlined ${ stats.count }/${ names.length } in ${ stats.ms }ms, ` +
			`${ stats.failed.length } failed, ${ Object.keys( stats.unsupported ).length } with unsupported tags, ` +
			`avg ${ Math.round( stats.bytesOut / stats.count ) } bytes out vs ${ Math.round( stats.bytesIn / stats.count ) } in`
	);
}
