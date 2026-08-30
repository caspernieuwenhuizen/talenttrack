<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Services\PotentialTrajectory;

/**
 * #3226 — a player's potential over time, with the direction of each
 * revision.
 *
 * The whole history has been stored since migration 0042 and read by
 * nothing a user could see. The part worth getting right is **direction**:
 * `PotentialBand::ALL` is ordered best-first, so a lower index is a better
 * band and a decreasing index is an upward revision. Comparing positions
 * rather than strings is what makes "revised down twice this season"
 * expressible, and it is the easy thing to get backwards.
 */
final class PotentialTrajectoryTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $player_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Potential',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->player_id = (int) $wpdb->insert_id;
    }

    private function record( string $band, string $set_at, string $notes = '', int $by = 0 ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $this->player_id,
            'set_at'         => $set_at,
            'set_by'         => $by,
            'potential_band' => $band,
            'notes'          => $notes !== '' ? $notes : null,
        ] );
    }

    /** @return list<array<string,mixed>> */
    private function series(): array {
        return ( new PotentialTrajectory() )->forPlayer( $this->player_id );
    }

    public function test_a_player_with_no_potential_has_no_trajectory(): void {
        $this->assertSame( [], $this->series() );
    }

    public function test_the_first_entry_has_no_direction(): void {
        $this->record( PotentialBand::SEMI_PRO, '2026-01-10 09:00:00' );

        $series = $this->series();

        $this->assertCount( 1, $series );
        $this->assertSame( PotentialTrajectory::FIRST, $series[0]['direction'] );
        $this->assertSame( 0, $series[0]['steps'] );
    }

    /** Oldest first — a trajectory is read forwards. */
    public function test_entries_come_back_oldest_first(): void {
        $this->record( PotentialBand::TOP_AMATEUR, '2026-01-10 09:00:00' );
        $this->record( PotentialBand::SEMI_PRO, '2026-06-10 09:00:00' );

        $series = $this->series();

        $this->assertSame( PotentialBand::TOP_AMATEUR, $series[0]['band'] );
        $this->assertSame( PotentialBand::SEMI_PRO, $series[1]['band'] );
    }

    /**
     * Top amateur → Semi-pro is a move toward the first team, so it is a
     * revision UP even though the array index went down. This is the
     * assertion that catches the comparison being inverted.
     */
    public function test_moving_toward_the_first_team_is_revised_up(): void {
        $this->record( PotentialBand::TOP_AMATEUR, '2026-01-10 09:00:00' );
        $this->record( PotentialBand::SEMI_PRO, '2026-06-10 09:00:00' );

        $series = $this->series();

        $this->assertSame( PotentialTrajectory::UP, $series[1]['direction'] );
        $this->assertSame( 1, $series[1]['steps'] );
    }

    public function test_moving_away_from_the_first_team_is_revised_down(): void {
        $this->record( PotentialBand::FIRST_TEAM, '2026-01-10 09:00:00' );
        $this->record( PotentialBand::TOP_AMATEUR, '2026-06-10 09:00:00' );

        $series = $this->series();

        $this->assertSame( PotentialTrajectory::DOWN, $series[1]['direction'] );
        $this->assertSame( 3, $series[1]['steps'], 'first_team to top_amateur is three bands.' );
    }

    /**
     * The development signal the issue is about: two downward revisions in
     * one season must both read as down, not just the last one.
     */
    public function test_two_downward_revisions_both_read_as_down(): void {
        $this->record( PotentialBand::FIRST_TEAM, '2026-01-10 09:00:00' );
        $this->record( PotentialBand::SEMI_PRO, '2026-04-10 09:00:00' );
        $this->record( PotentialBand::RECREATIONAL, '2026-08-10 09:00:00' );

        $series = $this->series();

        $this->assertSame( PotentialTrajectory::FIRST, $series[0]['direction'] );
        $this->assertSame( PotentialTrajectory::DOWN, $series[1]['direction'] );
        $this->assertSame( PotentialTrajectory::DOWN, $series[2]['direction'] );
    }

    /**
     * Re-affirming a band with notes appends a row on purpose — "still
     * first team, but the last six weeks have been flat". It is not a
     * revision, and must not render as one.
     */
    public function test_reaffirming_the_same_band_is_not_a_revision(): void {
        $this->record( PotentialBand::SEMI_PRO, '2026-01-10 09:00:00' );
        $this->record( PotentialBand::SEMI_PRO, '2026-06-10 09:00:00', 'Flat six weeks.' );

        $series = $this->series();

        $this->assertSame( PotentialTrajectory::SAME, $series[1]['direction'] );
        $this->assertSame( 0, $series[1]['steps'] );
        $this->assertSame( 'Flat six weeks.', $series[1]['notes'] );
    }

    /**
     * A band retired from the vocabulary after it was recorded still has
     * to render — it just cannot be compared, and must not silently be
     * treated as the bottom of the scale.
     */
    public function test_an_unknown_band_renders_without_a_direction(): void {
        $this->record( PotentialBand::SEMI_PRO, '2026-01-10 09:00:00' );
        $this->record( 'retired_band', '2026-06-10 09:00:00' );

        $series = $this->series();

        $this->assertSame( PotentialTrajectory::FIRST, $series[1]['direction'] );
        $this->assertSame( 'retired_band', $series[1]['label'], 'an unknown band falls back to its code' );
    }

    /**
     * ...and it must not corrupt the comparison for what follows: the next
     * known band is compared against the last known one.
     */
    public function test_an_unknown_band_does_not_break_the_next_comparison(): void {
        $this->record( PotentialBand::TOP_AMATEUR, '2026-01-10 09:00:00' );
        $this->record( 'retired_band', '2026-04-10 09:00:00' );
        $this->record( PotentialBand::SEMI_PRO, '2026-08-10 09:00:00' );

        $series = $this->series();

        $this->assertSame( PotentialTrajectory::UP, $series[2]['direction'] );
    }

    /** Labels come from one place, and the vocabulary is fully covered. */
    public function test_every_band_in_the_vocabulary_has_a_label(): void {
        $labels = PotentialTrajectory::labels();

        foreach ( PotentialBand::ALL as $band ) {
            $this->assertArrayHasKey( $band, $labels );
            $this->assertNotSame( '', (string) $labels[ $band ] );
        }
        $this->assertSame( PotentialBand::ALL, array_keys( $labels ), 'labels stay in best-first order' );
    }
}
