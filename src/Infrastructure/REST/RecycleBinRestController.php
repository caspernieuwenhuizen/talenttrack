<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Archive\CascadeRegistry;
use TT\Infrastructure\Archive\GenericCascadeDeleter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * RecycleBinRestController (#2023, epic #2018) — the shared read-only
 * cascade-preview endpoint the list-table "Move to recycle bin" confirm
 * dialog renders before a trash.
 *
 *   GET /talenttrack/v1/recycle-bin/preview/{entity}/{id}
 *
 * Returns the FULL itemized cascade a later purge would apply (#2024) —
 * what gets removed, nulled, zeroed, and what currently blocks a purge —
 * so the user sees the consequences up front. Trash itself is never
 * blocked (the move is reversible); blockers are shown informationally.
 *
 * One endpoint for every entity so the dialog logic + cap gate live in a
 * single place. Gated on `tt_edit_settings`, the same destructive-op cap
 * the trash routes use; the entity must be ownership-verified
 * (`ownedByCurrentClub`) so a forged id from another tenant returns 404.
 */
final class RecycleBinRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/recycle-bin/preview/(?P<entity>[a-z_]+)/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'preview' ],
                'permission_callback' => static function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );
    }

    /**
     * Read-only itemized cascade preview for one row, normalised to the
     * GenericCascadeDeleter::preview() shape regardless of which cascade
     * service backs the entity.
     */
    public static function preview( WP_REST_Request $r ): WP_REST_Response {
        $entity = sanitize_key( (string) $r['entity'] );
        $id     = absint( $r['id'] );

        if ( $id <= 0 || ! isset( ArchiveRepository::entityMap()[ $entity ] ) ) {
            return RestResponse::error( 'bad_request', __( 'Unknown entity or id.', 'talenttrack' ), 400 );
        }

        // Ownership backstop: a row outside the current club is a 404.
        if ( ! ( new ArchiveRepository() )->ownedByCurrentClub( $entity, $id ) ) {
            return RestResponse::error( 'not_found', __( 'Record not found.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( self::previewFor( $entity, [ $id ] ) );
    }

    /**
     * Unified preview shape, regardless of which cascade backs the entity:
     *   removals: list<{table, count}>      — rows a later purge would delete
     *   nullifications: list<{table, column, count}> — references it would null
     *   zeroings: list<{table, column, count}>       — references it would zero
     *   blockers: map<table, count>         — references that would block purge
     *
     * Plan-backed entities (evaluation / goal / tournament / holiday / …) use
     * GenericCascadeDeleter::preview() directly. Player has a bespoke cascade
     * (no registry plan / no preview()), so its itemized dependents come from
     * ArchiveRepository::activeDependentsFor(), normalised into `removals`.
     *
     * @param int[] $ids
     * @return array{removals: list<array{table:string,count:int}>, nullifications: list<array{table:string,column:string,count:int}>, zeroings: list<array{table:string,column:string,count:int}>, blockers: array<string,int>}
     */
    private static function previewFor( string $entity, array $ids ): array {
        $empty = [ 'removals' => [], 'nullifications' => [], 'zeroings' => [], 'blockers' => [] ];

        if ( CascadeRegistry::has( $entity ) ) {
            return array_merge( $empty, ( new GenericCascadeDeleter() )->preview( $entity, $ids ) );
        }

        // Entities with a bespoke cascade (player) or no plan: derive the
        // itemized dependent counts from the archive repository's normalised
        // label => count map. These show what the eventual purge would touch;
        // the move to the bin is reversible regardless.
        $dependents = ( new ArchiveRepository() )->activeDependentsFor( $entity, (int) $ids[0] );
        $removals   = [];
        foreach ( $dependents as $label => $count ) {
            if ( (int) $count > 0 ) {
                $removals[] = [ 'table' => (string) $label, 'count' => (int) $count ];
            }
        }
        return array_merge( $empty, [ 'removals' => $removals ] );
    }
}
