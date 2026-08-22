<?php
namespace TT\Modules\Alerts\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Digest\AlertDigestQuery;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * AlertDigestCron (#2634, epic #2629) — the periodic roll-up email.
 *
 * Scheduling matches the rest of the module: subscribe to the workflow
 * engine heartbeat (`tt_workflow_cron_tick`) and self-throttle, rather than
 * registering another `wp_schedule_event`. One chokepoint a future SaaS
 * scheduler replaces; fifty ad-hoc registrations are not replaceable.
 *
 * **Opt-in only** (epic decision 10). Nobody is enrolled by this shipping —
 * `MessageType::ALERT_DIGEST` is a normal, refusable Comms type, and the
 * digest is only ever sent to a user whose alert preferences include the
 * `digest` surface for at least one alert. There is no backfill and no
 * "we enabled this for you" release.
 *
 * **An empty digest sends nothing.** A daily "you have 0 alerts" email is
 * how a product teaches its users to filter it to spam, and it would take
 * the useful ones with it.
 */
final class AlertDigestCron {

    /** tt_config key: date of the last digest run, per club. */
    public const LAST_RUN_CONFIG_KEY = 'tt_alerts_last_digest_date';

    /** Most alerts listed in one digest before it becomes a wall of text. */
    private const MAX_LINES = 15;

    /** @var ConfigService */
    private $config;

    public function __construct( ?ConfigService $config = null ) {
        $this->config = $config ?? new ConfigService();
    }

    /** Priority 30: after the sweep (25), so a digest reflects the latest reconcile. */
    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 30 );
    }

    public static function onTick(): void {
        ( new self() )->maybeRun();
    }

    /**
     * Once per calendar day per club. The heartbeat is hourly, so this guard
     * is what keeps the digest daily rather than hourly.
     */
    public function maybeRun(): void {
        $today = current_time( 'Y-m-d' );
        foreach ( $this->clubIds() as $club_id ) {
            $this->withClub( $club_id, function () use ( $today ) {
                if ( $this->config->get( self::LAST_RUN_CONFIG_KEY, '' ) === $today ) return;
                $this->runForCurrentClub();
                $this->config->set( self::LAST_RUN_CONFIG_KEY, $today );
            } );
        }
    }

    /**
     * Build and send one digest per eligible recipient in the pinned club.
     *
     * @return array{recipients:int,sent:int,skipped_empty:int}
     */
    public function runForCurrentClub(): array {
        $stat  = [ 'recipients' => 0, 'sent' => 0, 'skipped_empty' => 0 ];
        $now   = current_time( 'mysql' );
        $query = new AlertDigestQuery();

        foreach ( $query->recipientsWithPending( $now ) as $user_id ) {
            $stat['recipients']++;

            $rows = $query->forUser( $user_id, $now, self::MAX_LINES );
            if ( empty( $rows ) ) {
                // Candidate at the SQL level but nothing the user's
                // preferences allow into a digest. Not an error — the common
                // case, since `digest` is opt-in.
                $stat['skipped_empty']++;
                continue;
            }

            if ( $this->sendTo( $user_id, $rows, $now ) ) {
                $query->markDigested( array_map( static fn( $r ): int => (int) $r->id, $rows ), $now );
                $stat['sent']++;
            }
        }

        return $stat;
    }

    /**
     * Hand one digest to Comms.
     *
     * Everything downstream — quiet hours, rate limiting, the opt-out check,
     * the audit row — is CommsService's job. The digest deliberately owns
     * none of it: a second implementation of quiet hours is a second thing
     * to get wrong.
     *
     * `markDigested` is called by the caller only when this returns true, so
     * a failed send leaves the occurrences eligible for tomorrow rather than
     * silently swallowing them.
     *
     * @param list<object> $rows
     */
    private function sendTo( int $user_id, array $rows, string $now ): bool {
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;

        $request = new CommsRequest(
            'alert_digest',
            MessageType::ALERT_DIGEST,
            (int) apply_filters( 'tt_current_club_id', 1 ),
            0, // system sender
            [ Recipient::self( $user_id, (string) $user->user_email ) ],
            [
                'alert_count' => (string) count( $rows ),
                'alert_lines' => $this->renderLines( $rows ),
                'deep_link'   => $this->inboxUrl(),
            ]
        );

        try {
            $results = ( new CommsService() )->send( $request );
        } catch ( \Throwable $e ) {
            error_log( '[TalentTrack alerts] digest send failed for user ' . $user_id . ': ' . $e->getMessage() );
            return false;
        }

        foreach ( is_array( $results ) ? $results : [] as $result ) {
            if ( is_object( $result ) && isset( $result->status ) && $result->status === 'sent' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * One line per alert: what it is and where to fix it.
     *
     * Links point at the SUBJECT, not at the alerts inbox — one click to the
     * thing that needs doing, rather than one click to a list and a second
     * click to the thing.
     *
     * @param list<object> $rows
     */
    private function renderLines( array $rows ): string {
        $lines = [];
        foreach ( $rows as $row ) {
            $payload = [];
            $raw     = (string) ( $row->payload_json ?? '' );
            if ( $raw !== '' ) {
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) $payload = $decoded;
            }

            $title = isset( $payload['title'] ) ? (string) $payload['title'] : '';
            if ( $title === '' ) {
                $definition = AlertRegistry::find( (string) ( $row->alert_key ?? '' ) );
                $title = $definition !== null ? $definition->label() : '';
            }
            if ( $title === '' ) continue;

            $url     = isset( $payload['url'] ) ? (string) $payload['url'] : '';
            $lines[] = $url !== '' ? '- ' . $title . ' — ' . $url : '- ' . $title;
        }
        return implode( "\n", $lines );
    }

    private function inboxUrl(): string {
        $base = class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' )
            ? \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
            : home_url( '/' );
        return (string) add_query_arg( 'tt_view', 'alerts', $base ); /* tt-xview-ok */
    }

    /** @return int[] */
    private function clubIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$wpdb->prefix}tt_config" );
        $ids = array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : [] ) ) );
        if ( ! in_array( 1, $ids, true ) ) $ids[] = 1;
        return array_filter( $ids, static function ( $id ) { return $id > 0; } );
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withClub( int $club_id, callable $fn ) {
        $filter = static function () use ( $club_id ) { return $club_id; };
        add_filter( 'tt_current_club_id', $filter, 9999 );
        $this->config = new ConfigService();
        try {
            return $fn();
        } finally {
            remove_filter( 'tt_current_club_id', $filter, 9999 );
            $this->config = new ConfigService();
        }
    }
}
