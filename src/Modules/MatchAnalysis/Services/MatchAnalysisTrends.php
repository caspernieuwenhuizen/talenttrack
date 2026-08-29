<?php
namespace TT\Modules\MatchAnalysis\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;

/**
 * MatchAnalysisTrends — a season of match analyses read as a trend (#2725).
 *
 * One match analysis is a note. Ten of them answer two different questions,
 * and this class answers both from the same stored rows:
 *
 *   - per **team**, how each phase of play has gone over a period
 *   - per **player**, what they have repeatedly been marked as, and in
 *     which phase
 *
 * ## It counts. It never averages.
 *
 * `went_well` / `mixed` / `needs_work` are three ordered values, not a
 * number. "Six of the last eight were needs work" is what the coach entered;
 * converting the three to a 1–3 mean and reporting 1.8 invents a precision
 * nobody typed. That conversion is exactly the kind of helpful
 * simplification a later reader makes, so: **do not add an average here.**
 *
 * ## An unrated section counts as nothing
 *
 * #2704 deliberately stores nothing for a section a coach left alone, and
 * that decision only holds if the reader honours it. An unrated section is
 * excluded from both numerator and denominator — never treated as neutral,
 * never treated as a middle value.
 *
 * ## Below the floor there is no trend
 *
 * One `needs_work` in one match is not a trend, and drawing it as one makes
 * a report whose job is to drive next month's training theme actively
 * misleading. Below `MIN_RATED_MATCHES` the caller gets `meets_floor =>
 * false` and should render an explicit "not enough matches yet" state
 * rather than a thin trend.
 *
 * Draft and final analyses both count. A rating is only ever stored because
 * a coach set it, and nothing else in the module reads on status; excluding
 * drafts would silently drop data a coach believes they have entered.
 */
final class MatchAnalysisTrends {

    /** Fewer rated matches than this in the period is not a trend. */
    public const MIN_RATED_MATCHES = 3;

