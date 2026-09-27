<?php
namespace TT\Tests\Php;

use ReflectionClass;
use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;

/**
 * #4056 — the lateness threshold is a setting, not a hand-edited row.
 *
 * #4013 gave chronic lateness its own bar, inheriting the absence
 * threshold when unset. It had no field, so the only way to move it was to
 * write into `tt_config` directly.
 *
 * What matters is not that the key round-trips — an option that stores and
 * changes nothing is the same bug wearing a green test — so these assert
 * against the at-risk list the report and `reports/attendance-at-risk`
 * both read: the same three-late player is flagged at one setting and not
 * at another, and clearing the field puts them back on the absence bar.
 */
final class AttendanceLateThresholdSettingTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private const FROM = '2020-01-01';
    private const TO   = '2020-12-31';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        // QueryHelpers holds one ConfigService for the process, and its cache
        // outlives the per-test transaction rollback. A fresh one per test
        // keeps a threshold set here from leaking into the next case.
        QueryHelpers::setConfigService( new ConfigService() );
    }

    public function tear_down(): void {
        QueryHelpers::setConfigService( new ConfigService() );
        parent::tear_down();
    }

    /**
     * The acceptance criterion: the configured number decides who carries
     * the lateness reason, on the list the report renders from.
     */
    public function test_the_configured_value_decides_who_carries_the_lateness_reason(): void {
        $team_id = $this->insertTeam( 'U16 lateness setting' );
        $player  = $this->insertPlayer( $team_id, 'Drie', 'Telaat' );

        // Pin the absence bar so what the lateness bar inherits is stated
        // rather than assumed from whatever the install seeded.
        QueryHelpers::set_config( AttendanceFlagService::CONFIG_KEY, '3' );

        // 3 late out of 10, never absent — exactly the inherited bar.
        $activities = $this->insertActivities( $team_id, 10, '2020-03-%02d' );
        foreach ( array_slice( $activities, 0, 7 ) as $a ) $this->insertAttendance( $a, $player, 'present' );
        foreach ( array_slice( $activities, 7, 3 ) as $a ) $this->insertAttendance( $a, $player, 'late' );

        // Unset: the absence threshold (3) governs, so three late flags.
        $this->assertSame(
            [ AttendanceFlagService::REASON_LATENESS ],
            $this->reasonsFor( $team_id, $player ),
            'inheriting a threshold of 3, three late marks flag'
        );

        // Raised above the count: the same player is no longer at risk.
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '5' );
        $this->assertSame(
            [],
            $this->reasonsFor( $team_id, $player ),
            'a lateness bar of 5 leaves a three-late player alone'
        );
        $this->assertSame(
            [],
            $this->atRiskIds( $team_id ),
            'and takes them off the at-risk list the report panel shows'
        );

        // Lowered below it: flagged again, and only for lateness.
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '2' );
        $this->assertSame(
            [ AttendanceFlagService::REASON_LATENESS ],
            $this->reasonsFor( $team_id, $player ),
            'a lateness bar of 2 flags a three-late player'
        );
        $this->assertSame( [ $player ], $this->atRiskIds( $team_id ) );
    }

    /** Lowering the lateness bar does not start flagging absences. */
    public function test_the_lateness_bar_does_not_move_the_absence_bar(): void {
        $team_id = $this->insertTeam( 'U15 absence unaffected' );
        $player  = $this->insertPlayer( $team_id, 'Twee', 'Gemist' );

        $activities = $this->insertActivities( $team_id, 10, '2020-04-%02d' );
        foreach ( array_slice( $activities, 0, 8 ) as $a ) $this->insertAttendance( $a, $player, 'present' );
        foreach ( array_slice( $activities, 8, 2 ) as $a ) $this->insertAttendance( $a, $player, 'absent' );

        QueryHelpers::set_config( AttendanceFlagService::CONFIG_KEY, '3' );
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '1' );

        $this->assertSame( 3, AttendanceFlagService::threshold(), 'the absence bar is untouched' );
        $this->assertSame( 1, AttendanceFlagService::lateThreshold() );
        $this->assertSame(
            [],
            $this->reasonsFor( $team_id, $player ),
            'two absences stay under the absence threshold of 3'
        );
    }

    /**
     * Clearing the field is how an academy goes back to one number, so a
     * blank value must read as unset rather than as zero.
     */
    public function test_clearing_the_field_restores_the_inheritance(): void {
        QueryHelpers::set_config( AttendanceFlagService::CONFIG_KEY, '6' );
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '2' );

        $this->assertSame( 2, AttendanceFlagService::lateThresholdOverride() );
        $this->assertSame( 2, AttendanceFlagService::lateThreshold() );

        // What the settings form posts when the operator empties the input.
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '' );

        $this->assertNull(
            AttendanceFlagService::lateThresholdOverride(),
            'an empty stored value is "inherit", not zero'
        );
        $this->assertSame( 6, AttendanceFlagService::lateThreshold(), 'so it follows the raised absence bar' );
    }

    /**
     * The settings field renders from the override, not the resolved value:
     * pre-filling it from `lateThreshold()` would post the inherited number
     * back on the next save and end the inheritance nobody asked to end.
     */
    public function test_the_override_reads_apart_from_the_resolved_value(): void {
        $this->assertNull( AttendanceFlagService::lateThresholdOverride(), 'nothing set on a fresh install' );
        $this->assertSame(
            AttendanceFlagService::threshold(),
            AttendanceFlagService::lateThreshold(),
            'while the resolved value still answers with the absence bar'
        );

        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '4' );
        $this->assertSame( 4, AttendanceFlagService::lateThresholdOverride() );

        // A value left behind by an older hand-edit reads as unset, exactly
        // as the flag itself already treats it.
        QueryHelpers::set_config( AttendanceFlagService::LATE_CONFIG_KEY, '0' );
        $this->assertNull( AttendanceFlagService::lateThresholdOverride() );
    }

    /**
     * The Configuration screen saves through the config endpoint's
     * allow-list; a key missing from it saves silently into nothing.
     */
    public function test_the_key_is_accepted_by_the_config_endpoint(): void {
        $ref  = new ReflectionClass( \TT\Infrastructure\REST\ConfigRestController::class );
        $keys = $ref->getConstant( 'ALLOWED_KEYS' );

        $this->assertIsArray( $keys );
        $this->assertContains( AttendanceFlagService::LATE_CONFIG_KEY, $keys );
        $this->assertContains( AttendanceFlagService::CONFIG_KEY, $keys, 'beside its neighbour' );
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return list<string> */
    private function reasonsFor( int $team_id, int $player_id ): array {
        $rows = ( new AttendanceRankingQuery() )->rows( self::FROM, self::TO, $team_id );
        foreach ( $rows as $r ) {
            if ( (int) $r['player_id'] === $player_id ) {
                return array_values( (array) $r['flag_reasons'] );
            }
        }
        $this->fail( 'the player must appear in the attendance ranking' );
    }

    /** @return list<int> */
    private function atRiskIds( int $team_id ): array {
        $out = [];
        foreach ( ( new AttendanceRankingQuery() )->atRisk( self::FROM, self::TO, $team_id ) as $r ) {
            $out[] = (int) $r['player_id'];
        }
        return $out;
    }

    /** @return list<int> */
    private function insertActivities( int $team_id, int $count, string $date_format ): array {
        $out = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $out[] = $this->insertActivity( $team_id, sprintf( $date_format, $i ) );
        }
        return $out;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => $first,
            'last_name'  => $last,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team_id,
            'title'               => 'Training ' . $date,
            'session_date'        => $date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'is_guest'    => 0,
            'record_type' => 'actual',
        ] );
    }
}
