<?php
/**
 * Migration: 0253_backfill_profile_physique
 *
 * #3282 — the profile height and weight catch up with the readings that
 * predate the sync.
 *
 * WHAT WAS INCOMPLETE
 *
 * #3219 made `tt_players.height_cm` follow the dated height series and #3281
 * did the same for `weight_kg`. Both work by subscribing to
 * `tt_measurement_result_saved`, which fires on create, update and archive —
 * so they are, by construction, forward-looking. Neither shipped a backfill.
 *
 * The consequence on every install that existed before those releases: a
 * player's profile only becomes correct if somebody happens to re-save one of
 * their readings. Until then the series is right, the column holds whatever
 * was typed at signup, and the two disagree exactly as they did before the
 * fix — for the majority of players, since most readings predate it. That is
 * what a pilot reported: a recorded height, an empty profile field, and
 * nothing on screen to explain the gap.
 *
 * WHY IT CALLS THE SERVICE INSTEAD OF WRITING SQL
 *
 * `ProfileMeasurementSync::syncFor()` already owns every rule this backfill
 * needs, and each of them is a rule the naive version gets wrong:
 *
 *   - the most recent ACTIVE reading wins, archived and trashed rows ignored;
 *   - the reading is converted out of its stored base unit (#3273), so a
 *     height recorded as 1.72 m lands as 172 and not as 2;
 *   - an implausible value is refused rather than written;
 *   - a player with no usable reading is left alone — the column may predate
 *     the series entirely, and losing a number is worse than keeping an old
 *     one;
 *   - a value that already matches is not rewritten, which is also what makes
 *     this migration idempotent.
 *
 * A second copy of that in SQL here would be a second thing to keep right.
 *
 * WHY IT IS BATCHED
 *
 * An academy with several seasons of testing data has thousands of results.
 * Loading every candidate player at once is how a plugin update times out on
 * shared hosting, so the player ids are walked in chunks and each player costs
 * two indexed lookups.
 *
 * SCOPE
 *
 * Both figures, because #3281 merged before this did.
 *
 * Tenancy is whatever `ProfileMeasurementSync` makes it. It resolves and
 * writes against `CurrentClub::id()`, which is the single club every install
 * has today; the candidate query deliberately does not filter by club, so if
 * a row ever belongs to another tenant the service's own club-scoped UPDATE
 * matches nothing and the player is skipped rather than crossed over. When
 * tenancy becomes real this migration is not what needs revisiting — the
 * service is.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;
use TT\Modules\Measurements\Services\ProfileMeasurementSync;

return new class extends Migration {

    /** How many players to resolve per pass. */
    private const BATCH = 200;

    public function getName(): string {
        return '0253_backfill_profile_physique';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Nothing to do on an install without the measurement tables — a
        // fresh site runs this migration in the same pass that creates them,
        // and there are no readings to backfill either way.
        $results = $p . 'tt_measurement_results';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $results ) ) ) {
            return;
        }

        $sync   = new ProfileMeasurementSync();
        $names  = array_merge( BmiSeriesBuilder::HEIGHT_NAMES, BmiSeriesBuilder::WEIGHT_NAMES );
        $marks  = implode( ',', array_fill( 0, count( $names ), '%s' ) );

        $offset = 0;
        while ( true ) {
            // Only players who actually have a height or weight reading. A
            // player with none is not a candidate — see "left alone" above.
            $sql = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT DISTINCT r.player_id
                   FROM {$results} r
                   JOIN {$p}tt_measurement_definitions d ON d.id = r.definition_id
                  WHERE r.archived_at IS NULL
                    AND r.trashed_at IS NULL
                    AND r.value_numeric IS NOT NULL
                    AND r.value_numeric > 0
                    AND LOWER(TRIM(d.name)) IN ({$marks})
               ORDER BY r.player_id ASC
                  LIMIT %d OFFSET %d",
                array_merge( $names, [ self::BATCH, $offset ] )
            );

            $player_ids = $wpdb->get_col( $sql );
            if ( empty( $player_ids ) ) break;

            foreach ( $player_ids as $player_id ) {
                // No `$kind`: resolve both figures for this player. Each is
                // independent — a player may have heights and no weights.
                $sync->syncFor( (int) $player_id );
            }

            if ( count( $player_ids ) < self::BATCH ) break;
            $offset += self::BATCH;
        }
    }
};
