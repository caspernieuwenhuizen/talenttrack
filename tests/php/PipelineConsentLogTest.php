<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ProspectStageClassifier;
use TT\Modules\Prospects\Frontend\FrontendOnboardingPipelineView;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * #4268 — the onboarding pipeline's "Consent requested" column reads the
 * consent log, not only an open `request_consent` task.
 *
 * A request logged through `POST prospects/{id}/consent-requests`, or one
 * still waiting after its task was completed, left the prospect in the
 * first column, while the prospects list's waiting counter and the
 * consent-awaiting alert already treated "an awaiting request and no agreed
 * one" as consent pending. The board now agrees with them.
 */
final class PipelineConsentLogTest extends WP_UnitTestCase {

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the classifier ─────────────────────────────────────────────────

    public function test_an_awaiting_log_entry_classifies_as_consent(): void {
        $row = (object) [ 'awaiting_consent' => 1 ];
        $this->assertSame( 'consent', ProspectStageClassifier::classify( $row, 0 ) );
    }

    public function test_invite_progress_still_outranks_a_waiting_request(): void {
        $this->assertSame( 'invited', ProspectStageClassifier::classify( (object) [ 'awaiting_consent' => 1, 'done_invite' => 1 ], 0 ) );
        $this->assertSame( 'test', ProspectStageClassifier::classify( (object) [ 'awaiting_consent' => 1, 'done_confirm' => 1 ], 0 ) );
    }

    public function test_without_a_task_or_a_log_entry_it_stays_in_prospects(): void {
        $this->assertSame( 'prospects', ProspectStageClassifier::classify( (object) [], 0 ) );
    }

    // ── the board ──────────────────────────────────────────────────────

    public function test_a_request_logged_over_rest_puts_the_prospect_in_the_consent_column(): void {
        $prospect = $this->prospect( 'Joep', 'Willemsx' );
        ( new ProspectConsentRequestsRepository() )->create( $prospect, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AWAITING );

        $card = $this->cardIn( 'consent', $prospect );
        $this->assertNotNull( $card, 'counted in Consent requested' );
        $this->assertNull( $this->cardIn( 'prospects', $prospect ), 'and no longer in Prospects' );
        $this->assertStringContainsString( 'prospect_id=' . $prospect, (string) $card['url'], 'with no open task, the card opens the prospect focus view' );
    }

    public function test_an_agreed_entry_releases_the_prospect_from_the_consent_column(): void {
        $prospect = $this->prospect( 'Chris', 'Brouwerx' );
        $repo     = new ProspectConsentRequestsRepository();
        $repo->create( $prospect, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AWAITING );
        $repo->create( $prospect, current_time( 'Y-m-d' ), 'Youth coordinator', ConsentOutcome::AGREED );

        $this->assertNull( $this->cardIn( 'consent', $prospect ) );
        $this->assertNotNull( $this->cardIn( 'prospects', $prospect ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

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

    /** @return array<string,mixed>|null */
    private function cardIn( string $stage, int $prospect_id ): ?array {
        foreach ( FrontendOnboardingPipelineView::computeStages( $this->admin ) as $column ) {
            if ( ( $column['key'] ?? '' ) !== $stage ) continue;
            foreach ( (array) ( $column['cards'] ?? [] ) as $card ) {
                if ( (int) ( $card['id'] ?? 0 ) === $prospect_id ) return (array) $card;
            }
        }
        return null;
    }
}
