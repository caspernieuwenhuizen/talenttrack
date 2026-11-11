// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The start wizard's group step at 360px (#4964): "All my teams", the teams,
 * and the optional position and age group. The markup is the one
 * `SubjectStep::renderGroup()` prints, under the sheets the wizard loads: no
 * sideways scroll, every row and select a 48px target, 8px between rows.
 */

const ORIGIN = 'http://tt-start-group.test';

test.use( { viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true } );

function asset( name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'css', name ), 'utf8' );
}

function team( id, name ) {
	return '<label class="tt-rsw-option" for="tt-rsw-group-' + id + '">' +
		'<input type="checkbox" id="tt-rsw-group-' + id + '" name="cohort_team_ids[]" value="' + id + '" />' +
		'<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">' + name + '</span></span></label>';
}

const STEP =
	'<fieldset class="tt-rsw-choices"><legend>Which teams make up the group?</legend>' +
	'<label class="tt-rsw-option" for="tt-rsw-group-all">' +
	'<input type="checkbox" id="tt-rsw-group-all" name="cohort_all_teams" value="1" aria-describedby="tt-rsw-group-all-why" />' +
	'<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">All my teams</span>' +
	'<span class="tt-rsw-option-hint" id="tt-rsw-group-all-why">Every team you build reports for. The teams ticked below are then not needed.</span></span></label>' +
	team( 11, 'Onder 14 Selectie Zaterdag' ) + team( 12, 'Onder 15 Selectie Zondagochtend' ) +
	'</fieldset>' +
	'<div class="tt-field"><label class="tt-field-label" for="tt-rsw-position">Only one position (optional)</label>' +
	'<select id="tt-rsw-position" class="tt-input" name="cohort_position" aria-describedby="tt-rsw-position-hint">' +
	'<option value="">Every position</option><option value="CB">Centre back</option><option value="CDM">Defensive midfielder (central defensive midfield)</option>' +
	'</select><p class="tt-field-hint" id="tt-rsw-position-hint">A player counts by their first position, so each player is in one position group.</p></div>' +
	'<div class="tt-field"><label class="tt-field-label" for="tt-rsw-age">Only one age group (optional)</label>' +
	'<select id="tt-rsw-age" class="tt-input" name="cohort_age_group"><option value="">Every age group</option><option value="O14">O14</option></select></div>';

test( 'at 360px under a finger the group step has no sideways scroll and 48px targets', async ( { page } ) => {
	await page.route( ORIGIN + '/**', ( route ) => route.fulfill( {
		contentType: 'text/html',
		body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' +
			asset( 'tokens.css' ) + asset( 'frontend-admin.css' ) + asset( 'frontend-report-start-wizard.css' ) +
			'</style></head><body><div class="tt-dashboard"><form>' + STEP + '</form></div></body></html>',
	} ) );
	await page.goto( ORIGIN + '/wizard' );

	const widths = await page.evaluate( () => ( { scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth } ) );
	expect( widths.scroll ).toBeLessThanOrEqual( widths.client );

	const rows = page.locator( '.tt-rsw-option' );
	await expect( rows ).toHaveCount( 3 );
	const boxes = [];
	for ( let i = 0; i < 3; i++ ) {
		const box = await rows.nth( i ).boundingBox();
		expect( box ).not.toBeNull();
		if ( box ) {
			expect( box.height ).toBeGreaterThanOrEqual( 48 );
			boxes.push( box );
		}
	}
	for ( let i = 1; i < boxes.length; i++ ) {
		expect( boxes[ i ].y - ( boxes[ i - 1 ].y + boxes[ i - 1 ].height ) ).toBeGreaterThanOrEqual( 8 );
	}

	for ( const id of [ '#tt-rsw-position', '#tt-rsw-age' ] ) {
		const box = await page.locator( id ).boundingBox();
		expect( box ).not.toBeNull();
		if ( box ) {
			expect( box.height, id + ' is a 48px target' ).toBeGreaterThanOrEqual( 48 );
			expect( box.x + box.width, id + ' fits the screen' ).toBeLessThanOrEqual( 360 );
		}
	}

	// The whole row is the target: tapping the hint ticks "All my teams".
	await page.locator( '#tt-rsw-group-all-why' ).tap();
	await expect( page.locator( '#tt-rsw-group-all' ) ).toBeChecked();

	// And the position is picked with the keyboard alone.
	await page.locator( '#tt-rsw-position' ).focus();
	await page.locator( '#tt-rsw-position' ).selectOption( 'CB' );
	await expect( page.locator( '#tt-rsw-position' ) ).toHaveValue( 'CB' );
} );
