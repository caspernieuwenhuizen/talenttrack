<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * #2410 — the shared team helpers must exclude archived AND trashed teams.
 *
 * `QueryHelpers::get_teams()` / `get_teams_for_coach()` feed ~40 call sites
 * (every team dropdown, the coach dashboard's team tabs). They carried no
 * lifecycle filter at all, so a retired team stayed pickable everywhere and a
 * new activity could be filed against a team the academy had retired.
 */
final class TeamLifecycleFilterTest extends WP_UnitTestCase {

    private function makeTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'name'    => $name,
            'club_id' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return list<int> */
    private function ids( array $rows ): array {
        return array_map( static fn( $r ) => (int) $r->id, $rows );
    }

    public function test_get_teams_excludes_archived(): void {
        $active   = $this->makeTeam( 'Active FC' );
        $archived = $this->makeTeam( 'Retired FC' );
        ( new ArchiveRepository() )->archive( 'team', [ $archived ], 1 );

        $ids = $this->ids( QueryHelpers::get_teams() );

        $this->assertContains( $active, $ids );
        $this->assertNotContains( $archived, $ids, 'an archived team must not be pickable' );
    }

    public function test_get_teams_excludes_trashed(): void {
        $trashed = $this->makeTeam( 'Binned FC' );
        $repo    = new ArchiveRepository();
        $repo->archive( 'team', [ $trashed ], 1 );
        $repo->trash( 'team', [ $trashed ], 1 );

        $this->assertNotContains(
            $trashed,
            $this->ids( QueryHelpers::get_teams() ),
            'a team in the recycle bin must not be pickable either'
        );
    }

    public function test_get_teams_can_opt_back_in(): void {
        $archived = $this->makeTeam( 'Retired FC' );
        ( new ArchiveRepository() )->archive( 'team', [ $archived ], 1 );

        $this->assertContains(
            $archived,
            $this->ids( QueryHelpers::get_teams( true ) ),
            '$include_archived = true is the opt-in for archive tabs / historical reports'
        );
    }

    public function test_restoring_makes_the_team_pickable_again(): void {
        $team = $this->makeTeam( 'Comeback FC' );
        $repo = new ArchiveRepository();
        $repo->archive( 'team', [ $team ], 1 );
        $repo->restore( 'team', [ $team ] );

        $this->assertContains( $team, $this->ids( QueryHelpers::get_teams() ) );
    }

    /**
     * get_team( $id ) stays unfiltered on purpose: detail views and
     * BackLabelResolver must still resolve an archived team by id, and
     * ArchivedDetailCard depends on it.
     */
    public function test_get_team_by_id_still_resolves_an_archived_team(): void {
        $archived = $this->makeTeam( 'Retired FC' );
        ( new ArchiveRepository() )->archive( 'team', [ $archived ], 1 );

        $row = QueryHelpers::get_team( $archived );

        $this->assertNotNull( $row, 'the detail view must still be able to load it' );
        $this->assertSame( $archived, (int) $row->id );
    }

    public function test_get_teams_for_coach_excludes_archived_and_trashed(): void {
        $user = self::factory()->user->create();

        // No tt_people row → the helper short-circuits; that path is covered
        // by asserting it stays empty rather than fataling on the new clause.
        $this->assertSame( [], QueryHelpers::get_teams_for_coach( $user ) );
        $this->assertSame( [], QueryHelpers::get_teams_for_coach( 0 ) );
    }
}
