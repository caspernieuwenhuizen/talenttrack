<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\ProfileMeasurementSync;

/**
 * #3219, #3281 — `tt_players.height_cm` and `tt_players.weight_kg` follow the
 * dated readings.
 *
 * The two cases a naive implementation gets wrong are the reason this file
 * exists: an edit that backdates a result, and an archive that promotes an
 * older row back to being the latest. In both, the row that triggered the
 * sync is not the row to copy, so the sync has to re-resolve rather than
 * trust what it was handed.
 *
 * #3281 generalised the service from height to both figures. The weight cases
 * below mirror the height ones deliberately: the rules are meant to be the
 * same rules, and a divergence should show up as a failing assertion rather
 * than as a coach noticing that one figure updates and the other does not.
 */
final class ProfileMeasurementSyncTest extends WP_UnitTestCase {

    private int $club;
    private int $height_def;
    private int $weight_def;
    private int $sprint_def;
    private int $player_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->club = (int) CurrentClub::id();

        $this->height_def = $this->definition( 'Lengte' );
        $this->weight_def = $this->definition( 'Gewicht' );
        $this->sprint_def = $this->definition( 'Sprint 10m' );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => $this->club,
            'first_name' => 'Growth',
            'last_name'  => 'Player',
            'status'     => 'active',
            'height_cm'  => 160,
            'weight_kg'  => 50,
        ] );
        $this->player_id = (int) $wpdb->insert_id;
    }

    private function definition( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => $this->club,
            'category_id' => 1,
            'name'        => $name,
            'value_type'  => 'numeric',
            'direction'   => 'neutral',
            'is_active'   => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function repo(): MeasurementResultsRepository {
        return new MeasurementResultsRepository();
    }

    private function record( int $definition_id, string $date, float $value ): int {
        return $this->repo()->create( [
            'club_id'       => $this->club,
            'player_id'     => $this->player_id,
            'definition_id' => $definition_id,
            'recorded_date' => $date,
            'value_numeric' => $value,
        ] );
    }

    private function profileHeight(): ?int {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT height_cm FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $this->player_id
        ) );
        return $v === null ? null : (int) $v;
    }

    private function profileWeight(): ?int {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $this->player_id
        ) );
        return $v === null ? null : (int) $v;
    }

    /**
     * The `tt_measurement_result_saved` subscriber is wired in
     * `MeasurementsModule::boot()`, which the test bootstrap may not have
     * run. Drive the service directly so the assertions are about the sync
     * rather than about module wiring.
     */
    private function sync( int $result_id ): void {
        ( new ProfileMeasurementSync() )->onResultSaved( $result_id, $this->player_id );
    }

    public function test_recording_a_height_updates_the_profile(): void {
        $id = $this->record( $this->height_def, '2026-03-01', 172.0 );
        $this->sync( $id );

        $this->assertSame( 172, $this->profileHeight() );
    }

    public function test_a_non_height_measurement_changes_nothing(): void {
        $id = $this->record( $this->sprint_def, '2026-03-01', 1.83 );
        $this->sync( $id );

        $this->assertSame( 160, $this->profileHeight() );
    }

    public function test_a_decimal_reading_is_rounded_to_the_column(): void {
        $id = $this->record( $this->height_def, '2026-03-01', 172.6 );
        $this->sync( $id );

        $this->assertSame( 173, $this->profileHeight() );
    }

    /**
     * The backdated-edit case. Two readings exist; editing the OLDER one
     * must leave the profile on the newer value, not copy what was just
     * written.
     */
    public function test_editing_an_older_reading_does_not_overwrite_the_latest(): void {
        $old = $this->record( $this->height_def, '2026-01-01', 168.0 );
        $new = $this->record( $this->height_def, '2026-06-01', 176.0 );
        $this->sync( $new );
        $this->assertSame( 176, $this->profileHeight() );

        $this->repo()->update( $old, [ 'value_numeric' => 169.0 ] );
        $this->sync( $old );

        $this->assertSame(
            176,
            $this->profileHeight(),
            'Editing a superseded reading must not pull the profile back to it.'
        );
    }

    /**
     * The other direction of the same trap: an edit that moves an existing
     * reading forward in time makes it the latest, and the profile must
     * follow it.
     */
    public function test_moving_a_reading_forward_makes_it_the_latest(): void {
        $old = $this->record( $this->height_def, '2026-01-01', 168.0 );
        $new = $this->record( $this->height_def, '2026-06-01', 176.0 );
        $this->sync( $new );

        $this->repo()->update( $old, [ 'recorded_date' => '2026-09-01' ] );
        $this->sync( $old );

        $this->assertSame( 168, $this->profileHeight() );
    }

    /**
     * The archived-latest case. Archiving the newest reading promotes the
     * previous one, and the profile follows it back down.
     */
    public function test_archiving_the_latest_falls_back_to_the_previous_reading(): void {
        $this->record( $this->height_def, '2026-01-01', 168.0 );
        $new = $this->record( $this->height_def, '2026-06-01', 176.0 );
        $this->sync( $new );
        $this->assertSame( 176, $this->profileHeight() );

        $this->repo()->archive( $new, 1 );
        $this->sync( $new );

        $this->assertSame( 168, $this->profileHeight() );
    }

    /**
     * Archiving the only reading leaves the profile alone. The value there
     * may predate the measurement series entirely, and losing a number is
     * worse than keeping an old one.
     */
    public function test_archiving_the_only_reading_leaves_the_profile_value(): void {
        $id = $this->record( $this->height_def, '2026-03-01', 172.0 );
        $this->sync( $id );
        $this->assertSame( 172, $this->profileHeight() );

        $this->repo()->archive( $id, 1 );
        $this->sync( $id );

        $this->assertSame( 172, $this->profileHeight(), 'The last height must not blank the profile.' );
    }

    /**
     * The sync spells its lifecycle predicate out rather than calling
     * `ArchiveRepository::filterClause( 'active', 'r' )`, because
     * `prepare()` takes a literal string at PHPStan level 8. This is the
     * tripwire for that duplication: if "active" ever stops meaning
     * archived-and-trashed-are-null, this fails rather than the profile
     * quietly picking up a reading somebody deleted.
     */
    public function test_a_trashed_reading_is_ignored(): void {
        global $wpdb;
        $keep = $this->record( $this->height_def, '2026-01-01', 168.0 );
        $gone = $this->record( $this->height_def, '2026-06-01', 176.0 );

        $wpdb->update(
            $wpdb->prefix . 'tt_measurement_results',
            [ 'trashed_at' => current_time( 'mysql', true ) ],
            [ 'id' => $gone ]
        );

        $this->sync( $keep );

        $this->assertSame( 168, $this->profileHeight() );
    }

    /**
     * The `IN` list in `latestHeightCm()` is four fixed placeholders, for
     * the same literal-string reason. If the vocabulary grows, that query
     * silently stops matching the new name — so fail here instead.
     */
    public function test_the_height_vocabulary_still_has_four_entries(): void {
        $this->assertCount(
            4,
            \TT\Modules\Measurements\Growth\BmiSeriesBuilder::HEIGHT_NAMES,
            'ProfileMeasurementSync::latestHeightCm() hardcodes four placeholders to stay a literal string. '
            . 'Adding a height name means widening that IN list in the same commit.'
        );
    }

    /**
     * The weight query's twin tripwire. Three, not four — which is exactly
     * why the two figures get a query each instead of one shared one.
     */
    public function test_the_weight_vocabulary_still_has_three_entries(): void {
        $this->assertCount(
            3,
            \TT\Modules\Measurements\Growth\BmiSeriesBuilder::WEIGHT_NAMES,
            'ProfileMeasurementSync::latestWeightKg() hardcodes three placeholders to stay a literal string. '
            . 'Adding a weight name means widening that IN list in the same commit.'
        );
    }

    // ---------------------------------------------------------------------
    // #3281 — weight. Each of these mirrors a height case above.
    // ---------------------------------------------------------------------

    public function test_recording_a_weight_updates_the_profile(): void {
        $id = $this->record( $this->weight_def, '2026-03-01', 64.0 );
        $this->sync( $id );

        $this->assertSame( 64, $this->profileWeight() );
        $this->assertSame( 160, $this->profileHeight(), 'a weight write leaves the height alone' );
    }

    /**
     * The case the naive version gets wrong: correcting an older reading
     * must not overwrite the profile with it.
     */
    public function test_editing_an_older_weight_leaves_the_latest_in_place(): void {
        $old = $this->record( $this->weight_def, '2026-01-01', 60.0 );
        $this->sync( $old );

        $new = $this->record( $this->weight_def, '2026-06-01', 66.0 );
        $this->sync( $new );
        $this->assertSame( 66, $this->profileWeight() );

        $this->repo()->update( $old, [ 'value_numeric' => 61.0 ] );
        $this->sync( $old );

        $this->assertSame(
            66,
            $this->profileWeight(),
            'correcting January must not pull the profile back off June'
        );
    }

    /** Archiving the latest promotes the one behind it. */
    public function test_archiving_the_latest_weight_falls_back_to_the_previous(): void {
        $keep = $this->record( $this->weight_def, '2026-01-01', 60.0 );
        $gone = $this->record( $this->weight_def, '2026-06-01', 66.0 );
        $this->sync( $gone );
        $this->assertSame( 66, $this->profileWeight() );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_measurement_results',
            [ 'archived_at' => '2026-07-01 00:00:00' ],
            [ 'id' => $gone ]
        );

        $this->sync( $keep );

        $this->assertSame( 60, $this->profileWeight() );
    }

    /**
     * Archiving the last weight must not blank the profile — the value
     * sitting there may predate the series entirely.
     */
    public function test_archiving_the_last_weight_leaves_the_profile_alone(): void {
        $only = $this->record( $this->weight_def, '2026-03-01', 64.0 );
        $this->sync( $only );
        $this->assertSame( 64, $this->profileWeight() );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_measurement_results',
            [ 'archived_at' => '2026-07-01 00:00:00' ],
            [ 'id' => $only ]
        );

        $this->sync( $only );

        $this->assertSame( 64, $this->profileWeight(), 'losing a number is worse than keeping an old one' );
    }

    /** A mistyped weight must never reach a player's profile. */
    public function test_an_out_of_range_weight_is_refused(): void {
        $id = $this->record( $this->weight_def, '2026-03-01', 640.0 );
        $this->sync( $id );

        $this->assertSame( 50, $this->profileWeight() );
    }

    /**
     * The youngest players are not typos.
     *
     * The wp-admin player form advertises `min="20"`, and taking that as the
     * sync's floor looked reasonable until a demo install's U7 squad turned
     * out to carry a real recorded weight of 17.9 kg. A six-year-old at 18 kg
     * is ordinary, and refusing it would have made the profile stop following
     * the readings for exactly the age group whose numbers move fastest.
     */
    public function test_a_young_player_s_low_weight_is_accepted(): void {
        $id = $this->record( $this->weight_def, '2026-03-01', 17.9 );
        $this->sync( $id );

        $this->assertSame( 18, $this->profileWeight() );
    }

    /**
     * The sibling of `test_a_non_height_measurement_changes_nothing` for the
     * figure #3281 added: a sprint time is now checked against two
     * vocabularies rather than one, and must still match neither.
     */
    public function test_a_non_weight_measurement_leaves_the_weight_alone(): void {
        $id = $this->record( $this->sprint_def, '2026-03-01', 1.9 );
        $this->sync( $id );

        $this->assertSame( 50, $this->profileWeight() );
    }

    /** Omitting the kind re-resolves both figures — the backfill's shape. */
    public function test_syncing_without_a_kind_resolves_both_figures(): void {
        $this->record( $this->height_def, '2026-03-01', 172.0 );
        $this->record( $this->weight_def, '2026-03-01', 64.0 );

        ( new ProfileMeasurementSync() )->syncFor( $this->player_id );

        $this->assertSame( 172, $this->profileHeight() );
        $this->assertSame( 64, $this->profileWeight() );
    }

    /** A mistyped reading must never reach a player's profile. */
    public function test_an_out_of_range_reading_is_refused(): void {
        $id = $this->record( $this->height_def, '2026-03-01', 1720.0 );
        $this->sync( $id );

        $this->assertSame( 160, $this->profileHeight() );
    }

    /** The sync must not reach across tenants. */
    public function test_a_reading_in_another_club_does_not_move_this_profile(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => $this->club + 1,
            'category_id' => 1,
            'name'        => 'Lengte',
            'value_type'  => 'numeric',
            'direction'   => 'neutral',
            'is_active'   => 1,
        ] );
        $other_def = (int) $wpdb->insert_id;

        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', [
            'club_id'       => $this->club + 1,
            'player_id'     => $this->player_id,
            'definition_id' => $other_def,
            'recorded_date' => '2026-06-01',
            'value_numeric' => 190.0,
        ] );

        ( new ProfileMeasurementSync() )->syncFor( $this->player_id );

        $this->assertSame( 160, $this->profileHeight() );
    }
}
