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
 * Recent audit events), and on the message log, a `.tt-table` that the
 * legacy phone stack in frontend-mobile.css must leave alone (its
 * `thead { display: none }` would drop the headers). The message log has a
 * row from tests/e2e/setup/message-log-row.php. Each case skips when its
 * page shows no table.
 */

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/**
 * The accessibility tree's view of the first stack-table on the page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} url
 * @returns {Promise<{ headers: string[], domHeaders: string[], tables: number, rows: number, cells: number, cellNames: string[], domCells: { label: string, text: string }[] } | null>}
 */
async function readTable( page, url = '/' ) {
	await page.goto( url );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const table = page.locator( 'table.tt-stack-table' ).first();
	if ( ( await table.count() ) === 0 ) return null;
	// On a phone the dashboard folds the widget away (GridRenderer's
	// <details class="tt-pd-fold">, closed by script at load). Closed content
	// is not in the accessibility tree; open it the way a user does.
	const folded = page.locator( 'details.tt-pd-fold' ).filter( { has: page.locator( 'table.tt-stack-table' ) } ).first();
	if ( ( await folded.count() ) > 0 && ! ( await folded.evaluate( ( el ) => /** @type {HTMLDetailsElement} */ ( el ).open ) ) ) {
		await folded.locator( 'summary' ).first().click();
		await expect( folded ).toHaveAttribute( 'open', '' );
	}
	await table.evaluate( ( el ) => el.setAttribute( 'data-tt-a11y-probe', '1' ) );

	const domHeaders = await table.locator( 'th' ).evaluateAll( ( ths ) => ths.map( ( th ) => ( th.textContent || '' ).trim() ).filter( Boolean ) );
	const domCells = await table.locator( 'tbody td' ).evaluateAll( ( tds ) => tds.map( ( td ) => ( {
		label: td.getAttribute( 'data-label' ) || '',
		text: ( td.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
	} ) ) );

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
		cellNames: sub[ 2 ].map( ( n ) => fold( ( n.name && n.name.value ) || '' ) ),
		domCells: domCells.map( ( c ) => ( { label: fold( c.label ), text: fold( c.text ) } ) ),
	};
}

/**
 * A stacked table on a phone: no visible header row, and to a screen reader
 * a table with its column headers, rows, and cells read once.
 *
 * @param {import('@playwright/test').Page} page
 * @param {NonNullable<Awaited<ReturnType<typeof readTable>>>} ax
 */
async function expectStackedTable( page, ax ) {
	// Stacked on screen: the header row takes no visible room.
	const thead = await page.locator( 'table[data-tt-a11y-probe="1"] thead' ).boundingBox();
	expect( thead && thead.height ).toBeLessThanOrEqual( 1 );

	expect( ax.tables ).toBeGreaterThanOrEqual( 1 );
	expect( ax.domHeaders.length ).toBeGreaterThan( 0 );
	expect( ax.headers ).toEqual( ax.domHeaders );
	expect( ax.rows ).toBeGreaterThanOrEqual( 2 );
	expect( ax.cells ).toBeGreaterThanOrEqual( 1 );

	// #4844 — each stacked cell is read once: the column header names it,
	// and the label drawn in front of the value stays out of the cell's own
	// name.
	expect( ax.cellNames.length ).toBe( ax.domCells.length );
	ax.domCells.forEach( ( cell, i ) => {
		if ( cell.label === '' || cell.text.startsWith( cell.label ) ) return;
		expect( ax.cellNames[ i ].startsWith( cell.label ), `cell ${ i } (${ cell.label }) reads its label` ).toBe( false );
	} );
}

test.describe( 'Stack-table on a phone', () => {
	test.use( { viewport: { width: 360, height: 780 }, hasTouch: true, isMobile: true } );

	test( 'is stacked on screen and a table with headers to a screen reader', async ( { page } ) => {
		const ax = await readTable( page );
		test.skip( ax === null, 'no data-table widget on this dashboard' );
		if ( ax === null ) return;
		await expectStackedTable( page, ax );
	} );

	// The message log is a .tt-table: below 480px frontend-mobile.css's
	// legacy stack would hide its header row with display: none.
	test( 'the message log keeps its headers below 480px', async ( { page } ) => {
		const ax = await readTable( page, '/?tt_view=messages' );
		test.skip( ax === null, 'the message log shows no table on this site' );
		if ( ax === null ) return;
		await expectStackedTable( page, ax );
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
