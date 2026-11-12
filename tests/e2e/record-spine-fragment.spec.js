// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * RecordSpine in-page tabs opened from the URL fragment (#5082).
 *
 * The real script runs on a routed origin with two strips on one page, so the
 * spec never skips. A fragment naming one of a strip's own panels selects it,
 * without moving focus; an unknown fragment, another strip's panel and no
 * fragment all leave the server's choice standing.
 */

const ORIGIN = 'http://tt-spine.test';
const SCRIPT = fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', 'components', 'record-spine-tabs.js' ), 'utf8' );

function strip( prefix, panels, active ) {
	const tabs = panels.map( ( p ) => '<button type="button" class="tt-spine__tab" role="tab" id="tt-tab-' + p + '" aria-controls="' + p + '"' +
		' aria-selected="' + ( p === active ? 'true' : 'false' ) + '" tabindex="' + ( p === active ? '0' : '-1' ) + '">' + p + '</button>' ).join( '' );
	const bodies = panels.map( ( p ) => '<div id="' + p + '" role="tabpanel"' + ( p === active ? '' : ' hidden' ) + '>' + p + ' body</div>' ).join( '' );
	return '<div class="tt-spine tt-spine--tabs-only" data-strip="' + prefix + '"><div class="tt-spine__tabs" role="tablist" data-tt-spine-tabs>' + tabs + '</div></div>' + bodies;
}

const BODY =
	'<div class="tt-dashboard">' +
	strip( 'a', [ 'pane-prep', 'pane-talk', 'pane-background' ], 'pane-prep' ) +
	strip( 'b', [ 'other-one', 'other-two' ], 'other-one' ) +
	'<p id="tt-form">an ordinary anchor</p></div>';

async function open( page, fragment ) {
	await page.route( ORIGIN + '/**', ( route ) => route.fulfill( {
		contentType: 'text/html',
		body: '<!doctype html><html><head></head><body>' + BODY + '<script>' + SCRIPT + '</script></body></html>',
	} ) );
	await page.goto( ORIGIN + '/page' + fragment );
}

async function state( page ) {
	return page.evaluate( () => ( {
		selected: Array.from( document.querySelectorAll( '[role="tab"][aria-selected="true"]' ) ).map( ( t ) => t.getAttribute( 'aria-controls' ) ),
		visible: Array.from( document.querySelectorAll( '[role="tabpanel"]' ) ).filter( ( p ) => ! p.hidden ).map( ( p ) => p.id ),
		focus: document.activeElement ? document.activeElement.tagName : '',
		hash: window.location.hash,
	} ) );
}

test.describe( 'RecordSpine tabs from the fragment', () => {
	test( 'a fragment naming one of the strip\'s panels selects it, without moving focus', async ( { page } ) => {
		await open( page, '#pane-talk' );
		const s = await state( page );
		expect( s.selected ).toEqual( [ 'pane-talk', 'other-one' ] );
		expect( s.visible ).toEqual( [ 'pane-talk', 'other-one' ] );
		expect( s.focus ).toBe( 'BODY' );
		expect( s.hash ).toBe( '#pane-talk' );
	} );

	test( 'an unknown fragment leaves the server\'s choice', async ( { page } ) => {
		await open( page, '#tt-form' );
		const s = await state( page );
		expect( s.selected ).toEqual( [ 'pane-prep', 'other-one' ] );
		expect( s.visible ).toEqual( [ 'pane-prep', 'other-one' ] );
		expect( s.focus ).toBe( 'BODY' );
	} );

	test( 'another strip\'s panel moves only that strip', async ( { page } ) => {
		await open( page, '#other-two' );
		const s = await state( page );
		expect( s.selected ).toEqual( [ 'pane-prep', 'other-two' ] );
		expect( s.visible ).toEqual( [ 'pane-prep', 'other-two' ] );
		expect( s.focus ).toBe( 'BODY' );
	} );

	test( 'a selector-shaped fragment is compared, never queried', async ( { page } ) => {
		await open( page, '#pane-talk,%20#other-two' );
		const s = await state( page );
		expect( s.selected ).toEqual( [ 'pane-prep', 'other-one' ] );
	} );

	test( 'no fragment leaves the server\'s choice', async ( { page } ) => {
		await open( page, '' );
		const s = await state( page );
		expect( s.selected ).toEqual( [ 'pane-prep', 'other-one' ] );
		expect( s.visible ).toEqual( [ 'pane-prep', 'other-one' ] );
		expect( s.focus ).toBe( 'BODY' );
	} );

	test( 'a tab click does not write the fragment', async ( { page } ) => {
		await open( page, '' );
		await page.click( '#tt-tab-pane-background' );
		const s = await state( page );
		expect( s.selected[ 0 ] ).toBe( 'pane-background' );
		expect( s.hash ).toBe( '' );
	} );
} );
