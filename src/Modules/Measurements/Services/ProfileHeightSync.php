<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;

/**
 * ProfileHeightSync (#3219) — keeps `tt_players.height_cm` following the
 * dated height readings.
 *
 * A player's height was stored in two places that never agreed. The player
 * row carries one undated integer, typed by hand on the player form, in
 * wp-admin, through CSV import or over REST; the measurements module carries
 * a dated series against a definition the academy names itself. Nothing
 * connected them, so the number a coach reads off a profile was whatever was
 * entered at signup — wrong within months for exactly the players in a growth
 * spurt.
 *
 * WHY THE PROFILE FOLLOWS, AND NOT THE OTHER WAY ROUND
 *
 * A dated reading is a measurement; an undated edit is a recollection. So the
 * series wins, and the profile column becomes a cache of its most recent
 * entry. The form stays — an academy that does not run measurement sessions
 * still needs somewhere to put a height — but as soon as a real reading
 * exists it takes over.
 *
 * {@see BmiSeriesBuilder} deliberately does NOT read the column this class
 * writes, and must not start: a BMI needs the height that was true on the day
 * of the weight, not the latest one. The two coexist on purpose.
 */
class ProfileHeightSync {

    /**
     * Subscribe to the one hook every result write already announces.
     *
     * `MeasurementResultsRepository` fires `tt_measurement_result_saved` from
     * create, update and archive alike, which is exactly the set of events
     * that can change which reading is the latest.
     */
    public static function boot(): void {
        add_action(
            'tt_measurement_result_saved',
            static function ( $result_id, $player_id = 0 ): void {
                ( new self() )->onResultSaved( (int) $result_id, (int) $player_id );
            },
            10,
            2
        );
    }

    /**
     * Re-resolve the player's height after a result write.
     *
     * Returns early for a result that is not a height so an ordinary sprint
     * time does not cost a second query, and does nothing at all when the
     * player has no readings left — see {@see self::syncFor()}.
     */
    public function onResultSaved( int $result_id, int $player_id ): void {
        if ( $result_id <= 0 ) return;
        if ( ! $this->isHeightResult( $result_id ) ) return;

        if ( $player_id <= 0 ) {
            global $wpdb;
            $p = $wpdb->prefix;
            $player_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT player_id FROM {$p}tt_measurement_results WHERE id = %d AND club_id = %d",
                $result_id,
                CurrentClub::id()
            ) );
        }

        $this->syncFor( $player_id );
    }

    /**
     * Write the player's latest height reading onto their player row.
     *
     * Three behaviours worth stating, because the naive version of this
     * method gets each of them wrong:
     *
     * - It re-resolves the latest reading rather than trusting the value that
     *   was just written. An edit can backdate a result, and an archive can
     *   promote an older row back to being the latest; in both cases the row
     *   that triggered this is not the one to copy.
     * - It writes nothing when no reading remains. Archiving the last height
     *   should not blank a profile whose value may predate the series
     *   entirely — losing a number is worse than keeping an old one.
     * - It writes nothing when the value already matches, so a save against
     *   an unrelated definition, or a correction that does not move the
     *   latest, leaves the row and its `updated_at` alone.
     *
     * @return bool Whether the player row was actually updated.
     */
    public function syncFor( int $player_id ): bool {
        if ( $player_id <= 0 ) return false;

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $latest = $this->latestHeightCm( $player_id, $club_id );
        if ( $latest === null ) return false;

        // The column is SMALLINT UNSIGNED (migration 0001), so a reading
        // recorded as 172.4 becomes 172. Guard the range as well: a typo of
        // 1720 must not be written to a player's profile.
        $rounded = (int) round( $latest );
        if ( $rounded < 50 || $rounded > 250 ) return false;

        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT height_cm FROM {$p}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            $club_id
        ) );
        if ( $current !== null && (int) $current === $rounded ) return false;

        return false !== $wpdb->update(
            $p . 'tt_players',
            [ 'height_cm' => $rounded ],
            [ 'id' => $player_id, 'club_id' => $club_id ]
        );
    }

    /**
     * The player's most recent active height reading, or null.
     *
     * Matches {@see BmiSeriesBuilder::readings()} — the same name list, the
     * same lifecycle predicate — so the profile height and the BMI series
     * never disagree about which readings count. Ties on a date resolve to
     * the highest id, which is the correction recorded last.
     *
     * Two things are spelled out rather than composed, because `prepare()`
     * takes a literal string at PHPStan level 8 and anything concatenated at
     * runtime is not one:
     *
     * - the lifecycle predicate is written out instead of calling
     *   `ArchiveRepository::filterClause( 'active', 'r' )`. It means the same
     *   thing today, and `test_a_trashed_reading_is_ignored` is the tripwire
     *   if that ever stops being true.
     * - the `IN` list is four fixed placeholders rather than a generated one,
     *   which couples this to the length of `HEIGHT_NAMES`. Two things
     *   catch that coupling breaking, so it is not left to a comment:
     *   PHPStan knows the constant's length and rejects a mismatched index,
     *   and `test_the_height_vocabulary_still_has_four_entries` states the
     *   requirement in words. A runtime count guard was tried and removed —
     *   PHPStan correctly called it dead code, because the length is known
     *   before the program runs.
     */
    public function latestHeightCm( int $player_id, int $club_id ): ?float {
        $names = BmiSeriesBuilder::HEIGHT_NAMES;

        global $wpdb;
        $p = $wpdb->prefix;

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT r.value_numeric
               FROM {$p}tt_measurement_results r
               JOIN {$p}tt_measurement_definitions d ON d.id = r.definition_id
              WHERE r.player_id = %d
                AND r.club_id = %d
                AND r.archived_at IS NULL
                AND r.trashed_at IS NULL
                AND r.value_numeric IS NOT NULL
                AND r.value_numeric > 0
                AND LOWER(TRIM(d.name)) IN (%s, %s, %s, %s)
           ORDER BY r.recorded_date DESC, r.id DESC
              LIMIT 1",
            $player_id,
            $club_id,
            $names[0],
            $names[1],
            $names[2],
            $names[3]
        ) );

        return $value === null ? null : (float) $value;
    }

    /**
     * Whether a result belongs to a height definition.
     *
     * Deliberately does NOT filter on lifecycle: archiving is one of the
     * three paths that announce a save, and the row is already archived by
     * the time this runs. Filtering here would make an archived height look
     * like a non-height and skip the re-resolve that archive exists to
     * trigger.
     */
    private function isHeightResult( int $result_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT LOWER(TRIM(d.name))
               FROM {$p}tt_measurement_results r
               JOIN {$p}tt_measurement_definitions d ON d.id = r.definition_id
              WHERE r.id = %d AND r.club_id = %d",
            $result_id,
            CurrentClub::id()
        ) );

        return $name !== null && in_array( (string) $name, BmiSeriesBuilder::HEIGHT_NAMES, true );
    }
}
