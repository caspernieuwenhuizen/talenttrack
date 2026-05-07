// @ts-check
/**
 * Admin-flow helpers for #0076 e2e specs.
 *
 * Small, defensive utilities. Specs use them rather than hand-rolling
 * the same `page.goto` + form-fill + flash-assert dance every time.
 */

const { expect } = require( '@playwright/test' );

/**
 * Navigate to a wp-admin page by `?page=` slug (the pattern every TT
 * admin page uses via `Menu::register()`).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} slug
 */
async function gotoAdminPage( page, slug ) {
    await page.goto( `/wp-admin/admin.php?page=${ slug }` );
}

/**
 * Click the "Add New" / "Add" page-title-action link if present, else
 * navigate to the per-page `&action=new` URL fallback.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} slug
 */
async function gotoAddNew( page, slug ) {
    await page.goto( `/wp-admin/admin.php?page=${ slug }&action=new` );
}

/**
 * Build a unique-per-run name suffix so concurrent / repeated runs
 * don't collide on UNIQUE name constraints.
 *
 * @param {string} prefix
 */
function uniqueName( prefix = 'e2e' ) {
    return `${ prefix }-${ Date.now() }-${ Math.floor( Math.random() * 10000 ) }`;
}

/**
 * Assert that the page reaches the admin index after a successful save
 * (admin pages typically redirect with `&saved=1` or to the list).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} slug
 */
async function expectBackOnList( page, slug ) {
    await page.waitForURL( new RegExp( `page=${ slug }(?!&action=)` ), { timeout: 15000 } );
}

/**
 * Submit the standard wp-admin form and wait for the redirect.
 *
 * @param {import('@playwright/test').Page} page
 * @param {RegExp} expectedRedirectPattern
 */
async function submitAndWait( page, expectedRedirectPattern ) {
    await Promise.all( [
        page.waitForURL( expectedRedirectPattern, { timeout: 15000 } ),
        page.click( 'input[type="submit"], button[type="submit"]' ),
    ] );
}

module.exports = {
    gotoAdminPage,
    gotoAddNew,
    uniqueName,
    expectBackOnList,
    submitAndWait,
};
