<?php
namespace TT\Modules\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;

/**
 * PotentialAccess (#4694) — who reads the academy's judgement of how far a
 * child will go: the potential band, its movement and the notes behind it.
 *
 * The rule the sensitive-field registry names `potential_judgement`, and
 * the one answer every surface that shows a band asks through
 * `RecordRedactor`: the trajectory, the status verdict, the squad overview,
 * the player report and its PDF, the profile's Behaviour & potential card,
 * the attribute set's development group and the KPI export. A surface never
 * decides this itself.
 *
 * Whether a potential was set, when and by whom stays for every reader of
 * it. Only the band and what derives from it are masked.
 *
 * ## Who is masked
 *
 * A reader who holds `player_potential` read academy-wide and changes it
 * nowhere academy-wide: the read-only observer is that seat. For such a
 * reader the band shows on a player only when one of these holds:
 *
 *  - they are staff for the player (`AuthorizationService::isStaffForPlayer()`);
 *  - they may set this player's potential
 *    (`PlayerStatusModule::potentialCaptureAvailableFor()`);
 *  - they work the prospect funnel and may view this player: the scout's
 *    rule, so a scout keeps the band of the players they are linked to
 *    whatever their matrix row on `player_potential` says. The observer
 *    reads prospects and changes none, so it does not work the funnel.
 *
 * Every other reader reads what they read before: a head coach, a head of
 * development, an administrator, a scout and a family reader never hold the
 * read-only academy-wide shape, so the rule leaves them alone. A reader of 0,
 * whose rights cannot be checked (a scheduled report's unverifiable
 * recipient), counts as one with none: the band is hidden from them.
 */
final class PotentialAccess {

    public const ENTITY = 'player_potential';

    /**
     * May this reader see the band, the movement and the notes of this
     * player's potential?
     *
     * @param bool|null $is_staff the staff answer for this reader and player
     *                            when the caller already holds it (the
     *                            redactor primes it for a whole list in one
     *                            pass); asked here when null.
     */
    public static function seesJudgement( int $user_id, int $player_id, ?bool $is_staff = null ): bool {
        // A reader whose rights cannot be checked counts as one with none.
        if ( $user_id <= 0 ) return false;
        if ( ! self::readsWithoutJudgement( $user_id ) ) return true;
        if ( $player_id <= 0 ) return false;

        if ( $is_staff ?? AuthorizationService::isStaffForPlayer( $user_id, $player_id ) ) return true;
        if ( PlayerStatusModule::potentialCaptureAvailableFor( $player_id, $user_id ) ) return true;
        return self::scoutReads( $user_id, $player_id );
    }

    /**
     * Does this reader read potential academy-wide without changing it
     * academy-wide? The shape the rule masks; false for everybody the seed
     * gives a band to, and for a reader the matrix does not know.
     */
    public static function readsWithoutJudgement( int $user_id ): bool {
        if ( $user_id <= 0 || ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) return false;
        if ( ! MatrixGate::can( $user_id, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) return false;
        return ! MatrixGate::can( $user_id, self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_GLOBAL );
    }

    /**
     * The scout's rule: works the prospect funnel and may view the player,
     * which for a scout means a player they are linked to.
     */
    public static function scoutReads( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        return \TT\Modules\Prospects\ProspectScope::worksTheFunnel( $user_id )
            && AuthorizationService::canViewPlayer( $user_id, $player_id );
    }
}
