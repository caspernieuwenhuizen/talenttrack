<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
use TT\Modules\Alerts\Policy\ClubAlertPolicy;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;

/**
 * #2632 — the three-layer precedence: definition → club policy → user.
 *
 * The acceptance criterion asks for the full combination matrix, and it is
 * worth being literal about it: this is the code path that decides whether a
 * person is told about a gap in a player's record, and every wrong answer is
 * either a missed problem or a nag that teaches someone to ignore the
 * feature.
 *
 * Two behaviours get particular attention because they look like bugs and
 * are not: an absent preference row means "the shipped default", never
 * "off"; and a stored empty set means "nowhere", which is a real choice.
 */
final class AlertPolicyResolverTest extends WP_UnitTestCase {

    private const KEY     = 'test.policy_alert';
    private const OP_KEY  = 'test.operational_alert';

    /** @var int */
    private $user;

    /** @var AlertPreferencesRepository */
    private $prefs;

    public function set_up(): void {
        parent::set_up();
        AlertPreferencesRepository::flushTableCache();
        AlertOccurrencesRepository::flushTableCache();

        $this->user  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->prefs = new AlertPreferencesRepository();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_preferences" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_alert_occurrences" );

        ( new \TT\Infrastructure\Config\ConfigService() )->setJson( ClubAlertPolicy::CONFIG_KEY, [] );

