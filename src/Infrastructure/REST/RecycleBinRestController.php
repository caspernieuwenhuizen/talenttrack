<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Archive\CascadeRegistry;
use TT\Infrastructure\Archive\DeleteBlockedException;
use TT\Infrastructure\Archive\GenericCascadeDeleter;
use TT\Infrastructure\RecycleBin\RecycleBinEntities;
use WP_REST_Request;
use WP_REST_Response;

/**
 * RecycleBinRestController (#2023 / #2024, epic #2018) — the REST surface for
 * the centralized recycle bin.
 *
 *   GET    /talenttrack/v1/recycle-bin                      — aggregated trashed rows across entities
 *   GET    /talenttrack/v1/recycle-bin/preview/{entity}/{id} — itemized cascade preview (#2023)
 *   POST   /talenttrack/v1/recycle-bin/{entity}/{id}/restore — bin → archived
 *   DELETE /talenttrack/v1/recycle-bin/{entity}/{id}        — purge (the single owner of permanent deletion)
 *
 * All shaping lives in {@see ArchiveRepository} (CLAUDE.md §4) — this
 * controller only validates, gates, and serialises. Security contract
 * (issue #2024 design review):
 *
 *   #1 IDOR — every mutating route's permission_callback verifies BOTH
 *      `tt_manage_recycle_bin` AND `ArchiveRepository::ownedByCurrentClub()`
 *      before the request reaches the handler. A 0-row restore/purge is a
 *      404, never a silent success.
 *   #7 allowlist — `{entity}` is validated against the single source of
 *      truth (`ArchiveRepository::entityMap()` keys) at the route
 *      `validate_callback`; an unknown entity is a 400, never a query.
 *   #4 audit — restore/purge write `tt_audit_log` inside ArchiveRepository.
 *
 * The aggregation GET and the entity allowlist both derive from the same
 * entity map, so the bin's list and its validator can never disagree.
 */
final class RecycleBinRestController {

