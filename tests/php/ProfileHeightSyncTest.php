<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\ProfileHeightSync;

/**
 * #3219 — `tt_players.height_cm` follows the dated height readings.
 *
 * The two cases a naive implementation gets wrong are the reason this file
 * exists: an edit that backdates a result, and an archive that promotes an
 * older row back to being the latest. In both, the row that triggered the
 * sync is not the row to copy, so the sync has to re-resolve rather than
 * trust what it was handed.
 */
final class ProfileHeightSyncTest extends WP_UnitTestCase {

    private int $club;
    private int $height_def;
    private int $sprint_def;
    private int $player_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->club = (int) CurrentClub::id();

        $this->height_def = $this->definition( 'Lengte' );
        $this->sprint_def = $this->definition( 'Sprint 10m' );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => $this->club,
            'first_name' => 'Growth',
            'last_name'  => 'Player',
            'status'     => 'active',
            'height_cm'  => 160,
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

    /**
     * The `tt_measurement_result_saved` subscriber is wired in
     * `MeasurementsModule::boot()`, which the test bootstrap may not have
     * run. Drive the service directly so the assertions are about the sync
     * rather than about module wiring.
     */
    private function sync( int $result_id ): void {
        ( new ProfileHeightSync() )->onResultSaved( $result_id, $this->player_id );
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
            'ProfileHeightSync::latestHeightCm() hardcodes four placeholders to stay a literal string. '
            . 'Adding a height name means widening that IN list in the same commit.'
        );
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

        ( new ProfileHeightSync() )->syncFor( $this->player_id );

        $this->assertSame( 160, $this->profileHeight() );
    }
}
