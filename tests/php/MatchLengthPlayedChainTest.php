<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\MatchPrep\Services\MatchLengthResolver;
use TT\Modules\Teams\FootballFormResolver;

/**
 * #4077 — the minutes report, the minutes audit and the minutes share
 * resolve a match's length through one chain.
 *
 * The minutes report used to fall back to 35 per half on any match without
 * a prep row, so a game in an age group configured at 2 x 30 counted 70
 * available minutes there and 60 in the audit and the share.
 */
final class MatchLengthPlayedChainTest extends WP_UnitTestCase {

    private const FROM = '2026-01-01';
    private const TO   = '2026-12-31';

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        // Written on every set_up: the config service caches per process,
        // while the row itself rolls back with the test's transaction.
        QueryHelpers::set_config(
            MatchLengthResolver::CONFIG_KEY,
            (string) wp_json_encode( [ 'O13' => 30 ] )
        );
    }

    public function tear_down(): void {
        QueryHelpers::set_config( MatchLengthResolver::CONFIG_KEY, '' );
        parent::tear_down();
    }

    public function test_a_match_with_no_prep_reads_its_age_groups_length_in_all_three_reports(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O13-1', 'age_group' => 'O13' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team,
            'first_name' => 'No',
            'last_name'  => 'Prep',
            'status'     => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team,
            'title'               => 'Paper match',
            'session_date'        => '2026-03-07',
            'activity_type_key'   => 'match',
            'game_subtype_key'    => 'League',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        $match = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $match,
            'player_id'      => $player,
            'status'         => 'present',
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => 45,
        ] );

        $this->assertNull(
            $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->p}tt_match_prep WHERE activity_id = %d", $match ) ),
            'precondition: the match has no prep row'
        );

        $this->assertSame( 60, ( new MatchLengthResolver() )->playedMatchMinutes( $match ) );

        $rows = ( new MinutesQuery() )->forTeam( $team, self::FROM, self::TO );
        $this->assertCount( 1, $rows );
        $this->assertSame( 60, (int) $rows[0]['available_minutes'], 'the minutes report reads 2 x 30, not 2 x 35' );

        $share = ( new MinutesShareQuery() )->availableForTeam( $team, self::FROM, self::TO );
        $this->assertSame( 60, $share['minutes'] );

        $team_row = $wpdb->get_row( $wpdb->prepare( "SELECT football_form, age_group FROM {$this->p}tt_teams WHERE id = %d", $team ) );
        $a_side   = FootballFormResolver::playersASide( FootballFormResolver::forTeamRow( $team_row ) );
        $matrix   = ( new MinutesAuditQuery() )->matrix( $team, self::FROM, self::TO );
        $this->assertSame( $a_side * 60, (int) $matrix['games'][0]['available_minutes'] );
    }
}
