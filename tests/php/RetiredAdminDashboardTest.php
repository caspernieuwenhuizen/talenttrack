<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Admin\AdminMenuRegistry;
use TT\Shared\CoreSurfaceRegistration;

/**
 * #2979 — the wp-admin dashboard is retired.
 *
 * It was a second dashboard: a tile grid mirroring the menu, plus five stat
 * cards carrying a weekly delta. The tile half was superseded by the
 * frontend root; the decision on the issue is that the deltas are not
 * ported first, they simply stop existing.
 *
 * Two things are worth pinning rather than trusting to a deleted method.
 * The menu entry must actually be gone — a registration that survives is
 * how a retired page quietly comes back. And `tt-account` must be
 * untouched: it was in the original scope, its premise turned out to be
 * untrue (there is no frontend account surface), and it carries MFA
 * enrolment plus six live redirect targets.
 */
final class RetiredAdminDashboardTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        // Boot normally registers these. Seed only when nothing has —
        // clearing and re-registering would drop the module-registered
        // entries that sibling tests read.
        if ( AdminMenuRegistry::allEntries() === [] ) {
            CoreSurfaceRegistration::register();
        }
    }

    public function test_the_dashboard_submenu_is_gone(): void {
        $slugs = array_column( AdminMenuRegistry::allEntries(), 'slug' );

        $this->assertNotContains(
            'tt-dashboard',
            $slugs,
            'The wp-admin dashboard is retired. A surviving registration is how '
            . 'a retired page comes back without anyone deciding it should.'
        );
    }

    /**
     * Out of scope, deliberately. There is no frontend account surface to
     * redirect to, the page carries MFA enrolment and the operator control
     * that disables another user's MFA, and six call sites redirect to it
     * with a message key. Porting it is #3134, not this.
     */
    public function test_the_account_page_is_untouched(): void {
        $slugs = array_column( AdminMenuRegistry::allEntries(), 'slug' );

        $this->assertContains( 'tt-account', $slugs );
    }

    /**
     * The recovery copies #2874 confirmed on purpose. Each of these has a
     * frontend equivalent and keeps its wp-admin page as the way back in
     * when the frontend is the thing that is broken.
     */
    public function test_the_recovery_pages_are_untouched(): void {
        $slugs = array_column( AdminMenuRegistry::allEntries(), 'slug' );

        foreach ( [ 'tt-matrix', 'tt-migrations', 'tt-error-log' ] as $slug ) {
            $this->assertContains( $slug, $slugs, "{$slug} is a recovery path and must stay registered." );
        }
    }

    /**
     * The tile registry went with the page it fed. Nothing else read it,
     * and leaving an API nobody feeds is how the next reader concludes the
     * dashboard still exists somewhere.
     */
    public function test_the_dashboard_tile_registry_is_gone(): void {
        $this->assertFalse( method_exists( AdminMenuRegistry::class, 'registerDashboardTile' ) );
        $this->assertFalse( method_exists( AdminMenuRegistry::class, 'dashboardTilesForUser' ) );
        $this->assertFalse( method_exists( AdminMenuRegistry::class, 'allDashboardTiles' ) );
        $this->assertFalse( method_exists( \TT\Shared\Admin\Menu::class, 'renderDashboardTiles' ) );
    }

    public function test_the_old_url_redirects_rather_than_dying(): void {
        $this->assertTrue(
            method_exists( \TT\Shared\Admin\Menu::class, 'redirectRetiredDashboard' ),
            'Retire by redirect, not by deletion: the slug is a plausible bookmark, '
            . 'and an admin whose bookmark 404s files a bug while one who lands on '
            . 'the working equivalent does not notice.'
        );
    }
}
