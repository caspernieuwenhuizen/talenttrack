<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Modules\Players\PlayerStatusModule;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * PotentialRecorder (#3412) — the one place a potential band is written.
 *
 * Three rules have always governed the write, and until now they lived
 * inside `PlayerStatusRestController::setPotential()`:
 *
 *   1. The band has to be one the vocabulary knows (#0057).
 *   2. The academy is not asked below `POTENTIAL_MIN_AGE` (#3265), and a
 *      rule that only exists in one caller is a rule every other caller
 *      ignores.
 *   3. Re-stating the standing band with no notes is not a change of mind
 *      and does not belong in the history (#2876). Re-affirming it *with*
 *      notes is a real act and still appends.
 *
 * #3412 adds a second writer — the squad report's editable band cell —
 * and a second copy of three rules is how two surfaces come to disagree
 * about what a potential entry means. So the rules moved here, the REST
 * controller became transport over them, and the new surface calls the
 * same method. The repository stays append-only underneath; this is the
 * policy that decides whether to append.
 *
 * Nothing here checks *permission*. Who may record a band is a question
 * about the caller, answered by `PlayerStatusModule::potentialCaptureAvailable()`
 * at each entry point; this answers whether the write itself is coherent.
 */
final class PotentialRecorder {

    public const RECORDED   = 'recorded';
    public const UNCHANGED  = 'unchanged';
    public const INVALID    = 'invalid_band';
    public const BELOW_AGE  = 'below_age_floor';
    public const NO_PLAYER  = 'no_player';

    private PlayerPotentialRepository $repo;

    public function __construct( ?PlayerPotentialRepository $repo = null ) {
        $this->repo = $repo ?? new PlayerPotentialRepository();
    }

    /**
     * Record a band for a player, or explain why not.
     *
     * `result` is the outcome, never an exception: a squad grid saving
     * twenty rows has to be able to report "eighteen saved, one unchanged,
     * one too young" rather than abandoning the batch on the first row that
     * does not apply.
     *
     * @return array{result:string,id:int,band:string,set_at:string}
     */
    public function record( int $player_id, string $band, ?string $notes = null ): array {
        $empty = [ 'result' => self::NO_PLAYER, 'id' => 0, 'band' => $band, 'set_at' => '' ];

        if ( $player_id <= 0 ) return $empty;

        if ( ! PotentialBand::isValid( $band ) ) {
            return [ 'result' => self::INVALID, 'id' => 0, 'band' => $band, 'set_at' => '' ];
        }

        if ( ! PlayerStatusModule::potentialAppliesToPlayer( $player_id ) ) {
            return [ 'result' => self::BELOW_AGE, 'id' => 0, 'band' => $band, 'set_at' => '' ];
        }

        $notes  = ( $notes === null || trim( $notes ) === '' ) ? null : $notes;
        $latest = $this->repo->latestFor( $player_id );

        if ( $latest && (string) ( $latest->potential_band ?? '' ) === $band && $notes === null ) {
            return [
                'result' => self::UNCHANGED,
                'id'     => (int) ( $latest->id ?? 0 ),
                'band'   => $band,
                'set_at' => (string) ( $latest->set_at ?? '' ),
            ];
        }

        $set_at = current_time( 'mysql' );
        $row    = [
            'player_id'      => $player_id,
            'potential_band' => $band,
        ];
        // The repository's shape makes `notes` optional rather than
        // nullable; omitting it is how "no note" is spelled.
        if ( $notes !== null ) $row['notes'] = $notes;
        $id = $this->repo->create( $row );

        return [ 'result' => self::RECORDED, 'id' => $id, 'band' => $band, 'set_at' => $set_at ];
    }
}
