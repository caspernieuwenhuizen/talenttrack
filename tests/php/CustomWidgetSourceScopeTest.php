<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\CustomWidgets\Domain\CustomDataSource;
use TT\Modules\CustomWidgets\DataSources\ActivitiesRecent;
use TT\Modules\CustomWidgets\DataSources\EvaluationsRecent;
use TT\Modules\CustomWidgets\DataSources\GoalsOpen;
use TT\Modules\CustomWidgets\DataSources\PdpFiles;
use TT\Modules\CustomWidgets\DataSources\PlayersActive;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * A custom widget's rows follow the viewer's record scope, as the bulk
 * exports do: a coach reads their own teams, a holder at global scope reads
 * the club, and a parent or player, who holds the entity for one child,
 * gets no squad list at all.
 */
final class CustomWidgetSourceScopeTest extends WP_UnitTestCase {

    private int $club  = 0;
    private int $own   = 0;
    private int $other = 0;
    private int $coach = 0;

    /** @var array<string, int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Cwscope Own U12', 'age_group' => 'U12' ] );
        $this->own = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Cwscope Other U12', 'age_group' => 'U12' ] );
        $this->other = (int) $wpdb->insert_id;

        foreach ( [ 'Ownkid' => $this->own, 'Otherkid' => $this->other ] as $name => $team ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $this->club, 'team_id' => $team, 'first_name' => $name, 'last_name' => 'Cwx',
                'status' => 'active', 'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_player' ] ),
            ] );
            $pid = (int) $wpdb->insert_id;
            $this->assertGreaterThan( 0, $pid, 'fixture: the player row was written' );
            $this->players[ $name ] = $pid;

            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => $this->club, 'player_id' => $pid, 'coach_id' => 1, 'eval_date' => gmdate( 'Y-m-d' ),
            ] );
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $this->club, 'player_id' => $pid, 'title' => 'Goal ' . $name, 'status' => 'active',
            ] );
            $wpdb->insert( "{$p}tt_pdp_files", [
                'club_id' => $this->club, 'player_id' => $pid, 'season_id' => 950001, 'status' => 'open',
            ] );
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $team, 'title' => 'Training ' . $name,
                'session_date' => gmdate( 'Y-m-d' ), 'activity_type_key' => 'training',
                'activity_status_key' => 'planned', 'plan_state' => 'scheduled',
            ] );
        }

        $this->coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Cwscope', 'last_name' => 'Coachx',
            'role_type' => 'head_coach', 'wp_user_id' => $this->coach, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->own, 'person_id' => $person,
            'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $this->own,
        ] );
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The rows themselves, on the source whose row query reads today's
     * schema. The counts below cover every source.
     */
    public function test_a_coach_gets_only_their_own_teams_rows(): void {
        $admin = $this->text( PlayersActive::class, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->assertStringContainsString( 'Otherkid', $admin, 'precondition: the club-wide reader sees both teams' );

        $mine = $this->text( PlayersActive::class, $this->coach );
        $this->assertStringContainsString( 'Ownkid', $mine, 'precondition: the coach reads their own team' );
        $this->assertStringNotContainsString( 'Otherkid', $mine );
    }

    public function test_a_parent_gets_no_squad_list(): void {
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->players['Ownkid'], $parent, true );
        AuthorizationService::flushCache();

        $this->assertSame( [], $this->rows( PlayersActive::class, $parent ) );
    }

    /** @return array<string, array{0: class-string<CustomDataSource>}> */
    public function countedSources(): array {
        return [
            'players'     => [ PlayersActive::class ],
            'evaluations' => [ EvaluationsRecent::class ],
            'goals'       => [ GoalsOpen::class ],
            'activities'  => [ ActivitiesRecent::class ],
            'pdp files'   => [ PdpFiles::class ],
        ];
    }

    /** A coach taken off the team gets a new cache key, so no cached rows. */
    public function test_the_cache_key_follows_the_viewers_teams(): void {
        global $wpdb;
        $before = \TT\Modules\CustomWidgets\DataSources\SourceScope::cacheVariant( $this->coach );
        $wpdb->delete( "{$wpdb->prefix}tt_user_role_scopes", [ 'scope_type' => 'team', 'scope_id' => $this->own ] );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        $this->assertNotSame( $before, \TT\Modules\CustomWidgets\DataSources\SourceScope::cacheVariant( $this->coach ) );
    }

    /** The PDP rows themselves: this source's row query reads today's schema. */
    public function test_the_pdp_rows_follow_the_viewers_teams(): void {
        $admin = $this->text( PdpFiles::class, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->assertStringContainsString( 'Otherkid', $admin, 'precondition: the club-wide reader sees both files' );

        $mine = $this->text( PdpFiles::class, $this->coach );
        $this->assertStringContainsString( 'Ownkid', $mine, 'precondition: the coach reads their own team\'s file' );
        $this->assertStringNotContainsString( 'Otherkid', $mine );

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->players['Ownkid'], $parent, true );
        $this->assertSame( [], $this->rows( PdpFiles::class, $parent ) );
    }

    /**
     * A KPI counts the rows the viewer may see: one team's worth for its
     * coach, both for a club-wide reader, none for a parent.
     *
     * @dataProvider countedSources
     */
    public function test_the_kpi_counts_only_the_viewers_rows( string $class ): void {
        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new PlayerParentsRepository() )->link( $this->players['Ownkid'], $parent, true );

        $this->assertSame( 2.0, $this->totalFor($class, self::factory()->user->create( [ 'role' => 'administrator' ] ) ), 'precondition: one row per team' );
        $this->assertSame( 1.0, $this->totalFor($class, $this->coach ) );
        $this->assertSame( 0.0, $this->totalFor($class, $parent ) );
    }

    public function test_a_team_filter_outside_the_coachs_teams_gives_nothing(): void {
        wp_set_current_user( $this->coach );
        $this->assertSame( [], ( new PlayersActive() )->fetch( $this->coach, [ 'team_id' => $this->other ], [], 100 ) );
        $this->assertSame( 0.0, (float) ( new PlayersActive() )->aggregate( $this->coach, [ 'team_id' => $this->other ], 'count' ) );
    }

    private function totalFor(string $class, int $user_id ): float {
        wp_set_current_user( $user_id );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        /** @var CustomDataSource $source */
        $source = new $class();
        return (float) $source->aggregate( $user_id, [], 'count' );
    }

    /** @return list<array<string, mixed>> */
    private function rows( string $class, int $user_id ): array {
        wp_set_current_user( $user_id );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        /** @var CustomDataSource $source */
        $source = new $class();
        return $source->fetch( $user_id, [], [], 100 );
    }

    private function text( string $class, int $user_id ): string {
        return (string) wp_json_encode( $this->rows( $class, $user_id ) );
    }
}
