<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4695 — migration 0315: the team manager's Report Studio read, the
 * observer's Studio cells removed, and publications to the observer
 * persona removed. Kept apart from the REST test, whose publishes commit.
 */
final class ReportStudioClosedToObserverMigrationTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0315_report_studio_closed_to_observer.php';

    private string $matrix = '';
    private string $publications = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->matrix       = "{$wpdb->prefix}tt_authorization_matrix";
        $this->publications = "{$wpdb->prefix}tt_report_publications";
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_it_adds_the_team_manager_read_once_and_removes_the_observer(): void {
        global $wpdb;
        $wpdb->delete( $this->matrix, [ 'persona' => 'team_manager', 'entity' => 'report_studio' ] );
        foreach ( [ 'report_studio', 'report_publishing' ] as $entity ) {
            $wpdb->insert( $this->matrix, [
                'persona' => 'readonly_observer', 'entity' => $entity, 'activity' => 'read',
                'scope_kind' => 'global', 'module_class' => 'x', 'is_default' => 0,
            ] );
        }
        foreach ( [ 'readonly_observer', 'head_coach' ] as $persona ) {
            $wpdb->insert( $this->publications, [ 'club_id' => 1, 'report_id' => 987654, 'persona_key' => $persona, 'published_by' => 1 ] );
        }

        $this->migration()->up();
        // Twice, because a re-run must change nothing.
        $this->migration()->up();

        $this->assertSame(
            [ [ 'activity' => 'read', 'scope_kind' => 'team', 'is_default' => '1' ] ],
            $wpdb->get_results( "SELECT activity, scope_kind, is_default FROM {$this->matrix} WHERE persona = 'team_manager' AND entity = 'report_studio'", ARRAY_A ),
            'one team read row for the team manager'
        );
        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->matrix} WHERE persona = 'readonly_observer' AND entity IN ( 'report_studio', 'report_publishing' )" ),
            'the observer holds no Studio row'
        );
        $this->assertSame(
            [ 'head_coach' ],
            array_values( (array) $wpdb->get_col( "SELECT persona_key FROM {$this->publications} WHERE report_id = 987654" ) ),
            'the publication to the observer is gone, the others stay'
        );
    }

    public function test_a_cell_the_academy_changed_keeps_its_value(): void {
        global $wpdb;
        $wpdb->delete( $this->matrix, [ 'persona' => 'team_manager', 'entity' => 'report_studio' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_authorization_changelog", [
            'persona' => 'team_manager', 'entity' => 'report_studio', 'activity' => 'read',
            'scope_kind' => 'team', 'change_type' => 'revoke', 'actor_user_id' => 1,
            'created_at' => current_time( 'mysql' ),
        ] );

        $this->migration()->up();

        $this->assertSame(
            0,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->matrix} WHERE persona = 'team_manager' AND entity = 'report_studio'" ),
            'a revoked cell stays revoked'
        );
    }

    public function test_the_seed_gives_the_team_manager_read_only_and_the_observer_nothing(): void {
        $rows = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $tm = [];
        $ob = [];
        foreach ( $rows as $row ) {
            if ( $row['entity'] === 'report_studio' && $row['persona'] === 'team_manager' ) $tm[] = $row['activity'] . '|' . $row['scope_kind'];
            if ( in_array( $row['entity'], [ 'report_studio', 'report_publishing' ], true ) && $row['persona'] === 'readonly_observer' ) $ob[] = $row['activity'];
        }
        $this->assertSame( [ 'read|team' ], $tm );
        $this->assertSame( [], $ob );
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }
}
