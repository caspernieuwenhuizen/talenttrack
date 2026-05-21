<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * PlayerEvaluationsCsvExporter (#865) — flat, one-row-per-evaluation CSV.
 *
 * Complements `EvaluationsXlsxExporter` (multi-sheet, partitioned by
 * season × eval-type) — coaches want a flat CSV for quick "filter in
 * Excel" workflows. Same source data; different shape.
 *
 * Columns: date, player, coach, opponent, competition, minutes_played,
 * one average column per main `tt_eval_categories` row.
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/player_evaluations?format=csv|xlsx`
 *   filters:
 *     `team_id`   (optional)
 *     `date_from` (Y-m-d, optional)
 *     `date_to`   (Y-m-d, optional)
 *
 * Cap: `tt_view_evaluations`.
 */
final class PlayerEvaluationsCsvExporter implements ExporterInterface {

    public function key(): string { return 'player_evaluations'; }

    public function label(): string { return __( 'Player evaluations (flat)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv', 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_evaluations'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id < 0 ) $team_id = 0;

        $filters = [ 'team_id' => $team_id ];
        if ( ! empty( $raw['date_from'] ) ) $filters['date_from'] = (string) $raw['date_from'];
        if ( ! empty( $raw['date_to'] ) )   $filters['date_to']   = (string) $raw['date_to'];

        if ( isset( $filters['date_from'], $filters['date_to'] )
            && $filters['date_from'] > $filters['date_to'] ) {
            [ $filters['date_from'], $filters['date_to'] ] =
                [ $filters['date_to'], $filters['date_from'] ];
        }
        return $filters;
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $filters = $request->filters;
        $team_id = (int) ( $filters['team_id'] ?? 0 );

        $main_cats = $wpdb->get_results(
            "SELECT id, label FROM {$p}tt_eval_categories
                WHERE parent_id IS NULL AND is_active = 1
                ORDER BY display_order ASC, id ASC"
        );
        $main_cats = is_array( $main_cats ) ? $main_cats : [];

        $where  = [ 'e.archived_at IS NULL', 'pl.club_id = %d' ];
        $params = [ (int) $request->clubId ];
        if ( $team_id > 0 ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'e.eval_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'e.eval_date <= %s';
            $params[] = (string) $filters['date_to'];
        }

        $sql = "SELECT e.id, e.eval_date, e.opponent, e.competition, e.game_result,
                       e.minutes_played, e.notes,
                       pl.id AS player_id, pl.first_name, pl.last_name,
                       t.name AS team_name,
                       u.display_name AS coach_name
                  FROM {$p}tt_evaluations e
                  INNER JOIN {$p}tt_players pl ON pl.id = e.player_id
                  LEFT JOIN  {$p}tt_teams t   ON t.id = pl.team_id AND t.club_id = pl.club_id
                  LEFT JOIN  {$wpdb->users} u ON u.ID = e.coach_id
                 WHERE " . implode( ' AND ', $where ) . "
                 ORDER BY e.eval_date ASC, pl.last_name ASC, pl.first_name ASC";
        $evaluations = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $evaluations = is_array( $evaluations ) ? $evaluations : [];

        // Aggregate main-category averages per evaluation.
        $cat_parents = [];
        $sub_to_main = $wpdb->get_results(
            "SELECT id, parent_id FROM {$p}tt_eval_categories WHERE parent_id IS NOT NULL"
        );
        foreach ( (array) $sub_to_main as $r ) {
            $cat_parents[ (int) $r->id ] = (int) $r->parent_id;
        }

        $agg = [];
        if ( $evaluations !== [] ) {
            $eval_ids = array_map( static fn( $r ) => (int) $r->id, $evaluations );
            $placeholders = implode( ',', array_fill( 0, count( $eval_ids ), '%d' ) );
            $ratings = $wpdb->get_results( $wpdb->prepare(
                "SELECT evaluation_id, category_id, rating
                    FROM {$p}tt_eval_ratings
                    WHERE evaluation_id IN ({$placeholders})",
                $eval_ids
            ) );
            foreach ( (array) $ratings as $r ) {
                $eid  = (int) $r->evaluation_id;
                $cat  = (int) $r->category_id;
                $main = $cat_parents[ $cat ] ?? $cat;
                $agg[ $eid ][ $main ][0] = ( $agg[ $eid ][ $main ][0] ?? 0.0 ) + (float) $r->rating;
                $agg[ $eid ][ $main ][1] = ( $agg[ $eid ][ $main ][1] ?? 0 ) + 1;
            }
        }

        $headers = [
            __( 'Evaluation ID',  'talenttrack' ),
            __( 'Date',           'talenttrack' ),
            __( 'Player ID',      'talenttrack' ),
            __( 'First name',     'talenttrack' ),
            __( 'Last name',      'talenttrack' ),
            __( 'Team',           'talenttrack' ),
            __( 'Coach',          'talenttrack' ),
            __( 'Opponent',       'talenttrack' ),
            __( 'Competition',    'talenttrack' ),
            __( 'Result',         'talenttrack' ),
            __( 'Minutes played', 'talenttrack' ),
        ];
        foreach ( $main_cats as $c ) {
            $headers[] = (string) $c->label;
        }
        $headers[] = __( 'Notes', 'talenttrack' );

        $rows = [];
        foreach ( $evaluations as $e ) {
            $row = [
                (int)    $e->id,
                (string) $e->eval_date,
                (int)    $e->player_id,
                (string) ( $e->first_name ?? '' ),
                (string) ( $e->last_name ?? '' ),
                (string) ( $e->team_name ?? '' ),
                (string) ( $e->coach_name ?? '' ),
                (string) ( $e->opponent ?? '' ),
                (string) ( $e->competition ?? '' ),
                (string) ( $e->game_result ?? '' ),
                $e->minutes_played !== null ? (int) $e->minutes_played : '',
            ];
            foreach ( $main_cats as $c ) {
                $cid = (int) $c->id;
                $eid = (int) $e->id;
                $sum = $agg[ $eid ][ $cid ][0] ?? null;
                $n   = $agg[ $eid ][ $cid ][1] ?? 0;
                $row[] = $n > 0 ? round( (float) $sum / $n, 2 ) : '';
            }
            $row[] = (string) ( $e->notes ?? '' );
            $rows[] = $row;
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }
}