        add_filter( 'tt_register_alerts', [ $this, 'registerStubs' ] );
        AlertRegistry::flush();
    }

    public function tear_down(): void {
        remove_filter( 'tt_register_alerts', [ $this, 'registerStubs' ] );
        AlertRegistry::flush();
        parent::tear_down();
    }

    /**
     * @param list<mixed> $alerts
     * @return list<mixed>
     */
    public function registerStubs( array $alerts ): array {
        $alerts[] = self::stub( self::KEY, [ Surface::BADGE, Surface::BANNER ], false );
        $alerts[] = self::stub( self::OP_KEY, [ Surface::BADGE ], true );
        return $alerts;
    }

    // ── layer 1: the definition's own default ──────────────────────────

    public function test_with_no_club_policy_and_no_user_row_the_definition_default_wins(): void {
        $this->assertSame(
            [ Surface::BADGE, Surface::BANNER ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY )
        );
    }

    /**
     * The rule that makes epic decision 9 work: wave 6 adds a dozen
     * definitions to a running install and they must take effect at their
     * shipped surfaces, without a backfill row per existing user.
     */
    public function test_an_absent_row_means_default_not_off_even_when_other_rows_exist(): void {
        $this->prefs->save( $this->user, 'test.some_other_alert', [] );

        $this->assertSame(
            [ Surface::BADGE, Surface::BANNER ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY ),
            'a user with preferences for other alerts must still get this one at its default'
        );
    }

    // ── layer 3: the user's own choice ─────────────────────────────────

    public function test_a_user_row_replaces_the_default(): void {
        $this->prefs->save( $this->user, self::KEY, [ Surface::BADGE ] );

        $this->assertSame(
            [ Surface::BADGE ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY )
        );
    }

    /**
     * An empty stored set is a real choice ("nowhere") and must not be
     * mistaken for an absent row ("wherever the definition says"). The two
     * states look identical in a naive `empty()` check and behave opposite.
     */
    public function test_an_empty_stored_set_means_nowhere(): void {
        $this->prefs->save( $this->user, self::KEY, [] );

        $this->assertSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY ) );
        $this->assertFalse( ( new AlertPolicyResolver() )->isEnabledFor( $this->user, self::KEY ) );
    }

    public function test_resetting_a_row_returns_the_alert_to_its_default(): void {
        $this->prefs->save( $this->user, self::KEY, [] );
        $this->assertSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY ) );

        $this->prefs->reset( $this->user, self::KEY );

        $this->assertSame(
            [ Surface::BADGE, Surface::BANNER ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY )
        );
    }

    public function test_one_users_preference_does_not_affect_another(): void {
        $other = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->prefs->save( $this->user, self::KEY, [] );

        $resolver = new AlertPolicyResolver();
        $this->assertSame( [], $resolver->surfacesFor( $this->user, self::KEY ) );
        $this->assertSame( [ Surface::BADGE, Surface::BANNER ], $resolver->surfacesFor( $other, self::KEY ) );
    }

    // ── layer 2: club policy, and how it overrides the user ────────────

    public function test_force_off_beats_the_definition_default(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_OFF );

        $this->assertSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY ) );
    }

    public function test_force_off_beats_a_user_who_wants_it(): void {
        $this->prefs->save( $this->user, self::KEY, [ Surface::BADGE, Surface::BANNER ] );
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_OFF );

        $this->assertSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY ) );
    }

    public function test_force_on_beats_a_user_who_muted_it(): void {
        $this->prefs->save( $this->user, self::KEY, [] );
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_ON, [ Surface::BANNER ] );

        $this->assertSame(
            [ Surface::BANNER ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY )
        );
    }

    public function test_force_on_without_chosen_surfaces_falls_back_to_the_definition_default(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_ON, [] );

        $this->assertSame(
            [ Surface::BADGE, Surface::BANNER ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY )
        );
    }

    public function test_user_choice_mode_lets_the_user_row_stand(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_USER_CHOICE );
        $this->prefs->save( $this->user, self::KEY, [ Surface::BADGE ] );

        $this->assertSame(
            [ Surface::BADGE ],
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY )
        );
    }

    // ── operational alerts ─────────────────────────────────────────────

    public function test_an_operational_alert_cannot_be_forced_off(): void {
        $error = ( new ClubAlertPolicy() )->set( self::OP_KEY, ClubAlertPolicy::MODE_FORCE_OFF );

        $this->assertNotNull( $error, 'the refusal must be explained, not silently ignored' );
        $this->assertNotSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::OP_KEY ) );
    }

    /**
     * Enforced on read as well as write, so a hand-edited config row — or one
     * stored before a definition became operational — cannot silence a
     * safeguarding alert.
     */
    public function test_a_force_off_stored_by_other_means_is_ignored_for_operational_alerts(): void {
        ( new \TT\Infrastructure\Config\ConfigService() )->setJson( ClubAlertPolicy::CONFIG_KEY, [
            self::OP_KEY => [ 'mode' => ClubAlertPolicy::MODE_FORCE_OFF ],
        ] );

        $this->assertSame(
            ClubAlertPolicy::MODE_USER_CHOICE,
            ( new ClubAlertPolicy() )->modeFor( self::OP_KEY )
        );
        $this->assertNotSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::OP_KEY ) );
    }

    public function test_operational_alerts_report_a_lock_reason(): void {
        $this->assertNotNull( ( new AlertPolicyResolver() )->lockReason( self::OP_KEY ) );
        $this->assertNull( ( new AlertPolicyResolver() )->lockReason( self::KEY ) );
    }

    public function test_club_forced_alerts_report_a_lock_reason(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_ON );
        $this->assertNotNull( ( new AlertPolicyResolver() )->lockReason( self::KEY ) );
    }

    // ── the interrupt tier ─────────────────────────────────────────────

    public function test_a_definition_cannot_declare_itself_interrupting(): void {
        $sneaky = self::stub( 'test.sneaky', [ Surface::BADGE, Surface::INTERRUPT ], false );
        add_filter( 'tt_register_alerts', static function ( array $a ) use ( $sneaky ): array {
            $a[] = $sneaky;
            return $a;
        } );
        AlertRegistry::flush();

        $this->assertNotContains(
            Surface::INTERRUPT,
            ( new AlertPolicyResolver() )->surfacesFor( $this->user, 'test.sneaky' ),
            'only a club admin may make an alert blocking (epic decision 4)'
        );
    }

    public function test_the_club_can_make_an_alert_interrupting(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_USER_CHOICE, [], true );

        $this->assertTrue( ( new AlertPolicyResolver() )->isInterrupt( $this->user, self::KEY ) );
    }

    /**
     * An alert the user has switched off entirely must not come back as a
     * modal because the club also ticked "interrupt". Off is off.
     */
    public function test_interrupt_does_not_resurrect_an_alert_the_user_muted(): void {
        $this->prefs->save( $this->user, self::KEY, [] );
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_USER_CHOICE, [], true );

        $this->assertSame( [], ( new AlertPolicyResolver() )->surfacesFor( $this->user, self::KEY ) );
        $this->assertFalse( ( new AlertPolicyResolver() )->isInterrupt( $this->user, self::KEY ) );
    }

    // ── escalation threshold (stored here, consumed by #2635) ──────────

    public function test_escalation_threshold_round_trips_and_defaults_to_null(): void {
        $policy = new ClubAlertPolicy();
        $this->assertNull( $policy->escalateAfterDays( self::KEY ) );

        $policy->set( self::KEY, ClubAlertPolicy::MODE_USER_CHOICE, [], false, 14 );
        $this->assertSame( 14, ( new ClubAlertPolicy() )->escalateAfterDays( self::KEY ) );
    }

    // ── evaluator integration ──────────────────────────────────────────

    /**
     * The acceptance criterion is specifically that force_off stops rows
     * being WRITTEN, not merely hidden — a table of invisible occurrences is
     * retention cost and privacy surface for no benefit.
     */
    public function test_force_off_stops_occurrences_being_written_at_all(): void {
        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_OFF );

        $stat = ( new AlertEvaluator() )->run( $this->occurringStub(), new AlertContext( 1 ) );

        $this->assertSame( 0, $stat['created'] );
        $this->assertSame( 0, ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ) );
    }

    /**
     * Switching an alert off must also clear the backlog it already made,
     * rather than stranding rows that are unreachable but still stored.
     */
    public function test_switching_an_alert_off_resolves_what_it_already_wrote(): void {
        $ev = new AlertEvaluator();
        $ev->run( $this->occurringStub(), new AlertContext( 1 ) );
        $this->assertSame( 1, ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ) );

        ( new ClubAlertPolicy() )->set( self::KEY, ClubAlertPolicy::MODE_FORCE_OFF );
        ( new AlertEvaluator() )->run( $this->occurringStub(), new AlertContext( 1 ) );

        $this->assertSame( 0, ( new AlertOccurrencesRepository() )->openCountForUser( $this->user ) );
    }

    public function test_a_user_who_muted_an_alert_gets_no_row(): void {
        $this->prefs->save( $this->user, self::KEY, [] );

        $stat = ( new AlertEvaluator() )->run( $this->occurringStub(), new AlertContext( 1 ) );

        $this->assertSame( 0, $stat['created'] );
        $this->assertSame( 1, $stat['skipped'] );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function occurringStub(): AlertInterface {
        $occ = new AlertOccurrence(
            self::KEY,
            $this->user,
            'activity',
            77,
            Severity::ATTENTION,
            [ 'title' => 'Needs doing', 'url' => 'https://example.test/' ]
        );
        return self::stub( self::KEY, [ Surface::BADGE, Surface::BANNER ], false, [ $occ ] );
    }

    /**
     * @param list<string>          $surfaces
     * @param list<AlertOccurrence> $occurrences
     */
    private static function stub( string $key, array $surfaces, bool $operational, array $occurrences = [] ): AlertInterface {
        return new class( $key, $surfaces, $operational, $occurrences ) implements AlertInterface {
            /** @var string */ private $k;
            /** @var list<string> */ private $s;
            /** @var bool */ private $op;
            /** @var list<AlertOccurrence> */ private $occ;

            public function __construct( string $k, array $s, bool $op, array $occ ) {
                $this->k = $k; $this->s = $s; $this->op = $op; $this->occ = $occ;
            }

            public function key(): string { return $this->k; }
            public function module(): string { return 'test'; }
            public function label(): string { return 'Policy stub'; }
            public function description(): string { return 'Stub for policy tests.'; }
            public function defaultSeverity(): string { return Severity::ATTENTION; }
            public function capRequired(): string { return ''; }
            public function defaultSurfaces(): array { return $this->s; }
            public function isOperational(): bool { return $this->op; }
            public function evaluate( AlertContext $context ): array { return $this->occ; }
        };
    }
}
