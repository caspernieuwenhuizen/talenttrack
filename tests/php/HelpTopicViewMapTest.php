<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Modules\Documentation\HelpTopics;

/**
 * #2547 — the view→topic map is a projection of the corpus.
 *
 * The old map was a 27-entry literal against a dispatcher routing 144
 * slugs, so about 117 screens opened "Getting started" and nothing
 * anywhere said a mapping was missing. The tests that matter here are
 * therefore the coverage ones: they are what stops the map decaying back
 * into that state one new view at a time, until #2551's lint takes over.
 */
final class HelpTopicViewMapTest extends WP_UnitTestCase {

    private const MODULE = 'TT\\Modules\\Methodology\\MethodologyModule';

    public function set_up(): void {
        parent::set_up();
        HelpTopics::flushCache();
    }

    public function tear_down(): void {
        ModuleRegistry::setEnabled( self::MODULE, true );
        // Back to its catalogued default, so a later test file does not
        // inherit a feature this one switched on.
        FeatureRegistry::setEnabled( 'analytics_cohort_board', false );
        HelpTopics::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function admin(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    /**
     * Every `?tt_view=` slug the dispatcher routes.
     *
     * #3022 — the same deriver the docs, mobile-class and tile-route gates
     * use. This test used to carry a fourth copy that saw only literal `case`
     * arms, so a route written as `case SomeView::SLUG:` read as a phantom
     * claim and a topic that legitimately served it failed the assertion.
     *
     * @return list<string>
     */
    private function dispatcherSlugs(): array {
        require_once TT_PATH . 'tools/lib/routable-slugs.php';

        [ $slugs ] = tt_routable_slugs(
            rtrim( TT_PATH, '/\\' ),
            TT_PATH . 'src/Shared/Frontend/DashboardShortcode.php'
        );

        return array_keys( $slugs );
    }

    /** @return array<string, string> */
    private function allowlist(): array {
        $path = TT_PATH . 'config/no_help_topic.php';
        $this->assertFileExists( $path );
        return (array) require $path;
    }

    // ── coverage ───────────────────────────────────────────────────────

    /** Every view any topic claims, ignoring whether this install can open it. */
    private function claimedByCorpus(): array {
        $claimed = [];
        foreach ( HelpTopics::all() as $topic_slug => $topic ) {
            foreach ( $topic['views'] as $view ) {
                if ( $view !== '' ) $claimed[ $view ] = $topic_slug;
            }
        }
        return $claimed;
    }

    /**
     * The rule the whole issue reduces to: two states, no third. A slug is
     * claimed by a `views:` entry, or it is on the allowlist with a
     * reason. Anything else is a screen whose help silently lies.
     *
     * Asserted against the corpus rather than against `viewToTopic()`,
     * because coverage is a property of the documentation and gating is a
     * property of one install. Four views — `team-chemistry`,
     * `chemistry-config`, `cohort-board`, `eval-coverage` — belong to
     * features that ship default-off, so a runtime map on a fresh install
     * correctly omits them. Their screens are switched off too, so there
     * is nothing to open help on; that is the gate working, not a gap.
     */
    public function test_every_dispatcher_slug_is_claimed_or_allowlisted(): void {
        $claimed   = $this->claimedByCorpus();
        $allowed   = $this->allowlist();
        $unclaimed = [];

        foreach ( $this->dispatcherSlugs() as $slug ) {
            if ( ! isset( $claimed[ $slug ] ) && ! isset( $allowed[ $slug ] ) ) {
                $unclaimed[] = $slug;
            }
        }

        $this->assertSame( [], $unclaimed, "Slugs with neither a topic nor an allowlist entry:\n  " . implode( "\n  ", $unclaimed ) );
    }

    /**
     * A default-off feature takes its help topic out of the runtime map,
     * and switching it on puts it back. The companion to the test above:
     * together they say coverage is complete *and* gating still applies.
     */
    public function test_a_default_off_feature_omits_its_views_until_enabled(): void {
        $user = $this->admin();

        FeatureRegistry::setEnabled( 'analytics_cohort_board', false );
        $this->assertArrayNotHasKey( 'cohort-board', HelpTopics::viewToTopic( $user ) );

        FeatureRegistry::setEnabled( 'analytics_cohort_board', true );
        $this->assertSame( 'cohort-board', HelpTopics::viewToTopic( $user )['cohort-board'] ?? null );
    }

    /**
     * The reverse: a `views:` entry naming a route that does not exist is
     * dead weight that reads as coverage.
     */
    public function test_every_declared_view_is_routable(): void {
        $routable = array_flip( $this->dispatcherSlugs() );
        $phantom  = [];

        foreach ( HelpTopics::all() as $topic_slug => $topic ) {
            foreach ( $topic['views'] as $view ) {
                if ( ! isset( $routable[ $view ] ) ) $phantom[] = "{$topic_slug} -> {$view}";
            }
        }

        $this->assertSame( [], $phantom, "Topics claiming non-routable views:\n  " . implode( "\n  ", $phantom ) );
    }

    /** An allowlist entry for a slug that is also claimed is a contradiction. */
    public function test_no_slug_is_both_claimed_and_allowlisted(): void {
        $both = array_intersect( array_keys( $this->claimedByCorpus() ), array_keys( $this->allowlist() ) );

        $this->assertSame( [], array_values( $both ) );
    }

    /** Every allowlist entry carries a reason, not an empty string. */
    public function test_every_allowlist_entry_gives_a_reason(): void {
        foreach ( $this->allowlist() as $slug => $reason ) {
            $this->assertIsString( $reason );
            $this->assertNotSame( '', trim( $reason ), "Allowlisted slug {$slug} has no reason." );
        }
    }

    // ── the map resolves to real, reachable topics ─────────────────────

    public function test_every_mapped_topic_is_registered(): void {
        $all = HelpTopics::all();

        foreach ( HelpTopics::viewToTopic( $this->admin() ) as $view => $topic ) {
            $this->assertArrayHasKey( $topic, $all, "View {$view} maps to unregistered topic {$topic}." );
        }
    }

    /** The two slugs that were pointing at topics which never existed. */
    public function test_the_previously_broken_slugs_now_resolve(): void {
        $map = HelpTopics::viewToTopic( $this->admin() );

        $this->assertSame( 'workflow-engine', $map['my-tasks'] ?? null );
        $this->assertSame( 'workflow-engine', $map['tasks-dashboard'] ?? null );
        $this->assertSame( 'workflow-engine', $map['workflow-config'] ?? null );
        $this->assertArrayHasKey( 'pdp-cycle', HelpTopics::all() );
    }

    public function test_the_literal_map_is_gone(): void {
        $src = (string) file_get_contents( TT_PATH . 'src/Shared/Frontend/DashboardShortcode.php' );

        $this->assertStringNotContainsString( "'workflow-tasks'", $src );
        $this->assertStringNotContainsString( "'players'             =>", $src );
    }

    // ── gating carries through ─────────────────────────────────────────

    /**
     * The map is built from `visibleFor()`, so a topic this install cannot
     * open is not merely hidden from the TOC — the drawer cannot resolve
     * to it either.
     */
    public function test_a_gated_away_topic_leaves_the_map(): void {
        $user = $this->admin();

        $this->assertContains( 'methodology', HelpTopics::viewToTopic( $user ) );

        ModuleRegistry::setEnabled( self::MODULE, false );

        $this->assertNotContains( 'methodology', HelpTopics::viewToTopic( $user ) );
    }

    /** Adding a view plus a `views:` line must need no PHP change. */
    public function test_the_map_is_derived_not_declared(): void {
        $map = HelpTopics::viewToTopic( $this->admin() );

        // Slugs registered in #2548, which no hand-maintained list ever knew.
        $this->assertSame( 'match-prep', $map['match-prep'] ?? null );
        $this->assertSame( 'measurements', $map['measurements-entry'] ?? null );
        $this->assertSame( 'tournaments', $map['tournament-match'] ?? null );
    }
}
