<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectsRepository;

/**
 * ProspectDeletion (#4840) — deleting a prospect moves it to the recycle
 * bin, where it is restored or purged like every other bin record.
 *
 * The bin takes archived rows only, so a prospect still on the pipeline is
 * archived under its own reason first. That reason is how a restore knows
 * to put it back on the pipeline (`ArchiveRepository::restoreFromTrash()`);
 * a prospect that was archived already keeps its own reason and comes back
 * archived. The state before the delete also goes into the `trashed`
 * audit payload.
 *
 * A prospect who became a player, or has a trial case, is never deleted
 * here: that record belongs to the player now.
 */
final class ProspectDeletion {

    /** The `archive_reason` of a prospect archived by its own delete. */
    public const ARCHIVE_REASON = 'deleted';

    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_IS_PLAYER = 'prospect_is_player';

    /** May this user delete prospects at all? `prospects: create_delete`. */
    public static function canDelete( int $user_id ): bool {
        return $user_id > 0 && AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_prospects' );
    }

    /**
     * May this user delete this prospect: the right, a prospect they can
     * see, and one that has not become a player.
     */
    public static function canDeleteProspect( int $user_id, int $prospect_id ): bool {
        if ( ! self::canDelete( $user_id ) || ! ProspectScope::canSee( $user_id, $prospect_id ) ) return false;
        $row = ( new ProspectsRepository() )->find( $prospect_id );
        return $row !== null && ! self::isPlayer( (array) $row );
    }

    /** @param array<string,mixed> $row */
    public static function isPlayer( array $row ): bool {
        return (int) ( $row['promoted_to_player_id'] ?? 0 ) > 0
            || (int) ( $row['promoted_to_trial_case_id'] ?? 0 ) > 0;
    }

    /**
     * Move the prospect to the recycle bin. The caller has checked the
     * right; this checks the prospect.
     *
     * @return true|\WP_Error
     */
    public static function trash( int $prospect_id, int $by_user_id ) {
        $repo = new ProspectsRepository();
        $row  = $prospect_id > 0 ? $repo->find( $prospect_id ) : null;
        if ( $row === null || ! ProspectScope::canSee( $by_user_id, $prospect_id ) ) {
            return new \WP_Error( self::ERR_NOT_FOUND, __( 'Prospect not found.', 'talenttrack' ) );
        }
        $row = (array) $row;
        if ( self::isPlayer( $row ) ) {
            return new \WP_Error( self::ERR_IS_PLAYER, __( 'This prospect is already a player; delete them through the player.', 'talenttrack' ) );
        }

        $previous = [
            'archived'       => ! empty( $row['archived_at'] ),
            'archived_at'    => $row['archived_at'] ?? null,
            'archive_reason' => $row['archive_reason'] ?? null,
        ];
        if ( ! $previous['archived'] && ! $repo->archive( $prospect_id, self::ARCHIVE_REASON, $by_user_id ) ) {
            return new \WP_Error( self::ERR_NOT_FOUND, __( 'Prospect not found.', 'talenttrack' ) );
        }

        $moved = ( new ArchiveRepository() )->trash( 'prospect', [ $prospect_id ], $by_user_id, [ 'previous' => $previous ] );
        if ( $moved === 0 ) {
            return new \WP_Error( self::ERR_NOT_FOUND, __( 'Prospect not found.', 'talenttrack' ) );
        }
        return true;
    }
}
