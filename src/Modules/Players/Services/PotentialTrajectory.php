<?php
namespace TT\Modules\Players\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Security\RecordRedactor;
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
     * Single source for every surface that shows a band: the popover, the
     * card, the capture screen, this trajectory, the potential overview,
     * the KPI export and the PDP evidence. The label is the `potential_band`
     * lookup's, in the reader's language, so an academy that renames a band
     * sees the new name everywhere (#3981). The keys and their order stay
     * fixed in `PotentialBand`, so a rename never moves a player's status.
     *
     * A lookup row with no label of its own for the reader's language falls
     * back to the shipped label below rather than to the raw key.
     *
     * @return array<string,string> band code => label
     */
    public static function labels(): array {
        $out = [];
        foreach ( self::defaultLabels() as $key => $fallback ) {
            $label       = LookupTranslator::byTypeAndName( 'potential_band', $key );
            $out[ $key ] = ( $label === '' || $label === $key ) ? $fallback : $label;
        }
        return $out;
    }

    /**
     * The shipped English labels, which the migration seeds into the lookup
     * and which stand in when a row carries no label for the reader.
     *
     * @return array<string,string> band code => label
     */
    public static function defaultLabels(): array {
        return [
            PotentialBand::EXCEPTIONAL => _x( 'Exceptional', 'potential band', 'talenttrack' ),
            PotentialBand::AHEAD       => _x( 'Ahead of age group', 'potential band', 'talenttrack' ),
            PotentialBand::ON_TRACK    => _x( 'On track', 'potential band', 'talenttrack' ),
            PotentialBand::NEEDS_TIME  => _x( 'Needs time', 'potential band', 'talenttrack' ),
            PotentialBand::BELOW_LEVEL => _x( 'Below academy level', 'potential band', 'talenttrack' ),
        ];
    }

    /**
     * The label for one band. A retired key still renders under its
     * successor's name, and a value the vocabulary never had renders as
     * itself rather than disappearing.
     */
    public static function labelFor( string $band ): string {
        $labels = self::labels();
        return $labels[ PotentialBand::normalise( $band ) ] ?? $band;
    }

    /**
     * The player's potential entries, **oldest first**, each carrying the
     * direction of the change that produced it.
     *
     * Oldest-first because a trajectory is read forwards; the repository
     * returns newest-first for the "what is it now" question, which is a
     * different one. A surface that wants newest-first can reverse it.
     *
     * #4694 — as this reader may read it: see {@see self::seriesFrom()}.
     *
     * @param int|null $reader_id the reader; the current user when null.
     * @return list<array{
     *     id:int, player_id:int, band?:string, label?:string, set_at:string, set_by:int,
     *     set_by_name:string, notes?:string, direction?:string, steps?:int
     * }>
     */
    public function forPlayer( int $player_id, ?int $reader_id = null ): array {
        if ( $player_id <= 0 ) return [];

        return self::seriesFrom( $this->repo->historyFor( $player_id ), $reader_id );
    }

    /**
     * The same decoration, from rows a caller already holds (#3412).
     *
     * A squad-level read fetches one player's whole cohort in a single
     * query and groups in PHP; without this it would have to re-derive
     * direction, and a second implementation of "a lower index is a better
     * band" is exactly the thing that ends up backwards on one surface.
     *
     * #4694 — the series comes as this reader may read it. Where the
     * `potential_judgement` rule hides the band from them, each entry keeps
     * its id, its player, when it was set and by whom, and leaves out the
     * band, its label, its direction, its steps and its notes
     * (`config/sensitive_fields.php`, `player_potential`), as a REST answer
     * leaves out every masked field: no `null`, no flag. The direction is
     * worked out from the bands first, so the masking changes nothing for
     * the next entry.
     *
     * @param list<object> $history newest-first, as the repository returns it
     * @param int|null     $reader_id the reader; the current user when null.
     * @return list<array{
     *     id:int, player_id:int, band?:string, label?:string, set_at:string, set_by:int,
     *     set_by_name:string, notes?:string, direction?:string, steps?:int
     * }>
     */
    public static function seriesFrom( array $history, ?int $reader_id = null ): array {
        $reader_id = $reader_id ?? get_current_user_id();
        $rows      = array_reverse( $history );

        $out       = [];
        $prev_rank = null;

        foreach ( $rows as $row ) {
            // #3981 — a retired key reads as its successor, so a response
            // never carries the old vocabulary.
            $band = PotentialBand::normalise( (string) ( $row->potential_band ?? '' ) );
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

            $set_by    = (int) ( $row->set_by ?? 0 );
            $player_id = (int) ( $row->player_id ?? 0 );

            $entry = [
                'id'          => (int) ( $row->id ?? 0 ),
                'player_id'   => $player_id,
                'band'        => $band,
                'label'       => self::labelFor( $band ),
                'set_at'      => (string) ( $row->set_at ?? $row->created_at ?? '' ),
                'set_by'      => $set_by,
                'set_by_name' => self::userName( $set_by ),
                'notes'       => (string) ( $row->notes ?? '' ),
                'direction'   => $direction,
                'steps'       => $steps,
            ];
            $out[] = self::forReader( $entry, $player_id, $reader_id );

            if ( $rank !== null ) $prev_rank = $rank;
        }

        return $out;
    }

    /**
     * #4694 — is this player's potential band hidden from this reader? The
     * `potential_judgement` rule of the sensitive-field registry, asked once
     * per reader and player. Every source that hands out a band asks this.
     *
     * A reader of 0 is a reader whose rights cannot be checked, such as a
     * scheduled report's recipient without an account here, and counts as
     * one with no rights: the band is hidden from them, as the evaluation
     * notes are. Every view and REST route passes the signed-in user.
     */
    public static function bandHiddenFrom( int $reader_id, int $player_id ): bool {
        if ( $reader_id <= 0 ) return true;
        // A row that names no player: masked exactly for the shape the rule
        // masks, whoever the player turns out to be.
        if ( $player_id <= 0 ) return \TT\Modules\Players\PotentialAccess::readsWithoutJudgement( $reader_id );
        return RecordRedactor::isMasked( 'player_potential', 'band', $reader_id, $player_id );
    }

    /**
     * #4694 — a row that carries a potential band, as a reader the band is
     * hidden from reads it: without any key the registry masks for
     * `player_potential`, and with `band_hidden`, so a report composed for
     * several readers can say "Hidden" rather than "not recorded" (the
     * `notes_hidden` of `PlayerSectionRights::withoutEvaluationNotes()`).
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function withoutJudgement( array $row ): array {
        foreach ( array_keys( RecordRedactor::fields( 'player_potential' ) ) as $field ) {
            unset( $row[ $field ] );
        }
        $row['band_hidden'] = true;
        return $row;
    }

    /**
     * One decorated entry as this reader may read it.
     *
     * @param array{id:int, player_id:int, band:string, label:string, set_at:string, set_by:int, set_by_name:string, notes:string, direction:string, steps:int} $entry
     * @return array{id:int, player_id:int, band?:string, label?:string, set_at:string, set_by:int, set_by_name:string, notes?:string, direction?:string, steps?:int}
     */
    private static function forReader( array $entry, int $player_id, int $reader_id ): array {
        if ( ! self::bandHiddenFrom( $reader_id, $player_id ) ) return $entry;
        return [
            'id'          => $entry['id'],
            'player_id'   => $entry['player_id'],
            'set_at'      => $entry['set_at'],
            'set_by'      => $entry['set_by'],
            'set_by_name' => $entry['set_by_name'],
        ];
    }

    /**
     * The band's position, for a caller that sorts by it (#3412).
     *
     * Public because ordering a squad best-band-first is the same question
     * as "which of these two is higher", and a report computing it from a
     * local copy of the vocabulary is how the two drift apart.
     */
    public static function rank( string $band ): ?int {
        return self::rankOf( $band );
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
