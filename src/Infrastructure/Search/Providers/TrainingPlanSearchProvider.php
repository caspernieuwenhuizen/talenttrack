<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\SearchQuery;

/**
 * Training plans (#4942): `tt_training_plans`, found by the plan's title,
 * its notes and its theme.
 *
 * The scope is the plan detail's own check
 * (`TrainingPlansRestController::planRefusal()`): a plan with no team is
 * club-wide library material, open to every holder of `tt_training_plan`;
 * a team's plan needs `AllTeamsScope::canReadTeam()`, a global `team` read
 * or a team the viewer works with. So a coach finds their own teams' plans
 * and the club-wide templates, and the head of development every plan. Not
 * archived. The plan's `visibility` column is not read by the list or the
 * detail page, so search does not read it either (owner decision on #4942).
 *
 * The team filter of the search page narrows to that team's plans. With the
 * Training module off the kind returns nothing.
 */
final class TrainingPlanSearchProvider extends LibraryRecordSearchProvider {

    /** @var array<int, array{all:bool, teams:list<int>}> */
    private array $reach = [];

    public function kind(): string { return 'training_plan'; }

    protected function alias(): string { return 'tp'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_training_plans tp
           LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = tp.team_id AND t.club_id = tp.club_id";
    }

    protected function columns(): string {
        return 'tp.id, tp.title, tp.notes, tp.team_id, tp.is_template, t.name AS team_name';
    }

    protected function live(): string {
        return 'tp.archived_at IS NULL';
    }

    protected function titleColumns(): array { return [ 'tp.title' ]; }

    protected function textColumns(): array { return [ 'tp.notes', 'tp.theme_key' ]; }

    protected function tieBreak(): string { return 'tp.title ASC, tp.id ASC'; }

    protected function moduleOn(): bool {
        return class_exists( '\\TT\\Modules\\Training\\TrainingModule' )
            && \TT\Core\ModuleRegistry::isEnabled( \TT\Modules\Training\TrainingModule::class );
    }

    protected function scope( int $user_id ): ?array {
        if ( ! user_can( $user_id, 'tt_training_plan' ) ) return null;
        $reach = $this->reachOf( $user_id );
        if ( $reach['all'] ) return [ 'sql' => '1=1', 'params' => [] ];
        if ( $reach['teams'] === [] ) return [ 'sql' => 'tp.team_id IS NULL', 'params' => [] ];
        return [
            'sql'    => '( tp.team_id IS NULL OR tp.team_id IN (' . implode( ',', array_fill( 0, count( $reach['teams'] ), '%d' ) ) . ') )',
            'params' => $reach['teams'],
        ];
    }

    protected function conditions( SearchQuery $query ): array {
        if ( $query->team_id === null ) return [ 'sql' => '1=1', 'params' => [] ];
        return [ 'sql' => 'tp.team_id = %d', 'params' => [ $query->team_id ] ];
    }

    protected function toHit( object $row, int $user_id ): array {
        $r       = (array) $row;
        $id      = (int) ( $r['id'] ?? 0 );
        $team_id = (int) ( $r['team_id'] ?? 0 );
        $where   = $team_id > 0
            ? (string) ( $r['team_name'] ?? '' )
            : ( ! empty( $r['is_template'] ) ? _x( 'Template', 'training plan template', 'talenttrack' ) : '' );
        $hit     = [
            'type'     => 'training_plan',
            'id'       => $id,
            'label'    => (string) ( $r['title'] ?? '' ),
            'sublabel' => implode( ' · ', array_filter( [ _x( 'Training plan', 'search result kind', 'talenttrack' ), $where ] ) ),
            'url'      => $this->detailUrl( 'training-plan', $id ),
            'score'    => 0,
            'actions'  => [],
            'tt_row'   => [ 'team_id' => $team_id ],
        ];
        if ( $team_id > 0 ) $hit['team_id'] = $team_id;
        // The detail page shows the notes in full.
        $excerpt = self::excerpt( (string) ( $r['notes'] ?? '' ) );
        if ( $excerpt !== '' ) $hit['excerpt'] = $excerpt;
        return $hit;
    }

    /** `planRefusal()` per hit, with the viewer's teams read once. */
    protected function confirmHits( int $user_id, array $hits ): array {
        if ( ! user_can( $user_id, 'tt_training_plan' ) ) return [];
        $reach = $this->reachOf( $user_id );
        $out   = [];
        foreach ( $hits as $hit ) {
            $team_id = (int) ( ( (array) ( $hit['tt_row'] ?? [] ) )['team_id'] ?? 0 );
            if ( $team_id <= 0 || $reach['all'] || in_array( $team_id, $reach['teams'], true ) ) $out[] = $hit;
        }
        return $out;
    }

    /**
     * The teams whose plans the viewer may open: every team with a global
     * `team` read, otherwise the teams they work with, the two rungs of
     * `AllTeamsScope::canReadTeam()`.
     *
     * @return array{all:bool, teams:list<int>}
     */
    private function reachOf( int $user_id ): array {
        if ( isset( $this->reach[ $user_id ] ) ) return $this->reach[ $user_id ];
        if ( QueryHelpers::user_has_global_entity_read( $user_id, 'team' ) ) {
            return $this->reach[ $user_id ] = [ 'all' => true, 'teams' => [] ];
        }
        $teams = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id, true ) as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id > 0 ) $teams[] = $id;
        }
        return $this->reach[ $user_id ] = [ 'all' => false, 'teams' => array_values( array_unique( $teams ) ) ];
    }
}
