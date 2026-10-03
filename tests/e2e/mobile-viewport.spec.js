// @ts-check
/**
 * Mobile viewport regression gate (#2813).
 *
 * The mobile audit found 2,213 defect rows across 150 surfaces, most of
 * them traceable to about a dozen shared-component causes. The fixes are
 * one-line CSS declarations, which is exactly the kind of change a later
 * edit undoes without anyone noticing — `frontend-mobile.css` had already
 * silently undone the 48px floor once.
 *
 * So this walks every surface a phone can actually reach and asserts three
 * things per surface:
 *
 *   1. No horizontal overflow — `scrollWidth <= clientWidth`.
 *   2. No visible interactive element under 48px in either dimension.
 *   3. No table wider than the viewport on a `native` surface.
 *
 * and, on `native` surfaces whose list has rows, two density checks (#4200):
 *
 *   4. No data row taller than 120px.
 *   5. The first data row starts within the top 400px of the page.
 *
 * WHICH SURFACES
 *
 * The slug list is read from `config/mobile_surfaces.php`, which #2812's
 * gate keeps in step with what the dispatcher actually routes. Surfaces
 * classified `desktop_only` are skipped: a phone visitor is intercepted
 * before the view renders and lands on the prompt page, so measuring them
 * would be measuring that one page eighty times.
 *
 * BASELINE, NOT BIG-BANG
 *
 * A gate that fails on 2,213 known rows is a gate somebody turns off. The
 * offenders listed in `mobile-baseline.json` are reported and not failed;
 * anything else fails. As waves of fixes land the baseline shrinks, and
 * when it reaches zero the job flips from reporting to blocking.
 *
 * A surface in the baseline that has *stopped* offending is reported too —
 * that is the line to delete, and leaving it there would let a real
 * regression hide behind a stale allowance.
 */

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const BASELINE_FILE = path.join( __dirname, 'mobile-baseline.json' );
const MIN_TAP = 48;

/**
 * List density on `native` surfaces (#4200). A data row taller than this is
 * a row a coach scrolls past one at a time; at 844px of viewport it leaves
 * fewer than seven on a screen before any chrome is counted.
 */
const MAX_ROW_HEIGHT = 120;

/**
 * How far down the page the first data row may start. Past this, filters
 * and page chrome have taken half the first screen and the list — the
 * reason the coach opened the page — begins at the fold.
 */
const MAX_FIRST_ROW_TOP = 400;

/**
 * How long a list may take to swap its loading row for real ones before the
 * density check gives up on it. The rows arrive over REST after first paint.
 */
const LIST_HYDRATION_TIMEOUT = 5000;

/**
 * How many surfaces may render no `.tt-dashboard` before the run counts as
 * blind rather than clean (#4195). For months every surface skipped this
 * way — `/?tt_view=` rendered the blog index because the dashboard page was
 * not the front page — and the gate stayed green because a skipped surface
 * cannot regress. A handful of slugs legitimately render outside the shell
 * for an admin; more than that means the routing broke again.
 */
const MAX_UNRENDERED = 5;

/**
 * The share of phone-reachable surfaces the walk must actually measure.
 * Capability gaps, switched-off modules and HTTP errors are all legitimate
 * skips, but if they add up to most of the list the run proves nothing.
 */
const MIN_MEASURED_SHARE = 0.6;

test.use( { storageState: 'tests/e2e/.auth/admin.json' } );

/**
 * Every slug a phone can reach, read out of the PHP manifest.
 *
 * Parsed rather than imported for the obvious reason — this is Node and
 * that is PHP. The manifest's shape is strict enough (one
 * `'slug' => [ 'class', 'reason' ],` per line) that a regex is honest
 * here, and #2812's gate fails the build if the file drifts from the
 * dispatcher, so the list cannot silently go stale.
 *
 * @returns {{ slug: string, cls: string }[]}
 */
