<?php
namespace TT\Modules\Measurements\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;
use TT\Modules\Measurements\Units\UnitContext;

/**
 * ProfileMeasurementSync (#3219, #3281) — keeps `tt_players.height_cm` and
 * `tt_players.weight_kg` following the dated readings.
 *
 * A player's height and weight were stored in two places that never agreed.
 * The player row carries two undated integers, typed by hand on the player
 * form, in wp-admin, through CSV import or over REST; the measurements module
 * carries a dated series against definitions the academy names itself. Nothing
 * connected them, so the numbers a coach reads off a profile were whatever was
 * entered at signup — wrong within months for exactly the players in a growth
 * spurt.
 *
 * #3219 connected height. Weight was left behind, which made the profile half
 * right in a way nothing on screen explained: an academy weighing its squad
 * every cycle had a correct dated series and a profile still showing the
 * signup number. #3281 generalised this class to both rather than adding a
 * second service beside it — two copies of these rules, one word apart, is how
 * a range guard gets fixed in one of them a year from now.
 *
 * WHY THE PROFILE FOLLOWS, AND NOT THE OTHER WAY ROUND
 *
 * A dated reading is a measurement; an undated edit is a recollection. So the
 * series wins, and the profile columns become a cache of its most recent
 * entries. The form stays — an academy that does not run measurement sessions
 * still needs somewhere to put a height and a weight — but as soon as a real
 * reading exists it takes over.
 *
 * {@see BmiSeriesBuilder} deliberately does NOT read the columns this class
 * writes, and must not start: a BMI needs the height that was true on the day
 * of the weight, not the latest one. The two coexist on purpose.
 */
class ProfileMeasurementSync {

