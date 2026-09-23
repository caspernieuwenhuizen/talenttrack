<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Invalidation\AlertInvalidationMap;
use TT\Modules\Prospects\Alerts\ProspectConsentAwaitingAlert;
use TT\Modules\Prospects\Cron\ProspectRetentionCron;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\ProspectsModule;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * #4017 — the club never came back, and nothing said so.
 *
 * A scout logged consent request 1 for a prospect as `awaiting` on 13 Apr
 * and it still said `awaiting` on the 19th. Nothing in the product showed it
 * waiting, so the only thing chasing it was the scout's notebook — and the
 * one piece of code that does read an ageing `awaiting` row uses it to hold
 * the retention clock, so an unchased request ends in a silent purge rather
 * than sitting there visibly waiting. That is the case the threshold has to
 * beat, and it is asserted here rather than described in a comment.
 */
final class ProspectConsentAwaitingAlertTest extends WP_UnitTestCase {

    private int $prospect = 0;

    private int $custodian = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        AlertInvalidationMap::flush();

        $this->custodian = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->custodian );

        $this->prospect = $this->seedProspect();
    }

    public function tear_down(): void {
        AlertInvalidationMap::flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_default_threshold_is_five_days(): void {
        $this->assertSame( 5, ( new ProspectConsentAwaitingAlert() )->waitingDays() );
    }

    /**
     * The whole point of the threshold, stated as an assertion: the alert
     * must fire with room to spare inside the retention window, because the
     * alternative outcome for an unchased request is deletion.
     */
    public function test_the_threshold_fires_well_inside_the_retention_window(): void {
        $this->assertLessThan(
            ProspectRetentionCron::DEFAULT_NO_PROGRESS_DAYS,
            ( new ProspectConsentAwaitingAlert() )->waitingDays(),
            'a threshold at or past the retention window would warn about a record that is already gone'
        );
    }

    public function test_a_request_inside_the_threshold_raises_nothing(): void {
        $this->ask( 2, ConsentOutcome::AWAITING );

        $this->assertSame( [], $this->occurrences() );
    }

    public function test_a_request_waiting_past_the_threshold_raises_an_alert(): void {
        $this->ask( 6, ConsentOutcome::AWAITING );

        $out = $this->occurrences();
        $this->assertNotEmpty( $out );

        $first = $out[0];
        $this->assertSame( 'prospects.consent_awaiting', $first->alertKey );
        $this->assertSame( 'prospect', $first->subjectType );
        $this->assertSame( $this->prospect, $first->subjectId );
        $this->assertStringContainsString( 'Joep', (string) ( $first->payload['title'] ?? '' ) );
        $this->assertStringContainsString( 'VV Rijnstreek', (string) ( $first->payload['title'] ?? '' ) );

        // A day either side: the date is written in UTC and the wait is
        // measured against the database server's CURDATE().
        $this->assertGreaterThanOrEqual( 5, (int) ( $first->payload['waiting_days'] ?? 0 ) );
        $this->assertLessThanOrEqual( 7, (int) ( $first->payload['waiting_days'] ?? 0 ) );
    }

    /**
     * The custodian audience: the alert reaches somebody who can act on it,
     * not only the scout who happened to log the request.
     */
    public function test_the_alert_reaches_a_prospect_custodian(): void {
        $this->ask( 9, ConsentOutcome::AWAITING );

        $recipients = [];
        foreach ( $this->occurrences() as $occurrence ) {
            $recipients[] = $occurrence->recipientUserId;
        }

        $this->assertContains( $this->custodian, $recipients );
    }

    /** Recording any outcome makes the condition false, so it self-resolves. */
    public function test_recording_an_outcome_stops_the_alert(): void {
        $entry = $this->ask( 12, ConsentOutcome::AWAITING );
        $this->assertNotEmpty( $this->occurrences() );

        ( new ProspectConsentRequestsRepository() )->setOutcome( $entry, ConsentOutcome::NO_REPLY );

        $this->assertSame( [], $this->occurrences(), 'a recorded outcome is the fix; the alert must go' );
    }

    /** Consent already on record settles the question, stale row or not. */
    public function test_an_agreed_request_elsewhere_silences_the_stale_one(): void {
        $this->ask( 20, ConsentOutcome::AWAITING );
        $this->ask( 1, ConsentOutcome::AGREED );

        $this->assertSame( [], $this->occurrences() );
    }

    public function test_consent_direct_from_the_family_silences_it_too(): void {
        global $wpdb;
        $this->ask( 20, ConsentOutcome::AWAITING );
        $wpdb->update(
            "{$wpdb->prefix}tt_prospects",
            [ 'consent_given_at' => '2026-01-01 00:00:00' ],
            [ 'id' => $this->prospect ]
        );

        $this->assertSame( [], $this->occurrences() );
    }

    /** One child asked about twice is still one thing to chase. */
    public function test_two_requests_for_one_prospect_raise_one_subject(): void {
        $this->ask( 30, ConsentOutcome::AWAITING );
        $this->ask( 8, ConsentOutcome::AWAITING );

        $subjects = [];
        foreach ( $this->occurrences() as $occurrence ) {
            $subjects[ $occurrence->subjectId ] = true;
        }

        $this->assertCount( 1, $subjects );
    }

    /** A long-forgotten request is louder than a week-old one. */
    public function test_a_long_wait_escalates_to_urgent(): void {
        $this->ask( 40, ConsentOutcome::AWAITING );

        $this->assertSame( Severity::URGENT, $this->occurrences()[0]->severity );
    }

    public function test_a_promoted_prospect_is_not_chased(): void {
        global $wpdb;
        $this->ask( 20, ConsentOutcome::AWAITING );
        $wpdb->update(
            "{$wpdb->prefix}tt_prospects",
            [ 'promoted_to_player_id' => 1 ],
            [ 'id' => $this->prospect ]
        );

        $this->assertSame( [], $this->occurrences() );
    }

    /**
     * The invalidation entry is what makes the resolution immediate rather
     * than up to an hour late. Its absence fails nothing at runtime, which
     * is exactly why it is asserted.
     */
    public function test_recording_an_outcome_is_wired_into_the_invalidation_map(): void {
        add_filter( 'tt_alert_invalidation_map', [ ProspectsModule::class, 'registerAlertInvalidation' ] );
        AlertInvalidationMap::flush();

        $map = AlertInvalidationMap::all();
        $this->assertArrayHasKey( 'tt_prospect_consent_outcome_recorded', $map );

        $extracted = ( $map['tt_prospect_consent_outcome_recorded'] )( $this->prospect, 7, ConsentOutcome::AGREED );
        $this->assertSame( [ [ 'prospect', [ $this->prospect ] ] ], $extracted );

        remove_filter( 'tt_alert_invalidation_map', [ ProspectsModule::class, 'registerAlertInvalidation' ] );
        AlertInvalidationMap::flush();
    }

    /** The hook fires from the repository, so every write path announces. */
    public function test_the_repository_announces_a_recorded_outcome(): void {
        $seen = [];
        $listener = static function ( $prospect_id, $entry_id, $outcome ) use ( &$seen ): void {
            $seen[] = [ (int) $prospect_id, (string) $outcome ];
        };
        add_action( 'tt_prospect_consent_outcome_recorded', $listener, 10, 3 );

        $entry = $this->ask( 3, ConsentOutcome::AWAITING );
        ( new ProspectConsentRequestsRepository() )->setOutcome( $entry, ConsentOutcome::DECLINED );

        remove_action( 'tt_prospect_consent_outcome_recorded', $listener, 10 );

        $this->assertContains( [ $this->prospect, ConsentOutcome::AWAITING ], $seen );
        $this->assertContains( [ $this->prospect, ConsentOutcome::DECLINED ], $seen );
    }

    /** The prospect list's waiting column, batched for the whole page. */
    public function test_the_waiting_days_read_is_one_query_for_the_page(): void {
        $second = $this->seedProspect();
        $this->ask( 4, ConsentOutcome::AWAITING );
        $this->ask( 11, ConsentOutcome::AWAITING, $second );
        $third = $this->seedProspect();

        $days = ( new ProspectConsentRequestsRepository() )
            ->waitingDaysFor( [ $this->prospect, $second, $third ] );

        $this->assertGreaterThanOrEqual( 3, $days[ $this->prospect ] );
        $this->assertLessThanOrEqual( 5, $days[ $this->prospect ] );
        $this->assertGreaterThanOrEqual( 10, $days[ $second ] );
        $this->assertLessThanOrEqual( 12, $days[ $second ] );
        // Absent, not zero: "nobody is waiting" and "asked today" are
        // different answers and the column has to show them differently.
        $this->assertArrayNotHasKey( $third, $days );
    }

    // Helpers

    /** @return list<\TT\Modules\Alerts\Domain\AlertOccurrence> */
    private function occurrences(): array {
        return ( new ProspectConsentAwaitingAlert() )->evaluate( new AlertContext( 1 ) );
    }

    /** Log a request asked `$daysAgo` days ago. Returns the entry id. */
    private function ask( int $daysAgo, string $outcome, ?int $prospect = null ): int {
        $id = ( new ProspectConsentRequestsRepository() )->create(
            $prospect ?? $this->prospect,
            gmdate( 'Y-m-d', time() - $daysAgo * DAY_IN_SECONDS ),
            'Youth coordinator at VV Rijnstreek',
            $outcome
        );
        $this->assertGreaterThan( 0, $id, 'the consent request must be recorded' );
        return $id;
    }

    private function seedProspect(): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'      => 1,
            'first_name'   => 'Joep',
            'last_name'    => 'Waiting',
            'current_club' => 'VV Rijnstreek',
        ] );
        $this->assertNotFalse( $ok, 'prospect insert must succeed' );
        return (int) $wpdb->insert_id;
    }
}
