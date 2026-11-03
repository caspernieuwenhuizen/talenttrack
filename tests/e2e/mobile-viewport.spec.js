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
 * All five read the page after its lists have loaded their rows (#4232).
 * "Visible" in check 2 means visible to a person: an element hidden by an
 * ancestor (the items of a closed menu) or clipped to a pixel (a visually
 * hidden file input) is not a tap target and is not measured. Neither is an
 * element with `pointer-events: none`, which a tap passes straight through
 * (#4237).
 *
 * WHICH SURFACES
 *
 * The slug list is read from `config/mobile_surfaces.php`, which #2812's
 * gate keeps in step with what the dispatcher actually routes. Surfaces
 * classified `desktop_only` are skipped: a phone visitor is intercepted
 * before the view renders and lands on the prompt page, so measuring them
 * would be measuring that one page eighty times.
 *
 * Record pages are walked too (`RECORD_SURFACES`): the spec makes the record
 * over REST, opens it by id and removes it afterwards (#4738).
 *
 * WHICH PERSONAS
 *
 * The admin walks every phone-reachable slug, the dashboard home and the
 * record pages. A head coach and a scout (#4812) walk their own dashboard
 * home and every phone-reachable surface it links to, because their
 * dashboard presets carry controls the admin never sees. Their accounts are
 * made over REST in `beforeAll` (`helpers/personas.js`), in Dutch, and
 * deleted afterwards. Each persona has its own baseline.
 *
 * WHICH WIDTHS
 *
 * The same walk runs once per phone project in `playwright.config.js`: 320,
 * 360 and 390px. Each width has its own block in the baseline.
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
const { uniqueName } = require( './helpers/admin' );
const { rest } = require( './helpers/match-prep' );
const { widestOffender } = require( './helpers/overflow' );
const { WALKED_PERSONAS, createPersona, deletePersona } = require( './helpers/personas' );
const { seedFixture, removeFixture } = require( './helpers/gate-fixture' );

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
 * walk measures the surface without them. The rows arrive over REST after
 * first paint.
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

/**
 * The baseline block for one viewport width (#4738).
 *
 * `mobile-baseline.json` keeps one block per width under `widths`, each
 * entry `{ kinds: [...], reason: '...' }`. A surface that overflows at 320
 * and not at 390 is a different allowance at each width, so one shared list
 * would hide a regression at whichever width it did not describe.
 *
 * The admin's blocks sit under the top-level `widths`; every other persona
 * the gate walks has its own under `personas.<key>.widths` (#4812), because
 * a coach and an admin see different controls on the same page.
 *
 * @param {number} width
 * @param {string} persona `admin`, or a key from WALKED_PERSONAS
 * @returns {Record<string, { kinds: string[], reason: string }>}
 */
function loadBaseline( width, persona ) {
	try {
		const file = JSON.parse( fs.readFileSync( BASELINE_FILE, 'utf8' ) );
		const widths = persona === ADMIN
			? file.widths || {}
			: ( ( file.personas || {} )[ persona ] || {} ).widths || {};
		return widths[ String( width ) ] || {};
	} catch ( e ) {
		return {};
	}
}

/** The persona key of the seeded admin account. */
const ADMIN = 'admin';

/**
 * Surfaces that are expected to redirect for the gate's account (#4780),
 * keyed by the slug the walk asks for. `to` is the `tt_view` they land on
 * ('' is the dashboard home). An expected redirect is reported and not
 * measured; any redirect not listed here fails the gate, because a page
 * measured under another page's key is a finding filed against the wrong
 * screen.
 *
 * @type {Record<string, { to: string, reason: string }>}
 */
const EXPECTED_REDIRECTS = {
	'mfa-prompt': {
		to: '',
		reason: 'no second-factor challenge is pending for the gate admin, so MfaChallengeHandler sends it to the dashboard home',
	},
};

/**
 * The persona dashboard home (#4780): the page every account lands on, with
 * no `tt_view` of its own.
 */
const HOME_SURFACE = { key: 'dashboard#home', cls: 'native', url: '/' };

/**
 * Record pages the walk opens by id (#4738). The slug list alone only
 * reaches lists and landing pages; a record such as the player profile is
 * where #4702's sideways scroll was, and the gate never saw it.
 *
 * Each record is made over REST before the walk and removed after it, so
 * the walk does not depend on another spec's leftovers or on seed data,
 * which the CI instance does not have. `key` is the record's name in the
 * baseline; `slug` is the surface whose mobile class it shares. Add a team
 * or an activity the same way.
 *
 * @type {{ key: string, slug: string, create: (page: import('@playwright/test').Page, created: string[]) => Promise<number|string>, url: (id: number) => string, dutch: { selector: string, text: string } }[]}
 */
const RECORD_SURFACES = [
	{
		key: 'players#record',
		slug: 'players',
		// A long name and every fact filled in, so the hero, the facts
		// strip and the KPI tiles carry the content that broke them at
		// 320 rather than a row of dashes.
		create: async ( page, created ) => {
			const res = await rest( page, 'POST', 'players', {
				first_name: 'Maximiliaan-Alexander',
				last_name: uniqueName( 'van Oldenbarnevelt-Wijnberghuizen' ),
				date_of_birth: '2009-03-31',
				date_joined: '2025-08-01',
				preferred_foot: 'both',
				status: 'active',
			} );
			const id = res.json && res.json.data ? Number( res.json.data.id ) : 0;
			if ( ! id ) return 'player create refused: HTTP ' + res.status;
			created.push( 'players/' + id );
			return id;
		},
		url: ( id ) => `/?tt_view=players&id=${ id }`,
		// The walk runs in Dutch (#4770). A label only the rendered
		// profile carries proves both that the page is the real profile,
		// not a "player not found" page, and that it rendered in Dutch.
		dutch: { selector: '.tt-player-facts__label', text: 'Voet' },
	},
];

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
 * Open every widget fold on the dashboard home (#4823).
 *
 * On a phone the dashboard closes its widget folds (`details.tt-pd-fold`,
 * #1375) once the page has loaded, and what is inside a closed fold is not
 * a tap target the checks count. The tables and their row links sit inside
 * those folds, so the home is measured as a user sees it after opening
 * each one: the fold's own summary is measured either way.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<number>} how many folds were opened
 */
async function openFolds( page ) {
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	return page.evaluate( () => {
		const folds = Array.from( document.querySelectorAll( '.tt-dashboard details.tt-pd-fold' ) );
		folds.forEach( ( d ) => { d.open = true; } );
		return folds.length;
	} );
}

/**
 * Wait for every `FrontendListTable` on the page to finish loading (#4232).
 *
 * The list fills its body over REST after first paint. Measuring before
 * that made the tap-target and overflow findings depend on how fast the
 * REST call came back: a row's links and buttons were counted on a quick
 * run and missed on a slow one. Every check reads the page after this.
 *
 * Bounded by LIST_HYDRATION_TIMEOUT. A list still loading when the wait
 * runs out is measured as it stands — its loading row has no targets and
 * no data rows.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<boolean>} whether the page has a list at all
 */
async function waitForListHydration( page ) {
	if ( ( await page.locator( '.tt-dashboard [data-tt-list-table="1"]' ).count() ) === 0 ) {
		return false;
	}

	try {
		await page.waitForFunction(
			() => document.querySelectorAll( '.tt-dashboard [data-tt-list-table="1"] [data-tt-list-loading="1"]' ).length === 0,
			null,
			{ timeout: LIST_HYDRATION_TIMEOUT }
		);
	} catch ( e ) {
		// Still loading. Whatever the page shows now is what gets measured.
	}

	return true;
}

/**
 * Measure one rendered surface. The caller waits for list hydration first.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} cls
 * @returns {Promise<{ kind: string, detail: string }[]>}
 */
async function measure( page, cls ) {
	// Name the widest offending element — "the page overflows" is a fact
	// you then have to go and diagnose by hand; the widest box that sticks
	// out is usually the answer itself. Boxes inside a horizontal scroll
	// strip are not candidates (#4767).
	const offender = await page.evaluate( widestOffender );

	return page.evaluate( ( { minTap, cls, offender } ) => {
		/** @type {{ kind: string, detail: string }[]} */
		const findings = [];
		const doc = document.documentElement;

		if ( doc.scrollWidth > doc.clientWidth ) {
			const where = offender
				? `${ offender.where } reaching ${ offender.right }px`
				: 'not found outside a horizontal scroll strip';

			findings.push( {
				kind: 'overflow',
				detail: `scrollWidth ${ doc.scrollWidth } exceeds clientWidth ${ doc.clientWidth }; widest box is ${ where }`,
			} );
		}

		const interactive = document.querySelectorAll(
			'.tt-dashboard a[href], .tt-dashboard button, .tt-dashboard input:not([type="hidden"]), .tt-dashboard select, .tt-dashboard textarea, .tt-dashboard [role="button"], .tt-dashboard summary'
		);

		/**
		 * Is the element inside the visually-hidden pattern itself: an
		 * ancestor of 1px or less on both axes with a deliberate clip or
		 * clip-path? A collapsed but unclipped box (a zero-height row, an
		 * overflow strip) does not count, so a target that sticks out of
		 * one is still measured.
		 *
		 * @param {Element} el
		 * @returns {boolean}
		 */
		const insideClippedBox = ( el ) => {
			for ( let box = el.parentElement; box && box !== document.body; box = box.parentElement ) {
				const r = box.getBoundingClientRect();
				if ( r.width > 1 || r.height > 1 ) continue;
				const cs = getComputedStyle( box );
				if ( cs.clipPath !== 'none' || cs.clip !== 'auto' ) return true;
			}
			return false;
		};

		/** @type {string[]} */
		const small = [];
		interactive.forEach( ( el ) => {
			const r = el.getBoundingClientRect();
			// Invisible or collapsed elements are not tap targets. A zero
			// box is a `display: none` element, not a 0px button. A box of
			// 1px or less is the visually-hidden pattern: an input clipped
			// to a pixel and driven by a visible button (#4232).
			if ( r.width <= 1 || r.height <= 1 ) return;
			// Asks about the element and its ancestors. Reading the
			// element's own computed style missed content hidden from
			// above — the items of a closed <details> menu keep a box and
			// their own `display`, and were reported as tap targets nobody
			// can tap (#4232).
			if ( ! el.checkVisibility( { opacityProperty: true, visibilityProperty: true, contentVisibilityAuto: true } ) ) return;
			// The same pattern one level up: an element inside a visually
			// hidden box. The stack-table primitive hides a table's header
			// row that way on a phone, clipped to a pixel so screen readers
			// keep the column headers, and the sort links in it keep their
			// own box, which passes checkVisibility(). Nobody can tap them.
			// The box is read live, so once focus is inside the header and
			// the primitive shows it (`thead:focus-within`), the links are
			// measured again.
			if ( insideClippedBox( el ) ) return;
			// An element that cannot receive a tap is not a tap target. In a
			// row-link list the row is the target and a link on its title
			// line lets the tap fall through to it (#4224); the gate counted
			// those links all the same (#4237). `pointer-events` inherits,
			// so the computed value also covers an inert ancestor.
			if ( getComputedStyle( el ).pointerEvents === 'none' ) return;

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
					// #4780 — name the table, so the finding can be traced
					// without a second run: its classes, its first two
					// header texts and the nearest prefixed container.
					const classes = String( t.className || '' ).trim().split( /\s+/ ).filter( Boolean ).join( '.' );
					const heads = Array.from( t.querySelectorAll( 'th' ) )
						.slice( 0, 2 )
						.map( ( th ) => ( th.textContent || '' ).replace( /\s+/g, ' ' ).trim() )
						.filter( Boolean );
					let box = t.parentElement;
					while ( box && ! /(^|\s)tt-/.test( String( box.className || '' ) ) ) box = box.parentElement;
					const inside = box ? ` in ${ box.tagName.toLowerCase() }.${ String( box.className ).trim().split( /\s+/ )[ 0 ] }` : '';
					wide.push( `table${ classes ? '.' + classes : '' } (${ heads.join( ', ' ) || 'no headers' })${ inside } ${ Math.round( r.width ) }px wide` );
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
	}, { minTap: MIN_TAP, cls, offender } );
}

/**
 * Measure list density on one rendered `native` surface (#4200).
 *
 * A list can fit the viewport, clear the 48px floor and still be unusable
 * on a phone: rows several screens tall, or the first row pushed under the
 * fold by filters and chrome. Neither shows up in `measure()`.
 *
 * `FrontendListTable` fills its body over REST after first paint, so this
 * waits for the server-rendered loading row to go before reading anything
 * (`waitForListHydration()`, the same wait the caller ran before
 * `measure()`; here it returns at once). A list that is still loading when
 * the wait runs out, or that came back empty or errored, has no data rows
 * and is not measured — an empty list has no density to judge.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{ rows: number, findings: { kind: string, detail: string }[] }>}
 */
async function measureDensity( page ) {
	if ( ! ( await waitForListHydration( page ) ) ) {
		return { rows: 0, findings: [] };
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

/** @type {Record<string, number|string>} record key => id, or why it could not be made */
const recordIds = {};
/** @type {string[]} REST paths of the records made for this run */
const created = [];
/** @type {Record<string, { id: number, state?: string, error?: string }>} persona key => account; `state` only when it can be walked with */
const accounts = {};
/** @type {Awaited<ReturnType<typeof seedFixture>>|null} the rows made for the persona dashboards (#4823) */
let fixture = null;

/**
 * A logged-in desktop page for the REST calls around the walk. The calls
 * need the dashboard's nonce (`window.TT`), which any dashboard page
 * carries; the phone settings play no part in them.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {import('@playwright/test').TestInfo} testInfo
 */
async function restPage( browser, testInfo ) {
	const context = await browser.newContext( {
		storageState: 'tests/e2e/.auth/admin.json',
		baseURL: testInfo.project.use.baseURL,
	} );
	const page = await context.newPage();
	await page.goto( '/?tt_view=players' );
	await page.waitForLoadState( 'load', { timeout: 30000 } );
	const ready = await page.evaluate( () => !! ( window.TT && window.TT.rest_url && window.TT.rest_nonce ) );
	return { context, page, ready };
}

test.describe( 'mobile viewport gate', () => {
	// 78-odd page loads. Generous but bounded; the issue's budget is five
	// minutes and a cold wp-env first paint is the slow part. Every surface
	// with a list adds a wait for its rows, bounded by
	// LIST_HYDRATION_TIMEOUT, so the ceiling sits above the worst case.
	test.setTimeout( 8 * 60 * 1000 );

	test.beforeAll( async ( { browser }, testInfo ) => {
		const { context, page, ready } = await restPage( browser, testInfo );
		for ( const record of RECORD_SURFACES ) {
			recordIds[ record.key ] = ready
				? await record.create( page, created )
				: 'the dashboard shell carried no REST config';
		}
		await context.close();

		// #4812 — the accounts the persona walks sign in with.
		for ( const persona of WALKED_PERSONAS ) {
			accounts[ persona.key ] = await createPersona( browser, testInfo.project.use.baseURL, persona, testInfo.project.name );
		}

		// #4823 — rows for the persona dashboards' tables, so their row
		// links are measured. Needs both persona accounts.
		const coach = accounts.head_coach;
		const scout = accounts.scout;
		fixture = coach && coach.state && scout && scout.state
			? await seedFixture( browser, testInfo.project.use.baseURL, {
				coachUserId: coach.id,
				coachState: coach.state,
				scoutState: scout.state,
			} )
			: null;
	} );

	test.afterAll( async ( { browser }, testInfo ) => {
		// #4823 — the fixture goes first, while the accounts it hangs off
		// still exist. Everything REST can remove must be gone; the staff
		// record and the prospect, which it cannot, must carry the prefix.
		// Asserted at the end, so a leftover never skips the account deletes.
		/** @type {string[]} */
		let left = [];
		if ( fixture ) {
			left = await removeFixture( browser, testInfo.project.use.baseURL, fixture );
			fixture = null;
		}

		// Any account that was made is deleted, also one that could not sign in.
		for ( const [ key, account ] of Object.entries( accounts ) ) {
			if ( account.id > 0 ) await deletePersona( browser, testInfo.project.use.baseURL, account.id );
			delete accounts[ key ];
		}
		if ( created.length ) {
			const { context, page } = await restPage( browser, testInfo );
			for ( const restPath of created.slice().reverse() ) {
				await rest( page, 'DELETE', restPath, {} );
			}
			created.length = 0;
			await context.close();
		}

		expect( left, `the gate's fixture was not cleaned up:\n  ${ left.join( '\n  ' ) }` ).toEqual( [] );
	} );

	/**
	 * Walk `surfaces`, the dashboard home and (for the admin) the record
	 * pages as one persona, and fail on anything the persona's baseline
	 * does not allow.
	 *
	 * @param {import('@playwright/test').Page} page  a phone page signed in as the persona
	 * @param {string} persona                        `admin` or a WALKED_PERSONAS key
	 * @param {{ slug: string, cls: string }[]} surfaces
	 * @param {boolean} withRecords                   walk RECORD_SURFACES too
	 */
	const walk = async ( page, persona, surfaces, withRecords ) => {
		const viewport = page.viewportSize();
		const width = viewport ? viewport.width : 0;
		const baseline = loadBaseline( width, persona );
		const who = `${ width }px as ${ persona }`;

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
		/** @type {string[]} keys that took a redirect listed in EXPECTED_REDIRECTS */
		const redirected = [];

		/**
		 * Load one surface and measure it. Returns why it was skipped, or
		 * an empty string when it was measured.
		 *
		 * @param {string} key   the name in the baseline
		 * @param {string} cls   the surface's mobile class
		 * @param {string} url
		 * @param {string} view  the `tt_view` the page must still show
		 *                       when it has loaded ('' for the home)
		 */
		const visit = async ( key, cls, url, view ) => {
			const response = await page.goto( url, {
				waitUntil: 'domcontentloaded',
			} );

			// A surface can legitimately be unreachable for this account:
			// a module switched off or a capability the admin lacks. Neither
			// is a viewport defect, and failing on them would make the gate
			// a test of the seed data instead of the CSS.
			if ( response && response.status() >= 400 ) {
				return `HTTP ${ response.status() }`;
			}

			// #4780 — a surface that redirected is never measured under the
			// key it asked for. The `mfa-prompt` walk used to land on the
			// dashboard home and book that page's wide table as the
			// prompt's. An expected redirect is a skip; any other fails.
			const landed = new URL( page.url() ).searchParams.get( 'tt_view' ) || '';
			if ( landed !== view ) {
				const where = landed ? `?tt_view=${ landed }` : 'the dashboard home';
				const expected = EXPECTED_REDIRECTS[ key ];
				if ( expected && expected.to === landed ) {
					redirected.push( key );
					return `redirected to ${ where }, as expected: ${ expected.reason }`;
				}
				regressions.push( `${ key }: redirected to ${ where } unexpectedly, so it was not measured. List it in EXPECTED_REDIRECTS with a reason, or fix the redirect.` );
				return `redirected to ${ where }, unexpectedly`;
			}
			if ( ( await page.locator( '.tt-dashboard' ).count() ) === 0 ) {
				unrendered.push( key );
				return 'no .tt-dashboard rendered';
			}

			await hideAdminBar( page );
			if ( key === HOME_SURFACE.key ) await openFolds( page );

			// Every check reads the same settled page: a list's rows carry
			// links and buttons of their own, and whether they had arrived
			// used to decide what the tap-target check saw (#4232).
			await waitForListHydration( page );

			const findings = await measure( page, cls );
			measured++;

			if ( cls === 'native' ) {
				const density = await measureDensity( page );
				if ( density.rows ) {
					withRows.push( `${ key } (${ density.rows })` );
				}
				findings.push( ...density.findings );
			}

			if ( ! findings.length ) return '';

			found[ key ] = findings;

			const allowed = baseline[ key ] ? baseline[ key ].kinds : [];
			for ( const f of findings ) {
				if ( ! allowed.includes( f.kind ) ) {
					regressions.push( `${ key } [${ cls }] ${ f.kind }: ${ f.detail }` );
				}
			}
			return '';
		};

		for ( const { slug, cls } of surfaces ) {
			const why = await visit( slug, cls, `/?tt_view=${ slug }`, slug );
			if ( why ) skipped.push( `${ slug } (${ why })` );
		}

		// #4780 — the persona dashboard home, the page everyone lands on.
		// It has no slug, so the slug walk never measured it; it was only
		// ever seen by accident, behind a redirect. Like a record page it is
		// never a legitimate skip.
		{
			const why = await visit( HOME_SURFACE.key, HOME_SURFACE.cls, HOME_SURFACE.url, '' );
			if ( why ) regressions.push( `${ HOME_SURFACE.key }: not measured, ${ why }` );
		}

		// A record page is never a legitimate skip. The spec made the
		// record itself, so a record it cannot open means the walk is blind
		// to it, which is the gap this was added to close.
		for ( const record of withRecords ? RECORD_SURFACES : [] ) {
			const id = recordIds[ record.key ];
			const surface = SURFACES.find( ( s ) => s.slug === record.slug );
			if ( typeof id !== 'number' ) {
				regressions.push( `${ record.key }: not walked, ${ id || 'no record was made' }` );
				continue;
			}
			if ( ! surface ) {
				regressions.push( `${ record.key }: '${ record.slug }' is not a phone-reachable surface in config/mobile_surfaces.php` );
				continue;
			}
			const why = await visit( record.key, surface.cls, record.url( id ), record.slug );
			if ( why ) {
				regressions.push( `${ record.key }: not measured, ${ why }` );
				continue;
			}
			const shown = ( await page.locator( record.dutch.selector ).allTextContents() ).map( ( t ) => t.trim() );
			if ( ! shown.includes( record.dutch.text ) ) {
				regressions.push(
					`${ record.key }: "${ record.dutch.text }" not found in ${ record.dutch.selector } (saw ${ JSON.stringify( shown ) }). ` +
					'The mobile gate walks in Dutch: compile the nl_NL .mo and set the site locale first (tests/e2e/README.md).'
				);
			}
		}

		// A baseline entry that no longer offends is the line to delete.
		// Left in place it is a permanent exemption for a surface that
		// earned its way out, and the next real regression hides behind it.
		/** @type {string[]} */
		const stale = [];
		/** @type {string[]} */
		const unexplained = [];
		for ( const [ key, entry ] of Object.entries( baseline ) ) {
			const still = ( found[ key ] || [] ).map( ( f ) => f.kind );
			for ( const kind of entry.kinds || [] ) {
				if ( ! still.includes( kind ) ) {
					stale.push( `${ key }: ${ kind }` );
				}
			}
			// An allowance nobody can explain is one nobody can argue
			// with, so every entry carries its reason (#4738).
			if ( ! entry.reason || ! String( entry.reason ).trim() ) {
				unexplained.push( key );
			}
		}

		if ( skipped.length ) {
			console.log( `\n[${ who }] Skipped ${ skipped.length } surface(s), not measurable for this account:\n  ${ skipped.join( '\n  ' ) }` );
		}

		// The same for an expected redirect that no longer happens: left in
		// place it would quietly excuse the surface if it ever redirected
		// again, and the page it lands on would go unmeasured. Only the
		// admin walk visits every slug, so only it can tell.
		if ( persona === ADMIN ) {
			const staleRedirects = Object.keys( EXPECTED_REDIRECTS ).filter( ( key ) => ! redirected.includes( key ) );
			if ( staleRedirects.length ) {
				console.log( `\n[${ who }] ${ staleRedirects.length } EXPECTED_REDIRECTS entry(ies) no longer redirect — remove them:\n  ${ staleRedirects.join( '\n  ' ) }` );
			}
		}

		if ( stale.length ) {
			console.log( `\n[${ who }] ${ stale.length } baseline entry(ies) no longer offend — remove them:\n  ${ stale.join( '\n  ' ) }` );
		}

		// Emit the current state in the baseline's own shape, so adopting
		// it after a wave of fixes is a copy-paste rather than an exercise
		// in transcribing 80 lines of console output by hand.
		// A reason already written for an entry is carried over; a new
		// entry gets an empty one, which the spec refuses until somebody
		// fills it in from the detail lines below.
		const shape = {};
		for ( const [ key, findings ] of Object.entries( found ) ) {
			shape[ key ] = {
				kinds: findings.map( ( f ) => f.kind ).sort(),
				reason: baseline[ key ] ? baseline[ key ].reason : '',
			};
		}
		const block = { widths: { [ String( width ) ]: shape } };
		console.log(
			`\n[${ who }] Current offenders, in mobile-baseline.json shape:\n${ JSON.stringify( persona === ADMIN ? block : { personas: { [ persona ]: block } }, null, 2 ) }`
		);

		// What each finding is, baselined or not. A baseline entry only
		// says a kind is allowed on a surface; this says which element
		// earns it, so an entry can be argued with rather than inherited
		// (#4237).
		/** @type {string[]} */
		const details = [];
		for ( const [ slug, findings ] of Object.entries( found ) ) {
			for ( const f of findings ) {
				details.push( `${ slug } ${ f.kind }: ${ f.detail }` );
			}
		}
		if ( details.length ) {
			console.log( `\n[${ who }] Findings in detail:\n  ${ details.join( '\n  ' ) }` );
		}

		// The walk visits every slug it was given, every record page and the
		// dashboard home. Counting the home in the measured total but not in
		// the visited one printed "94 of 94" on a run that skipped mfa-prompt.
		const records = withRecords ? RECORD_SURFACES.length : 0;
		const visited = surfaces.length + records + 1;
		console.log(
			`\n[${ who }] Measured ${ measured } of ${ visited } surface(s) ` +
			`(${ surfaces.length } slugs, ${ records } record page(s), the dashboard home); ` +
			`${ skipped.length } skipped, ${ redirected.length } of them by an expected redirect.`
		);

		// Density can only be judged where a list actually has rows. Saying
		// which surfaces those were keeps "no density findings" honest: on
		// an install with no data it means nothing was looked at.
		console.log(
			`\n[${ who }] List density measured on ${ withRows.length } native surface(s) with data rows (row count):${ withRows.length ? `\n  ${ withRows.join( '\n  ' ) }` : ' none' }`
		);

		// Blindness first: a run that measured almost nothing has no
		// regressions to report, and passing on that is how this gate sat
		// green for months while measuring nothing (#4195).
		expect(
			unrendered.length,
			`[${ who }] ${ unrendered.length } surface(s) rendered no .tt-dashboard — /?tt_view= is not reaching the dashboard; check that the dashboard page is the static front page (tests/e2e/setup/dashboard-front-page.php). Unrendered: ${ unrendered.join( ', ' ) }`
		).toBeLessThanOrEqual( MAX_UNRENDERED );
		expect(
			measured,
			`[${ who }] measured only ${ measured } of ${ surfaces.length } surfaces — the walk is mostly skipping, which is a broken run, not a clean one`
		).toBeGreaterThanOrEqual( Math.ceil( surfaces.length * MIN_MEASURED_SHARE ) );

		expect(
			unexplained,
			`[${ who }] ${ unexplained.length } baseline entry(ies) have no reason: ${ unexplained.join( ', ' ) }`
		).toEqual( [] );

		expect(
			regressions,
			`[${ who }] New mobile viewport defects on ${ regressions.length } surface(s) not in tests/e2e/mobile-baseline.json:\n  ${ regressions.join( '\n  ' ) }`
		).toEqual( [] );
	};

	test( 'no surface regresses beyond the recorded baseline', async ( { page } ) => {
		expect(
			SURFACES.length,
			'parsed no surfaces out of config/mobile_surfaces.php — the manifest shape changed and this spec is blind'
		).toBeGreaterThan( 50 );

		await walk( page, ADMIN, SURFACES, true );
	} );

	// #4812 — the same walk as a head coach and as a scout. Each sees
	// dashboard presets and controls the admin never does: the coach and
	// scout widgets with their row links, for one.
	for ( const persona of WALKED_PERSONAS ) {
		test( `no surface regresses beyond the recorded baseline as ${ persona.key }`, async ( { browser }, testInfo ) => {
			const account = accounts[ persona.key ];
			const state = account && account.state;
			expect( state, `[${ persona.key }] no account to walk with: ${ account ? account.error : 'none made' }` ).toBeTruthy();
			if ( ! state ) return;

			const context = await browser.newContext( phoneContext( testInfo, state ) );
			const page = await context.newPage();
			try {
				const surfaces = await surfacesOffered( page );

				// The baseline is keyed by persona, so the walk has to be
				// the persona it says it is. A teamless `tt_coach` that
				// started landing as an assistant coach would otherwise be
				// measured against the head coach's allowances.
				expect(
					await page.locator( '.tt-pd-landing' ).first().getAttribute( 'data-tt-pd-persona' ),
					`[${ persona.key }] the account lands on another persona's dashboard`
				).toBe( persona.key );

				// The walk is the set of surfaces the home links to, so
				// fewer links mean a smaller walk. A floor keeps a lost tile
				// or a renamed nav from shrinking it silently (#4195).
				console.log( `\n[${ persona.key }] Walking ${ surfaces.length } surface(s) its home links to: ${ surfaces.map( ( s ) => s.slug ).join( ', ' ) || 'none' }` );
				expect(
					surfaces.length,
					`[${ persona.key }] its home links to only ${ surfaces.length } phone-reachable surface(s), fewer than ${ persona.minSurfaces }: the walk is shrinking`
				).toBeGreaterThanOrEqual( persona.minSurfaces );

				// #4823 — the dashboard tables have rows, so the row links
				// the walk measures on the home are real ones, not nothing.
				expect( fixture, `[${ persona.key }] the dashboard fixture was not made` ).not.toBeNull();
				const isCoach = persona.key === 'head_coach';
				const fixtureErrors = fixture ? ( isCoach ? fixture.errors : fixture.scoutErrors ) : [];
				expect( fixtureErrors, `[${ persona.key }] the dashboard fixture is incomplete` ).toEqual( [] );
				if ( isCoach ) {
					expect( fixture && fixture.featureOn, 'behaviour_rating is switched on' ).toBe( true );
					expect( fixture && fixture.coachMayRate, 'the coach holds tt_rate_player_behaviour (behaviour-ratings answered 400, not 403)' ).toBe( true );
				}
				await page.goto( '/', { waitUntil: 'domcontentloaded' } );
				const rowLinks = await page.locator( '.tt-dashboard .tt-pd-row-link' ).count();
				console.log( `\n[${ persona.key }] ${ rowLinks } row link(s) on the dashboard home` );
				expect( rowLinks, `[${ persona.key }] the dashboard home shows no .tt-pd-row-link, so the walk is blind to them` ).toBeGreaterThan( 0 );

				await walk( page, persona.key, surfaces, false );
			} finally {
				await context.close();
			}
		} );
	}

	// #4812 — the check the coach walk relies on, proved on the coach's own
	// dashboard: a link 30px tall planted in the dashboard preset is a
	// tap-target finding, under the coarse pointer the phone projects run.
	test( 'a 30px-tall link in the coach dashboard preset is a tap-target finding', async ( { browser }, testInfo ) => {
		const account = accounts.head_coach;
		const state = account && account.state;
		expect( state, `no head coach account: ${ account ? account.error : 'none made' }` ).toBeTruthy();
		if ( ! state ) return;

		const context = await browser.newContext( phoneContext( testInfo, state ) );
		const page = await context.newPage();
		try {
			await page.goto( '/', { waitUntil: 'domcontentloaded' } );
			await hideAdminBar( page );
			expect(
				await page.locator( '.tt-dashboard .tt-pd-landing' ).count(),
				'the head coach lands on the persona dashboard'
			).toBeGreaterThan( 0 );

			// The detail names the first five small targets only, so the
			// proof is the count going up by one, not the name showing.
			const smallCount = ( findings ) => {
				const tap = findings.find( ( f ) => f.kind === 'tap-target' );
				return tap ? Number( ( /^(\d+) under/.exec( tap.detail ) || [ '', '0' ] )[ 1 ] ) : 0;
			};
			const before = smallCount( await measure( page, 'native' ) );

			await page.evaluate( () => {
				const host = document.querySelector( '.tt-dashboard .tt-pd-widget' ) || document.querySelector( '.tt-dashboard .tt-pd-landing' );
				const a = document.createElement( 'a' );
				a.href = '#planted';
				a.textContent = 'Planted';
				// Inline !important outranks the coarse-pointer floor in the
				// stylesheets, which is what makes this a real 30px target.
				a.style.cssText = 'display:inline-block !important; width:160px !important; height:30px !important; min-height:0 !important; max-height:30px !important; padding:0 !important; line-height:30px !important;';
				if ( host ) host.appendChild( a );
			} );

			expect( await page.locator( 'a[href="#planted"]' ).count(), 'the link was planted inside .tt-dashboard' ).toBe( 1 );
			const after = await measure( page, 'native' );
			expect( after.map( ( f ) => f.kind ), 'a tap-target finding' ).toContain( 'tap-target' );
			expect( smallCount( after ), 'the planted 30px link is counted' ).toBe( before + 1 );
		} finally {
			await context.close();
		}
	} );

	// #4823 — the row links the fixture renders, measured against #4817's
	// floor. #4819 added it to persona-dashboard.css; since #4830 the
	// coarse-pointer half sits next to the record-link floor in public.css.
	// Without it a row link is its line of text, 21px tall. This takes the
	// floor away on the page and requires the row links to come back as a
	// tap-target finding the persona's home baseline does not allow, so
	// reverting the floor fails the gate. The baseline is per kind, so this
	// holds only while no persona's home allows a tap-target finding (#4865
	// brought the scout's last two up to the floor).
	for ( const persona of WALKED_PERSONAS ) {
		test( `without the row-link rule, the ${ persona.key } dashboard's row links are tap-target findings`, async ( { browser }, testInfo ) => {
			const account = accounts[ persona.key ];
			const state = account && account.state;
			expect( state, `[${ persona.key }] no account to walk with` ).toBeTruthy();
			if ( ! state ) return;

			const context = await browser.newContext( phoneContext( testInfo, state ) );
			const page = await context.newPage();
			try {
				await page.goto( '/', { waitUntil: 'domcontentloaded' } );
				await hideAdminBar( page );
				await openFolds( page );
				const links = await page.locator( '.tt-dashboard .tt-pd-row-link' ).count();
				expect( links, `[${ persona.key }] no row links to measure` ).toBeGreaterThan( 0 );

				const tapCount = ( findings ) => {
					const tap = findings.find( ( f ) => f.kind === 'tap-target' );
					return tap ? Number( ( /^(\d+) under/.exec( tap.detail ) || [ '', '0' ] )[ 1 ] ) : 0;
				};
				const sizes = () => page.$$eval( '.tt-dashboard .tt-pd-row-link', ( els ) => els.map( ( el ) => {
					const r = el.getBoundingClientRect();
					return `${ Math.round( r.width ) }x${ Math.round( r.height ) }`;
				} ) );

				const kept = await sizes();
				const before = tapCount( await measure( page, 'native' ) );
				expect(
					kept.every( ( s ) => s.split( 'x' ).every( ( n ) => Number( n ) >= 48 ) ),
					`[${ persona.key }] with the floor in place every row link is at least 48x48: ${ kept.join( ', ' ) }`
				).toBe( true );

				// The page as a revert leaves it: no rule for the class, so
				// the link is its line of text.
				await page.addStyleTag( {
					content: '.tt-pd-row-link { display: inline !important; min-height: 0 !important; min-width: 0 !important; justify-content: normal !important; }',
				} );
				const reverted = await sizes();
				const findings = await measure( page, 'native' );
				const allowed = ( loadBaseline( page.viewportSize().width, persona.key )[ HOME_SURFACE.key ] || { kinds: [] } ).kinds;
				console.log( `\n[${ persona.key }] row links ${ kept.join( ', ' ) } with the floor, ${ reverted.join( ', ' ) } without; tap-target findings ${ before } then ${ tapCount( findings ) }` );
				expect( tapCount( findings ), `[${ persona.key }] every reverted row link is counted as a tap-target finding` ).toBe( before + links );
				expect( allowed, `[${ persona.key }] the baseline allows tap-target findings on the dashboard home, so a revert would pass` ).not.toContain( 'tap-target' );
			} finally {
				await context.close();
			}
		} );
	}

	// #4864 — the walk clears the admin's flash queue before it starts, so it
	// never meets a flash. This queues one (a new team with no head coach
	// gets TeamStaffPrompt's warning), opens the dashboard and measures the
	// close control the way the walk measures any tap target.
	test( 'a flash message close control is a full tap target', async ( { browser }, testInfo ) => {
		const context = await browser.newContext( phoneContext( testInfo, 'tests/e2e/.auth/admin.json' ) );
		const page = await context.newPage();
		let teamId = 0;
		try {
			await page.goto( '/', { waitUntil: 'load' } );
			const team = await rest( page, 'POST', 'teams', { name: uniqueName( 'e2e-flash' ) } );
			teamId = team.json && team.json.data && team.json.data.id ? Number( team.json.data.id ) : 0;
			expect( teamId, `team refused: HTTP ${ team.status }` ).toBeGreaterThan( 0 );

			await page.goto( '/', { waitUntil: 'domcontentloaded' } );
			await hideAdminBar( page );
			const close = page.locator( '.tt-dashboard .tt-flash .tt-flash-dismiss' ).first();
			await expect( close, 'the queued flash renders with its close control' ).toBeVisible();
			expect( await close.getAttribute( 'aria-label' ), 'the close control has a name' ).toBeTruthy();
			expect( await close.getAttribute( 'style' ), 'the close control carries no inline style' ).toBeNull();

			const box = await close.boundingBox();
			const size = box ? `${ Math.round( box.width ) }x${ Math.round( box.height ) }` : 'none';
			console.log( `\n[${ page.viewportSize().width }px] flash close control ${ size }` );
			expect( box && box.width >= 48 && box.height >= 48, `the close control is at least 48x48 under a finger: ${ size }` ).toBe( true );

			const findings = await measure( page, 'native' );
			const tap = findings.find( ( f ) => f.kind === 'tap-target' );
			expect( tap ? tap.detail : '', 'the tap-target check does not count the close control' ).not.toContain( '"×"' );
		} finally {
			if ( teamId ) {
				await rest( page, 'DELETE', `teams/${ teamId }`, {} );
				await rest( page, 'DELETE', `teams/${ teamId }/permanent`, {} );
			}
			await context.close();
		}
	} );
} );

/**
 * The phone context options of the running project, for a context the spec
 * opens itself under another account. Picked key by key rather than spread
 * from the project, which also carries runner options a context refuses.
 *
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {string} storageState
 */
function phoneContext( testInfo, storageState ) {
	const use = testInfo.project.use;
	return {
		storageState,
		baseURL: use.baseURL,
		viewport: use.viewport,
		deviceScaleFactor: use.deviceScaleFactor,
		hasTouch: use.hasTouch,
		isMobile: use.isMobile,
		userAgent: use.userAgent,
	};
}

/**
 * The surfaces a persona's own dashboard offers (#4812): every `?tt_view=`
 * its home links to, in the dashboard or the shell's navigation, that
 * `config/mobile_surfaces.php` lists as phone-reachable. Walking the whole
 * manifest as a coach would measure dozens of "not allowed" notices; this is
 * what the coach can actually reach from where they land.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<{ slug: string, cls: string }[]>}
 */
async function surfacesOffered( page ) {
	await page.goto( '/', { waitUntil: 'domcontentloaded' } );
	const linked = await page.$$eval( '.tt-dashboard a[href], .tt-shell-nav a[href]', ( links ) => links.map( ( a ) => {
		try {
			const url = new URL( a.getAttribute( 'href' ) || '', window.location.href );
			if ( url.origin !== window.location.origin || url.searchParams.has( 'id' ) ) return '';
			return url.searchParams.get( 'tt_view' ) || '';
		} catch ( e ) {
			return '';
		}
	} ) );
	const wanted = new Set( linked.filter( Boolean ) );
	return SURFACES.filter( ( s ) => wanted.has( s.slug ) );
}

