<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;

/**
 * RecycleBinRestActions (#2023) — the shared REST handler body for the
 * per-entity "Move to recycle bin" (`POST {plural}/{id}/trash`) route.
 *
 * Each entity controller registers its own `/trash` route (so the URL stays
 * resource-oriented) but routes the callback through here, so the
 * ownership backstop + state-invariant + audit write live in ONE place and
 * can't drift per entity. The `permission_callback` on the route already
 * gated the capability (`tt_edit_settings`); this method adds the
 * `ownedByCurrentClub()` IDOR backstop (#2021 security #1) so a forged id
 * from another tenant maps to 404, never a silent no-op success.
 *
 * Trash moves a row from the archive tier into the recycle bin
 * (archived → trashed) — reversible (the bin view restores it, #2024). The
 * irreversible purge is NOT exposed here; it lives bin-only.
 */
final class RecycleBinRestActions {

    /**
     * @param string $entity        ArchiveRepository entity key (e.g. 'player').
     * @param int    $id            Row id from the route.
     * @param string $not_found_msg Localised 404 message for this entity.
     */
    public static function trash( string $entity, int $id, string $not_found_msg ): \WP_REST_Response {
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid id.', 'talenttrack' ), 400 );
        }

        $repo = new ArchiveRepository();

        // IDOR backstop: a row that doesn't exist in this club is a 404,
        // never a 0-row "success".
        if ( ! $repo->ownedByCurrentClub( $entity, $id ) ) {
            return RestResponse::error( 'not_found', $not_found_msg, 404 );
        }

        $n = $repo->trash( $entity, [ $id ], get_current_user_id() );
        if ( $n === 0 ) {
            // Owned, but not movable to the bin — it was never archived, or is
            // already trashed. Surface as a precondition failure, not a 404.
            return RestResponse::error(
                'not_archived',
                __( 'Only archived records can be moved to the recycle bin.', 'talenttrack' ),
                409
            );
        }

        return RestResponse::success( [ 'trashed' => true, 'id' => $id ] );
    }
}
