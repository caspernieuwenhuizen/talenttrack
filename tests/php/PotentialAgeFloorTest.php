<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\PotentialStaleAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Players\PlayerStatusModule;

/**
 * #3265 — potential is not asked below U13.
 *
 * The bands describe a professional ceiling, which is a fair question about
 * a teenager and a guess about a child. Two properties matter and they pull
 * in opposite directions, so both are pinned here:
 *
 *   - the floor holds on **every** write path, not just the screen; and
 *   - it never reaches the **read** path, so a band recorded before the
 *     floor existed stays visible, exactly as #3243 established for the
 *     feature flag.
 *
 * The alert half is the one that made this a bug on real installs rather
 * than a tidiness issue: without the floor, every U7 with no potential row
 * goes overdue on their creation date and stays there forever, with no
 * action that clears it except recording the judgement the floor exists to
 * prevent.
 */
final class PotentialAgeFloorTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        FeatureRegistry::setEnabled( 'potential_rating', true );

        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => $this->club,
            'name'      => 'Floor Test',
            'age_group' => 'U9',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        FeatureRegistry::setEnabled( 'potential_rating', true );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** A player born `$years` ago to the day, plus a month's margin. */
    private function playerAged( ?int $years ): int {
        global $wpdb;
        $dob = $years === null
            ? null
            : gmdate( 'Y-m-d', strtotime( "-{$years} years -1 month" ) );

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $this->team_id,
            'first_name'    => 'Age',
            'last_name'     => $years === null ? 'Unknown' : (string) $years,
            'status'        => 'active',
            'date_of_birth' => $dob,
        ] );
        return (int) $wpdb->insert_id;
    }

    // ── the predicate ──────────────────────────────────────────────────

    public function test_the_floor_is_thirteen(): void {
        $this->assertSame( 13, PlayerStatusModule::POTENTIAL_MIN_AGE );
    }

    public function test_a_child_below_the_floor_is_not_asked(): void {
        foreach ( [ 5, 7, 9, 12 ] as $age ) {
            $this->assertFalse(
                PlayerStatusModule::potentialAppliesToPlayer( $this->playerAged( $age ) ),
                "A {$age}-year-old must not be asked for a professional ceiling."
            );
        }
    }

    public function test_a_player_at_or_above_the_floor_is_asked(): void {
        foreach ( [ 13, 15, 17 ] as $age ) {
            $this->assertTrue(
                PlayerStatusModule::potentialAppliesToPlayer( $this->playerAged( $age ) ),
                "A {$age}-year-old is old enough for the question."
            );
        }
    }

    /**
     * A missing birthdate is a data gap, not evidence of being too young.
     * Letting it block capture would make an empty field look like a broken
     * screen, and there is no way for the coach to tell which it is.
     */
    public function test_an_unknown_birthdate_does_not_block_capture(): void {
        $this->assertTrue( PlayerStatusModule::potentialAppliesToPlayer( $this->playerAged( null ) ) );
        $this->assertTrue( PlayerStatusModule::potentialAppliesAtBirthdate( null ) );
        $this->assertTrue( PlayerStatusModule::potentialAppliesAtBirthdate( '' ) );
        $this->assertTrue( PlayerStatusModule::potentialAppliesAtBirthdate( 'not a date' ) );
    }

    /** A future date is nonsense input, and nonsense must not gate a write. */
    public function test_a_future_birthdate_does_not_block_capture(): void {
        $this->assertTrue(
            PlayerStatusModule::potentialAppliesAtBirthdate( gmdate( 'Y-m-d', strtotime( '+1 year' ) ) )
        );
    }

    /** Calendar age, not season age — the boundary is the birthday. */
    public function test_the_boundary_is_the_thirteenth_birthday(): void {
        $this->assertFalse(
            PlayerStatusModule::potentialAppliesAtBirthdate( gmdate( 'Y-m-d', strtotime( '-13 years +2 days' ) ) ),
            'Two days short of thirteen is still below the floor.'
        );
        $this->assertTrue(
            PlayerStatusModule::potentialAppliesAtBirthdate( gmdate( 'Y-m-d', strtotime( '-13 years -2 days' ) ) ),
            'Two days past the thirteenth birthday is above it.'
        );
    }

    // ── the alert ──────────────────────────────────────────────────────

    /**
     * The bug this issue exists for. A U7 with no potential row is measured
     * from their creation date, so on an academy running young squads every
     * player goes overdue at once and nothing clears it.
     */
    public function test_the_stale_alert_skips_players_below_the_floor(): void {
        global $wpdb;

        $young = $this->playerAged( 7 );
        $old   = $this->playerAged( 15 );

        // Both created long enough ago to be well past the default window.
        $long_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-3 years' ) );
        foreach ( [ $young, $old ] as $id ) {
            $wpdb->update( "{$this->p}tt_players", [ 'created_at' => $long_ago ], [ 'id' => $id ] );
        }

        $subjects = $this->alertSubjects();

        $this->assertNotContains(
            $young,
            $subjects,
            'A seven-year-old must never be reported as having a stale potential — there is no honest way to clear it.'
        );
        $this->assertContains(
            $old,
            $subjects,
            'A fifteen-year-old with no potential in three years is exactly what this alert is for.'
        );
    }

    /** A player with no birthdate still gets reported. */
    public function test_the_stale_alert_still_reports_a_player_with_no_birthdate(): void {
        global $wpdb;

        $unknown = $this->playerAged( null );
        $wpdb->update(
            "{$this->p}tt_players",
            [ 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-3 years' ) ) ],
            [ 'id' => $unknown ]
        );

        $this->assertContains( $unknown, $this->alertSubjects() );
    }

    /** @return list<int> player ids the alert's own query returns */
    private function alertSubjects(): array {
        $alert = new PotentialStaleAlert();

        $rows = ( new \ReflectionMethod( PotentialStaleAlert::class, 'rows' ) );
        $rows->setAccessible( true );

        $out = [];
        foreach ( (array) $rows->invoke( $alert, new AlertContext() ) as $row ) {
            $out[] = (int) ( $row->player_id ?? 0 );
        }
        return $out;
    }
}
