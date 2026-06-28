<?php
namespace TT\Infrastructure\RecycleBin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * RecycleBinAuditActions (#2020, epic #2018) — canonical audit action-key
 * vocabulary for the recycle-bin lifecycle.
 *
 * The audit log's action column is free-form by convention ("{entity}.{verb}"
 * — see AuditService), and the frontend viewer's action filter is populated
 * from the DISTINCT actions that actually exist in the log
 * (AuditService::distinctValues). There is no static action registry to
 * "register" into; instead the keys surface the moment a row is written.
 *
 * That makes a single source of truth for the exact strings important: the
 * later children that move rows in/out of the bin (#2022 restore, #2024
 * purge) and the purge cron (#2025) MUST all write the same
 * `{entity}.trashed` / `{entity}.restored` / `{entity}.purged` keys, or the
 * viewer's dropdown fragments into near-duplicates ("player.purged" vs
 * "players.purged"). This class is that contract — call `trashed('player')`
 * rather than concatenating strings at each site.
 *
 * Entity keys are the same short keys `ArchiveRepository::TABLE_MAP` uses
 * (`player`, `team`, `evaluation`, …), so the audit action namespace lines
 * up with the bin's entity namespace.
 */
final class RecycleBinAuditActions {

    public const VERB_TRASHED   = 'trashed';
    public const VERB_RESTORED  = 'restored';
    public const VERB_PURGED    = 'purged';

    /** Audit-log key for moving an entity row into the bin. */
    public static function trashed( string $entity ): string {
        return self::key( $entity, self::VERB_TRASHED );
    }

    /** Audit-log key for restoring a trashed row out of the bin. */
    public static function restored( string $entity ): string {
        return self::key( $entity, self::VERB_RESTORED );
    }

    /** Audit-log key for permanently purging a trashed row (irreversible). */
    public static function purged( string $entity ): string {
        return self::key( $entity, self::VERB_PURGED );
    }

    /**
     * Every recycle-bin action key the bin can emit — the cartesian product
     * of the bin-archivable entities × the three lifecycle verbs. Used by
     * tests and (future) documentation tooling to assert the viewer can
     * surface the full vocabulary. Not used to pre-seed the log: keys
     * appear in the viewer only once a real row carries them.
     *
     * @return list<string>
     */
    public static function all(): array {
        $out = [];
        foreach ( self::entities() as $entity ) {
            $out[] = self::trashed( $entity );
            $out[] = self::restored( $entity );
            $out[] = self::purged( $entity );
        }
        return $out;
    }

    /**
     * The bin-archivable entity keys. Sourced from ArchiveRepository so the
     * audit vocabulary cannot drift from the schema the migration created.
     *
     * @return list<string>
     */
    public static function entities(): array {
        return array_keys( ArchiveRepository::entityMap() );
    }

    private static function key( string $entity, string $verb ): string {
        return $entity . '.' . $verb;
    }
}