    /**
     * Per team function, how the phase has gone across the period.
     *
     * @param list<int> $team_ids restrict to these teams; empty means every team.
     * @return array{
     *   rated_matches:int,
     *   meets_floor:bool,
     *   sections:list<array{key:string,label:string,total:int,counts:array<string,int>}>
     * }
     */
    public function forTeams( array $team_ids, string $from, string $to ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        [ $team_sql, $team_params ] = self::teamFilter( $team_ids, 'a' );
        if ( $team_sql === null ) {
            return self::emptyTeamResult();
        }

        $params = array_merge( [ CurrentClub::id(), $from, $to ], $team_params );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.section_key, s.rating, COUNT(*) AS n
               FROM {$p}tt_match_analysis_sections s
               JOIN {$p}tt_match_analyses ma ON ma.id = s.analysis_id AND ma.club_id = s.club_id
               JOIN {$p}tt_activities a ON a.id = ma.activity_id AND a.club_id = ma.club_id
              WHERE ma.club_id = %d
                AND a.session_date BETWEEN %s AND %s
                AND s.rating IS NOT NULL AND s.rating <> ''
                {$team_sql}
              GROUP BY s.section_key, s.rating",
            $params
        ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rated_matches = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT ma.id)
               FROM {$p}tt_match_analyses ma
               JOIN {$p}tt_activities a ON a.id = ma.activity_id AND a.club_id = ma.club_id
               JOIN {$p}tt_match_analysis_sections s ON s.analysis_id = ma.id AND s.club_id = ma.club_id
              WHERE ma.club_id = %d
                AND a.session_date BETWEEN %s AND %s
                AND s.rating IS NOT NULL AND s.rating <> ''
                {$team_sql}",
            $params
        ) );

        $tally = [];
        foreach ( (array) $rows as $row ) {
            $key    = (string) $row->section_key;
            $rating = (string) $row->rating;
            if ( ! MatchAnalysisEnums::isRating( $rating ) ) continue;
            $tally[ $key ][ $rating ] = ( $tally[ $key ][ $rating ] ?? 0 ) + (int) $row->n;
        }

        $sections = [];
        foreach ( MatchAnalysisEnums::ratedSectionKeys() as $key ) {
            $counts = self::zeroedRatings();
            foreach ( $tally[ $key ] ?? [] as $rating => $n ) {
                $counts[ $rating ] = $n;
            }
            $sections[] = [
                'key'    => $key,
                'label'  => MatchAnalysisEnums::sectionLabel( $key ),
                'total'  => array_sum( $counts ),
                'counts' => $counts,
            ];
        }

        return [
            'rated_matches' => $rated_matches,
            'meets_floor'   => $rated_matches >= self::MIN_RATED_MATCHES,
            'sections'      => $sections,
        ];
    }

    /**
     * What one player has been marked as across the period, and for which
     * phase of play.
     *
     * @return array{
     *   rated_matches:int,
     *   meets_floor:bool,
     *   markers:array<string,int>,
     *   tags:list<array{key:string,label:string,total:int,counts:array<string,int>}>
     * }
     */
    public function forPlayer( int $player_id, string $from, string $to ): array {
        if ( $player_id <= 0 ) {
            return self::emptyPlayerResult();
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT mp.marker, mp.team_function, COUNT(*) AS n
               FROM {$p}tt_match_analysis_players mp
               JOIN {$p}tt_match_analyses ma ON ma.id = mp.analysis_id AND ma.club_id = mp.club_id
               JOIN {$p}tt_activities a ON a.id = ma.activity_id AND a.club_id = ma.club_id
              WHERE mp.club_id = %d
                AND mp.player_id = %d
                AND mp.marker <> ''
                AND a.session_date BETWEEN %s AND %s
              GROUP BY mp.marker, mp.team_function",
            CurrentClub::id(), $player_id, $from, $to
        ) );

        $rated_matches = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT ma.id)
               FROM {$p}tt_match_analysis_players mp
               JOIN {$p}tt_match_analyses ma ON ma.id = mp.analysis_id AND ma.club_id = mp.club_id
               JOIN {$p}tt_activities a ON a.id = ma.activity_id AND a.club_id = ma.club_id
              WHERE mp.club_id = %d
                AND mp.player_id = %d
                AND mp.marker <> ''
                AND a.session_date BETWEEN %s AND %s",
            CurrentClub::id(), $player_id, $from, $to
        ) );

        $markers = array_fill_keys( array_keys( MatchAnalysisEnums::markers() ), 0 );
        $by_tag  = [];
        foreach ( (array) $rows as $row ) {
            $marker = (string) $row->marker;
            if ( ! MatchAnalysisEnums::isMarker( $marker ) ) continue;
            $n = (int) $row->n;
            $markers[ $marker ] += $n;

            // An untagged item says what the player did, not which phase it
            // was in, so it counts towards the markers and towards nothing
            // in the per-phase breakdown.
            $tag = (string) ( $row->team_function ?? '' );
            if ( $tag === '' || ! MatchAnalysisEnums::isPlayerItemTag( $tag ) ) continue;
            $by_tag[ $tag ][ $marker ] = ( $by_tag[ $tag ][ $marker ] ?? 0 ) + $n;
        }

        $tags = [];
        foreach ( MatchAnalysisEnums::playerItemTags() as $key => $label ) {
            if ( empty( $by_tag[ $key ] ) ) continue;
            $counts = array_fill_keys( array_keys( MatchAnalysisEnums::markers() ), 0 );
            foreach ( $by_tag[ $key ] as $marker => $n ) {
                $counts[ $marker ] = $n;
            }
            $tags[] = [
                'key'    => (string) $key,
                'label'  => (string) $label,
                'total'  => array_sum( $counts ),
                'counts' => $counts,
            ];
        }

        return [
            'rated_matches' => $rated_matches,
            'meets_floor'   => $rated_matches >= self::MIN_RATED_MATCHES,
            'markers'       => $markers,
            'tags'          => $tags,
        ];
    }

    /**
     * @param list<int> $team_ids
     * @return array{0:?string,1:list<int>} SQL fragment + params; a null
     *         fragment means "an empty allow-list", i.e. match nothing.
     */
    private static function teamFilter( array $team_ids, string $alias ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $team_ids ) ) ) );
        if ( $team_ids !== [] && $ids === [] ) {
            return [ null, [] ];
        }
        if ( $ids === [] ) {
            return [ '', [] ];
        }
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return [ "AND {$alias}.team_id IN ({$ph})", $ids ];
    }

    /** @return array<string,int> */
    private static function zeroedRatings(): array {
        return array_fill_keys( array_keys( MatchAnalysisEnums::ratings() ), 0 );
    }

    /**
     * @return array{rated_matches:int, meets_floor:bool, sections:list<array{key:string,label:string,total:int,counts:array<string,int>}>}
     */
    private static function emptyTeamResult(): array {
        return [ 'rated_matches' => 0, 'meets_floor' => false, 'sections' => [] ];
    }

    /**
     * @return array{rated_matches:int, meets_floor:bool, markers:array<string,int>, tags:list<array{key:string,label:string,total:int,counts:array<string,int>}>}
     */
    private static function emptyPlayerResult(): array {
        return [
            'rated_matches' => 0,
            'meets_floor'   => false,
            'markers'       => array_fill_keys( array_keys( MatchAnalysisEnums::markers() ), 0 ),
            'tags'          => [],
        ];
    }
}
