<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\PersonaDashboard\Widgets\OnboardingPipelineWidget;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ProspectStages;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * #4403 — the dashboard's pipeline strip counts prospects waiting on
 * consent under Consent requested, as the board does, instead of under
 * Prospects.
 */
final class PipelineWidgetConsentTest extends WP_UnitTestCase {

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
        wp_cache_flush();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_open_consent_task_counts_under_consent_requested(): void {
        $prospect = $this->prospect( 'Daan', 'Vermeulx' );
        $this->task( $prospect, 'request_consent', 'open' );

        $counts = $this->stripCounts();
        $this->assertSame( 1, $counts['consent'] );
        $this->assertSame( 0, $counts['prospects'] );
    }

    public function test_an_awaiting_log_entry_counts_under_consent_requested(): void {
        $prospect = $this->prospect( 'Sem', 'Jacobsx' );
        ( new ProspectConsentRequestsRepository() )->create( $prospect, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AWAITING );

        $counts = $this->stripCounts();
        $this->assertSame( 1, $counts['consent'] );
        $this->assertSame( 0, $counts['prospects'] );
    }

    public function test_an_agreed_entry_releases_the_prospect(): void {
        $prospect = $this->prospect( 'Luuk', 'Dekkerx' );
        $repo     = new ProspectConsentRequestsRepository();
        $repo->create( $prospect, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AWAITING );
        $repo->create( $prospect, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AGREED );

        $counts = $this->stripCounts();
        $this->assertSame( 0, $counts['consent'] );
        $this->assertSame( 1, $counts['prospects'] );
    }

    public function test_an_invited_prospect_with_a_stale_consent_task_stays_invited(): void {
        $prospect = $this->prospect( 'Milan', 'Smitx' );
        $this->task( $prospect, 'request_consent', 'open' );
        $this->task( $prospect, 'invite_to_test_training', 'completed' );

        $counts = $this->stripCounts();
        $this->assertSame( 1, $counts['invited'] );
        $this->assertSame( 0, $counts['consent'] );
    }

    public function test_every_stage_on_the_strip_matches_the_board(): void {
        $this->task( $this->prospect( 'Bram', 'Visserx' ), 'request_consent', 'open' );
        ( new ProspectConsentRequestsRepository() )->create( $this->prospect( 'Thijs', 'Muldex' ), current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AWAITING );
        $this->prospect( 'Noah', 'Bosx' );
        $invited = $this->prospect( 'Finn', 'Peetx' );
        $this->task( $invited, 'request_consent', 'open' );
        $this->task( $invited, 'invite_to_test_training', 'completed' );

        $board = [];
        foreach ( ProspectStages::computeStages( $this->admin ) as $column ) {
            $board[ (string) $column['key'] ] = count( (array) ( $column['cards'] ?? [] ) );
        }
        $strip = $this->stripCounts();

        $this->assertSame( array_keys( $board ), array_keys( $strip ), 'the strip has the board\'s stages, in the board\'s order' );
        $this->assertSame( $board, $strip );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function stripCounts(): array {
        wp_cache_flush();
        $out = [];
        foreach ( OnboardingPipelineWidget::computeStageCounts( $this->admin, 1 ) as $stage ) {
            $out[ $stage['key'] ] = (int) $stage['count'];
        }
        return $out;
    }

    private function prospect( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'       => 1,
            'first_name'    => $first,
            'last_name'     => $last,
            'current_club'  => 'VV Voorbeeld',
            'discovered_at' => current_time( 'Y-m-d' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function task( int $prospect_id, string $template_key, string $status ): void {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_workflow_tasks", [
            'club_id'          => 1,
            'template_key'     => $template_key,
            'assignee_user_id' => $this->admin,
            'status'           => $status,
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
            'completed_at'     => $status === 'completed' ? current_time( 'mysql' ) : null,
            'prospect_id'      => $prospect_id,
        ] );
        $this->assertNotFalse( $ok, 'task insert must succeed' );
    }
}
