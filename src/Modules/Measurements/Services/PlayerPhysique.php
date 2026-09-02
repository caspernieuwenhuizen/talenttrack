<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerPhysique (#3280) — a player's current height and weight, and where
 * each of them came from.
 *
 * The player profile's Identity card showed neither, while the player's own
 * *My profile* screen showed both: a coach opening a player's file could not
 * read their height, and the player could. This is the read model that fixes
 * that, and it lives here rather than in the view because deciding which of
 * two sources answers is a decision, not composition (CLAUDE.md §4).
 *
 * WHY THE SERIES FIRST, AND THE COLUMN BEHIND IT
 *
 * `tt_players.height_cm` / `weight_kg` are a cache of the dated series, kept
 * current by {@see ProfileMeasurementSync}. That cache only started following
 * the readings in #3219 (height) and #3281 (weight) and is backfilled by a
 * migration, so on any install it can be stale for a player whose readings
 * predate the sync. Reading the series first makes the card correct whatever
 * state the cache is in.
 *
 * The column is still the fallback rather than dead weight: an academy that
 * runs no testing sessions types these numbers on the player form, and that
 * is the whole of what it has.
 *
 * The date is part of the answer. "132 cm" tells a coach nothing about
 * whether it is from this season, which is exactly the question a growing
 * child's height raises.
 */
class PlayerPhysique {

    private ProfileMeasurementSync $sync;

    public function __construct( ?ProfileMeasurementSync $sync = null ) {
        $this->sync = $sync ?? new ProfileMeasurementSync();
    }

    /**
     * Height and weight for one player, each with its provenance.
     *
     * A figure is absent from the result entirely when there is neither a
     * reading nor a profile value — the caller renders no row at all rather
     * than an em dash, which is how every other optional Identity row
     * behaves.
     *
     * `measured_on` is null for a profile-column value, because there is no
     * date to show: nobody recorded when that number was true.
     *
     * @param object|null $player the already-loaded player row, when the
     *        caller has one, so the fallback costs no second query.
     *
     * @return array{
     *   height?: array{value: float, unit: string, measured_on: ?string, from_reading: bool},
     *   weight?: array{value: float, unit: string, measured_on: ?string, from_reading: bool}
     * }
     */
    public function forPlayer( int $player_id, ?object $player = null, bool $may_read_measurements = true ): array {
        if ( $player_id <= 0 ) return [];

        $club_id = CurrentClub::id();
        $out     = [];

        // A reading is measurement data; the profile column is on the player
        // record and is already visible to anyone who can open the edit form.
        // A viewer without measurements access therefore falls through to the
        // column rather than being shown a reading they may not read. These
        // are minors — when the two sources disagree, the caller with less
        // access sees less.
        $height = $may_read_measurements ? $this->sync->latestHeightReading( $player_id, $club_id ) : null;
        $weight = $may_read_measurements ? $this->sync->latestWeightReading( $player_id, $club_id ) : null;

        if ( $height !== null ) {
            $out['height'] = [
                'value'        => $height['value'],
                'unit'         => 'cm',
                'measured_on'  => $height['date'] !== '' ? $height['date'] : null,
                'from_reading' => true,
            ];
        } elseif ( $player !== null && ! empty( $player->height_cm ) ) {
            $out['height'] = [
                'value'        => (float) $player->height_cm,
                'unit'         => 'cm',
                'measured_on'  => null,
                'from_reading' => false,
            ];
        }

        if ( $weight !== null ) {
            $out['weight'] = [
                'value'        => $weight['value'],
                'unit'         => 'kg',
                'measured_on'  => $weight['date'] !== '' ? $weight['date'] : null,
                'from_reading' => true,
            ];
        } elseif ( $player !== null && ! empty( $player->weight_kg ) ) {
            $out['weight'] = [
                'value'        => (float) $player->weight_kg,
                'unit'         => 'kg',
                'measured_on'  => null,
                'from_reading' => false,
            ];
        }

        return $out;
    }
}
