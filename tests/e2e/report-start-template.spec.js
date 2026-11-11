// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The start wizard's "Start from" choice at 360px (#5023): an empty report or
 * a template, each a radio row with its description. The markup is the one
 * `StartChoices::templateFieldset()` prints, under the wizard's own sheet:
 * no sideways scroll, and every row a 48px target with 8px between rows.
 */

const ORIGIN = 'http://tt-start-template.test';

function asset( name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'css', name ), 'utf8' );
}

function option( value, label, hint ) {
	const id = 'tt-rsw-template-' + ( value || 'none' );
	return '<label class="tt-rsw-option" for="' + id + '">' +
		'<input type="radio" id="' + id + '" name="start_template" value="' + value + '" aria-describedby="' + id + '-hint" />' +
		'<span class="tt-rsw-option-text"><span class="tt-rsw-option-label">' + label + '</span>' +
		'<span class="tt-rsw-option-hint" id="' + id + '-hint">' + hint + '</span></span></label>';
}

const FIELDSET =
	'<fieldset class="tt-rsw-choices"><legend>Start from</legend>' +
	option( '', 'An empty report', 'Choose every block yourself.' ) +
	option( 'team_monthly_report', 'Team monthly report', 'The monthly report of one team, the same on screen and on paper: the headline numbers, squad status, evaluations, attendance, minutes, matches, who needs a conversation, what changed, tests, player by player, decisions and actions, and data quality.' ) +
	option( 'player_report_family', 'Player report for the family', 'The player report as the player and their parents receive it, through a snapshot you share: evaluation scores without the coach\'s notes, attendance, playing time, goals and tests.' ) +
	'<p class="tt-rsw-option-hint">A template sets what the report is, who it is about and who it is written for, and starts it with its blocks. You can change the blocks afterwards.</p>' +
	'</fieldset>';

test( 'at 360px the template choice has no sideways scroll and 48px rows', async ( { page } ) => {
	await page.setViewportSize( { width: 360, height: 740 } );
	await page.route( ORIGIN + '/**', ( route ) => route.fulfill( {
		contentType: 'text/html',
		body: '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' +
			asset( 'tokens.css' ) + asset( 'frontend-report-start-wizard.css' ) +
			'</style></head><body><div class="tt-dashboard"><form>' + FIELDSET + '</form></div></body></html>',
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

	// The whole row is the target: tapping the description picks the template.
	await page.locator( '#tt-rsw-template-team_monthly_report-hint' ).click();
	await expect( page.locator( '#tt-rsw-template-team_monthly_report' ) ).toBeChecked();
} );
