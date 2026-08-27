<?php
namespace TT\Modules\Import;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoCoverage;

/**
 * ImportUndoService (#2959, epic #2954) — take back a whole import.
 *
 * DELETE ORDER
 *
 * Reuses `DemoCoverage::deleteOrder()` and `::tableMap()` — the same
 * dependency-sorted walk `DemoDataCleaner` uses, where dependents go
 * before the rows they depend on. Deliberately not a second ordering:
 * a hand-rolled sequence would drift from that one the first time a
 * table gains a foreign key, and orphaned rows are exactly the failure
 * that is invisible until something else breaks.
 *
 * `DemoCoverage` is DemoData's, and this is the one place the Import
 * module reaches into it. That is a knowledge dependency (which table
 * holds which entity, and in what order they unwind), not a behavioural
 * one — no demo row is read or written here.
 *
 * SCOPE
 *
 * Every delete is club-scoped and gated by an id list drawn from
 * `tt_import_tags`, so an undo can only reach rows that this batch
 * created. Demo rows, other batches and hand-entered records are not
 * reachable from here.
 */
final class ImportUndoService {

    /**
     * Delete everything one import created.
     *
     * @return array{ok:bool, deleted:array<string,int>, error:string}
     */
    public function undo( string $batch_key ): array {
        global $wpdb;

        if ( trim( $batch_key ) === '' ) {
            return [ 'ok' => false, 'deleted' => [], 'error' => __( 'No import was named.', 'talenttrack' ) ];
        }

        $club_id   = CurrentClub::id();
        $table_map = DemoCoverage::tableMap();
        $deleted   = [];

        foreach ( DemoCoverage::deleteOrder() as $entity_type ) {
            if ( ! isset( $table_map[ $entity_type ] ) ) continue;

            $ids = ImportBatchRegistry::allEntityIds( $entity_type, $batch_key );
            if ( empty( $ids ) ) continue;

            [ $table, $id_col ] = $table_map[ $entity_type ];

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $sql = $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$table}
                  WHERE {$id_col} IN ({$placeholders}) AND club_id = %d",
                array_merge( $ids, [ $club_id ] )
            );

            $affected = $wpdb->query( $sql );
            if ( $affected !== false && (int) $affected > 0 ) {
                $deleted[ $entity_type ] = (int) $affected;
            }
        }

        // Drop the tags last: until they are gone the undo is replayable,
        // which is what makes a half-finished run safe to re-run rather
        // than something that has to be reasoned about.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}tt_import_tags
              WHERE batch_key = %s AND club_id = %d",
            $batch_key, $club_id
        ) );

        $wpdb->update(
            "{$wpdb->prefix}tt_import_batches",
            [ 'counts_json' => wp_json_encode( [] ) ],
            [ 'batch_key' => $batch_key, 'club_id' => $club_id ]
        );

        Logger::info( 'import.undo', [ 'batch' => $batch_key, 'deleted' => $deleted ] );

        return [ 'ok' => true, 'deleted' => $deleted, 'error' => '' ];
    }

    /**
     * How many of a batch's rows have been touched since it landed.
     *
     * Only tables carrying `updated_at` can answer, so this is a floor
     * rather than a count — which is why the UI phrases it as "at least
     * this many" work rather than a precise figure. Reported anyway,
     * because an undo that silently discards a coach's afternoon of edits
     * is worse than one that warns imprecisely.
     */
    public static function editedSince( string $batch_key, string $created_at ): int {
        global $wpdb;

        if ( $batch_key === '' || $created_at === '' ) return 0;

        $club_id   = CurrentClub::id();
        $table_map = DemoCoverage::tableMap();
        $total     = 0;

        foreach ( $table_map as $entity_type => $spec ) {
            [ $table, $id_col ] = $spec;

            $full = $wpdb->prefix . $table;
            $has_updated = $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM {$full} LIKE %s", 'updated_at'
            ) );
            if ( ! $has_updated ) continue;

            $ids = ImportBatchRegistry::allEntityIds( $entity_type, $batch_key );
            if ( empty( $ids ) ) continue;

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $count = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$full}
                  WHERE {$id_col} IN ({$placeholders}) AND club_id = %d AND updated_at > %s",
                array_merge( $ids, [ $club_id, $created_at ] )
            ) );

            $total += (int) $count;
        }

        return $total;
    }

    /**
     * "3 teams, 24 players" — what the confirm needs to name.
     *
     * @param array<string,int> $counts
     */
    public static function describeCounts( array $counts ): string {
        $parts = [];
        foreach ( $counts as $key => $n ) {
            $n = (int) $n;
            if ( $n <= 0 ) continue;
            $parts[] = sprintf( '%d %s', $n, self::entityLabel( (string) $key, $n ) );
        }
        return implode( ', ', $parts );
    }

    /**
     * Every label carries a context.
     *
     * Partly so a translator can see these are counted nouns inside a
     * sentence like "3 teams, 24 players", and partly because several of
     * these one-word msgids already exist elsewhere in the catalogue —
     * `activity` bare, `team` and `player` under other contexts. A bare
     * duplicate msgid is not a warning; msgfmt fails on it outright.
     */
    private static function entityLabel( string $key, int $n ): string {
        switch ( $key ) {
            case 'teams':
                return _nx( 'team', 'teams', $n, 'import undo count', 'talenttrack' );
            case 'players':
                return _nx( 'player', 'players', $n, 'import undo count', 'talenttrack' );
            case 'people':
                return _nx( 'staff member', 'staff members', $n, 'import undo count', 'talenttrack' );
            case 'activities':
                return _nx( 'activity', 'activities', $n, 'import undo count', 'talenttrack' );
            case 'evaluations':
                return _nx( 'evaluation', 'evaluations', $n, 'import undo count', 'talenttrack' );
            case 'goals':
                return _nx( 'goal', 'goals', $n, 'import undo count', 'talenttrack' );
            default:
                return _nx( 'record', 'records', $n, 'import undo count', 'talenttrack' );
        }
    }
}
