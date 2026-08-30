<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * PotentialTrajectory (#3226) — a player's potential over time, with the
 * direction of each revision.
 *
 * `tt_player_potential` has been append-only since migration 0042: one row
 * per change, and `latestFor()` for the current value. The whole history
 * was therefore already stored, and read by nothing a user could see —
 * only `EvidencePacket` consumed it, inside the PDP. The profile and the
 * capture screen both showed the current band alone.
 *
 * That loses the part that matters. A single band is a label; a sequence
 * is the academy changing its mind, and the direction is the signal:
 *
 *   - revised **down** twice in a season — something is going wrong, and it
 *     was noticed twice before anybody said it out loud;
 *   - revised **up** — the thing worth pointing at in a PDP conversation or
 *     a parent meeting;
 *   - never revised — indistinguishable from a flat line until now.
 *
 * CLAUDE.md §1 asks for progression rather than snapshots. The storage
 * already honoured that; this is the read model that lets a surface show
 * it, shared by the REST route and the rendered view so both answer the
 * same way (§4).
 *
 * ## Direction is an index, not a value
 *
 * `PotentialBand::ALL` is ordered best-first, so a *lower* index is a
 * higher band. Comparing positions rather than strings is what makes
 * "revised up" meaningful, and it keeps working if the vocabulary gains a
 * band in the middle.
 */
class PotentialTrajectory {

    public const UP    = 'up';
    public const DOWN  = 'down';
    public const SAME  = 'same';
    public const FIRST = 'first';

    private PlayerPotentialRepository $repo;

    public function __construct( ?PlayerPotentialRepository $repo = null ) {
        $this->repo = $repo ?? new PlayerPotentialRepository();
    }

    /**
     * The band vocabulary with its human labels, best band first.
     *
     * Single source: `FrontendPlayerDetailView`'s popover, the capture
     * screen's select and this trajectory all read it here. There were two
     * copies of this map before #3226 and adding a third was not an option.
     *
     * @return array<string,string> band code => label
     */
    public static function labels(): array {
        return [
            PotentialBand::FIRST_TEAM             => __( 'First team', 'talenttrack' ),
            PotentialBand::PROFESSIONAL_ELSEWHERE => __( 'Professional elsewhere', 'talenttrack' ),
            PotentialBand::SEMI_PRO               => __( 'Semi-pro', 'talenttrack' ),
            PotentialBand::TOP_AMATEUR            => __( 'Top amateur', 'talenttrack' ),
            PotentialBand::RECREATIONAL           => __( 'Foundation', 'talenttrack' ),
        ];
    }

    public static function labelFor( string $band ): string {
        $labels = self::labels();
        return $labels[ $band ] ?? $band;
    }

    /**
     * The player's potential entries, **oldest first**, each carrying the
     * direction of the change that produced it.
     *
     * Oldest-first because a trajectory is read forwards; the repository
     * returns newest-first for the "what is it now" question, which is a
     * different one. A surface that wants newest-first can reverse it.
     *
     * @return list<array{
     *     id:int, band:string, label:string, set_at:string, set_by:int,
     *     set_by_name:string, notes:string, direction:string, steps:int
     * }>
     */
    public function forPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        $rows = array_reverse( $this->repo->historyFor( $player_id ) );

        $out       = [];
        $prev_rank = null;

        foreach ( $rows as $row ) {
            $band = (string) ( $row->potential_band ?? '' );
            $rank = self::rankOf( $band );

            if ( $prev_rank === null || $rank === null ) {
                $direction = self::FIRST;
                $steps     = 0;
            } elseif ( $rank === $prev_rank ) {
                $direction = self::SAME;
                $steps     = 0;
            } else {
                // A lower index is a better band, so a decreasing rank is
                // an upward revision.
                $direction = $rank < $prev_rank ? self::UP : self::DOWN;
                $steps     = abs( $rank - $prev_rank );
            }

            $set_by = (int) ( $row->set_by ?? 0 );

            $out[] = [
                'id'          => (int) ( $row->id ?? 0 ),
                'band'        => $band,
                'label'       => self::labelFor( $band ),
                'set_at'      => (string) ( $row->set_at ?? $row->created_at ?? '' ),
                'set_by'      => $set_by,
                'set_by_name' => self::userName( $set_by ),
                'notes'       => (string) ( $row->notes ?? '' ),
                'direction'   => $direction,
                'steps'       => $steps,
            ];

            if ( $rank !== null ) $prev_rank = $rank;
        }

        return $out;
    }

    /**
     * Position of a band in the best-first vocabulary, or null for a value
     * the vocabulary no longer contains — a band retired after it was
     * recorded still has to render, it just cannot be compared.
     */
    private static function rankOf( string $band ): ?int {
        $index = array_search( $band, PotentialBand::ALL, true );
        return $index === false ? null : (int) $index;
    }

    private static function userName( int $user_id ): string {
        if ( $user_id <= 0 ) return '';
        $user = get_userdata( $user_id );
        return $user ? (string) $user->display_name : '';
    }
}