    private const NS  = 'talenttrack/v1';
    private const CAP = 'tt_manage_recycle_bin';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        // GET /recycle-bin — cross-entity aggregation. Cap-only: the list is
        // already club-scoped per entity in the repository, so there is no
        // single {id} to ownership-check here.
        register_rest_route( self::NS, '/recycle-bin', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_bin' ],
                'permission_callback' => static function () { return current_user_can( self::CAP ); },
            ],
        ] );

        register_rest_route( self::NS, '/recycle-bin/preview/(?P<entity>[a-z_]+)/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'preview' ],
                'permission_callback' => static function () { return current_user_can( 'tt_edit_settings' ); },
            ],
        ] );

        // POST /recycle-bin/{entity}/{id}/restore — bin → archived.
        register_rest_route( self::NS, '/recycle-bin/(?P<entity>[a-z_]+)/(?P<id>\d+)/restore', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'restore' ],
                'permission_callback' => static function ( WP_REST_Request $r ) {
                    return self::canMutate( $r );
                },
                'args'                => self::entityArg(),
            ],
        ] );

        // DELETE /recycle-bin/{entity}/{id} — purge (irreversible).
        register_rest_route( self::NS, '/recycle-bin/(?P<entity>[a-z_]+)/(?P<id>\d+)', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'purge' ],
                'permission_callback' => static function ( WP_REST_Request $r ) {
                    return self::canMutate( $r );
                },
                'args'                => self::entityArg(),
            ],
        ] );
    }

    /**
     * `{entity}` route arg: validate against the bin's entity allowlist
     * (#2024 security #7). Unknown entity → 400 before any query runs. The
     * allowlist is the entity map, shared with the list + the validator so
     * they cannot drift.
     *
     * @return array<string,array<string,callable>>
     */
    private static function entityArg(): array {
        return [
            'entity' => [
                'validate_callback' => static function ( $v ): bool {
                    return is_string( $v ) && RecycleBinEntities::isValid( $v );
                },
            ],
        ];
    }

    /**
     * Shared permission_callback for the mutating routes (#2024 security #1).
     * Requires the cap AND that the target row is owned by the current club.
     * A row outside the club (forged id from another tenant, or a never-
     * created id) fails ownership → the request is denied (403/404 surface),
     * never a 0-row "success".
     */
    private static function canMutate( WP_REST_Request $r ): bool {
        if ( ! current_user_can( self::CAP ) ) {
            return false;
        }
        $entity = (string) $r['entity'];
        $id     = absint( $r['id'] );
        if ( $id <= 0 || ! RecycleBinEntities::isValid( $entity ) ) {
            return false;
        }
        return ( new ArchiveRepository() )->ownedByCurrentClub( $entity, $id );
    }

    /**
     * GET /recycle-bin — every trashed row across every bin-archivable
     * entity, grouped by entity key. Each group carries its count + label;
     * each row carries the aggregation fields (who/when binned,
     * days_until_purge) plus a display identity resolved from the full row.
     *
     * The aggregation itself is club-scoped per entity inside
     * ArchiveRepository::trashedAcrossEntities() (#2024 security #3) — this
     * handler only enriches each row with its display identity.
     */
    public static function list_bin(): WP_REST_Response {
        $repo  = new ArchiveRepository();
        $agg   = $repo->trashedAcrossEntities();
        $total = 0;
        $groups = [];

        foreach ( $agg as $entity => $rows ) {
            $items = [];
            foreach ( $rows as $row ) {
                $found    = $repo->findIncludingArchived( $entity, (int) $row['id'] );
                $identity = ( $found !== null )
                    ? RecycleBinEntities::identity( $found['row'] )
                    /* translators: %d is a record id. */
                    : sprintf( __( 'Record #%d', 'talenttrack' ), (int) $row['id'] );
                $items[] = array_merge( $row, [ 'identity' => $identity ] );
            }
            $total   += count( $items );
            $groups[] = [
                'entity' => $entity,
                'label'  => RecycleBinEntities::label( $entity ),
                'count'  => count( $items ),
                'rows'   => $items,
            ];
        }

        return RestResponse::success( [
            'groups'         => $groups,
            'total'          => $total,
            'retention_days' => $repo->retentionDays(),
        ] );
    }

    /**
     * POST /recycle-bin/{entity}/{id}/restore — move a trashed row back to
     * the archive tier. Cap + ownership already enforced in the
     * permission_callback; a 0-row result here means the row is owned but not
     * actually in the bin → 404 (treat as not-found, never a false success).
     */
    public static function restore( WP_REST_Request $r ): WP_REST_Response {
        $entity = (string) $r['entity'];
        $id     = absint( $r['id'] );

        $n = ( new ArchiveRepository() )->restoreFromTrash( $entity, [ $id ], get_current_user_id() );
        if ( $n === 0 ) {
            return RestResponse::error( 'not_found', __( 'Record not found in the recycle bin.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( [ 'restored' => true, 'entity' => $entity, 'id' => $id ] );
    }

    /**
     * DELETE /recycle-bin/{entity}/{id} — permanently purge a trashed row via
     * the existing fail-closed cascade. A DeleteBlockedException (an
     * undeclared reference would be orphaned) is caught and surfaced as the
     * dependency report with a 409 — the row stays in the bin, nothing is
     * written. A 0-row result is a 404.
     */
    public static function purge( WP_REST_Request $r ): WP_REST_Response {
        $entity = (string) $r['entity'];
        $id     = absint( $r['id'] );

        try {
            $deleted = ( new ArchiveRepository() )->purge( $entity, [ $id ], get_current_user_id() );
        } catch ( DeleteBlockedException $e ) {
            return RestResponse::error(
                'delete_blocked',
                __( 'This record cannot be permanently deleted yet — other records still depend on it.', 'talenttrack' ),
                409,
                [ 'report' => $e->report() ]
            );
        }

        if ( $deleted === 0 ) {
            return RestResponse::error( 'not_found', __( 'Record not found in the recycle bin.', 'talenttrack' ), 404 );
        }

        return RestResponse::success( [ 'purged' => true, 'entity' => $entity, 'id' => $id, 'deleted' => $deleted ] );
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
