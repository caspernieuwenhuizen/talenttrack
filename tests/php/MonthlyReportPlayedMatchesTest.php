<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\TeamMatchRecord;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MinutesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\QualityComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;

/**
 * #4998, #4957 — the monthly report counts played matches by one rule.
 *
 * A completed fixture dated in the future once read "minutes recorded for 2
 * of 2 played matches" beside a match section that had played one. Both
 * sections now read `PlayedMatch`: the match section over the fixtures, the
 * minutes section over the fixtures and the tournament days.
 */
final class MonthlyReportPlayedMatchesTest extends WP_UnitTestCase {

    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->hide_errors();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Played U13', 'age_group' => 'U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Played', 'last_name' => 'Playerx', 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;

        // Played last week, minutes recorded.
        $past = $this->match( gmdate( 'Y-m-d', strtotime( '-7 days' ) ) );
        // Marked completed, with minutes, but dated three weeks from now.
        $future = $this->match( gmdate( 'Y-m-d', strtotime( '+21 days' ) ) );
        // #4957 — a tournament day last week, minutes recorded: not in the
        // record, in every minutes figure.
        $tournament = $this->match( gmdate( 'Y-m-d', strtotime( '-5 days' ) ), 'tournament' );
        foreach ( [ $past => 60, $future => 50, $tournament => 40 ] as $activity => $minutes ) {
            $wpdb->insert( "{$p}tt_attendance", [
                'club_id' => $club, 'activity_id' => $activity, 'player_id' => $player, 'is_guest' => 0,
                'record_type' => 'actual', 'status' => 'present', 'minutes_played' => $minutes,
            ] );
        }
    }

    public function test_the_minutes_counts_agree_with_the_match_section(): void {
        $ctx    = $this->context();
        $played = TeamMatchRecord::tally( $ctx->fixtures() )['played'];
        $this->assertSame( 1, $played, 'precondition: the match section has played one match' );

        $minutes = MinutesComposer::compose( $this->context() );
        $this->assertSame( 1, $minutes['tournaments_played'] );
        $this->assertSame( $played + $minutes['tournaments_played'], $minutes['matches_played'], 'the minutes section counts the played fixtures and the tournament day' );
        $this->assertSame( 2, $minutes['matches_recorded'], 'both carry minutes; the future fixture is not played' );
    }

    public function test_matches_without_minutes_counts_over_the_same_fixtures(): void {
        $this->assertSame( 0, QualityComposer::compose( $this->context() )['matches_without_minutes'] );
    }

    private function context(): TeamReportContext {
        return new TeamReportContext(
            $this->team,
            gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
            gmdate( 'Y-m-d', strtotime( '+30 days' ) )
        );
    }

    private function match( string $date, string $type = 'game' ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => (int) CurrentClub::id(), 'team_id' => $this->team, 'title' => 'Played ' . $date,
            'session_date' => $date, 'activity_type_key' => $type, 'game_subtype_key' => 'League',
            'activity_status_key' => 'completed', 'plan_state' => 'completed', 'opponent' => 'VVV',
        ] );
        return (int) $wpdb->insert_id;
    }
}
