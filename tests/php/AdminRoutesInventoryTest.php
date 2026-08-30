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

    /**
     * #3132 — a dispatcher arm written as `case SomeView::SLUG:` is as
     * routable as a literal one, and the command's own regex could not see
     * it. Both of these are constant arms, so a regression to a second
     * regex fails here rather than being reported as work to do.
     */
    public function test_constant_arm_routes_are_visible(): void {
        $slugs = AdminRoutesCommand::routableSlugs();

        $this->assertContains( 'eval-category-weights', $slugs,
            'FrontendCategoryWeightsView::SLUG is a class constant, not a literal in the dispatcher' );
        $this->assertContains( 'methodology-vocabulary', $slugs,
            'FrontendMethodologyVocabularyView::SLUG is a class constant, not a literal in the dispatcher' );
    }

    /** Pre-auth routes live above the dispatch chain and route all the same. */
    public function test_pre_auth_routes_are_visible(): void {
        $slugs = AdminRoutesCommand::routableSlugs();

        $this->assertContains( 'accept-invite', $slugs );
        $this->assertContains( 'reset-password', $slugs );
    }

    /**
     * #3132 — every port #2874 commissioned renamed its slug, so the
     * prefix rule reported all three as unrouted: the same wrong answer the
     * two hand-written audits produced, from the tool built to prevent it.
     */
    public function test_renamed_ports_are_reported_as_routed(): void {
        $by_slug = [];
        foreach ( AdminRoutesCommand::rows() as $row ) {
            $by_slug[ $row['admin_slug'] ] = $row;
        }

        $expected = [
            'tt-category-weights'          => 'eval-category-weights',
            'tt-persona-dashboard-editor'  => 'persona-templates',
            'tt-methodology-principle-edit' => 'methodology-vocabulary',
            'tt-methodology-vision-edit'    => 'methodology-vocabulary',
        ];

        foreach ( $expected as $admin_slug => $frontend_slug ) {
            // A module may be switched off in this install; absence is not a
            // failure, a wrong answer is.
            if ( ! isset( $by_slug[ $admin_slug ] ) ) continue;

            $this->assertSame( 'routed', $by_slug[ $admin_slug ]['status'],
                "{$admin_slug} was ported and must not read as work to do" );
            $this->assertSame( $frontend_slug, $by_slug[ $admin_slug ]['frontend_slug'],
                "{$admin_slug} must name the surface it actually reaches" );
        }
    }

    /**
     * The map records a decision; it must not be able to invent a route. A
     * frontend slug the dispatcher does not answer has to read as unrouted,
     * or the map becomes a way to silence the tool rather than to inform it.
     */
    public function test_every_recorded_pairing_names_a_real_route(): void {
        $routable = AdminRoutesCommand::routableSlugs();
        $map      = AdminRoutesCommand::renamedPairings();

        $this->assertNotEmpty( $map );
        foreach ( $map as $admin_slug => $entry ) {
            $this->assertArrayHasKey( 'frontend_slug', $entry, "{$admin_slug} has no frontend slug" );
            $this->assertArrayHasKey( 'renamed_by', $entry,
                "{$admin_slug} does not say which issue renamed it" );
            $this->assertMatchesRegularExpression( '/^#\d+$/', (string) $entry['renamed_by'] );
            $this->assertContains( (string) $entry['frontend_slug'], $routable,
                "{$admin_slug} claims a port to a slug the dispatcher does not answer" );
        }
    }

    /**
     * A route the deriver cannot follow is unknown, not absent. Reporting it
     * is what keeps the table from implying completeness it does not have.
     */
    public function test_unresolvable_route_sites_are_reportable(): void {
        $this->assertIsArray( AdminRoutesCommand::unresolvedRouteSites() );
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
