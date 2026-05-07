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
		},
	],
} );