function phoneReachableSurfaces() {
	const file = path.join( __dirname, '..', '..', 'config', 'mobile_surfaces.php' );
	const src = fs.readFileSync( file, 'utf8' );
	const out = [];

	const re = /^\s*'([a-z0-9][a-z0-9-]*)'\s*=>\s*\[\s*'(native|viewable|read_only|desktop_only)'/gm;
	let m;
	while ( ( m = re.exec( src ) ) !== null ) {
		if ( m[ 2 ] === 'desktop_only' ) continue;
		out.push( { slug: m[ 1 ], cls: m[ 2 ] } );
	}

	return out;
}

/** @returns {Record<string, string[]>} slug => list of allowed finding kinds */
function loadBaseline() {
	try {
		return JSON.parse( fs.readFileSync( BASELINE_FILE, 'utf8' ) ).surfaces || {};
	} catch ( e ) {
		return {};
	}
}

/**
 * WordPress's own admin bar declares `min-width: 600px`, which fakes
 * roughly 240px of overflow at 360 and 210 at 390 — on every surface, in
 * every run. Measuring it would mean every page fails for a reason that
 * has nothing to do with TalentTrack's CSS. It is hidden, not ignored,
 * because it also pushes layout down and changes what is above the fold.
 *
 * @param {import('@playwright/test').Page} page
 */
async function hideAdminBar( page ) {
	await page.addStyleTag( {
		content: '#wpadminbar { display: none !important; } html { margin-top: 0 !important; }',
	} );
}

/**
 * Measure one rendered surface.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} cls
 * @returns {Promise<{ kind: string, detail: string }[]>}
 */
async function measure( page, cls ) {
	return page.evaluate( ( { minTap, cls } ) => {
		/** @type {{ kind: string, detail: string }[]} */
		const findings = [];
		const doc = document.documentElement;

		if ( doc.scrollWidth > doc.clientWidth ) {
			// Name the widest offending element — "the page overflows" is
			// a fact you then have to go and diagnose by hand; the widest
			// box that sticks out is usually the answer itself.
			let worst = null;
			let worstRight = doc.clientWidth;
			document.querySelectorAll( '.tt-dashboard *' ).forEach( ( el ) => {
				const r = el.getBoundingClientRect();
				if ( r.width === 0 || r.height === 0 ) return;
				if ( r.right > worstRight ) {
					worstRight = r.right;
					worst = el;
				}
			} );

			const where = worst
				? `${ worst.tagName.toLowerCase() }.${ String( worst.className || '' ).trim().split( /\s+/ ).slice( 0, 3 ).join( '.' ) }`
				: 'unknown element';

			findings.push( {
				kind: 'overflow',
				detail: `scrollWidth ${ doc.scrollWidth } exceeds clientWidth ${ doc.clientWidth }; widest box is ${ where } reaching ${ Math.round( worstRight ) }px`,
			} );
		}

		const interactive = document.querySelectorAll(
			'.tt-dashboard a[href], .tt-dashboard button, .tt-dashboard input:not([type="hidden"]), .tt-dashboard select, .tt-dashboard textarea, .tt-dashboard [role="button"], .tt-dashboard summary'
		);

		/** @type {string[]} */
		const small = [];
		interactive.forEach( ( el ) => {
			const r = el.getBoundingClientRect();
			// Invisible or collapsed elements are not tap targets. A zero
			// box is a closed accordion's contents, not a 0px button.
			if ( r.width === 0 || r.height === 0 ) return;
			const style = window.getComputedStyle( el );
			if ( style.visibility === 'hidden' || style.display === 'none' ) return;
			if ( parseFloat( style.opacity || '1' ) === 0 ) return;

			if ( r.width < minTap || r.height < minTap ) {
				const label = ( el.textContent || el.getAttribute( 'aria-label' ) || el.tagName ).trim().slice( 0, 40 );
				small.push( `${ el.tagName.toLowerCase() } "${ label }" ${ Math.round( r.width ) }x${ Math.round( r.height ) }` );
			}
		} );

		if ( small.length ) {
			findings.push( {
				kind: 'tap-target',
				detail: `${ small.length } under ${ minTap }px: ${ small.slice( 0, 5 ).join( '; ' ) }${ small.length > 5 ? ' …' : '' }`,
			} );
		}

		// A table that outruns the viewport is only a defect where the
		// phone is the primary device. On a `viewable` surface a
		// horizontally scrolling table inside its own container is the
		// intended compromise, not a failure.
		if ( cls === 'native' ) {
			/** @type {string[]} */
			const wide = [];
			document.querySelectorAll( '.tt-dashboard table' ).forEach( ( t ) => {
				const r = t.getBoundingClientRect();
				if ( r.width > doc.clientWidth ) {
					wide.push( `${ Math.round( r.width ) }px wide` );
				}
			} );
			if ( wide.length ) {
				findings.push( {
					kind: 'wide-table',
					detail: `${ wide.length } table(s) wider than the ${ doc.clientWidth }px viewport: ${ wide.join( ', ' ) }`,
				} );
			}
		}

		return findings;
	}, { minTap: MIN_TAP, cls } );
}

/**
 * Measure list density on one rendered `native` surface (#4200).
 *
 * A list can fit the viewport, clear the 48px floor and still be unusable
 * on a phone: rows several screens tall, or the first row pushed under the
 * fold by filters and chrome. Neither shows up in `measure()`.
 *
 * `FrontendListTable` fills its body over REST after first paint, so this
 * waits for the server-rendered loading row to go before reading anything.
 * A list that is still loading when the wait runs out, or that came back
 * empty or errored, has no data rows and is not measured — an empty list
 * has no density to judge.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{ rows: number, findings: { kind: string, detail: string }[] }>}
 */
async function measureDensity( page ) {
	if ( ( await page.locator( '.tt-dashboard [data-tt-list-table="1"]' ).count() ) === 0 ) {
		return { rows: 0, findings: [] };
	}

	try {
		await page.waitForFunction(
			() => document.querySelectorAll( '.tt-dashboard [data-tt-list-table="1"] [data-tt-list-loading="1"]' ).length === 0,
			null,
			{ timeout: LIST_HYDRATION_TIMEOUT }
		);
	} catch ( e ) {
		// Still loading. Whatever rows other lists on the page have are
		// measured below; the stuck one contributes none.
	}

	return page.evaluate( ( { maxRow, maxFirstTop } ) => {
		/** @type {{ kind: string, detail: string }[]} */
		const findings = [];
		/** @type {string[]} */
		const tall = [];
		/** @type {string[]} */
		const low = [];
		let rows = 0;

		document.querySelectorAll( '.tt-dashboard [data-tt-list-table="1"]' ).forEach( ( list, index ) => {
			const body = list.querySelector( '[data-tt-list-body="1"]' );
			if ( ! body ) return;

			// Table rows and card-grid cards alike are direct children of
			// the body; the loading, empty and error placeholders are too,
			// and are not data.
			const data = Array.from( body.children ).filter( ( el ) => {
				if ( el.matches( '.tt-list-table-loading, .tt-list-table-empty, .tt-list-table-error' ) ) return false;
				const r = el.getBoundingClientRect();
				return r.width > 0 && r.height > 0;
			} );
			if ( ! data.length ) return;
			rows += data.length;

			const name = list.id ? `#${ list.id }` : `list ${ index + 1 }`;

			let tallest = 0;
			let over = 0;
			data.forEach( ( el ) => {
				const h = el.getBoundingClientRect().height;
				if ( h > maxRow ) over++;
				if ( h > tallest ) tallest = h;
			} );
			if ( over ) {
				tall.push( `${ name }: ${ over } of ${ data.length } row(s), tallest ${ Math.round( tallest ) }px` );
			}

			// Document position, not viewport position: the answer must
			// not depend on whether something scrolled the page first.
			const top = data[ 0 ].getBoundingClientRect().top + window.scrollY;
			if ( top > maxFirstTop ) {
				low.push( `${ name }: first row starts at ${ Math.round( top ) }px` );
			}
		} );

		if ( tall.length ) {
			findings.push( {
				kind: 'row-height',
				detail: `row(s) taller than ${ maxRow }px — ${ tall.join( '; ' ) }`,
			} );
		}
		if ( low.length ) {
			findings.push( {
				kind: 'first-row-low',
				detail: `first data row below ${ maxFirstTop }px — ${ low.join( '; ' ) }`,
			} );
		}

		return { rows, findings };
	}, { maxRow: MAX_ROW_HEIGHT, maxFirstTop: MAX_FIRST_ROW_TOP } );
}

const SURFACES = phoneReachableSurfaces();
const baseline = loadBaseline();

test.describe( 'mobile viewport at 390x844', () => {
	// 78-odd page loads. Generous but bounded; the issue's budget is five
	// minutes and a cold wp-env first paint is the slow part. The density
	// check adds a wait per native list surface, bounded by
	// LIST_HYDRATION_TIMEOUT, so the ceiling sits above the worst case.
	test.setTimeout( 8 * 60 * 1000 );

	test( 'no surface regresses beyond the recorded baseline', async ( { page } ) => {
		expect(
			SURFACES.length,
			'parsed no surfaces out of config/mobile_surfaces.php — the manifest shape changed and this spec is blind'
		).toBeGreaterThan( 50 );

		/** @type {Record<string, {kind: string, detail: string}[]>} */
		const found = {};
		/** @type {string[]} */
		const regressions = [];
		/** @type {string[]} */
		const skipped = [];
		/** @type {string[]} */
		const unrendered = [];
		/** @type {string[]} */
		const withRows = [];
		let measured = 0;

		for ( const { slug, cls } of SURFACES ) {
			const response = await page.goto( `/?tt_view=${ slug }`, {
				waitUntil: 'domcontentloaded',
			} );

			// A surface can legitimately be unreachable for this account:
			// a module switched off, a capability the admin lacks, or a
			// half-authenticated landing on the MFA prompt. None of those
			// are viewport defects, and failing on them would make the
			// gate a test of the seed data instead of the CSS.
			if ( response && response.status() >= 400 ) {
				skipped.push( `${ slug } (HTTP ${ response.status() })` );
				continue;
			}
			if ( page.url().includes( 'tt_view=mfa-prompt' ) ) {
				skipped.push( `${ slug } (redirected to the MFA prompt)` );
				continue;
			}
			if ( ( await page.locator( '.tt-dashboard' ).count() ) === 0 ) {
				skipped.push( `${ slug } (no .tt-dashboard rendered)` );
				unrendered.push( slug );
				continue;
			}

			await hideAdminBar( page );

			const findings = await measure( page, cls );
			measured++;

			// Density runs after the three checks above so that waiting for
			// the list to load cannot change what they see.
			if ( cls === 'native' ) {
				const density = await measureDensity( page );
				if ( density.rows ) {
					withRows.push( `${ slug } (${ density.rows })` );
				}
				findings.push( ...density.findings );
			}

			if ( ! findings.length ) continue;

			found[ slug ] = findings;

			const allowed = baseline[ slug ] || [];
			for ( const f of findings ) {
				if ( ! allowed.includes( f.kind ) ) {
					regressions.push( `${ slug } [${ cls }] ${ f.kind }: ${ f.detail }` );
				}
			}
		}

		// A baseline entry that no longer offends is the line to delete.
		// Left in place it is a permanent exemption for a surface that
		// earned its way out, and the next real regression hides behind it.
		/** @type {string[]} */
		const stale = [];
		for ( const [ slug, kinds ] of Object.entries( baseline ) ) {
			const still = ( found[ slug ] || [] ).map( ( f ) => f.kind );
			for ( const kind of kinds ) {
				if ( ! still.includes( kind ) ) {
					stale.push( `${ slug }: ${ kind }` );
				}
			}
		}

		if ( skipped.length ) {
			console.log( `\nSkipped ${ skipped.length } surface(s), not measurable for this account:\n  ${ skipped.join( '\n  ' ) }` );
		}

		if ( stale.length ) {
			console.log( `\n${ stale.length } baseline entry(ies) no longer offend — remove them:\n  ${ stale.join( '\n  ' ) }` );
		}

		// Emit the current state in the baseline's own shape, so adopting
		// it after a wave of fixes is a copy-paste rather than an exercise
		// in transcribing 80 lines of console output by hand.
		const shape = {};
		for ( const [ slug, findings ] of Object.entries( found ) ) {
			shape[ slug ] = findings.map( ( f ) => f.kind ).sort();
		}
		console.log(
			`\nCurrent offenders, in mobile-baseline.json shape:\n${ JSON.stringify( { surfaces: shape }, null, 2 ) }`
		);

		console.log( `\nMeasured ${ measured } of ${ SURFACES.length } phone-reachable surface(s).` );

		// Density can only be judged where a list actually has rows. Saying
		// which surfaces those were keeps "no density findings" honest: on
		// an install with no data it means nothing was looked at.
		console.log(
			`\nList density measured on ${ withRows.length } native surface(s) with data rows (row count):${ withRows.length ? `\n  ${ withRows.join( '\n  ' ) }` : ' none' }`
		);

		// Blindness first: a run that measured almost nothing has no
		// regressions to report, and passing on that is how this gate sat
		// green for months while measuring nothing (#4195).
		expect(
			unrendered.length,
			`${ unrendered.length } surface(s) rendered no .tt-dashboard — /?tt_view= is not reaching the dashboard; check that the dashboard page is the static front page (tests/e2e/setup/dashboard-front-page.php). Unrendered: ${ unrendered.join( ', ' ) }`
		).toBeLessThanOrEqual( MAX_UNRENDERED );
		expect(
			measured,
			`measured only ${ measured } of ${ SURFACES.length } surfaces — the walk is mostly skipping, which is a broken run, not a clean one`
		).toBeGreaterThanOrEqual( Math.ceil( SURFACES.length * MIN_MEASURED_SHARE ) );

		expect(
			regressions,
			`New mobile viewport defects on ${ regressions.length } surface(s) not in tests/e2e/mobile-baseline.json:\n  ${ regressions.join( '\n  ' ) }`
		).toEqual( [] );
	} );
} );
