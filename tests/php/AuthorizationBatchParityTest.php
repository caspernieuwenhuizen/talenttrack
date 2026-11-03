<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * The batched checks search uses are held to the single checks the target
 * screens ask: for every persona and every player,
 *
 *  - `mayReadPlayerSections()` agrees with `mayReadPlayerSection()`, for the
 *    goals and the evaluations sections, with one child having hidden goals
 *    from their guardian;
 *  - `canEvaluatePlayers()` agrees with `canEvaluatePlayer()`.
 *
 * Each single answer is taken with the caches emptied, and so is each batch,
 * so one cannot answer from what the other cached.
 */
final class AuthorizationBatchParityTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
        $this->addCandidateSourcePersonas();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_the_section_batch_agrees_with_the_single_check(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->players['jose'], 'goals', false );
        $players = $this->playerRows();

        $answers = [];
        foreach ( [ 'goals', 'evaluations' ] as $section ) {
            foreach ( $this->personas as $persona => $uid ) {
                wp_set_current_user( $uid );
                $single = [];
                foreach ( array_keys( $players ) as $pid ) {
                    $this->resetSearchCaches();
                    $single[ $pid ] = AuthorizationService::mayReadPlayerSection( $uid, $pid, $section );
                }
                $this->resetSearchCaches();
                $batch = AuthorizationService::mayReadPlayerSections( $uid, $players, $section );
                foreach ( $single as $pid => $expected ) {
                    $this->assertSame( $expected, $batch[ $pid ] ?? null, "{$persona}, player {$pid}, {$section}: the batch and the single check disagree" );
                    $answers[ $expected ? 'yes' : 'no' ] = true;
                }
            }
        }
        $this->assertCount( 2, $answers, 'Precondition: both answers occur.' );

        // Precondition: the child's switch is exercised. The guardian reads
        // José's evaluations but not the goals he hid.
        $this->resetSearchCaches();
        $this->assertTrue( AuthorizationService::mayReadPlayerSection( $this->personas['parent'], $this->players['jose'], 'evaluations' ) );
        $this->resetSearchCaches();
        $this->assertFalse( AuthorizationService::mayReadPlayerSection( $this->personas['parent'], $this->players['jose'], 'goals' ) );
    }

    public function test_the_evaluate_batch_agrees_with_the_single_check(): void {
        $players        = $this->playerRows();
        $team_by_player = array_map( static fn( array $row ): int => $row['team_id'], $players );

        $answers = [];
        foreach ( $this->personas as $persona => $uid ) {
            wp_set_current_user( $uid );
            $single = [];
            foreach ( array_keys( $players ) as $pid ) {
                $this->resetSearchCaches();
                $single[ $pid ] = AuthorizationService::canEvaluatePlayer( $uid, $pid );
            }
            $this->resetSearchCaches();
            $batch = AuthorizationService::canEvaluatePlayers( $uid, $team_by_player );
            foreach ( $single as $pid => $expected ) {
                $this->assertSame( $expected, $batch[ $pid ] ?? null, "{$persona}, player {$pid}: canEvaluatePlayers and canEvaluatePlayer disagree" );
                $answers[ $expected ? 'yes' : 'no' ] = true;
            }
        }
        $this->assertCount( 2, $answers, 'Precondition: both answers occur.' );
    }

    /** @return array<int, array{team_id:int, wp_user_id:int}> */
    private function playerRows(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, team_id, wp_user_id FROM {$wpdb->prefix}tt_players WHERE club_id = %d",
            (int) CurrentClub::id()
        ) );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row->id ] = [ 'team_id' => (int) $row->team_id, 'wp_user_id' => (int) $row->wp_user_id ];
        }
        $this->assertGreaterThanOrEqual( 5, count( $out ) );
        return $out;
    }
}
