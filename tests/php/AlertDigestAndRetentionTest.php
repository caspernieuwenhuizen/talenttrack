<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Cron\AlertRetentionCron;
use TT\Modules\Alerts\Digest\AlertDigestQuery;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;

/**
 * #2634 — digest selection and the 90-day retention purge.
 *
 * The digest tests are mostly about what must NOT be sent. An alert stays
 * open for as long as its underlying problem does, so every rule here exists
 * to stop the same three items being mailed every morning until someone
 * fixes them — the behaviour that gets a sender filtered to spam, taking the
 * alerts that mattered with it.
 *
 * The retention tests protect the opposite property: resolved rows go, open
 * rows never do, however old. An alert unresolved for a year is a finding
 * about the academy's data discipline and deleting it would erase the
 * evidence at exactly the moment it became interesting.
 */
final class AlertDigestAndRetentionTest extends WP_UnitTestCase {

    public const KEY = 'test.digest_alert';

    /** @var int */
    private $user;

    /** @var AlertDigestQuery */
    private $query;

    public function set_up(): void {
        parent::set_up();
        AlertPreferencesRepository::flushTableCache();
        AlertDigestQuery::flushColumnCache();

        $this->user  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        // The stub addresses its occurrence to the current user, so pin one.
        wp_set_current_user( $this->user );
        $this->query = new AlertDigestQuery();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_preferences" );

        add_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
    }

    public function tear_down(): void {
        remove_filter( 'tt_register_alerts', [ $this, 'registerStub' ] );
        AlertRegistry::flush();
        parent::tear_down();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerStub( array $alerts ): array {
        $alerts[] = self::stub();
        return $alerts;
    }

    // ── the digest is opt-in ───────────────────────────────────────────

    /**
     * Epic decision 10. A fresh install must mail nobody: `digest` is not in
     * any definition's shipped surfaces, so nothing qualifies until a user
     * asks for it.
     */
    public function test_a_user_who_has_not_opted_in_gets_nothing(): void {
        $this->seed();

        $this->assertSame( [], $this->query->forUser( $this->user, current_time( 'mysql' ) ) );
    }

    /**
     * Even a definition that lists `digest` among its own defaults must not
     * enrol anybody — the stub used throughout this file does exactly that,
     * so this asserts the resolver strips it rather than the stub avoiding
     * the situation.
     */
    public function test_a_definition_cannot_enrol_users_by_declaring_digest_as_a_default(): void {
        $resolver = new \TT\Modules\Alerts\Policy\AlertPolicyResolver();

        $this->assertNotContains(
            Surface::DIGEST,
            $resolver->surfacesFor( $this->user, self::KEY ),
            'surfaces that leave the building are opt-in only (epic decision 10)'
        );
    }

    public function test_a_user_who_opted_in_gets_their_open_alerts(): void {
        $this->optIn();
        $this->seed();

        $rows = $this->query->forUser( $this->user, current_time( 'mysql' ) );

        $this->assertCount( 1, $rows );
        $this->assertSame( self::KEY, $rows[0]->alert_key );
    }

    // ── what must not be re-sent ───────────────────────────────────────

    public function test_an_already_digested_occurrence_is_not_repeated(): void {
        $this->optIn();
        $this->seed();
        $now = current_time( 'mysql' );

        $first = $this->query->forUser( $this->user, $now );
        $this->assertCount( 1, $first );

        $this->query->markDigested( [ (int) $first[0]->id ], $now );

        $this->assertSame(
            [],
            $this->query->forUser( $this->user, $now ),
            'an open alert must not be mailed again every day until it is fixed'
        );
    }

    public function test_an_occurrence_resolved_before_the_digest_runs_is_excluded(): void {
        $this->optIn();
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET resolved_at = NOW()" );

        $this->assertSame( [], $this->query->forUser( $this->user, current_time( 'mysql' ) ) );
    }

    public function test_an_occurrence_already_read_in_the_app_is_excluded(): void {
        $this->optIn();
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET read_at = NOW()" );

        $this->assertSame(
            [],
            $this->query->forUser( $this->user, current_time( 'mysql' ) ),
            'mailing something the user has already seen in the app is pure noise'
        );
    }

    public function test_a_snoozed_occurrence_is_excluded_until_the_snooze_lapses(): void {
        $this->optIn();
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET snoozed_until = DATE_ADD(NOW(), INTERVAL 3 DAY)" );

        $this->assertSame( [], $this->query->forUser( $this->user, current_time( 'mysql' ) ) );
    }

    public function test_a_dismissed_occurrence_is_excluded(): void {
        $this->optIn();
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences SET dismissed_at = NOW()" );

        $this->assertSame( [], $this->query->forUser( $this->user, current_time( 'mysql' ) ) );
    }

    /**
     * The recipient sweep is driven off the occurrences table, so a club
     * where nobody has anything pending costs one query and mails nobody.
     */
    public function test_no_pending_occurrences_means_no_recipients(): void {
        $this->assertSame( [], $this->query->recipientsWithPending( current_time( 'mysql' ) ) );
    }

    // ── retention ──────────────────────────────────────────────────────

    public function test_resolved_occurrences_older_than_the_window_are_purged(): void {
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences
                          SET resolved_at = DATE_SUB(NOW(), INTERVAL 120 DAY)" );

        $purged = ( new AlertRetentionCron() )->runForCurrentClub();

        $this->assertSame( 1, $purged );
        $this->assertSame( 0, $this->countRows() );
    }

    public function test_recently_resolved_occurrences_are_kept(): void {
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences
                          SET resolved_at = DATE_SUB(NOW(), INTERVAL 10 DAY)" );

        ( new AlertRetentionCron() )->runForCurrentClub();

        $this->assertSame( 1, $this->countRows() );
    }

    /**
     * The property that matters most here. An alert nobody has fixed for a
     * year is evidence, not litter — purging it would erase the history at
     * the moment it became worth reading.
     */
    public function test_open_occurrences_are_never_purged_however_old(): void {
        $this->seed();

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}tt_alert_occurrences
                          SET first_seen_at = DATE_SUB(NOW(), INTERVAL 500 DAY),
                              last_seen_at  = DATE_SUB(NOW(), INTERVAL 500 DAY),
                              resolved_at   = NULL" );

        $purged = ( new AlertRetentionCron() )->runForCurrentClub();

        $this->assertSame( 0, $purged );
        $this->assertSame( 1, $this->countRows() );
    }

