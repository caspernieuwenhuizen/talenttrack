<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityRecordedData (#4182) — which activities already carry what
 * happened at them.
 *
 * An activity that was played is part of a player's record: its register,
 * minutes, match analysis, live match log and evaluations feed the player
 * report, the monthly report and the journey. An automated process must
 * never archive such a row on its own judgement, because every report
 * skips archived activities and the history disappears with it.
 *
 * "Recorded data" is any of:
 * - status `completed`;
 * - a recorded (`actual`) attendance row, which is also where minutes live;
 * - a match analysis;
 * - a match execution (the live match log);
 * - an evaluation linked to the activity.
 *
 * The rule exists once, as `sqlCondition()`, so the Spond sync and the
 * repair migration 0298 cannot drift apart.
 */
final class ActivityRecordedData {

    /**
     * SQL boolean over the `tt_activities` row aliased `$alias`: true when
     * that activity carries recorded data. Contains no placeholders.
     */
    public static function sqlCondition( string $alias ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        $a = preg_replace( '/[^A-Za-z0-9_]/', '', $alias );

        return "( {$a}.activity_status_key = 'completed'
            OR EXISTS ( SELECT 1 FROM {$p}tt_attendance rd_att
                         WHERE rd_att.activity_id = {$a}.id AND rd_att.club_id = {$a}.club_id
                           AND rd_att.record_type = 'actual' )
            OR EXISTS ( SELECT 1 FROM {$p}tt_match_analyses rd_ma
                         WHERE rd_ma.activity_id = {$a}.id AND rd_ma.club_id = {$a}.club_id )
            OR EXISTS ( SELECT 1 FROM {$p}tt_match_execution rd_mx
                         WHERE rd_mx.activity_id = {$a}.id AND rd_mx.club_id = {$a}.club_id )
            OR EXISTS ( SELECT 1 FROM {$p}tt_evaluations rd_ev
                         WHERE rd_ev.activity_id = {$a}.id AND rd_ev.club_id = {$a}.club_id ) )";
    }

    /**
     * The subset of `$activity_ids` that carries recorded data.
     *
     * @param int[] $activity_ids
     * @return list<int>
     */
    public static function idsWithRecordedData( array $activity_ids ): array {
        $ids = [];
        foreach ( $activity_ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $ids[ $id ] = $id;
        }
        if ( ! $ids ) return [];

        global $wpdb;
        $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $cond = self::sqlCondition( 'a' );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $col = $wpdb->get_col( $wpdb->prepare(
            "SELECT a.id FROM {$wpdb->prefix}tt_activities a
              WHERE a.id IN ({$ph}) AND a.club_id = %d AND {$cond}",
            ...array_merge( array_values( $ids ), [ CurrentClub::id() ] )
        ) );

        return array_values( array_map( 'intval', (array) $col ) );
    }
}
