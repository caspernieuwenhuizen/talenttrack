<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Teams\FootballFormResolver;

/**
 * #4087 — a match played in quarters is a whole match in all three minutes
 * reports.
 *
 * The minutes report multiplied the prep's period length by the number of
 * periods; the minutes audit and the minutes share multiplied it by two. A
 * 4 x 15 prep read 60 available minutes in the first and 30 in the others.
 */
final class MinutesQuartersLengthTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-12-31';

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function test_the_period_count_is_two_at_least_and_four_at_most(): void {
        $this->assertSame( 2, MinutesQuery::clampPeriods( 0 ) );
        $this->assertSame( 2, MinutesQuery::clampPeriods( 1 ) );
        $this->assertSame( 4, MinutesQuery::clampPeriods( 4 ) );
        $this->assertSame( 4, MinutesQuery::clampPeriods( 9 ), 'a stray period number does not multiply the length' );
    }

    public function test_a_quarters_prep_reads_sixty_minutes_in_the_report_the_audit_and_the_share(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Quarters U10' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team,
            'first_name' => 'Four',
            'last_name'  => 'Quarters',
            'status'     => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team,
            'title'               => 'Quarters match',
            'session_date'        => '2026-03-07',
            'activity_type_key'   => 'match',
            'game_subtype_key'    => 'League',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        $match = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_match_prep", [
            'club_id'             => $this->club,
            'uuid'                => wp_generate_uuid4(),
            'activity_id'         => $match,
            'half_length_minutes' => 15,
        ] );
        $prep = (int) $wpdb->insert_id;

        for ( $period = 1; $period <= 4; $period++ ) {
            $wpdb->insert( "{$this->p}tt_match_prep_lineup", [
                'club_id'       => $this->club,
                'match_prep_id' => $prep,
                'half'          => $period,
                'slot_number'   => 1,
                'player_id'     => $player,
            ] );
        }

        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $match,
            'player_id'      => $player,
            'status'         => 'Present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => 60,
        ] );

        $this->assertSame( [ $match => 4 ], MinutesQuery::periodCountsFor( [ $match ] ) );

        $rows = ( new MinutesQuery() )->forTeam( $team, self::FROM, self::TO );
        $this->assertCount( 1, $rows );
        $this->assertSame( 60, (int) $rows[0]['available_minutes'], 'the minutes report' );

        $share = ( new MinutesShareQuery() )->availableForTeam( $team, self::FROM, self::TO );
        $this->assertSame( 60, $share['minutes'], 'the minutes share' );

        $team_row = $wpdb->get_row( $wpdb->prepare( "SELECT football_form, age_group FROM {$this->p}tt_teams WHERE id = %d", $team ) );
        $a_side   = FootballFormResolver::playersASide( FootballFormResolver::forTeamRow( $team_row ) );
        $matrix   = ( new MinutesAuditQuery() )->matrix( $team, self::FROM, self::TO );
        $this->assertSame( $a_side * 60, (int) $matrix['games'][0]['available_minutes'], 'the minutes audit' );
    }

    public function test_a_prep_in_halves_still_reads_two_periods(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Halves U12' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team,
            'title'               => 'Halves match',
            'session_date'        => '2026-03-14',
            'activity_type_key'   => 'match',
            'game_subtype_key'    => 'League',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        $match = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $match,
            'player_id'      => 1,
            'status'         => 'Present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => 30,
        ] );
        $wpdb->insert( "{$this->p}tt_match_prep", [
            'club_id'             => $this->club,
            'uuid'                => wp_generate_uuid4(),
            'activity_id'         => $match,
            'half_length_minutes' => 30,
        ] );

        $this->assertSame( [], MinutesQuery::periodCountsFor( [ $match ] ), 'no line-up: the caller reads two halves' );

        $share = ( new MinutesShareQuery() )->availableForTeam( $team, self::FROM, self::TO );
        $this->assertSame( 60, $share['minutes'] );
    }
}
