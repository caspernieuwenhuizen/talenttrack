// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * The attendance, minutes and ratings grids read their save's answer.
 *
 * `POST /attendance/bulk` and `POST /minutes/bulk` answer 200 with
 * `skipped: N` for a change they did not write, a team the save may not
 * change among them. `POST /activities/{id}/ratings/bulk` refuses such a
 * team outright (403); its `skipped` counts blank cells, which write
 * nothing by design, and a player or category that left the activity
 * after the grid was opened. The grids used to read only `rejected`, so
 * a skipped save said "All changes saved" and the coach's work was lost.
 *
 * Each grid's real script runs against a minimal copy of its markup on a
 * routed origin, with the bulk endpoint answered by the test. Nothing here
 * needs the wp-env data, so the spec never skips.
 */

const ORIGIN = 'http://tt-grid.test';
const SAVED = 'All changes saved';
const SKIPPED = "%d change(s) were not saved: you can't change this team";

function script( name ) {
	return fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', name ), 'utf8' );
}

/**
 * Serve `body` + config + the grid script at ORIGIN, and answer the bulk
 * endpoint with `data`.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} body
 * @param {string} config
 * @param {string} js
 * @param {object} data
 */
async function mount( page, body, config, js, data ) {
	await page.route( ORIGIN + '/**', async ( route ) => {
		const url = route.request().url();
		if ( url.endsWith( '/grid' ) ) {
			await route.fulfill( {
				contentType: 'text/html',
				body: '<!doctype html><html><body>' + body +
					'<script>' + config + '</script><script>' + js + '</script></body></html>',
			} );
			return;
		}
		await route.fulfill( {
			contentType: 'application/json',
			body: JSON.stringify( { success: true, data } ),
		} );
	} );
	await page.goto( ORIGIN + '/grid' );
}

const ATTENDANCE_BODY =
	'<div class="tt-agrid-card"><table class="tt-agrid"><thead><tr>' +
	'<th data-activity="11" data-completes="0" data-label="1 Oct"></th></tr></thead><tbody><tr>' +
	'<td class="tt-agrid-cell tt-agrid-cell--empty" data-player="5" data-activity="11">' +
	'<select class="tt-agrid-sel" data-player="5" data-activity="11" aria-label="Attendance">' +
	'<option value=""></option><option value="present">Present</option></select>' +
	'<span class="tt-agrid-abbr"></span></td><td class="tt-agrid__rate"></td></tr></tbody></table>' +
	'<span data-agrid-status role="status"></span>' +
	'<button type="button" data-agrid-save disabled>Save</button></div>';

const ATTENDANCE_CONFIG = 'window.TTAttendanceGrid = ' + JSON.stringify( {
	restBulk: ORIGIN + '/attendance/bulk',
	nonce: 'e2e',
	statuses: [
		{ value: '', label: '-', short: '.', mod: 'empty' },
		{ value: 'present', label: 'Present', short: 'P', mod: 'present' },
	],
	i18n: { saving: 'Saving', saved: SAVED, error: 'Could not save', skipped: SKIPPED, rejected: '%d change(s) not saved.' },
} ) + ';';

const MINUTES_BODY =
	'<div class="tt-agrid-card"><table class="tt-agrid"><tbody><tr>' +
	'<td class="tt-agrid-cell tt-agrid-min" data-player="5" data-activity="11">' +
	'<input class="tt-agrid-min-in" type="text" inputmode="numeric" value="0" data-player="5" data-activity="11" aria-label="Minutes"></td>' +
	'<td class="tt-agrid__rate"></td></tr></tbody></table>' +
	'<span data-agrid-status role="status"></span>' +
	'<button type="button" data-agrid-save disabled>Save</button></div>';

const MINUTES_CONFIG = 'window.TTMinutesGrid = ' + JSON.stringify( {
	restBulk: ORIGIN + '/minutes/bulk',
	restStats: ORIGIN + '/activities/',
	nonce: 'e2e',
	stats: [ 'goals', 'assists' ],
	i18n: { saving: 'Saving', saved: SAVED, error: 'Could not save', skipped: SKIPPED },
} ) + ';';

function ratingsBody( value ) {
	return '<div class="tt-rgrid" data-tt-rgrid data-rest="' + ORIGIN + '/activities/11/ratings/bulk" data-nonce="e2e">' +
		'<table><tbody><tr><td><input type="number" class="tt-rgrid-input" data-player-id="5" data-category-id="3"' +
		' min="5" max="10" step="0.5" value="' + value + '" aria-label="Rating"></td></tr></tbody></table>' +
		'<p data-tt-rgrid-status role="status"></p>' +
		'<button type="submit" class="tt-save-btn">Save ratings</button></div>';
}

const RATINGS_SKIPPED_ONE = '%d change was not saved: the player or category is no longer part of this activity. Reload the grid.';
const RATINGS_SKIPPED_MANY = '%d changes were not saved: the player or category is no longer part of this activity. Reload the grid.';