    public function test_the_retention_window_is_ninety_days(): void {
        $this->assertSame( 90, AlertRetentionCron::RETENTION_DAYS );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function optIn(): void {
        ( new AlertPreferencesRepository() )->save(
            $this->user,
            self::KEY,
            [ Surface::BADGE, Surface::DIGEST ]
        );
    }

    private function seed(): void {
        ( new \TT\Modules\Alerts\AlertEvaluator() )->run( self::stub(), new AlertContext( 1 ) );
    }

    private function countRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_alert_occurrences" );
    }

    private static function stub(): AlertInterface {
        return new class implements AlertInterface {
            public function key(): string { return AlertDigestAndRetentionTest::KEY; }
            public function module(): string { return 'test'; }
            public function subjectType(): string { return 'activity'; }
            public function label(): string { return 'Digest stub'; }
            public function description(): string { return 'Stub for digest tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return [ Surface::BADGE, Surface::DIGEST ]; }
            public function isOperational(): bool { return false; }
            public function evaluate( AlertContext $context ): array {
                return [ new \TT\Modules\Alerts\Domain\AlertOccurrence(
                    AlertDigestAndRetentionTest::KEY,
                    get_current_user_id() ?: 1,
                    'activity',
                    404,
                    Severity::ATTENTION,
                    [ 'title' => 'Digest stub alert', 'url' => 'https://example.test/activity/404' ]
                ) ];
            }
        };
    }
}
