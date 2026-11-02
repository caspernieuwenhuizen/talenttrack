// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright config for the TalentTrack end-to-end suite (#12).
 *
 * v1 scope: Chromium only, single worker, traces on retry. Once the
 * smoke flows are stable we expand to Firefox + WebKit + parallel
 * workers per the plan in `tests/e2e/README.md`.
 *
 * The base URL points at the local wp-env install on port 8889 by
 * default (the @wordpress/env default for the test instance). Override
 * via `BASE_URL` env var for CI / staging runs.
 */
/**
 * One phone project for the mobile gate at a given viewport.
 *
 * The three widths share every other setting, so they are built here
 * rather than copied three times: a difference between them would make one
 * width measure something the others do not.
 *
 * @param {string} name
 * @param {number} width
 * @param {number} height
 */
function phoneProject( name, width, height ) {
	return {
		name,
		// One long walk over ~78 surfaces. The suite-wide `retries: 2`
		// would triple its wall-clock on the failure that is its whole
		// purpose, and a viewport measurement is deterministic — a
		// retry cannot turn an overflowing box into a passing one.
		retries: 0,
		use: {
			browserName: 'chromium',

			// Spelled out rather than spread from `devices['iPhone 13']`.
			// The gate's meaning depends on these exact values, and a
			// Playwright upgrade that re-tunes a device descriptor
			// would silently move the goalposts of a regression gate.
			viewport: { width, height },
			deviceScaleFactor: 3,

			// `hasTouch` is load-bearing. The 48px floor sits behind
			// `@media (pointer: coarse)`, so a run without touch
			// measures desktop density and passes every tap-target
			// assertion in the spec for the wrong reason.
			hasTouch: true,
			isMobile: true,

			// `MobileDetector::isPhone()` reads the UA server-side to
			// decide what a phone gets, so the UA is what makes the
			// server render the phone path at all. Without it the run
			// measures the desktop render at a narrow width, which is
			// a different thing entirely.
			userAgent:
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) ' +
				'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
		},
		testMatch: '**/mobile-viewport.spec.js',
	};
}

module.exports = defineConfig( {
	testDir: './tests/e2e',

	// #0076 v1 — globalSetup runs once before the suite, logs in as
	// `admin / password`, and saves the storageState. Per-spec tests
	// reuse it via `test.use({ storageState: 'tests/e2e/.auth/admin.json' })`
	// to skip the login dance on every run.
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),

	// Each test should be self-contained — no shared state across files.
	fullyParallel: false,
	workers: 1,

	// CI: fail loud on accidental .only.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,

	reporter: [
		[ 'list' ],
		[ 'html', { open: 'never', outputFolder: 'playwright-report' } ],
	],

	use: {
		// wp-env's "tests" instance defaults to localhost:8889.
		baseURL: process.env.BASE_URL || 'http://localhost:8889',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
			testIgnore: '**/mobile-viewport.spec.js',
		},

		// #2813 — the mobile regression gate, at three widths (#4738).
		// 390x844 is the iPhone 13 / 14 / 15 viewport. 360 is the base
		// width `CLAUDE.md` §2 writes CSS for, and 320 is the narrowest
		// phone still in use: the player profile scrolled sideways there
		// (#4702) while the gate, measuring 390 only, stayed green.
		phoneProject( 'phone-320', 320, 844 ),
		phoneProject( 'phone-360', 360, 800 ),
		phoneProject( 'iphone-13', 390, 844 ),
	],
} );