const RATINGS_CONFIG = 'window.TTRatingsGrid = ' + JSON.stringify( {
	i18n: {
		saving: 'Saving', saved: SAVED, error: 'Could not save',
		skipped_one: RATINGS_SKIPPED_ONE, skipped_many: RATINGS_SKIPPED_MANY,
	},
} ) + ';';

test.describe( 'Grid save reads skipped changes', () => {

	test( 'attendance: a skipped save is not reported as saved and keeps the cell dirty', async ( { page } ) => {
		await mount( page, ATTENDANCE_BODY, ATTENDANCE_CONFIG, script( 'frontend-attendance-grid.js' ),
			{ saved: 0, skipped: 1, failed: 0, completed: 0, rejected: [], rejected_count: 0, message: '' } );

		await page.selectOption( 'select.tt-agrid-sel', 'present' );
		await page.click( '[data-agrid-save]' );

		const status = page.locator( '[data-agrid-status]' );
		await expect( status ).toHaveText( SKIPPED.replace( '%d', '1' ) );
		await expect( page.locator( 'td.tt-agrid-cell' ) ).toHaveClass( /is-dirty/ );
		await expect( page.locator( 'td.tt-agrid-cell' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page.locator( '[data-agrid-save]' ) ).toBeEnabled();
	} );

	test( 'attendance: a written save still says saved', async ( { page } ) => {
		await mount( page, ATTENDANCE_BODY, ATTENDANCE_CONFIG, script( 'frontend-attendance-grid.js' ),
			{ saved: 1, skipped: 0, failed: 0, completed: 0, rejected: [], rejected_count: 0, message: '' } );

		await page.selectOption( 'select.tt-agrid-sel', 'present' );
		await page.click( '[data-agrid-save]' );

		await expect( page.locator( '[data-agrid-status]' ) ).toHaveText( SAVED );
		await expect( page.locator( 'td.tt-agrid-cell' ) ).not.toHaveClass( /is-dirty/ );
	} );

	test( 'minutes: a skipped save is not reported as saved and keeps the cell dirty', async ( { page } ) => {
		await mount( page, MINUTES_BODY, MINUTES_CONFIG, script( 'frontend-minutes-grid.js' ),
			{ saved: 0, skipped: 1, failed: 0 } );

		await page.fill( 'input.tt-agrid-min-in', '45' );
		await page.click( '[data-agrid-save]' );

		await expect( page.locator( '[data-agrid-status]' ) ).toHaveText( SKIPPED.replace( '%d', '1' ) );
		await expect( page.locator( 'td.tt-agrid-min' ) ).toHaveClass( /is-dirty/ );
		await expect( page.locator( 'input.tt-agrid-min-in' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page.locator( '[data-agrid-save]' ) ).toBeEnabled();
	} );

	test( 'minutes: a written save still says saved', async ( { page } ) => {
		await mount( page, MINUTES_BODY, MINUTES_CONFIG, script( 'frontend-minutes-grid.js' ),
			{ saved: 1, skipped: 0, failed: 0 } );

		await page.fill( 'input.tt-agrid-min-in', '45' );
		await page.click( '[data-agrid-save]' );

		await expect( page.locator( '[data-agrid-status]' ) ).toHaveText( SAVED );
		await expect( page.locator( 'td.tt-agrid-min' ) ).not.toHaveClass( /is-dirty/ );
	} );

	// The player or category left the activity after the grid was opened.
	test( 'ratings: a skipped score is not reported as saved and stays dirty', async ( { page } ) => {
		await mount( page, ratingsBody( '' ), RATINGS_CONFIG, script( 'frontend-ratings-grid.js' ),
			{ saved: 0, skipped: 1, failed: 0, rejected: [], rejected_count: 0 } );

		await page.fill( 'input.tt-rgrid-input', '7' );
		await page.click( '.tt-save-btn' );

		await expect( page.locator( '[data-tt-rgrid-status]' ) ).toHaveText( RATINGS_SKIPPED_ONE.replace( '%d', '1' ) );
		await expect( page.locator( 'input.tt-rgrid-input' ) ).toHaveClass( /is-dirty/ );
		await expect( page.locator( 'input.tt-rgrid-input' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( page.locator( '.tt-save-btn' ) ).toBeEnabled();
	} );

	// A blank cell is skipped by design (not rated, nothing written), so
	// clearing one is not a refusal.
	test( 'ratings: a blank cell counted as skipped still says saved', async ( { page } ) => {
		await mount( page, ratingsBody( '7' ), RATINGS_CONFIG, script( 'frontend-ratings-grid.js' ),
			{ saved: 0, skipped: 1, failed: 0, rejected: [], rejected_count: 0 } );

		await page.fill( 'input.tt-rgrid-input', '' );
		await page.click( '.tt-save-btn' );

		await expect( page.locator( '[data-tt-rgrid-status]' ) ).toHaveText( SAVED );
	} );
} );
