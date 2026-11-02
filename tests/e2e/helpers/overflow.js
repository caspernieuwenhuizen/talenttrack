// @ts-check
/**
 * Which element makes a page overflow, for the mobile gate (#4767).
 *
 * Runs in the page, so it is passed to `page.evaluate()` as is and must not
 * close over anything in this file.
 */

/**
 * The widest box under `.tt-dashboard` that extends the page past the
 * viewport, or null when none does.
 *
 * A box inside a horizontal scroll strip (an ancestor with `overflow-x:
 * auto` or `scroll`, such as the player tabs) is skipped: the strip scrolls
 * on purpose and the box cannot widen the page, so naming it pointed the
 * reader at the one element that was not the problem.
 *
 * @returns {{ where: string, right: number } | null}
 */
function widestOffender() {
	const doc = document.documentElement;
	const root = document.querySelector( '.tt-dashboard' );
	/** @type {WeakMap<Element, boolean>} */
	const inStrip = new WeakMap();

	/** @param {Element} el */
	const insideScrollStrip = ( el ) => {
		const chain = [];
		let node = el.parentElement;
		let found = false;
		while ( node && node !== document.body ) {
			if ( inStrip.has( node ) ) {
				found = /** @type {boolean} */ ( inStrip.get( node ) );
				break;
			}
			chain.push( node );
			const x = getComputedStyle( node ).overflowX;
			if ( x === 'auto' || x === 'scroll' ) {
				found = true;
				break;
			}
			if ( node === root ) break;
			node = node.parentElement;
		}
		chain.forEach( ( n ) => inStrip.set( n, found ) );
		return found;
	};

	let worst = null;
	let worstRight = doc.clientWidth;
	document.querySelectorAll( '.tt-dashboard *' ).forEach( ( el ) => {
		const r = el.getBoundingClientRect();
		if ( r.width === 0 || r.height === 0 ) return;
		if ( r.right <= worstRight ) return;
		if ( insideScrollStrip( el ) ) return;
		worstRight = r.right;
		worst = el;
	} );

	if ( ! worst ) return null;

	const el = /** @type {Element} */ ( worst );
	return {
		where: `${ el.tagName.toLowerCase() }.${ String( el.className || '' ).trim().split( /\s+/ ).slice( 0, 3 ).join( '.' ) }`,
		right: Math.round( worstRight ),
	};
}

module.exports = { widestOffender };
