// Spike for #12: edge cases the Lucide run does not cover.
// Usage: node fixtures.mjs

import { Resvg } from '@resvg/resvg-js';
import { outlineIcon } from './outline.mjs';

const wrap = ( body, stroke = true ) =>
	`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" ${
		stroke ? 'fill="none" stroke="#000" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' : ''
	}>${ body }</svg>`;

function coverage( svg, size = 24, ss = 32 ) {
	const big = size * ss;
	const px = new Resvg( svg, { fitTo: { mode: 'width', value: big }, font: { loadSystemFonts: false } } ).render().pixels;
	let sum = 0;
	for ( let i = 3; i < px.length; i += 4 ) {
		sum += px[ i ];
	}
	return sum / 255 / ( ss * ss ); // painted area in 24px pixels
}

const cases = [
	// A zero length subpath: SVG paints a dot for it with round caps.
	{ name: 'zero-length-dot', nodes: [ [ 'path', { d: 'M12 12z' } ] ], svg: '<path d="M12 12z"/>' },
	{ name: 'zero-length-h0', nodes: [ [ 'path', { d: 'M12 12h0' } ] ], svg: '<path d="M12 12h0"/>' },
	// Self intersecting stroke: the overlap must not become a hole.
	{ name: 'figure-eight', nodes: [ [ 'path', { d: 'M4 4L20 20L20 4L4 20Z' } ] ], svg: '<path d="M4 4L20 20L20 4L4 20Z"/>' },
	// Overlapping elements: two crossing lines and a circle through them.
	{
		name: 'overlapping-elements',
		nodes: [ [ 'line', { x1: '4', y1: '12', x2: '20', y2: '12' } ], [ 'line', { x1: '12', y1: '4', x2: '12', y2: '20' } ], [ 'circle', { cx: '12', cy: '12', r: '6' } ] ],
		svg: '<line x1="4" y1="12" x2="20" y2="12"/><line x1="12" y1="4" x2="12" y2="20"/><circle cx="12" cy="12" r="6"/>',
	},
];

let failed = 0;
for ( const c of cases ) {
	const up = coverage( wrap( c.svg ) );
	const ours = coverage( outlineIcon( c.nodes ).svg );
	// A hole where strokes overlap would lose whole pixels; 0.5% of area is curve noise.
	const ok = Math.abs( up - ours ) / up < 0.005;
	failed += ok ? 0 : 1;
	console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ c.name }: upstream ${ up.toFixed( 3 ) } px, outlined ${ ours.toFixed( 3 ) } px` );
}

// clip-rule is not on core's allowlist. It only has an effect on shapes inside a
// clipPath, which core strips anyway, so dropping it from a plain path must change nothing.
const heroLike = ( clip ) =>
	wrap(
		`<path fill="#000" fill-rule="evenodd"${ clip ? ' clip-rule="evenodd"' : '' } d="M12 2a10 10 0 1 0 0 20a10 10 0 1 0 0-20zm0 5a5 5 0 1 1 0 10a5 5 0 1 1 0-10z"/>`,
		false
	);
const withClip = coverage( heroLike( true ) ), without = coverage( heroLike( false ) );
const clipOk = withClip === without;
failed += clipOk ? 0 : 1;
console.log( `${ clipOk ? 'ok  ' : 'FAIL' } clip-rule-stripped: with ${ withClip.toFixed( 3 ) } px, without ${ without.toFixed( 3 ) } px` );

process.exit( failed ? 1 : 0 );
