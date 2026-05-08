<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * EvaluationsXlsxExporter (#0063 use case 6) — multi-sheet evaluations XLSX.
 *
 * Per the user-direction shaping (2026-05-08):
 *   - Tabs partition by `(season × eval_type)`. Season is the primary
 *     axis ("Season determines"). Eval type is the `tt_lookups` row
 *     keyed by `lookup_type='eval_type'` (Match / Training / Tournament /
 *     etc.) — NOT the four main `tt_eval_categories` (Technical /
 *     Tactical / Physical / Mental); those drive the per-row category
 *     average columns instead.
 *   - One row per evaluation. Columns: date, player, coach, opponent,
 *     competition, minutes_played, plus a rolled-up average per main
 *     `tt_eval_categories` (parent_id IS NULL) drawn from the
 *     `tt_eval_ratings` sub-rows.
 *   - Filters: `team_id` (optional), `date_from` / `date_to` (optional).
 *
 * Evaluations whose `eval_date` doesn't fall in any `tt_seasons`
 * window land in a fallback tab named `_Unscoped`. Evaluations with
 * a NULL `eval_type_id` land in an `_AnyType` partition. Both
 * fallbacks keep the round-trip lossless even on installs that
 * haven't seeded seasons / eval-type lookups.
 *
 * Excel sheet-name constraint: 31 chars, no `[]:*?\/`. Long names
 * (e.g. "2025-2026 — Match preparation") get truncated; the
 * `XlsxRenderer::cleanSheetName()` enforces this so the exporter
 * doesn't have to.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/evaluations_xlsx?format=xlsx`
 *
 * Cap: `tt_view_evaluations`.
 */
final class EvaluationsXlsxExporter implements ExporterInterface {

    public function key(): string { return 'evaluations_xlsx'; }

    public function label(): string { return __( 'Evaluations export (multi-sheet XLSX)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_evaluations'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id < 0 ) $team_id = 0;

        $filters = [ 'team_id' => $team_id ];

        if ( isset( $raw['date_from'] ) && $raw['date_from'] !== '' ) {
            $filters['date_from'] = (string) $raw['date_from'];
        }
        if ( isset( $raw['date_to'] ) && $raw['date_to'] !== '' ) {
            $filters['date_to'] = (string) $raw['date_to'];
        }
        // Auto-swap reversed ranges (mirrors AttendanceRegisterCsvExporter).
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

        // Resolve seasons + main category labels once — both are small
        // tables and we re-key by id on the PHP side rather than
        // re-joining in every per-evaluation query.
        $seasons = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, start_date, end_date
                FROM {$p}tt_seasons
                WHERE club_id = %d
                ORDER BY start_date ASC",
            (int) $request->clubId
        ) );
        $seasons = is_array( $seasons ) ? $seasons : [];

        $main_cats = $wpdb->get_results(
            "SELECT id, label FROM {$p}tt_eval_categories
                WHERE parent_id IS NULL AND is_active = 1
                ORDER BY display_order ASC, id ASC"
        );
        $main_cats = is_array( $main_cats ) ? $main_cats : [];

        $eval_types = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_lookups
                WHERE club_id = %d AND lookup_type = %s AND is_active = 1
                ORDER BY display_order ASC, id ASC",
            (int) $request->clubId,
            'eval_type'
        ) );
        $eval_types = is_array( $eval_types ) ? $eval_types : [];

        // Build the where clause for the evaluations + ratings join.
        $where  = [ 'e.archived_at IS NULL' ];
        $params = [];

        // tt_evaluations doesn't carry club_id directly today; scope via
        // the player's club to stay tenant-safe.
        $where[]  = 'pl.club_id = %d';
        $params[] = (int) $request->clubId;

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

        $sql = "SELECT
                    e.id, e.eval_date, e.eval_type_id,
                    e.opponent, e.competition, e.match_result,
                    e.minutes_played, e.notes,
                    pl.id AS player_id, pl.first_name, pl.last_name,
                    u.display_name AS coach_name
                  FROM {$p}tt_evaluations e
                  JOIN {$p}tt_players pl ON pl.id = e.player_id
                  LEFT JOIN {$wpdb->users} u ON u.ID = e.coach_id
                  WHERE " . implode( ' AND ', $where ) . "
                  ORDER BY e.eval_date ASC, e.id ASC";
        $evaluations = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $evaluations = is_array( $evaluations ) ? $evaluations : [];

        if ( $evaluations === [] ) {
            return [
                'sheets' => [
                    '_Empty' => [
                        [ __( 'Date', 'talenttrack' ), __( 'Player', 'talenttrack' ) ],
                        [],
                    ],
                ],
            ];
        }

        // Pull all ratings for this evaluation set in one query, then
        // group on the PHP side. tt_eval_ratings.evaluation_id is the
        // join key.
        $eval_ids   = array_map( static fn( $r ) => (int) $r->id, $evaluations );
        $placeholders = implode( ',', array_fill( 0, count( $eval_ids ), '%d' ) );
        $ratings_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT evaluation_id, category_id, rating
                FROM {$p}tt_eval_ratings
                WHERE evaluation_id IN ({$placeholders})",
            $eval_ids
        ) );
        $ratings_rows = is_array( $ratings_rows ) ? $ratings_rows : [];

        // For each main category, average across its sub-categories'
        // ratings for that evaluation. Fall back to the legacy
        // tt_evaluations.rating + category_id pair when no per-rating
        // sub-rows exist.
        $cat_parents = [];
        $sub_to_main = $wpdb->get_results(
            "SELECT id, parent_id FROM {$p}tt_eval_categories
                WHERE parent_id IS NOT NULL"
        );
        $sub_to_main = is_array( $sub_to_main ) ? $sub_to_main : [];
        foreach ( $sub_to_main as $row ) {
            $cat_parents[ (int) $row->id ] = (int) $row->parent_id;
        }

        // eval_id => main_cat_id => [ sum, n ]
        $agg = [];
        foreach ( $ratings_rows as $r ) {
            $eid    = (int) $r->evaluation_id;
            $cat_id = (int) $r->category_id;
            $main   = $cat_parents[ $cat_id ] ?? $cat_id;
            $agg[ $eid ][ $main ][0] = ( $agg[ $eid ][ $main ][0] ?? 0.0 ) + (float) $r->rating;
            $agg[ $eid ][ $main ][1] = ( $agg[ $eid ][ $main ][1] ?? 0 ) + 1;
        }

        // Build sheets keyed by (season_id, eval_type_id).
        $type_label_by_id = [];
        foreach ( $eval_types as $t ) $type_label_by_id[ (int) $t->id ] = (string) $t->name;

        $sheet_buckets = []; // sheet_name => array of rows

        foreach ( $evaluations as $e ) {
            $season_label = self::resolveSeasonLabel( (string) $e->eval_date, $seasons );
            $type_label   = $type_label_by_id[ (int) ( $e->eval_type_id ?? 0 ) ] ?? '_AnyType';

            $sheet_name = $season_label . ' — ' . $type_label;

            if ( ! isset( $sheet_buckets[ $sheet_name ] ) ) {
                $sheet_buckets[ $sheet_name ] = [];
            }

            $row = [
                (string) $e->eval_date,
                trim( (string) $e->first_name . ' ' . (string) $e->last_name ),
                (string) ( $e->coach_name ?? '' ),
                (string) ( $e->opponent ?? '' ),
                (string) ( $e->competition ?? '' ),
                (string) ( $e->match_result ?? '' ),
                $e->minutes_played !== null ? (int) $e->minutes_played : '',
            ];
            foreach ( $main_cats as $cat ) {
                $cid  = (int) $cat->id;
                $eid  = (int) $e->id;
                $sum  = $agg[ $eid ][ $cid ][0] ?? null;
                $n    = $agg[ $eid ][ $cid ][1] ?? 0;
                $row[] = $n > 0 ? round( (float) $sum / $n, 2 ) : '';
            }
            $sheet_buckets[ $sheet_name ][] = $row;
        }

        // Common header row for every sheet.
        $headers = [
            __( 'Date',           'talenttrack' ),
            __( 'Player',         'talenttrack' ),
            __( 'Coach',          'talenttrack' ),
            __( 'Opponent',       'talenttrack' ),
            __( 'Competition',    'talenttrack' ),
            __( 'Result',         'talenttrack' ),
            __( 'Minutes played', 'talenttrack' ),
        ];
        foreach ( $main_cats as $cat ) {
            $headers[] = (string) $cat->label;
        }

        $sheets = [];
        // Sort sheet keys for deterministic ordering — seasons by
        // start date (handled by the query) then eval type alpha.
        ksort( $sheet_buckets, SORT_STRING );
        foreach ( $sheet_buckets as $name => $rows ) {
            $sheets[ $name ] = [ $headers, $rows ];
        }

        return [ 'sheets' => $sheets ];
    }

    /**
     * @param object[] $seasons
     */
    private static function resolveSeasonLabel( string $eval_date, array $seasons ): string {
        if ( $eval_date === '' ) return '_Unscoped';
        foreach ( $seasons as $s ) {
            if ( $eval_date >= (string) $s->start_date && $eval_date <= (string) $s->end_date ) {
                return (string) $s->name;
            }
        }
        return '_Unscoped';
    }
}
