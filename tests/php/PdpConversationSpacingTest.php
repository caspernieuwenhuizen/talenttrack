<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Carryover\SeasonCarryover;
use TT\Modules\Pdp\Repositories\PdpBlocksRepository;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;

/**
 * #3670 — an automatically scheduled development talk lands on a whole
 * day at 18:00.
 *
 * `evenlySpacedDates()` divided the season in seconds, so the time of day
 * was whatever the division left over: a U11 file opened its cycle at
 * 2026-11-20 07:59:59 and closed it at 2027-03-11 15:59:58, and other
 * files showed 04:47:56. The configured-blocks path had the same defect
 * from the other end, always landing on 11:59:59 or 23:59:59. The date of
 * the next talk is the answer to "what does this player need next", and a
 * time nobody chose reads as placeholder data to the coach, the player and
 * the parent alike.
 *
 * The dates themselves are unchanged — only the time of day is fixed.
 */
final class PdpConversationSpacingTest extends WP_UnitTestCase {

    private const TALK_TIME = '18:00:00';

    private const SEASON_START = '2026-08-01';
    private const SEASON_END   = '2027-06-30';

    private int $season = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $admin  = 0;

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        // Hand the PDP caps out explicitly so the test does not depend on
        // whether the matrix bridge is active in this install.
        $admin_id = $this->admin;
        $this->cap_filter = static function ( $allcaps, $caps, $args, $user ) use ( $admin_id ) {
            $uid = is_object( $user ) ? (int) $user->ID : 0;
            if ( $uid === $admin_id ) {
                $allcaps['tt_view_pdp'] = true;
                $allcaps['tt_edit_pdp'] = true;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => self::SEASON_START,
            'end_date'   => self::SEASON_END,
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Talks O11-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'team_id'    => $this->team,
            'first_name' => 'Tess',
            'last_name'  => 'Talk',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The bug as it was reported: a 2-talk cycle over the 2026/27 season.
     * The two dates are the ones the old arithmetic produced; only the
     * time changes.
     */
    public function test_a_two_talk_cycle_keeps_its_dates_and_gains_a_real_time(): void {
        $this->assertSame(
            [ '2026-11-20 18:00:00', '2027-03-11 18:00:00' ],
            PdpConversationsRepository::evenlySpacedDates( self::SEASON_START, self::SEASON_END, 2 )
        );
    }

    /** Three and four talks are spread the same way, inside the season. */
    public function test_every_cycle_size_stays_inside_the_season_at_the_same_time(): void {
        foreach ( [ 2, 3, 4 ] as $size ) {
            $dates = PdpConversationsRepository::evenlySpacedDates( self::SEASON_START, self::SEASON_END, $size );
            $this->assertCount( $size, $dates, "cycle size {$size}" );

            $previous = self::SEASON_START . ' 00:00:00';
            foreach ( $dates as $when ) {
                $this->assertSame(
                    self::TALK_TIME,
                    substr( $when, 11 ),
                    "cycle size {$size}: {$when} is not at the default talk time"
                );
                $this->assertGreaterThan( $previous, $when, "cycle size {$size}: dates must ascend" );
                $this->assertLessThan( self::SEASON_END . ' 00:00:00', $when, "cycle size {$size}: {$when} is outside the season" );
                $previous = $when;
            }
        }
    }

    /** A range that is not a range, or too short to give each talk a day. */
    public function test_an_unusable_range_yields_no_dates(): void {
        $this->assertSame( [], PdpConversationsRepository::evenlySpacedDates( self::SEASON_END, self::SEASON_START, 3 ) );
        $this->assertSame( [], PdpConversationsRepository::evenlySpacedDates( '', self::SEASON_END, 3 ) );
        $this->assertSame( [], PdpConversationsRepository::evenlySpacedDates( 'not-a-date', self::SEASON_END, 3 ) );
        $this->assertSame( [], PdpConversationsRepository::evenlySpacedDates( self::SEASON_START, self::SEASON_END, 0 ) );
        // Two days of season cannot hold three talks on their own days.
        $this->assertSame( [], PdpConversationsRepository::evenlySpacedDates( '2026-08-01', '2026-08-03', 3 ) );
    }

    /**
     * Academy-configured blocks: the talk sits on the block's middle day,
     * at the same time, and the planning window is the block verbatim.
     */
    public function test_configured_blocks_schedule_on_the_middle_day(): void {
        ( new PdpBlocksRepository() )->replaceForSeason( $this->season, [
            [ 'sequence' => 1, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30' ],
            [ 'sequence' => 2, 'start_date' => '2027-01-05', 'end_date' => '2027-01-20' ],
        ] );

        $file_id = $this->seedFile( $this->season, 2 );
        $convs   = new PdpConversationsRepository();
        $this->assertSame( 2, $convs->createCycle( $file_id, 2, self::SEASON_START, self::SEASON_END, $this->season ) );

        $rows = $convs->listForFile( $file_id );
        $this->assertCount( 2, $rows );

        // 29 days from 2026-09-01 to 2026-09-30, so the middle day is +14.
        $this->assertSame( '2026-09-15 18:00:00', (string) $rows[0]->scheduled_at );
        $this->assertSame( '2026-09-01', (string) $rows[0]->planning_window_start );
        $this->assertSame( '2026-09-30', (string) $rows[0]->planning_window_end );

        // 15 days from 2027-01-05 to 2027-01-20, so the middle day is +7.
        $this->assertSame( '2027-01-12 18:00:00', (string) $rows[1]->scheduled_at );
        $this->assertSame( '2027-01-05', (string) $rows[1]->planning_window_start );
        $this->assertSame( '2027-01-20', (string) $rows[1]->planning_window_end );
    }

    /**
     * The route the reporter used: open a file for a player in a season
     * with no configured blocks, then read the file back.
     */
    public function test_rest_create_schedules_every_talk_at_the_default_time(): void {
        $create = new WP_REST_Request( 'POST', '/talenttrack/v1/pdp-files' );
        $create->set_param( 'player_id', $this->player );
        $create->set_param( 'season_id', $this->season );
        $create->set_param( 'cycle_size', 3 );

        $created = rest_get_server()->dispatch( $create );
        $this->assertSame( 200, $created->get_status() );
        $body = (array) $created->get_data();
        $data = (array) ( $body['data'] ?? [] );
        $file_id = (int) ( $data['id'] ?? 0 );
        $this->assertGreaterThan( 0, $file_id );

        $read = rest_get_server()->dispatch(
            new WP_REST_Request( 'GET', '/talenttrack/v1/pdp-files/' . $file_id )
        );
        $this->assertSame( 200, $read->get_status() );
        $read_body = (array) $read->get_data();
        $read_data = (array) ( $read_body['data'] ?? [] );
        $conversations = (array) ( $read_data['conversations'] ?? [] );
        $this->assertCount( 3, $conversations );

        $scheduled = [];
        foreach ( $conversations as $c ) {
            $row = (array) $c;
            $scheduled[] = (string) ( $row['scheduled_at'] ?? '' );
        }
        $this->assertSame(
            PdpConversationsRepository::evenlySpacedDates( self::SEASON_START, self::SEASON_END, 3 ),
            $scheduled
        );
        foreach ( $scheduled as $when ) {
            $this->assertSame( self::TALK_TIME, substr( $when, 11 ), "{$when} is not at the default talk time" );
        }
    }

    /** Season carry-over opens next year's cycle in the same shape. */
    public function test_season_carryover_uses_the_same_shape(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Carry-over reads from "the most recent season that is not this
        // one", so any season the install seeded would win the comparison
        // and carry nothing over. Leave only the two this test owns.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}tt_seasons WHERE id <> %d", $this->season ) );

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2025/26',
            'start_date' => '2025-08-01',
            'end_date'   => '2026-06-30',
            'is_current' => 0,
        ] );
        $previous_season = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $previous_season );
        $this->assertGreaterThan( 0, $this->seedFile( $previous_season, 2 ) );

        SeasonCarryover::run( $this->season );

        $new_file = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_pdp_files WHERE player_id = %d AND season_id = %d",
            $this->player, $this->season
        ) );
        $this->assertGreaterThan( 0, $new_file, 'carry-over did not open a file for the new season' );

        $rows = ( new PdpConversationsRepository() )->listForFile( $new_file );
        $this->assertCount( 2, $rows );
        foreach ( $rows as $row ) {
            $when = (string) $row->scheduled_at;
            $this->assertSame( self::TALK_TIME, substr( $when, 11 ), "{$when} is not at the default talk time" );
        }
    }

    /** A bare PDP file row for $season, with no conversations yet. */
    private function seedFile( int $season_id, int $cycle_size ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_pdp_files', [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $this->player,
            'season_id'      => $season_id,
            'owner_coach_id' => $this->admin,
            'cycle_size'     => $cycle_size,
            'status'         => 'open',
        ] );
        return (int) $wpdb->insert_id;
    }
}
