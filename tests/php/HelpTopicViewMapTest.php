<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
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
        HelpTopics::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function admin(): int {
        $id = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $id );
        return $id;
    }

    /** Every `?tt_view=` slug the dispatcher routes. */
    private function dispatcherSlugs(): array {
        $src    = (string) file_get_contents( TT_PATH . 'src/Shared/Frontend/DashboardShortcode.php' );
        $tokens = token_get_all( $src );

        $slugs = [];
        $inFn  = false;
        $depth = 0;

        for ( $i = 0; $i < count( $tokens ); $i++ ) {
            $t = $tokens[ $i ];

            if ( is_array( $t ) && $t[0] === T_FUNCTION ) {
                $name = '';
                for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
                    if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_STRING ) { $name = $tokens[ $j ][1]; break; }
                }
                $inFn  = (bool) preg_match( '/^dispatch\w*View$/', $name );
                $depth = 0;
                continue;
            }

            if ( $t === '{' ) { $depth++; continue; }
            if ( $t === '}' ) { $depth--; if ( $depth <= 0 ) $inFn = false; continue; }

            if ( $inFn && is_array( $t ) && $t[0] === T_CASE ) {
                for ( $j = $i + 1; $j < $i + 5 && $j < count( $tokens ); $j++ ) {
                    if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_CONSTANT_ENCAPSED_STRING ) {
                        $v = trim( $tokens[ $j ][1], "'\"" );
                        if ( preg_match( '/^[a-z0-9][a-z0-9-]*$/', $v ) ) $slugs[ $v ] = true;
                        break;
                    }
                }
            }
        }

        return array_keys( $slugs );
    }

    /** @return array<string, string> */
    private function allowlist(): array {
        $path = TT_PATH . 'config/no_help_topic.php';
        $this->assertFileExists( $path );
        return (array) require $path;
    }

    // ── coverage ───────────────────────────────────────────────────────

    /**
     * The rule the whole issue reduces to: two states, no third. A slug is
     * claimed by a `views:` entry, or it is on the allowlist with a
     * reason. Anything else is a screen whose help silently lies.
     */
    public function test_every_dispatcher_slug_is_claimed_or_allowlisted(): void {
        $map       = HelpTopics::viewToTopic( $this->admin() );
        $allowed   = $this->allowlist();
        $unclaimed = [];

        foreach ( $this->dispatcherSlugs() as $slug ) {
            if ( ! isset( $map[ $slug ] ) && ! isset( $allowed[ $slug ] ) ) {
                $unclaimed[] = $slug;
            }
        }

        $this->assertSame( [], $unclaimed, "Slugs with neither a topic nor an allowlist entry:\n  " . implode( "\n  ", $unclaimed ) );
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
        $map  = HelpTopics::viewToTopic( $this->admin() );
        $both = array_intersect( array_keys( $map ), array_keys( $this->allowlist() ) );

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
