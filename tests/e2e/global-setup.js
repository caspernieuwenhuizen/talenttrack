// @ts-check
const { chromium } = require( '@playwright/test' );
const path = require( 'path' );
const fs = require( 'fs' );

/**
 * Playwright globalSetup (#0076 v1).
 *
 * Runs once before the suite starts. Logs in as the wp-env-seeded
 * `admin / password` and saves the storageState so per-spec tests can
 * reuse it via `use: { storageState: ... }` and skip the login dance
 * on every run. Cuts ~3-5 seconds per spec.
 *
 * Per the architecture decisions in the spec:
 *   - shared admin auth for v1; programmatic auth helper lands when
 *     the first non-admin persona test does
 *   - no demo-data seeding here yet — that's deferred to a follow-up
 *     when a spec actually needs more than the wp-env baseline
 *
 * The storage state goes in `tests/e2e/.auth/admin.json` (gitignored).
 */
module.exports = async ( config ) => {
    const baseURL = config?.use?.baseURL || process.env.BASE_URL || 'http://localhost:8889';
    const authDir = path.join( __dirname, '.auth' );
    const authFile = path.join( authDir, 'admin.json' );

    if ( ! fs.existsSync( authDir ) ) fs.mkdirSync( authDir, { recursive: true } );

    const browser = await chromium.launch();
    const context = await browser.newContext( { baseURL } );
    const page = await context.newPage();

    await page.goto( '/wp-login.php' );
    await page.fill( 'input[name="log"]', 'admin' );
    await page.fill( 'input[name="pwd"]', 'password' );
    await Promise.all( [
        page.waitForURL( /\/wp-admin/, { timeout: 30000 } ),
        page.click( 'input#wp-submit' ),
    ] );

    await context.storageState( { path: authFile } );
    await browser.close();
};
