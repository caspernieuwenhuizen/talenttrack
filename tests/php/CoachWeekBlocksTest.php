<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Frontend\FrontendConfigurationView;
use TT\Shared\Frontend\FrontendTileGrid;

/**
 * #4492 — the "This week" panel as configured blocks: the settings per
 * persona and device, the registry, the scope choices and the per-device
 * markup. Uses the week of Monday 2 to Sunday 8 November 2026.
 */
final class CoachWeekBlocksTest extends WP_UnitTestCase {

    private int $team      = 0;
    private int $otherTeam = 0;
    private int $coach     = 0;
    private int $tuesday   = 0;
    private int $match     = 0;
    private int $other     = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        ActivityRegisterProgress::forget();
        WeekBlockRegistry::reset();
        foreach ( [ CoachWeek::CONFIG_KEY, CoachWeek::SETTINGS_KEY, \TT\Shared\Dates\TTDate::WEEK_START_KEY ] as $key ) {
            $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'club_id' => 1, 'config_key' => $key ] );
        }
        $prop = new \ReflectionProperty( \TT\Shared\Dates\TTDate::class, 'week_monday_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );

        $this->team      = $this->team( 'JO13-1' );
        $this->otherTeam = $this->team( 'JO15-1' );
        $starter = $this->player( $this->team, 'Speler1' );
        $this->player( $this->team, 'Speler2' );

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->grantTeam( $this->coach, $this->team );
        wp_set_current_user( $this->coach );

        $this->tuesday = $this->activity( $this->team, '2026-11-03', 'training', 'Training JO13-1' );
        $this->activity( $this->team, '2026-11-05', 'training', 'Training JO13-1' );
        $this->match   = $this->activity( $this->team, '2026-11-07', 'game', 'Uit', [
            'opponent'         => 'VV Zwaluwen JO13-2',
            'home_away'        => 'away',
            'time_of_presence' => '09:15:00',
            'start_time'       => '10:00:00',
            'location'         => 'Wijchen',
        ] );
        $this->other   = $this->activity( $this->otherTeam, '2026-11-04', 'training', 'Other team' );

        $repo = new MatchPrepRepository();
        $repo->replaceLineupForHalf( $repo->ensureForActivity( $this->match, 30 ), 1, [ 1 => $starter ] );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // Registry contract

    public function test_every_block_has_a_unique_key_a_known_group_and_a_label(): void {
        $keys = [];
        foreach ( WeekBlockRegistry::all() as $key => $block ) {
            $this->assertSame( $key, $block->key() );
            $this->assertNotContains( $key, $keys, 'block keys are unique' );
            $keys[] = $key;
            $this->assertContains( $block->group(), WeekBlockGroup::keys(), $key . ' names a known group' );
            $this->assertNotSame( '', $block->label(), $key . ' has a label' );
            foreach ( CoachWeek::PERSONAS as $persona ) {
                foreach ( [ 'desktop', 'phone' ] as $device ) {
                    $this->assertIsBool( $block->defaultFor( $persona, $device ) );
                }
            }
        }
    }

    public function test_action_blocks_are_in_priority_order(): void {
        $this->assertSame(
            [ 'action_mark_attendance', 'action_match_prep', 'action_start_match', 'action_open_activity' ],
            array_map( static fn( ActionBlock $b ): string => $b->key(), WeekBlockRegistry::actions() )
        );
        $this->assertSame( array_values( CoachWeek::actionBlocks() ), array_map( static fn( ActionBlock $b ): string => $b->key(), WeekBlockRegistry::actions() ) );
    }

    public function test_a_block_writes_only_its_own_fields_and_no_two_blocks_share_one(): void {
        $base  = array_keys( CoachWeek::forUser( $this->coach, '2026-11-04' )['rows'][0] );
        $owned = [];
        foreach ( WeekBlockRegistry::all() as $key => $block ) {
            foreach ( array_keys( $block->rowDefaults() ) as $field ) {
                $this->assertArrayNotHasKey( $field, $owned, "{$key} claims {$field}, which another block already owns" );
                $owned[ $field ] = $key;
            }
        }
        $identity = array_diff( $base, array_keys( $owned ) );
        foreach ( [ 'activity_id', 'team_id', 'date', 'when', 'actions' ] as $field ) {
            $this->assertContains( $field, $identity, "{$field} is the row's own, no block may claim it" );
        }

        $panel = [];
        foreach ( WeekBlockRegistry::all() as $key => $block ) {
            foreach ( array_keys( $block->panelDefaults() ) as $field ) {
                $this->assertNotContains( $field, [ 'visible', 'rows', 'blocks', 'week_start', 'week_end', 'today', 'past', 'phone_on', 'window', 'has_match', 'multiple_teams' ], "{$key} may not claim {$field}" );
                $this->assertArrayNotHasKey( $field, $panel, "{$key} and another block both claim {$field}" );
                $panel[ $field ] = $key;
            }
        }
    }

    public function test_a_block_whose_module_is_off_has_no_switch_and_is_not_listed(): void {
        $module = \TT\Modules\Training\TrainingModule::class;
        if ( \TT\Core\ModuleRegistry::isAlwaysOn( $module ) ) $this->markTestSkipped( 'Training is always on here.' );

        \TT\Core\ModuleRegistry::setEnabled( $module, false );
        try {
            $this->assertArrayNotHasKey( 'training_plan', WeekBlockRegistry::available() );
            $this->assertNotContains( 'training_plan', CoachWeek::forUser( $this->coach, '2026-11-04' )['blocks']['desktop'] );

            $method = new \ReflectionMethod( FrontendConfigurationView::class, 'renderCoachWeekConfig' );
            $method->setAccessible( true );
            ob_start();
            $method->invoke( null, [ 'head_coach' => 'Head coach' ] );
            $html = (string) ob_get_clean();
            $this->assertStringNotContainsString( 'data-tt-coach-week-block="training_plan"', $html );
        } finally {
            \TT\Core\ModuleRegistry::setEnabled( $module, true );
        }
        $this->assertContains( 'training_plan', CoachWeek::forUser( $this->coach, '2026-11-04' )['blocks']['desktop'], 'back on, the saved choice applies again' );
    }

    // Rights on the shared context

    public function test_buttons_follow_the_edit_right_per_team_not_per_week(): void {
        $dual = get_user_by( 'id', $this->coach );
        $dual->add_role( 'tt_readonly_observer' );
        \TT\Infrastructure\Security\AuthorizationService::flushCache();
        $this->assertTrue( \TT\Modules\Activities\ActivityAccess::hasGlobalRead( $this->coach ), 'precondition: the observer role reads every team' );

        $all = [];
        foreach ( CoachWeek::PERSONAS as $persona ) $all[ $persona ] = [ 'on' => true, 'teams' => 'all', 'desktop' => WeekBlockRegistry::keys() ];
        $this->configureAll( $all );

        $rows = [];
        foreach ( CoachWeek::forUser( $this->coach, '2026-11-04' )['rows'] as $row ) $rows[ $row['activity_id'] ] = $row;

        $this->assertArrayHasKey( $this->other, $rows, 'the second role widens the week to every team' );
        $this->assertSame( [], $rows[ $this->other ]['actions'], 'no button on a team this user may not edit' );
        $this->assertContains( CoachWeek::ACTION_MARK_ATTENDANCE, $rows[ $this->tuesday ]['actions'], 'their own team keeps its buttons' );
    }

    public function test_the_context_answers_who_may_see_a_player(): void {
        global $wpdb;
        $own   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}tt_players WHERE team_id = %d LIMIT 1", $this->team ) );
        $other = $this->player( $this->otherTeam, 'Elders' );
        $week  = new \TT\Modules\Activities\CoachWeek\WeekContext( $this->coach, 'head_coach', '2026-11-04', '2026-11-02', '2026-11-08', [ $this->team => 'JO13-1' ], [], [] );

        $this->assertTrue( $week->canSeePlayer( $own ), 'a player on their own team' );
        $this->assertFalse( $week->canSeePlayer( $other ), 'a player on another team: a block gives a count, never the name' );
        $this->assertTrue( $week->canSeeTeam( $this->team ) );
        $this->assertFalse( $week->canSeeTeam( $this->otherTeam ) );
        $this->assertFalse( $week->canEditTeam( $this->otherTeam ) );
    }

    // Availability for scouts and observers is opt-in

    public function test_scouts_and_observers_get_availability_only_when_switched_on(): void {
        foreach ( [ 'scout', 'readonly_observer' ] as $persona ) {
            $defaults = CoachWeek::defaultSettings()[ $persona ];
            $this->assertNotContains( 'availability', $defaults['desktop'], $persona );
            $this->assertNotContains( 'availability', $defaults['phone'], $persona );
        }

        QueryHelpers::set_config( CoachWeek::CONFIG_KEY, '["head_coach","readonly_observer"]' );
        $legacy = CoachWeek::settings();
        $this->assertNotContains( 'availability', $legacy['readonly_observer']['desktop'], 'the older list does not switch it on for an observer' );
        $this->assertContains( 'availability', $legacy['head_coach']['desktop'] );

        $this->configure( 'readonly_observer', [ 'desktop' => [ 'availability' ] ] );
        $this->assertSame( [ 'availability' ], CoachWeek::settings()['readonly_observer']['desktop'], 'an academy may switch it on' );
    }

    // Defaults and the fallback to the older persona list

    public function test_with_no_settings_the_coach_gets_every_block_on_desktop_and_the_short_set_on_phone(): void {
        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );

        $this->assertTrue( $week['visible'] );
        $this->assertTrue( $week['phone_on'] );
        $this->assertSame( 'show', $week['past'] );
        $this->assertSame( array_values( array_diff( WeekBlockRegistry::keys(), [ 'next_week' ] ) ), $week['blocks']['desktop'] );
        $this->assertNotContains( 'location', $week['blocks']['phone'] );
        $this->assertNotContains( 'training_plan', $week['blocks']['phone'] );
        $this->assertContains( 'action_start_match', $week['blocks']['phone'] );
        $this->assertSame( [], $week['next_week'], 'the peek is off until an academy turns it on' );
    }

    public function test_a_team_manager_on_the_older_list_keeps_the_whole_desktop_panel(): void {
        QueryHelpers::set_config( CoachWeek::CONFIG_KEY, '["head_coach","team_manager"]' );
        $settings = CoachWeek::settings();

        $this->assertTrue( $settings['team_manager']['on'] );
        $this->assertTrue( $settings['team_manager']['phone_on'] );
        $this->assertSame( $settings['head_coach']['desktop'], $settings['team_manager']['desktop'], 'the same full desktop panel the coach gets' );
        $this->assertContains( 'action_mark_attendance', $settings['team_manager']['desktop'] );
        $this->assertSame( CoachWeek::defaultSettings()['team_manager']['phone'], $settings['team_manager']['phone'], 'phones take the shorter list' );
    }

    public function test_the_older_persona_list_still_decides_who_sees_the_panel(): void {
        QueryHelpers::set_config( CoachWeek::CONFIG_KEY, '["team_manager"]' );
        $this->assertFalse( CoachWeek::forUser( $this->coach, '2026-11-04' )['visible'] );
        $this->assertSame( [ 'team_manager' ], CoachWeek::configuredPersonas() );
    }

    // Per-device blocks

    public function test_location_off_for_phone_only_is_listed_and_marked_for_desktop_only(): void {
        $all = WeekBlockRegistry::keys();
        $this->configure( 'head_coach', [ 'desktop' => $all, 'phone' => array_values( array_diff( $all, [ 'location' ] ) ) ] );

        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );
        $this->assertContains( 'location', $week['blocks']['desktop'] );
        $this->assertNotContains( 'location', $week['blocks']['phone'] );

        $html = $this->panelHtml( $week );
        $this->assertMatchesRegularExpression( '#class="tt-week-meta tt-week-off-phone">[^<]*Wijchen#', $html, 'the desktop line carries the location' );
        $this->assertMatchesRegularExpression( '#class="tt-week-meta tt-week-off-desktop">[^<]*Kick-off 10:00</span>#', $html, 'the phone line leaves it out' );
    }

    public function test_also_on_phones_off_hides_the_whole_panel_on_a_phone(): void {
        $this->configure( 'head_coach', [ 'phone_on' => false ] );

        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );
        $this->assertFalse( $week['phone_on'] );
        $this->assertSame( [], $week['blocks']['phone'] );
        $this->assertStringContainsString( '<section class="tt-week tt-week-off-phone"', $this->panelHtml( $week ) );
    }

    public function test_a_block_off_for_both_devices_is_not_computed(): void {
        $this->configure( 'head_coach', [
            'desktop' => array_values( array_diff( WeekBlockRegistry::keys(), [ 'availability' ] ) ),
            'phone'   => [],
        ] );
        $sat = CoachWeek::forUser( $this->coach, '2026-11-04' )['rows'][2];

        $this->assertSame( $this->match, $sat['activity_id'] );
        $this->assertNull( $sat['availability'] );
        $this->assertNotNull( $sat['lineup'] );
    }

    public function test_an_action_switched_off_is_not_offered(): void {
        $this->configure( 'head_coach', [
            'desktop' => array_values( array_diff( WeekBlockRegistry::keys(), [ 'action_start_match' ] ) ),
            'phone'   => [],
        ] );
        $sat = CoachWeek::forUser( $this->coach, '2026-11-07' )['rows'][2];

        $this->assertSame( [ CoachWeek::ACTION_OPEN_ACTIVITY ], $sat['actions'] );
        $this->assertLessThanOrEqual( ActionBlock::MAX_PER_ROW, count( $sat['actions'] ) );
    }

    // Scope

    public function test_all_teams_without_a_global_read_falls_back_to_the_own_teams(): void {
        $this->configure( 'head_coach', [ 'teams' => 'all' ] );

        $ids = array_column( CoachWeek::forUser( $this->coach, '2026-11-04' )['rows'], 'activity_id' );
        $this->assertNotContains( $this->other, $ids, 'no other team\'s activity for a coach without a global read' );
        $this->assertContains( $this->tuesday, $ids );
    }

    public function test_all_teams_with_a_global_read_covers_the_academy(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $persona = \TT\Modules\Authorization\PersonaResolver::landingPersona( $admin );
        $this->assertNotNull( $persona );
        $this->configure( (string) $persona, [ 'on' => true, 'teams' => 'all' ] );

        $week = CoachWeek::forUser( $admin, '2026-11-04' );
        $this->assertTrue( $week['visible'] );
        $this->assertContains( $this->other, array_column( $week['rows'], 'activity_id' ) );
        $this->assertTrue( $week['multiple_teams'] );
    }

    public function test_all_teams_for_a_team_manager_stays_on_their_team_and_so_does_the_peek(): void {
        if ( get_role( 'tt_team_manager' ) === null ) add_role( 'tt_team_manager', 'Team Manager', [ 'read' => true ] );
        $manager = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $this->grantTeam( $manager, $this->team, 'team_manager' );
        \TT\Infrastructure\Security\AuthorizationService::flushCache();
        $this->assertSame( 'team_manager', \TT\Modules\Authorization\PersonaResolver::landingPersona( $manager ) );
        $this->assertFalse( \TT\Modules\Activities\ActivityAccess::hasGlobalRead( $manager ) );

        $own_next   = $this->activity( $this->team, '2026-11-10', 'training', 'Own next' );
        $other_next = $this->activity( $this->otherTeam, '2026-11-09', 'training', 'Other next' );
        $this->configure( 'team_manager', [ 'on' => true, 'teams' => 'all', 'desktop' => WeekBlockRegistry::keys() ] );

        $week = CoachWeek::forUser( $manager, '2026-11-04' );
        $this->assertTrue( $week['visible'] );
        $this->assertNotContains( $this->other, array_column( $week['rows'], 'activity_id' ) );
        $this->assertContains( $own_next, array_column( $week['next_week'], 'activity_id' ) );
        $this->assertNotContains( $other_next, array_column( $week['next_week'], 'activity_id' ), 'the peek reads the same teams' );
    }

    public function test_the_next_seven_days_window_starts_today(): void {
        $this->configure( 'head_coach', [ 'window' => 'rolling' ] );
        $week = CoachWeek::forUser( $this->coach, '2026-11-05' );

        $this->assertSame( [ '2026-11-05', '2026-11-11' ], [ $week['week_start'], $week['week_end'] ] );
        $this->assertNotContains( $this->tuesday, array_column( $week['rows'], 'activity_id' ) );
    }

    public function test_past_days_hide_and_fold(): void {
        $this->configure( 'head_coach', [ 'past' => 'hide' ] );
        $hidden = CoachWeek::forUser( $this->coach, '2026-11-04' );
        $this->assertNotContains( $this->tuesday, array_column( $hidden['rows'], 'activity_id' ) );
        $this->assertTrue( $hidden['has_match'] );

        $this->configure( 'head_coach', [ 'past' => 'fold' ] );
        $folded = CoachWeek::forUser( $this->coach, '2026-11-04' );
        $this->assertSame( 'fold', $folded['past'] );
        $this->assertContains( $this->tuesday, array_column( $folded['rows'], 'activity_id' ) );

        $html = $this->panelHtml( $folded );
        $this->assertStringContainsString( '<details class="tt-week-fold">', $html, 'a native details element: Enter and tap open it' );
        $this->assertMatchesRegularExpression( '#<summary class="tt-week-fold-summary"><span class="[^"]*">[^<]*1 activity#', $html );
    }

    public function test_the_peek_lists_the_first_two_activities_of_next_week(): void {
        $first  = $this->activity( $this->team, '2026-11-10', 'training', 'Next one' );
        $second = $this->activity( $this->team, '2026-11-12', 'training', 'Next two' );
        $this->activity( $this->team, '2026-11-14', 'training', 'Next three' );
        $this->configure( 'head_coach', [ 'desktop' => WeekBlockRegistry::keys() ] );

        $week = CoachWeek::forUser( $this->coach, '2026-11-04' );
        $this->assertSame( [ $first, $second ], array_column( $week['next_week'], 'activity_id' ) );
        $this->assertStringContainsString( 'tt-week-next tt-week-off-phone', $this->panelHtml( $week ) );
    }

    // Storage

    public function test_the_stored_settings_keep_known_personas_and_blocks_only(): void {
        $stored = json_decode( CoachWeek::normaliseSettingsStored( (string) wp_json_encode( [
            'player'     => [ 'on' => true ],
            'head_coach' => [ 'on' => true, 'window' => 'month', 'desktop' => [ 'location', 'bogus', 'type' ], 'phone' => 'all' ],
        ] ) ), true );

        $this->assertArrayNotHasKey( 'player', $stored );
        $this->assertSame( CoachWeek::PERSONAS, array_keys( $stored ), 'every staff persona, in order' );
        $this->assertSame( 'week', $stored['head_coach']['window'], 'an unknown window takes the default' );
        $this->assertSame( [ 'type', 'location' ], $stored['head_coach']['desktop'], 'known blocks, registry order' );
        $this->assertSame( [], $stored['head_coach']['phone'] );
        $this->assertSame( CoachWeek::defaultSettings()['team_manager'], $stored['team_manager'], 'a missing persona takes its defaults' );
    }

    public function test_save_round_trips_through_the_config_route(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $settings = CoachWeek::defaultSettings();
        $settings['head_coach']['past']  = 'fold';
        $settings['head_coach']['phone'] = [ 'type' ];

        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/config' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [ 'config' => [ CoachWeek::SETTINGS_KEY => (string) wp_json_encode( $settings ) ] ] ) );
        $res = rest_do_request( $req );

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'fold', CoachWeek::settings()['head_coach']['past'] );
        $this->assertSame( [ 'type' ], CoachWeek::settings()['head_coach']['phone'] );
    }

    public function test_a_user_who_may_not_change_feature_toggles_is_refused_and_nothing_changes(): void {
        $this->configure( 'head_coach', [ 'past' => 'fold' ] );
        $before = QueryHelpers::get_config( CoachWeek::SETTINGS_KEY, '' );

        // The head coach holds no config edit right at all, so the route
        // gate refuses before any key is looked at.
        $res = $this->postSettings( (string) wp_json_encode( [ 'head_coach' => [ 'on' => false ] ] ) );

        $this->assertSame( 403, $res->get_status() );
        $this->assertSame( $before, QueryHelpers::get_config( CoachWeek::SETTINGS_KEY, '' ), 'the coach cannot change the academy setting' );
    }

    public function test_an_unreadable_value_is_refused_not_reset(): void {
        $this->configure( 'head_coach', [ 'past' => 'fold' ] );
        $before = QueryHelpers::get_config( CoachWeek::SETTINGS_KEY, '' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        foreach ( [ 'not json', '["head_coach"]', '42', '[]', ' [ ] ' ] as $raw ) {
            $res = $this->postSettings( $raw );
            $this->assertSame( 400, $res->get_status(), $raw );
            $error = (array) $res->get_data()['errors'][0];
            $this->assertSame( 'invalid_field', $error['code'] );
            $this->assertSame( CoachWeek::SETTINGS_KEY, ( (array) $error['details'] )['field'] );
            $this->assertSame( $before, QueryHelpers::get_config( CoachWeek::SETTINGS_KEY, '' ), 'nothing was saved' );
        }
    }

    public function test_me_week_returns_the_blocks(): void {
        $res  = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/me/week' ) );
        $data = (array) $res->get_data()['data'];

        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( [ 'desktop', 'phone' ], array_keys( $data['blocks'] ) );
        $this->assertContains( 'location', $data['blocks']['desktop'] );
    }

    // Settings screen

    public function test_the_settings_screen_lists_every_block_with_a_desktop_and_a_phone_switch(): void {
        $method = new \ReflectionMethod( FrontendConfigurationView::class, 'renderCoachWeekConfig' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, [ 'head_coach' => 'Head coach', 'assistant_coach' => 'Assistant coach', 'player' => 'Player' ] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="config[' . CoachWeek::SETTINGS_KEY . ']"', $html );
        $this->assertStringNotContainsString( 'value="player"', $html, 'players are never a choice' );
        foreach ( WeekBlockRegistry::available() as $key => $block ) {
            $this->assertStringContainsString( 'aria-label="' . esc_attr( $block->label() . ' on desktop' ) . '"', $html, $key );
            $this->assertStringContainsString( 'aria-label="' . esc_attr( $block->label() . ' on phone' ) . '"', $html, $key );
        }
        $this->assertStringContainsString( 'data-tt-coach-week-reset', $html );
        $this->assertDoesNotMatchRegularExpression( '#<input[^>]+data-tt-coach-week-block[^>]+name=#', $html, 'block switches are not posted one by one' );
    }

    // Helpers

    /** @param array<string, mixed> $overrides */
    private function configure( string $persona, array $overrides ): void {
        $settings              = CoachWeek::settings();
        $settings[ $persona ]  = array_merge( $settings[ $persona ], $overrides );
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );
    }

    /** @param array<string, array<string, mixed>> $overrides persona => fields */
    private function configureAll( array $overrides ): void {
        $settings = CoachWeek::settings();
        foreach ( $overrides as $persona => $fields ) $settings[ $persona ] = array_merge( $settings[ $persona ], $fields );
        QueryHelpers::set_config( CoachWeek::SETTINGS_KEY, CoachWeek::normaliseSettingsStored( (string) wp_json_encode( $settings ) ) );
    }

    private function postSettings( string $raw ): \WP_REST_Response {
        $req = new WP_REST_Request( 'POST', '/talenttrack/v1/config' );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [ 'config' => [ CoachWeek::SETTINGS_KEY => $raw ] ] ) );
        return rest_do_request( $req );
    }

    /** @param array<string, mixed> $week */
    private function panelHtml( array $week ): string {
        $method = new \ReflectionMethod( FrontendTileGrid::class, 'renderWeekPanel' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $week );
        return (string) ob_get_clean();
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function player( int $team_id, string $last ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $team_id,
            'first_name' => 'Week',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string, mixed> $extra */
    private function activity( int $team_id, string $date, string $type, string $title, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', array_merge( [
            'club_id'           => 1,
            'team_id'           => $team_id,
            'title'             => $title,
            'session_date'      => $date,
            'start_time'        => '18:30:00',
            'activity_type_key' => $type,
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    /** A staff record and an active team-scope grant, what get_teams_for_coach() reads. */
    private function grantTeam( int $user_id, int $team_id, string $role_type = 'head_coach' ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Week',
            'last_name'  => 'Coach',
            'role_type'  => $role_type,
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
    }
}
