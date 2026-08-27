<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Cli\AdminRoutesCommand;
use TT\Shared\Admin\AdminOnlyNotice;

/**
 * #2981 / #2980 — the admin-route inventory and the recorded reasons.
 *
 * The value being protected is that this answer stops being re-derived by
 * hand. #2874 produced two inventories and both were wrong in different
 * directions, so these tests pin the properties that made them wrong: that
 * already-ported pages are recognised as routed, and that deliberately
 * admin-only pages do not read as gaps.
 */
final class AdminRoutesInventoryTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        AdminOnlyNotice::clearCache();
    }

    public function test_routable_slugs_are_read_from_the_dispatcher(): void {
        $slugs = AdminRoutesCommand::routableSlugs();

        $this->assertNotEmpty( $slugs );
        // A representative spread; these are long-standing routes.
        $this->assertContains( 'players', $slugs );
        $this->assertContains( 'teams', $slugs );
        $this->assertContains( 'reports', $slugs );
    }

    /**
     * The seven pages #2874's audit listed as needing a port. They had all
     * been routable for months. If this test ever fails, an inventory is
     * about to commission work that is already done.
     */
    public function test_already_ported_pages_are_recognised_as_routed(): void {
        $slugs = AdminRoutesCommand::routableSlugs();

        foreach ( [ 'modules', 'seasons', 'spond', 'matrix', 'migrations', 'usage-stats', 'usage-stats-details' ] as $slug ) {
            $this->assertContains( $slug, $slugs, "{$slug} has been routable since before #2874's audit" );
        }
    }

    public function test_admin_only_surfaces_all_carry_a_reason(): void {
        $list = AdminRoutesCommand::adminOnly();

        $this->assertNotEmpty( $list );
        foreach ( $list as $slug => $reason ) {
            $this->assertIsString( $slug );
            $this->assertIsString( $reason );
            $this->assertNotSame( '', trim( $reason ), "{$slug} is listed with no reason" );
            // A reason that only a developer understands does not answer the
            // question an operator arrived with.
            $this->assertGreaterThan( 40, strlen( $reason ), "{$slug}'s reason is too terse to explain anything" );
        }
    }

    public function test_diagnostic_pages_do_not_read_as_unrouted_gaps(): void {
        $rows = AdminRoutesCommand::rows();
        $this->assertNotEmpty( $rows );

        $by_slug = [];
        foreach ( $rows as $row ) {
            $by_slug[ $row['admin_slug'] ] = $row['status'];
        }

        foreach ( [ 'tt-error-log', 'tt-roles-debug', 'tt-user-compare' ] as $slug ) {
            if ( ! isset( $by_slug[ $slug ] ) ) continue; // module may be off in this install
            $this->assertSame( 'diagnostic', $by_slug[ $slug ],
                "{$slug} stays in wp-admin on purpose and must not show as work to do" );
        }
    }

    public function test_every_row_carries_the_reporting_columns(): void {
        foreach ( AdminRoutesCommand::rows() as $row ) {
            foreach ( [ 'admin_slug', 'title', 'cap', 'module', 'enabled', 'frontend_slug', 'status' ] as $column ) {
                $this->assertArrayHasKey( $column, $row );
            }
            $this->assertContains( $row['status'], [ 'routed', 'unrouted', 'diagnostic' ] );
        }
    }

    public function test_notice_returns_the_recorded_reason(): void {
        $this->assertNotNull( AdminOnlyNotice::reasonFor( 'tt-error-log' ) );
        $this->assertNull( AdminOnlyNotice::reasonFor( 'tt-players' ),
            'a ported page has no reason to explain itself' );
        $this->assertNull( AdminOnlyNotice::reasonFor( '' ) );
    }
}
