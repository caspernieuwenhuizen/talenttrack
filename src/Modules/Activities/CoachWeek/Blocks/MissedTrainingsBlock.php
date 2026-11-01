<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\ActivityLifecycle;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Analytics\Domain\AttendanceFlagService;

/**
 * #4496 — players who missed each of their team's last three recorded
 * trainings.
 *
 * The attendance report's rule, so the two never disagree: a training
 * counts when it is completed, on or before today, and has a recorded
 * (`actual`, non-guest) register. Missed is
 * `AttendanceFlagService::isMissed()`: absent, excused, injured or suspended. A player
 * with no row on one of the three is not counted as missing it.
 */
final class MissedTrainingsBlock extends AttentionBlock {

    /** How many of the latest recorded trainings a player must have missed. */
    public const TRAININGS = 3;

    /** How far back to look for those trainings. */
    private const LOOKBACK_DAYS = 120;

    public function key(): string { return 'missed'; }
    public function label(): string { return __( 'Missed several trainings', 'talenttrack' ); }
    public function help(): string { return __( 'Players absent from each of their team\'s last three recorded trainings.', 'talenttrack' ); }

    protected function field(): string { return 'attention_missed'; }

    protected function items( WeekContext $week ): array {
        $team_ids = array_keys( $week->teams );
        if ( $team_ids === [] ) return [];

        global $wpdb;
        $p         = $wpdb->prefix;
        $club      = (int) CurrentClub::id();
        $completed = ActivityLifecycle::completedClause( 'a' );
        $from      = gmdate( 'Y-m-d', (int) strtotime( $week->today . ' 12:00:00 UTC -' . self::LOOKBACK_DAYS . ' days' ) );
        $in        = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $trainings = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.team_id
               FROM {$p}tt_activities a
              WHERE a.club_id = %d
                AND a.team_id IN ($in)
                AND a.activity_type_key = %s
                AND a.archived_at IS NULL AND a.trashed_at IS NULL
                AND {$completed}
                AND a.session_date BETWEEN %s AND %s
                AND EXISTS ( SELECT 1 FROM {$p}tt_attendance r
                              WHERE r.activity_id = a.id AND r.club_id = a.club_id
                                AND r.record_type = 'actual' AND r.is_guest = 0 )
              ORDER BY a.session_date DESC, a.id DESC",
            array_merge( [ $club ], $team_ids, [ ActivityTypeKey::TRAINING, $from, $week->today ] )
        ) );

        $latest = [];
        foreach ( (array) $trainings as $t ) {
            $f   = (array) $t;
            $tid = (int) ( $f['team_id'] ?? 0 );
            if ( count( $latest[ $tid ] ?? [] ) < self::TRAININGS ) $latest[ $tid ][] = (int) ( $f['id'] ?? 0 );
        }
        $latest = array_filter( $latest, static fn( array $ids ): bool => count( $ids ) === self::TRAININGS );
        if ( $latest === [] ) return [];

        $activity_ids = array_merge( ...array_values( $latest ) );
        $in_a         = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $marks = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, status
               FROM {$p}tt_attendance
              WHERE club_id = %d AND activity_id IN ($in_a)
                AND record_type = 'actual' AND is_guest = 0",
            array_merge( [ $club ], $activity_ids )
        ) );

        $missed = [];
        foreach ( (array) $marks as $m ) {
            $f = (array) $m;
            if ( AttendanceFlagService::isMissed( (string) ( $f['status'] ?? '' ) ) ) {
                $missed[ (int) ( $f['player_id'] ?? 0 ) ][ (int) ( $f['activity_id'] ?? 0 ) ] = true;
            }
        }

        $out = [];
        foreach ( self::players( $week ) as $pid => $player ) {
            $ids = $latest[ $player['team_id'] ] ?? [];
            if ( $ids === [] ) continue;
            $all = true;
            foreach ( $ids as $aid ) {
                if ( ! isset( $missed[ $pid ][ $aid ] ) ) { $all = false; break; }
            }
            if ( $all ) {
                $out[] = [
                    'player_id' => $pid,
                    'name'      => self::fullName( $player['first_name'], $player['last_name'] ),
                ];
            }
        }
        return $out;
    }
}
