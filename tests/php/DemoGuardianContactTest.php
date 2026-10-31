<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\NoGuardianContactAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\Generators\GuardianGenerator;
use TT\Modules\DemoData\Generators\PlayerGenerator;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4254 — a generated demo academy gave a guardian only to the few players
 * linked to the two parent personas. Every other player had no guardian
 * contact at all, so the profile card said "No linked parent or guardian
 * yet" for most of the roster and the no-guardian alert had the whole squad
 * to flag.
 *
 * What is pinned here:
 *
 *   - every squad member but the last has a guardian on file, and the last
 *     has none, so the alert keeps a real sample;
 *   - the assignment is positional, so a reseed reproduces it (#2461);
 *   - every address is on the reserved `.test` domain;
 *   - a player linked to a parent persona carries no made-up guardian;
 *   - the alert fires only for the sample;
 *   - the staff card shows the on-file guardian.
 */
final class DemoGuardianContactTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    public function tear_down(): void {
        unset( $_GET['tab'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    // ---- the generator ------------------------------------------------

    public function test_every_squad_member_but_the_last_has_a_guardian_on_file(): void {
        $teams = [ $this->makeTeam( 'Ajax JO13-1', 'JO13' ), $this->makeTeam( 'Ajax JO15-1', 'JO15' ) ];
        $this->runPlayers( $teams, 12, 'guardian-batch' );

        foreach ( $teams as $team ) {
            $squad = $this->squad( (int) $team->id );
            $this->assertCount( 12, $squad );

            $this->assertSame(
                [ 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0 ],
                $this->guardianPattern( $squad ),
                'every squad member but the last has a guardian on file'
            );
            foreach ( array_slice( $squad, 0, 11 ) as $player ) {
                $this->assertNotSame( '', trim( (string) $player->guardian_name ), 'a guardian on file has a name' );
                $this->assertStringContainsString( (string) $player->last_name, (string) $player->guardian_name );
            }
        }
    }

    public function test_every_address_is_on_the_reserved_test_domain(): void {
        $team = $this->makeTeam( 'PSV JO12-1', 'JO12' );
        $this->runPlayers( [ $team ], 12, 'guardian-batch' );

        $seen = 0;
        foreach ( $this->squad( (int) $team->id ) as $player ) {
            $email = (string) $player->guardian_email;
            $this->assertSame( '', (string) $player->guardian_phone, 'no made-up phone number is written' );
            if ( $email === '' ) continue;
            $seen++;
            $this->assertMatchesRegularExpression( '/@demo\.talenttrack\.test$/', $email );
        }
        $this->assertSame( 11, $seen );
    }

    public function test_the_assignment_is_positional_so_a_reseed_reproduces_it(): void {
        $first  = $this->makeTeam( 'Feyenoord JO14-1', 'JO14' );
        $second = $this->makeTeam( 'Feyenoord JO14-2', 'JO14' );

        $this->runPlayers( [ $first ], 12, 'run-one' );
        $this->runPlayers( [ $second ], 12, 'run-two' );

        $one = $this->squad( (int) $first->id );
        $two = $this->squad( (int) $second->id );

        $this->assertSame( $this->guardianPattern( $one ), $this->guardianPattern( $two ) );
        $this->assertSame(
            array_map( static fn( $p ) => (string) $p->guardian_email, $one ),
            array_map( static fn( $p ) => (string) $p->guardian_email, $two ),
            'two runs of the same plan wrote different addresses'
        );
    }

    public function test_a_squad_of_one_keeps_its_guardian(): void {
        $team = $this->makeTeam( 'AZ JO9-1', 'JO9' );
        $this->runPlayers( [ $team ], 1, 'tiny-squad' );

        $this->assertSame( [ 1 ], $this->guardianPattern( $this->squad( (int) $team->id ) ) );
    }

    public function test_a_player_linked_to_a_parent_persona_carries_no_made_up_guardian(): void {
        $team    = $this->makeTeam( 'Twente JO11-1', 'JO11' );
        $players = $this->runPlayers( [ $team ], 12, 'guardian-batch' );
        $parent  = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        ( new GuardianGenerator( new DemoBatchRegistry( 'guardian-batch' ), $players, [ 'parent' => $parent ] ) )->generate();

        $squad = $this->squad( (int) $team->id );
        $this->assertNull( $squad[0]->guardian_name, 'the linked child kept the made-up guardian' );
        $this->assertNull( $squad[0]->guardian_email );
        $this->assertNotNull( $squad[1]->guardian_name, 'an unlinked child lost the guardian on file' );
    }

    public function test_the_no_guardian_alert_fires_only_for_the_sample(): void {
        $teams = [ $this->makeTeam( 'Utrecht JO13-1', 'JO13' ), $this->makeTeam( 'Utrecht JO16-1', 'JO16' ) ];
        $this->runPlayers( $teams, 12, 'guardian-batch' );

        $expected = [];
        foreach ( $teams as $team ) {
            $squad      = $this->squad( (int) $team->id );
            $expected[] = (int) end( $squad )->id;
        }
        sort( $expected );

        $team_ids = array_map( static fn( $t ) => (int) $t->id, $teams );
        $alerted  = [];
        foreach ( $this->alertRows() as $row ) {
            if ( ! in_array( (int) $row->team_id, $team_ids, true ) ) continue;
            $alerted[] = (int) $row->player_id;
        }
        sort( $alerted );

        $this->assertSame( $expected, $alerted, 'the alert flags exactly one player per squad' );
    }

    // ---- the card -------------------------------------------------------

    public function test_staff_see_the_on_file_guardian_on_the_card(): void {
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'        => $this->club,
            'team_id'        => $team,
            'first_name'     => 'Sem',
            'last_name'      => 'Jansen',
            'status'         => 'active',
            'guardian_name'  => 'Petra Jansen',
            'guardian_email' => 'guardian.1.1@demo.talenttrack.test',
        ] );
        $player = (int) $wpdb->insert_id;
        $coach  = $this->makeHeadCoach( $team );

        wp_set_current_user( $coach );
        $_GET['tab'] = 'profile';
        ob_start();
        FrontendPlayerDetailView::render( $player, $coach, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Parents · Guardians', $html, 'precondition: the coach sees the card' );
        $this->assertStringContainsString( 'Guardian on file', $html );
        $this->assertStringContainsString( 'Petra Jansen', $html );
        $this->assertStringContainsString( 'mailto:guardian.1.1@demo.talenttrack.test', $html );
        $this->assertStringNotContainsString( 'No linked parent or guardian yet.', $html );
    }

    // ---- fixtures -------------------------------------------------------

    private function makeTeam( string $name, string $age_group ): object {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => $this->club,
            'name'      => $name,
            'age_group' => $age_group,
        ] );
        return (object) [
            'id'                 => (int) $wpdb->insert_id,
            'name'               => $name,
            'age_group'          => $age_group,
            'head_coach_user_id' => 0,
        ];
    }

    /**
     * @param object[] $teams
     * @return object[]
     */
    private function runPlayers( array $teams, int $per_team, string $batch ): array {
        return ( new PlayerGenerator( new DemoBatchRegistry( $batch ), $teams, [ 'admin' => 1 ], $per_team, 8 ) )->generate();
    }

    /** @return object[] the active squad in insertion order */
    private function squad( int $team_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->p}tt_players
              WHERE club_id = %d AND team_id = %d AND status = 'active'
              ORDER BY id",
            $this->club,
            $team_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param object[] $squad
     * @return int[] 1 where a guardian is on file
     */
    private function guardianPattern( array $squad ): array {
        $out = [];
        foreach ( $squad as $player ) {
            $out[] = trim( (string) $player->guardian_name ) !== '' && trim( (string) $player->guardian_email ) !== '' ? 1 : 0;
        }
        return $out;
    }

    /** @return object[] the rows the alert's condition selects */
    private function alertRows(): array {
        $method = new \ReflectionMethod( NoGuardianContactAlert::class, 'rows' );
        $method->setAccessible( true );
        return (array) $method->invoke( new NoGuardianContactAlert(), new AlertContext( $this->club ) );
    }

    /** A head coach on one team, built the way production builds one (see GuardianContactsAuditTest). */
    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the head coach auth role must be seeded' );

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }
}
