<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;

/**
 * #2165 — regression guard for the Test results browse data path.
 *
 * MeasurementResultsRepository::listLatestWithPreviousForDefinition() feeds
 * both FrontendTestResultsView and GET /measurement-results. It previously
 * selected and filtered on pl.age_group, a column that does not exist on
 * tt_players (age group lives on tt_teams). The prepared query errored, so
 * the view and REST endpoint returned zero rows for every test.
 *
 * These tests seed a player on a team with a saved result and assert the
 * method returns the row, exposes the team's age group, and honours the
 * age-group filter — which is only possible once age group resolves against
 * tt_teams.
 */
final class MeasurementResultsBrowseRepositoryTest extends WP_UnitTestCase {

    public function test_returns_rows_for_a_definition_with_a_result(): void {
        $def_id = $this->makeDefinition();
        $this->seedPlayerWithResult( $def_id, 'O17', 'Nieuwenhuizen', '2026-06-20', 'Talentvol' );

        $rows = ( new MeasurementResultsRepository() )
            ->listLatestWithPreviousForDefinition( $def_id );

        $this->assertCount( 1, $rows, 'the player with a value for the test is listed' );
        $this->assertSame( 'Nieuwenhuizen', $rows[0]->last_name );
        $this->assertSame( 'Talentvol', $rows[0]->value_text );
        $this->assertSame( 'O17', $rows[0]->age_group, 'age group resolves from the team' );
    }

    public function test_age_group_filter_narrows_by_the_teams_age_group(): void {
        $def_id = $this->makeDefinition();
        $this->seedPlayerWithResult( $def_id, 'O17', 'Jansen',  '2026-06-20', 'Talentvol' );
        $this->seedPlayerWithResult( $def_id, 'O15', 'Pietersen', '2026-06-20', 'Basis' );

        $repo = new MeasurementResultsRepository();

        $all = $repo->listLatestWithPreviousForDefinition( $def_id );
        $this->assertCount( 2, $all, 'both players list with no filter' );

        $filtered = $repo->listLatestWithPreviousForDefinition( $def_id, [ 'age_group' => 'O17' ] );
        $this->assertCount( 1, $filtered, 'the age-group filter narrows to the O17 team' );
        $this->assertSame( 'Jansen', $filtered[0]->last_name );
    }

    private function makeDefinition(): int {
        return ( new MeasurementDefinitionsRepository() )->create( [
            'category_id' => 1,
            'name'        => 'Talentenstatus',
            'value_type'  => 'status',
            'direction'   => 'neutral',
            'frequency'   => 'adhoc',
        ] );
    }

    private function seedPlayerWithResult(
        int $def_id, string $age_group, string $last_name, string $recorded_date, string $value_text
    ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => 1,
            'name'      => $age_group . ' ' . $last_name,
            'age_group' => $age_group,
        ] );
        $team_id = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Luuk',
            'last_name'  => $last_name,
            'status'     => 'active',
            'team_id'    => $team_id,
        ] );
        $player_id = (int) $wpdb->insert_id;

        ( new MeasurementResultsRepository() )->create( [
            'player_id'     => $player_id,
            'definition_id' => $def_id,
            'recorded_date' => $recorded_date,
            'value_text'    => $value_text,
        ] );

        return $player_id;
    }
}