    /**
     * What this class knows how to sync: the player column, the vocabulary
     * that feeds it, the unit its column is denominated in, and the band a
     * value has to be inside to be believable.
     *
     * The bands are not decoration. `height_cm` and `weight_kg` are both
     * `SMALLINT UNSIGNED`, and a fat-fingered `1720` must not reach a
     * player's profile.
     *
     * The weight floor is 10 kg and NOT the `min="20"` the wp-admin player
     * form advertises, which is wrong for this product: the seeded U7 squad
     * on a demo install carries a real recorded weight of 17.9 kg, and a
     * six-year-old at 18 kg is ordinary rather than a typo. Inheriting that
     * 20 would have made the sync silently refuse the youngest players in
     * the academy — the exact group whose numbers move fastest. 10 kg is
     * below any plausible academy player while still catching a gross
     * mistype. (The form's own `min` has the same defect and is not fixed
     * here; it stops an operator typing a real U7 weight by hand.)
     */
    public const HEIGHT = 'height';
    public const WEIGHT = 'weight';

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
     * Re-resolve the player's height or weight after a result write.
     *
     * Returns early for a result that is neither, so an ordinary sprint time
     * does not cost a second query, and re-resolves only the figure that was
     * touched — a weight save has no bearing on which height is the latest.
     */
    public function onResultSaved( int $result_id, int $player_id ): void {
        if ( $result_id <= 0 ) return;

        $kind = $this->kindOfResult( $result_id );
        if ( $kind === null ) return;

        if ( $player_id <= 0 ) {
            global $wpdb;
            $p = $wpdb->prefix;
            $player_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT player_id FROM {$p}tt_measurement_results WHERE id = %d AND club_id = %d",
                $result_id,
                CurrentClub::id()
            ) );
        }

        $this->syncFor( $player_id, $kind );
    }

    /**
     * Write the player's latest reading(s) onto their player row.
     *
     * Pass a `$kind` to sync one figure, or omit it to re-resolve both — the
     * shape a backfill wants.
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
     * @param self::HEIGHT|self::WEIGHT|null $kind
     * @return bool Whether the player row was actually updated.
     */
    public function syncFor( int $player_id, ?string $kind = null ): bool {
        if ( $player_id <= 0 ) return false;

        if ( $kind === null ) {
            // Two statements, not `||`, so a height write is not skipped by a
            // weight that short-circuits ahead of it.
            $height = $this->syncFor( $player_id, self::HEIGHT );
            $weight = $this->syncFor( $player_id, self::WEIGHT );
            return $height || $weight;
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        if ( $kind === self::WEIGHT ) {
            $latest = $this->latestWeightKg( $player_id, $club_id );
            $column = 'weight_kg';
            $min    = 10;
            $max    = 200;
        } else {
            $latest = $this->latestHeightCm( $player_id, $club_id );
            $column = 'height_cm';
            $min    = 50;
            $max    = 250;
        }

        if ( $latest === null ) return false;

        // Both columns are SMALLINT UNSIGNED (migration 0001), so a reading
        // recorded as 172.4 becomes 172. Guard the range as well: a typo of
        // 1720 must not be written to a player's profile.
        $rounded = (int) round( $latest );
        if ( $rounded < $min || $rounded > $max ) return false;

        // Both columns are read in one literal statement and the right one
        // picked in PHP. Interpolating the column name would build the query
        // string at runtime, and `prepare()` wants a literal at PHPStan
        // level 8 — see the note on `latestHeightCm()`.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT height_cm, weight_kg FROM {$p}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            $club_id
        ) );
        if ( ! $row ) return false;

        $current = $column === 'weight_kg' ? $row->weight_kg : $row->height_cm;
        if ( $current !== null && (int) $current === $rounded ) return false;

        return false !== $wpdb->update(
            $p . 'tt_players',
            [ $column => $rounded ],
            [ 'id' => $player_id, 'club_id' => $club_id ]
        );
    }

    /**
     * The player's most recent active height reading in centimetres, or null.
     *
     * Matches {@see BmiSeriesBuilder::readings()} — the same name list, the
     * same lifecycle predicate — so the profile figures and the BMI series
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
     *
     * This is why height and weight get a query each rather than one shared
     * one: the vocabularies are different lengths (four names against three),
     * and a generated placeholder list is exactly the thing level 8 refuses.
     */
    public function latestHeightCm( int $player_id, int $club_id ): ?float {
        $names = BmiSeriesBuilder::HEIGHT_NAMES;

        global $wpdb;
        $p = $wpdb->prefix;

        // #3273 — the unit columns travel with the reading. `value_numeric` is
        // the dimension's base (metres for a length), and this method's
        // contract is centimetres, so the conversion is asked for rather than
        // assumed. A definition with no resolvable dimension falls back to the
        // stored number, which is what it has always meant on that install.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.value_numeric,
                    d.unit, d.dimension, d.entry_unit_id, d.numeric_format, d.value_type
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

        if ( ! $row || $row->value_numeric === null ) return null;

        $base = (float) $row->value_numeric;

        return UnitContext::forDefinition( $row )->toSymbol( $base, 'cm' ) ?? $base;
    }

    /**
     * The player's most recent active weight reading in kilograms, or null.
     *
     * The height method's twin, and everything in its docblock applies here
     * with one number changed: `WEIGHT_NAMES` has **three** entries, so this
     * query has three placeholders and
     * `test_the_weight_vocabulary_still_has_three_entries` is its tripwire.
     */
    public function latestWeightKg( int $player_id, int $club_id ): ?float {
        $names = BmiSeriesBuilder::WEIGHT_NAMES;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.value_numeric,
                    d.unit, d.dimension, d.entry_unit_id, d.numeric_format, d.value_type
               FROM {$p}tt_measurement_results r
               JOIN {$p}tt_measurement_definitions d ON d.id = r.definition_id
              WHERE r.player_id = %d
                AND r.club_id = %d
                AND r.archived_at IS NULL
                AND r.trashed_at IS NULL
                AND r.value_numeric IS NOT NULL
                AND r.value_numeric > 0
                AND LOWER(TRIM(d.name)) IN (%s, %s, %s)
           ORDER BY r.recorded_date DESC, r.id DESC
              LIMIT 1",
            $player_id,
            $club_id,
            $names[0],
            $names[1],
            $names[2]
        ) );

        if ( ! $row || $row->value_numeric === null ) return null;

        $base = (float) $row->value_numeric;

        return UnitContext::forDefinition( $row )->toSymbol( $base, 'kg' ) ?? $base;
    }

    /**
     * Which figure a result belongs to, or null for anything else.
     *
     * Deliberately does NOT filter on lifecycle: archiving is one of the
     * three paths that announce a save, and the row is already archived by
     * the time this runs. Filtering here would make an archived height look
     * like an unrelated test and skip the re-resolve that archive exists to
     * trigger.
     *
     * @return self::HEIGHT|self::WEIGHT|null
     */
    private function kindOfResult( int $result_id ): ?string {
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

        if ( $name === null ) return null;

        $name = (string) $name;
        if ( in_array( $name, BmiSeriesBuilder::HEIGHT_NAMES, true ) ) return self::HEIGHT;
        if ( in_array( $name, BmiSeriesBuilder::WEIGHT_NAMES, true ) ) return self::WEIGHT;

        return null;
    }
}
