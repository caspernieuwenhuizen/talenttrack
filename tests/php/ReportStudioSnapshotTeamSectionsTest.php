<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Blocks\MonthlySectionBlock;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\CutsForReader;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * #4961 — snapshot blocks of a team follow the reader's current section
 * rights: for every team monthly section block and the key figures, the
 * copy frozen for the head of development and cut for a reader equals what
 * the block composes for that reader live. The narrower reader holds neither
 * the team's attendance right nor the safeguarding level.
 */
final class ReportStudioSnapshotTeamSectionsTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team = 0;
    private int $hod = 0;
    private int $narrow = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Team cut U16', 'age_group' => 'U16' ] );
        $this->team   = (int) $wpdb->insert_id;
        $this->hod    = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team );
        $this->narrow = $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team );
        $this->seed();
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_frozen_team_sections_cut_for_a_reader_equal_the_live_blocks(): void {
        $this->assertTrue( AuthorizationService::canReadTeamAttendance( $this->hod, $this->team ), 'Precondition: the head of development reads attendance.' );
        $this->assertFalse( AuthorizationService::canReadTeamAttendance( $this->narrow, $this->team ), 'Precondition: the narrower reader does not.' );
        $this->assertNotContains( RecordVisibility::LEVEL_SAFEGUARDING, RecordVisibility::forJourney( $this->narrow ), 'Precondition: nor the safeguarding level.' );

        $input = [ 'scope' => 'team', 'team_id' => $this->team, 'from' => self::FROM, 'to' => self::TO, 'audience' => PlayerReportAudience::INTERNAL ];
        $keys  = array_merge( [ 'key_figures' ], array_values( MonthlySectionBlock::KEYS ) );
        foreach ( $keys as $key ) {
            $block = BlockRegistry::find( $key );
            $this->assertInstanceOf( CutsForReader::class, $block, $key );

            $frozen = $this->render( $key, $input, $this->hod );
            foreach ( [ 'head of development' => $this->hod, 'narrower reader' => $this->narrow ] as $who => $reader ) {
                $live = $this->render( $key, $input, $reader );
                $cut  = $block->forReader( $frozen, $reader, $input );
                $this->assertSame( wp_json_encode( $live ), wp_json_encode( $cut ), "{$key} for the {$who}" );
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function render( string $key, array $input, int $user ): array {
        SharedReads::flush();
        $block   = BlockRegistry::find( $key );
        $context = ReportContext::fromInput( $input, $user );
        $this->assertNotNull( $block );
        $this->assertInstanceOf( ReportContext::class, $context );
        return $block->render( $context, [] );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) add_role( $role, $role_type, [ 'read' => true ] );
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Cut',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }

    /** A squad over March 2020: trainings with registers, a match, a public and a safeguarding change, and an open injury. */
    private function seed(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $players = [];
        foreach ( [ 'Ada', 'Bo', 'Cor', 'Dirk' ] as $n => $first ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => $first, 'last_name' => 'Cut',
                'jersey_number' => $n + 1, 'status' => 'active', 'date_of_birth' => '2005-04-12',
            ] );
            $players[] = (int) $wpdb->insert_id;
        }
        $statuses = [ 'Present', 'Absent', 'Late', 'Present' ];
        foreach ( [ '2020-02-11', '2020-03-03', '2020-03-10' ] as $i => $date ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Training ' . $date, 'session_date' => $date,
                'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $activity = (int) $wpdb->insert_id;
            foreach ( $players as $j => $pid ) {
                $wpdb->insert( "{$p}tt_attendance", [
                    'club_id' => $this->club, 'activity_id' => $activity, 'player_id' => $pid,
                    'status' => $statuses[ ( $i + $j ) % 4 ], 'record_type' => 'actual', 'is_guest' => 0,
                ] );
            }
        }
        foreach ( [ [ 0, 'position_changed', 'Moved to centre back.', 'public' ], [ 1, 'team_changed', 'Safeguarding: moved for welfare.', 'safeguarding' ] ] as $i => [ $who, $type, $summary, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'player_id' => $players[ $who ], 'event_type' => $type,
                'event_date' => '2020-03-1' . ( $i + 2 ) . ' 09:00:00', 'summary' => $summary, 'visibility' => $visibility,
                'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => $i + 1,
            ] );
        }
        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $players[2], 'started_on' => '2020-03-18', 'actual_return' => null, 'notes' => 'Ankle.',
        ] );
    }
}
