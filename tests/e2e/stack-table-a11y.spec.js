// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * #4810 — a stack-table stays a table to a screen reader.
 *
 * On a phone the primitive (assets/css/components/stack-table.css) changes
 * `display` on every table element, which drops the implicit table roles in
 * some engines, and it hides the header row. StackTable::attrs() writes the
 * roles explicitly and the header row is hidden visually only, so the
 * browser's accessibility tree must still hold a table with its column
 * headers. Read from Chromium's own tree over CDP, not from the DOM: a header
 * row hidden with `display: none` would pass a DOM check and fail here.
 *
 * Measured on the dashboard home's first data-table widget (the admin's
 * Recent audit events). Skips when the dashboard shows none.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/**
 * The accessibility tree's view of the first stack-table on the page.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{ headers: string[], domHeaders: string[], tables: number, rows: number, cells: number } | null>}
 */
async function readTable( page ) {
	await page.goto( '/' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const table = page.locator( 'table.tt-stack-table' ).first();
	if ( ( await table.count() ) === 0 ) return null;
	await table.evaluate( ( el ) => el.setAttribute( 'data-tt-a11y-probe', '1' ) );

	const domHeaders = await table.locator( 'th' ).evaluateAll( ( ths ) => ths.map( ( th ) => ( th.textContent || '' ).trim() ).filter( Boolean ) );

	const cdp = await page.context().newCDPSession( page );
	await cdp.send( 'DOM.enable' );
	await cdp.send( 'Accessibility.enable' );
	const { root } = await cdp.send( 'DOM.getDocument', { depth: -1 } );
	const { nodeId } = await cdp.send( 'DOM.querySelector', { nodeId: root.nodeId, selector: 'table[data-tt-a11y-probe="1"]' } );
	const { nodes } = await cdp.send( 'Accessibility.getPartialAXTree', { nodeId, fetchRelatives: true } );
	const own = await cdp.send( 'Accessibility.queryAXTree', { nodeId } );
	const sub = [];
	for ( const role of [ 'columnheader', 'row', 'cell' ] ) {
		const r = await cdp.send( 'Accessibility.queryAXTree', { nodeId, role } );
		sub.push( r.nodes.filter( ( n ) => ! n.ignored ) );
	}
	await cdp.detach();

	const roleOf = ( n ) => ( n.role && n.role.value ) || '';
	const tables = nodes.concat( own.nodes ).filter( ( n ) => ! n.ignored && roleOf( n ) === 'table' );
	// Chromium applies `text-transform` to the accessible name, and the
	// desktop header row is uppercase, so names compare without case.
	const fold = ( s ) => String( s ).trim().toLowerCase();
	return {
		tables: new Set( tables.map( ( n ) => n.nodeId ) ).size,
		headers: sub[ 0 ].map( ( n ) => fold( ( n.name && n.name.value ) || '' ) ),
		domHeaders: domHeaders.map( fold ),
		rows: sub[ 1 ].length,
		cells: sub[ 2 ].length,
	};
}

test.describe( 'Stack-table on a phone', () => {
	test.use( { viewport: { width: 360, height: 780 }, hasTouch: true, isMobile: true } );

	test( 'is stacked on screen and a table with headers to a screen reader', async ( { page } ) => {
		const ax = await readTable( page );
		test.skip( ax === null, 'no data-table widget on this dashboard' );
		if ( ax === null ) return;

		// Stacked on screen: the header row takes no visible room.
		const thead = await page.locator( 'table.tt-stack-table thead' ).first().boundingBox();
		expect( thead && thead.height ).toBeLessThanOrEqual( 1 );

		expect( ax.tables ).toBeGreaterThanOrEqual( 1 );
		expect( ax.domHeaders.length ).toBeGreaterThan( 0 );
		expect( ax.headers ).toEqual( ax.domHeaders );
		expect( ax.rows ).toBeGreaterThanOrEqual( 2 );
		expect( ax.cells ).toBeGreaterThanOrEqual( 1 );
	} );
} );

test.describe( 'Stack-table on a desktop', () => {
	test.use( { viewport: { width: 1280, height: 900 }, hasTouch: false, isMobile: false } );

	test( 'is a table on screen and to a screen reader', async ( { page } ) => {
		const ax = await readTable( page );
		test.skip( ax === null, 'no data-table widget on this dashboard' );
		if ( ax === null ) return;

		await expect( page.locator( 'table.tt-stack-table thead th' ).first() ).toBeVisible();
		expect( ax.tables ).toBeGreaterThanOrEqual( 1 );
		expect( ax.headers ).toEqual( ax.domHeaders );
	} );
} );
