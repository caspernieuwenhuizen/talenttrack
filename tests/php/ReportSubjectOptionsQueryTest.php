<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSubjectOptions;
use TT\Modules\ReportStudio\Wizard\SubjectStep;

/**
 * #4719 — the start wizard's subject step reads every team's players in one
 * query, so it costs the same for 3 teams as for 30, and offers exactly the
 * players the per-team read offered.
 */
final class ReportSubjectOptionsQueryTest extends WP_UnitTestCase {

    private int $hod = 0;

    /** @var list<int> */
    private array $teams = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();
        $this->hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        FeatureRegistry::setEnabled( 'report_studio', true );
        wp_set_current_user( $this->hod );
        $this->flush();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        $this->flush();
        parent::tear_down();
    }

    public function test_the_subject_step_costs_the_same_for_3_teams_and_for_30(): void {
        $this->addTeams( 3 );
        $this->renderStep();
        $small = $this->countQueries( fn() => $this->renderStep() );

        $this->addTeams( 27 );
        $this->renderStep();
        $large = $this->countQueries( fn() => $this->renderStep() );

        $this->assertGreaterThan( 0, $small, 'Precondition: the step reads something.' );
        $this->assertSame( $small, $large, 'the statement count does not grow with the teams' );
    }

    public function test_the_players_on_offer_are_the_ones_the_per_team_read_offered(): void {
        $this->addTeams( 4 );
        global $wpdb;
        // A player who left the squad and an archived one stay off the list.
        $wpdb->insert( "{$wpdb->prefix}tt_players", [ 'club_id' => CurrentClub::id(), 'team_id' => $this->teams[0], 'first_name' => 'Gone', 'last_name' => 'Away', 'status' => 'inactive' ] );

        $reader = ReportReader::for( $this->hod );
        $teams  = ReportSubjectOptions::teams( $reader );

        $expected = [];
        foreach ( array_keys( $teams ) as $team_id ) {
            foreach ( QueryHelpers::get_players( $team_id ) as $row ) {
                $pid = (int) $row->id;
                if ( $reader->readsPlayer( $pid ) ) {
                    $name = QueryHelpers::player_display_name( $row );
                    $expected[ $team_id ][ $pid ] = $name !== '' ? $name : '#' . $pid;
                }
            }
        }

        $actual = ReportSubjectOptions::players( ReportReader::for( $this->hod ), $teams );
        $this->assertNotSame( [], $expected, 'Precondition: there are players on offer.' );
        $this->assertSame( $expected, $actual, 'the same players, teams and order' );
        $this->assertSame( $actual, ReportSubjectOptions::players( ReportReader::for( $this->hod ) ), 'reading the teams itself gives the same answer' );
    }

    // ---- helpers ------------------------------------------------------------

    private function renderStep(): void {
        $this->flush();
        ob_start();
        ( new SubjectStep() )->render( [ 'scope' => 'player', 'binding' => 'fixed' ] );
        ob_end_clean();
    }

    private function countQueries( callable $run ): int {
        $n       = 0;
        $counter = static function ( $sql ) use ( &$n ) {
            $n++;
            return $sql;
        };
        add_filter( 'query', $counter );
        $run();
        remove_filter( 'query', $counter );
        return $n;
    }

    private function addTeams( int $count ): void {
        global $wpdb;
        for ( $i = 0; $i < $count; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Subject team ' . ( count( $this->teams ) + 1 ), 'age_group' => 'U12' ] );
            $team = (int) $wpdb->insert_id;
            $this->teams[] = $team;
            foreach ( [ 'Zara', 'Abel' ] as $first ) {
                $wpdb->insert( "{$wpdb->prefix}tt_players", [ 'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Team' . $team, 'status' => 'active' ] );
            }
        }
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
    }
}
