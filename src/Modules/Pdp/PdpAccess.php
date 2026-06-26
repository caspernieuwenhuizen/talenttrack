<?php
namespace TT\Modules\Pdp;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * PdpAccess — the single PDP-file visibility decision (#1923, folds in
 * #1758).
 *
 * Before this class the same "can this viewer see this player's PDP
 * file?" ladder was hand-rolled in several places — the files REST
 * controller, the verdicts REST controller, and the frontend manage
 * view — and they had drifted: the frontend used only
 * `is_admin || coach_owns_player`, so a Head of Development who does not
 * personally coach the player was denied the files tab (#1758) even
 * though every REST surface already let them through via the matrix
 * `pdp_file/read/global` grant. Routing every surface through one helper
 * removes the divergence.
 *
 * The ladder is the matrix-aware one introduced for the files
 * controller (#0080 Wave C3):
 *
 *   1. Global PDP read access (matrix `pdp_file/read/global`, WP site
 *      admin, the legacy `tt_edit_settings` umbrella, or the
 *      HoD / academy-admin persona fallback for installs whose matrix
 *      is dormant).
 *   2. A PDP editor (`tt_edit_pdp`) who coaches the player's team.
 *   3. A PDP viewer (`tt_view_pdp`) who coaches the player's team.
 *
 * `coach_owns_player()` reads through the active `tt_user_role_scopes`
 * team grant, the single source of truth every assignment path writes
 * to — so a head coach of the player's team passes (the #1758 root
 * cause was a view-layer gate that never consulted the global-reader
 * branch, not the ownership query itself).
 *
 * Capabilities, never role names (docs/access-control.md #0052): the
 * decision is expressed in `current_user_can()` + `MatrixGate`, so a
 * future SaaS auth backend that does not preserve WP role slugs still
 * gets the same answer.
 */
final class PdpAccess {

    /**
     * Can the user read the PDP file for the given player?
     *
     * @param int $user_id   WP user id (not person_id).
     * @param int $player_id tt_players.id the file belongs to.
     */
    public static function canSeeFile( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        if ( self::hasGlobalPdpAccess( $user_id, MatrixGate::READ ) ) return true;

        if ( user_can( $user_id, 'tt_edit_pdp' ) ) {
            return QueryHelpers::coach_owns_player( $user_id, $player_id );
        }

        return user_can( $user_id, 'tt_view_pdp' )
            && QueryHelpers::coach_owns_player( $user_id, $player_id );
    }

    /**
     * Can the user edit the PDP file for the given player?
     *
     * Mirrors the read ladder but requires the edit cap. Kept here so the
     * write surfaces share the same single decision.
     */
    public static function canEditFile( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        if ( self::hasGlobalPdpAccess( $user_id, MatrixGate::CHANGE ) ) return true;

        return user_can( $user_id, 'tt_edit_pdp' )
            && QueryHelpers::coach_owns_player( $user_id, $player_id );
    }

    /**
     * Does the user hold unrestricted (global-scope) PDP access for the
     * given activity? The matrix grant is the precise semantic; the
     * remaining fallbacks keep installs whose matrix is dormant or
     * partially seeded working, exactly as the files controller's
     * original `hasGlobalPdpAccess()` did.
     *
     * @param string $activity MatrixGate::READ | MatrixGate::CHANGE.
     */
    public static function hasGlobalPdpAccess( int $user_id, string $activity ): bool {
        if ( $user_id <= 0 ) return false;

        if ( class_exists( '\TT\Modules\Authorization\MatrixGate' )
            && MatrixGate::can( $user_id, 'pdp_file', $activity, MatrixGate::SCOPE_GLOBAL )
        ) {
            return true;
        }

        if ( user_can( $user_id, 'manage_options' ) ) return true;
        if ( user_can( $user_id, 'tt_edit_settings' ) ) return true;

        // Persona fallback for dormant-matrix installs. `read` is open to
        // every global reader; `change` is restricted to HoD + academy
        // admin, mirroring the FunctionalRoles seed.
        if ( class_exists( '\TT\Modules\Authorization\PersonaResolver' ) ) {
            $personas       = PersonaResolver::personasFor( $user_id );
            $global_readers = [ 'head_of_development', 'academy_admin' ];
            foreach ( $personas as $p ) {
                if ( in_array( $p, $global_readers, true ) ) return true;
            }
        }

        return false;
    }

    /**
     * Is the user a global PDP-verdict authority (head of academy)?
     *
     * Replaces the `tt_head_dev` role-name string compare (#0052 PR-B
     * debt) used to attribute a verdict sign-off. HoD + academy admin
     * hold `pdp_verdict/change/global` in the matrix; a head coach holds
     * it only at team scope, so this cleanly separates the
     * head-of-academy signer from the coach signer without naming a role.
     */
    public static function isGlobalVerdictAuthority( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        if ( ! class_exists( '\TT\Modules\Authorization\MatrixGate' ) ) {
            // Matrix unavailable — fall back to the persona lens so the
            // attribution still distinguishes HoD/admin from a coach.
            if ( class_exists( '\TT\Modules\Authorization\PersonaResolver' ) ) {
                foreach ( PersonaResolver::personasFor( $user_id ) as $p ) {
                    if ( $p === 'head_of_development' || $p === 'academy_admin' ) return true;
                }
            }
            return false;
        }
        return MatrixGate::can( $user_id, 'pdp_verdict', MatrixGate::CHANGE, MatrixGate::SCOPE_GLOBAL );
    }
}
