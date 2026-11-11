<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\DataSources\EvaluationsRecent;
use TT\Modules\CustomWidgets\DataSources\GoalsOpen;

/**
 * #4596 — two custom-widget sources read columns the tables do not have.
 *
 * The evaluations source joined the evaluator on `evaluator_id` (the column
 * is `coach_id`), left the overall rating empty and ignored its team
 * filter. The goals source read `target_date` (the column is `due_date`)
 * and filtered on `active`, a status no goal carries, so it listed nothing.
 */
final class CustomWidgetColumnsTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $viewer = 0;
    private int $team = 0;
    private int $other_team = 0;
    private int $player = 0;
    private int $other_player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $this->p      = $wpdb->prefix;
        $this->club   = (int) CurrentClub::id();
        $this->viewer = self::factory()->user->create( [ 'role' => 'administrator', 'display_name' => 'Coach Klaasen' ] );
        wp_set_current_user( $this->viewer );

        $this->team         = $this->team( 'Cwcol JO13' );
        $this->other_team   = $this->team( 'Cwcol JO17' );
        $this->player       = $this->player( $this->team, 'Aarts' );
        $this->other_player = $this->player( $this->other_team, 'Bosman' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_evaluation_row_carries_the_evaluator_and_the_overall_and_the_team_filter_narrows(): void {
        $category = $this->mainCategory();
        $own      = $this->evaluation( $this->player, '2032-03-04 10:00:00', $category, 7.0 );
        $this->evaluation( $this->other_player, '2032-03-05 10:00:00', $category, 5.0 );

        $window = [ 'date_from' => '2032-03-01', 'date_to' => '2032-03-31 23:59:59' ];
        $source = new EvaluationsRecent();

        $this->assertCount( 2, $source->fetch( $this->viewer, $window, [] ) );

        $rows = $source->fetch( $this->viewer, $window + [ 'team_id' => $this->team ], [] );
        $this->assertCount( 1, $rows, 'the team filter narrows through the player\'s team' );
        $this->assertSame( 'KPI Aarts', $rows[0]['player'] );
        $this->assertSame( 'Coach Klaasen', $rows[0]['evaluator'] );

        $expected = ( new EvalRatingsRepository() )->overallRatingsForEvaluations( [ $own ] )[ $own ]['value'] ?? null;
        $this->assertNotNull( $expected );
        $this->assertSame( round( (float) $expected, 1 ), $rows[0]['overall'], 'the overall as the evaluation views compute it' );

        $this->assertSame( 1.0, $source->aggregate( $this->viewer, $window + [ 'team_id' => $this->team ], 'count' ), 'the aggregate honours the same filter' );
    }

    public function test_open_goals_are_listed_by_due_date_in_both_stored_spellings(): void {
        $this->goal( 'Cwcol koppen', 'In Progress', '2032-06-01' );
        $this->goal( 'Cwcol passen', 'in_progress', '2032-04-01' );
        $this->goal( 'Cwcol eigen doel', 'pending_approval', '2032-05-01' );
        $this->goal( 'Cwcol klaar', 'completed', '2032-03-01' );
        $this->goal( 'Cwcol gestopt', 'Cancelled', '2032-03-02' );

        $rows   = $this->fixtureRows( ( new GoalsOpen() )->fetch( $this->viewer, [], [] ) );
        $titles = array_column( $rows, 'title' );
        $this->assertSame( [ 'Cwcol passen', 'Cwcol eigen doel', 'Cwcol koppen' ], $titles, 'open goals by due date; completed and cancelled are not open' );
        $this->assertSame( '2032-04-01', $rows[0]['due_date'] );
        $this->assertSame( LookupTranslator::byTypeAndName( 'goal_status', 'in_progress' ), $rows[0]['status'], 'the status through the lookup translation' );
    }

    public function test_the_status_filter_offers_the_goal_status_lookup(): void {
        $filters = ( new GoalsOpen() )->filters();
        $options = (array) ( $filters[0]['options'] ?? [] );
        $values  = array_column( $options, 'value' );

        $expected = [];
        foreach ( QueryHelpers::get_lookups( 'goal_status' ) as $row ) {
            $expected[] = strtolower( str_replace( ' ', '_', trim( (string) $row->name ) ) );
        }
        $this->assertSame( $expected, $values );
        foreach ( [ 'active', 'archived' ] as $gone ) {
            $this->assertNotContains( $gone, $values, "{$gone} is not a goal status" );
        }
    }

    public function test_a_chosen_status_lists_only_that_status(): void {
        $this->goal( 'Cwcol klaar een', 'Completed', '2032-03-01' );
        $this->goal( 'Cwcol klaar twee', 'completed', '2032-03-02' );
        $this->goal( 'Cwcol bezig', 'in_progress', '2032-03-03' );

        $rows = $this->fixtureRows( ( new GoalsOpen() )->fetch( $this->viewer, [ 'status' => 'completed' ], [] ) );
        $this->assertSame( [ 'Cwcol klaar een', 'Cwcol klaar twee' ], array_column( $rows, 'title' ) );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function fixtureRows( array $rows ): array {
        return array_values( array_filter( $rows, static fn( array $row ): bool => strpos( (string) ( $row['title'] ?? '' ), 'Cwcol ' ) === 0 ) );
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function player( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => 'KPI', 'last_name' => $last, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function mainCategory(): int {
        global $wpdb;
        $category = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_eval_categories WHERE club_id = %d AND parent_id IS NULL AND is_active = 1 ORDER BY display_order, id LIMIT 1",
            $this->club
        ) );
        if ( $category > 0 ) return $category;
        $wpdb->insert( "{$this->p}tt_eval_categories", [
            'club_id' => $this->club, 'category_key' => 'zz_cwcol_main', 'label' => 'Cwcol main',
            'parent_id' => null, 'display_order' => 1, 'is_active' => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluation( int $player_id, string $created_at, int $category_id, float $rating ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id' => $this->club, 'player_id' => $player_id, 'coach_id' => $this->viewer,
            'eval_date' => substr( $created_at, 0, 10 ), 'notes' => '', 'created_at' => $created_at,
        ] );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_eval_ratings", [
            'club_id' => $this->club, 'evaluation_id' => $id, 'category_id' => $category_id, 'rating' => $rating,
        ] );
        return $id;
    }

    private function goal( string $title, string $status, string $due ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id' => $this->club, 'player_id' => $this->player, 'title' => $title,
            'status' => $status, 'due_date' => $due, 'created_by' => 0,
        ] );
        return (int) $wpdb->insert_id;
    }
}
