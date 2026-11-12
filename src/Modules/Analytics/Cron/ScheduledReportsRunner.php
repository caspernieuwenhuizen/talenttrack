<?php
namespace TT\Modules\Analytics\Cron;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactResolver;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Domain\UnknownFactKeyException;
use TT\Modules\Analytics\Export\CsvExporter;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportDelivery;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDelivery;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsOutcomeSummary;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Templates\ScheduledReportNotSentTemplate;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Modules\Export\ExportGate;

/**
 * ScheduledReportsRunner — daily cron that processes due schedules
 * (#0083 Child 6).
 *
 * `init()` registers a daily WP-cron `tt_scheduled_reports_cron`.
 * The hook handler iterates `ScheduledReportsRepository::dueForRun()`,
 * renders each as CSV via `CsvExporter::forKpi()`, sends it through
 * Comms with the file attached, then stamps `last_run_at` +
 * `next_run_at` on the schedule.
 *
 * Each run writes one row to the audit log (`scheduled_report.run`)
 * with the schedule id, recipient count, and success / failure.
 *
 * License-gated: schedules registered on installs whose effective
 * tier doesn't have `scheduled_reports` are skipped at run time
 * (operators can keep their definitions in case the plan upgrades).
 */
final class ScheduledReportsRunner {

    public const HOOK = 'tt_scheduled_reports_cron';

    /** The length of the schedule's `last_error` column. */
    private const NOTE_LIMIT = 255;

    /**
     * #4963 — the most Studio schedules one run sends. A schedule past it
     * stays due and goes out on the next run.
     */
    public const STUDIO_PER_RUN = 20;

    public static function init(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        // Schedule on plugin boot if missing.
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public static function run(): void {
        // License gate — silently skip when the tier doesn't have it.
        // Operators see their definitions but no emails go out.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'scheduled_reports' )
        ) {
            return;
        }

        $repo   = new ScheduledReportsRepository();
        $due    = $repo->dueForRun();
        $now    = current_time( 'mysql', true );
        $studio = 0;

        foreach ( $due as $schedule ) {
            // #3462 — the team monthly report renders a PDF from the
            // schedule's own composition; a KPI schedule keeps the CSV path
            // below exactly as it was.
            if ( ( $schedule['report_key'] ?? '' ) === ScheduledReportsRepository::REPORT_TEAM_MONTHLY ) {
                self::runTeamMonthly( $repo, $schedule, $now );
                continue;
            }
            // #3891 — the player report, one player or a squad's round.
            if ( ( $schedule['report_key'] ?? '' ) === ScheduledReportsRepository::REPORT_PLAYER ) {
                self::runPlayerReport( $repo, $schedule, $now );
                continue;
            }
            // #4963 — a published Report Studio report, bounded per tick: a
            // Studio run composes every block for every reader, so the rest
            // wait for the next run rather than stretching this one.
            if ( ( $schedule['report_key'] ?? '' ) === ScheduledReportsRepository::REPORT_STUDIO ) {
                if ( ++$studio > self::STUDIO_PER_RUN ) continue;
                self::runStudio( $repo, $schedule, $now );
                continue;
            }

            $kpi_key = (string) ( $schedule['kpi_key'] ?? '' );
            $kpi     = $kpi_key !== '' ? KpiRegistry::find( $kpi_key ) : null;
            if ( $kpi === null ) {
                $repo->markRun( (int) $schedule['id'], $now );
                continue;
            }
            self::runKpi( $repo, $schedule, $now, $kpi_key, $kpi->label, $kpi->factKey );
        }
    }

    /**
     * #4619 — one KPI schedule. Every recipient is checked: an account gets
     * the CSV built with its own scope, and only when it may read the fact
     * at all; an address with no account here gets the CSV built with the
     * creator's scope, and only for a fact that holds no player data. Who
     * was left out, and why, is kept on the schedule and in the audit row.
     *
     * @param array<string,mixed> $schedule
     */
    private static function runKpi( ScheduledReportsRepository $repo, array $schedule, string $now, string $kpi_key, string $kpi_label, string $fact_key ): void {
        $id   = (int) ( $schedule['id'] ?? 0 );
        $plan = self::planKpiDelivery(
            (array) ( $schedule['recipients'] ?? [] ),
            $fact_key,
            (int) ( $schedule['created_by'] ?? 0 )
        );

        $delivered = 0;
        $sent      = false;
        try {
            foreach ( $plan['deliveries'] as $delivery ) {
                $delivered += count( $delivery['recipients'] );
                if ( self::sendKpiCsv( $schedule, $kpi_key, $kpi_label, $delivery['scope'], $delivery['recipients'] ) ) {
                    $sent = true;
                }
            }
        } catch ( UnknownFactKeyException $e ) {
            // #4860 — the KPI names a field its fact no longer has. Nothing
            // is sent, because the file would carry the un-narrowed number;
            // the schedule says why in its list, and the run is logged once
            // per distinct reason rather than every heartbeat.
            //
            // #4874 — the owner is told once per breakage. The reason on the
            // schedule is the flag: the message goes out when the reason
            // first appears, and again only after it has cleared (a
            // successful send, a resume) and the schedule breaks again.
            $note = $e->readerMessage();
            if ( (string) ( $schedule['last_error'] ?? '' ) !== $note ) {
                Logger::warning( 'Scheduled report: the KPI names a field that no longer exists, send skipped.', [ 'schedule_id' => $id, 'kpi_key' => $kpi_key ] + $e->details() );
                self::tellOwnerNotSent( $schedule, $e->key );
            }
            $repo->recordError( $id, $note );
            $repo->markRun( $id, $now );
            return;
        }

        if ( $plan['skipped'] !== [] ) {
            $repo->recordError( $id, self::skippedNote( $plan['skipped'] ) );
        } elseif ( $sent ) {
            $repo->clearError( $id );
        }

        if ( $plan['deliveries'] !== [] || $plan['skipped'] !== [] ) {
            self::auditLog( $schedule, $delivered, $sent, $plan['skipped'] );
        }
        $repo->markRun( $id, $now );
    }

    /**
     * Who receives a KPI schedule, with which scope, and who is left out.
     *
     * A role key expands to its users in this club, and a member of the role
     * from outside it is left out (`not_in_club`), and so is a member in it
     * with no email address (`no_email`, #5150); a plain address that
     * belongs to an account in this club is that account, any other address
     * has no account here. An account receives the CSV built with its
     * own scope, when `FactQuery::canRead()` lets it read the fact; accounts
     * that read the same rows (`FactScope::rowsKey()`) share one build.
     * Addresses with no account share one CSV built with the creator's
     * scope, and only when the fact carries no player data.
     *
     * Each skipped entry carries `via`: the role key it was reached through,
     * or '' for an address the creator typed.
     *
     * @param string[] $entries
     * @return array{
     *     deliveries: list<array{scope: FactScope, recipients: list<Recipient>}>,
     *     skipped: list<array{email: string, user_id: int, reason: string, via: string}>
     * }
     */
    public static function planKpiDelivery( array $entries, string $fact_key, int $creator_id ): array {
        $users     = [];
        $via       = [];
        $addresses = [];
        $skipped   = [];
        $foreign   = [];
        foreach ( $entries as $entry ) {
            $entry = trim( (string) $entry );
            if ( $entry === '' ) continue;

            if ( is_email( $entry ) ) {
                $user_id = self::clubAccountFor( $entry );
                if ( $user_id > 0 ) {
                    $users[ $user_id ] = self::userRecipient( $user_id, $entry );
                    $via[ $user_id ]   = '';
                    continue;
                }
                // #4718 — an address that belongs to an account, in this
                // club or not, follows the export rule for that account.
                $account_id = self::accountFor( $entry );
                if ( $account_id > 0 && ! ExportGate::mayExport( $account_id ) ) {
                    $skipped[] = [ 'email' => $entry, 'user_id' => 0, 'reason' => 'no_file_access', 'via' => '' ];
                    continue;
                }
                $addresses[ strtolower( $entry ) ] = new Recipient( 0, Recipient::KIND_SYSTEM, null, $entry );
                continue;
            }

            $role_users = get_users( [ 'role' => $entry, 'fields' => [ 'ID' ] ] );
            foreach ( $role_users as $u ) {
                $user_id = (int) ( $u->ID ?? 0 );
                if ( $user_id <= 0 || isset( $users[ $user_id ] ) ) continue;
                if ( isset( $foreign[ $user_id ] ) ) continue;
                if ( ! self::inClub( $user_id ) ) {
                    $foreign[ $user_id ] = true;
                    $skipped[]           = self::notInClub( $entry );
                    continue;
                }
                $email = (string) ( ContactResolver::emailForUser( $user_id ) ?? '' );
                if ( $email === '' || ! is_email( $email ) ) {
                    // #5150 — in the club, so recorded, as a PDF schedule does.
                    $foreign[ $user_id ] = true;
                    $skipped[]           = [ 'email' => '', 'user_id' => $user_id, 'reason' => self::NO_EMAIL, 'via' => $entry ];
                    continue;
                }
                $users[ $user_id ] = self::userRecipient( $user_id, $email );
                $via[ $user_id ]   = $entry;
            }
        }

        $fact          = FactRegistry::find( $fact_key );
        $entity        = $fact !== null ? $fact->readEntity : '';
        $by_visibility = $fact !== null && $fact->visibilityColumn !== null;
        $groups        = [];
        $taken         = [];
        foreach ( $users as $user_id => $recipient ) {
            $taken[ strtolower( $recipient->emailAddress ) ] = true;
            // #4718 — the CSV is a file; an account that may receive none
            // is left out and named like any other refusal.
            if ( ! ExportGate::mayExport( (int) $user_id ) ) {
                $skipped[] = [ 'email' => $recipient->emailAddress, 'user_id' => $user_id, 'reason' => 'no_file_access', 'via' => $via[ $user_id ] ?? '' ];
                continue;
            }
            $scope = FactScope::forUser( $user_id );
            if ( ! FactQuery::canRead( $fact_key, $scope ) ) {
                $skipped[] = [ 'email' => $recipient->emailAddress, 'user_id' => $user_id, 'reason' => 'no_read_access', 'via' => $via[ $user_id ] ?? '' ];
                continue;
            }
            $key = $scope->rowsKey( $entity, $by_visibility );
            if ( ! isset( $groups[ $key ] ) ) $groups[ $key ] = [ 'scope' => $scope, 'recipients' => [] ];
            $groups[ $key ]['recipients'][] = $recipient;
        }
        $deliveries = array_values( $groups );

        $outside = [];
        foreach ( $addresses as $key => $recipient ) {
            if ( isset( $taken[ $key ] ) ) continue;
            if ( self::factCarriesPlayerData( $fact_key ) ) {
                $skipped[] = [ 'email' => $recipient->emailAddress, 'user_id' => 0, 'reason' => 'player_data_to_address', 'via' => '' ];
                continue;
            }
            $outside[] = $recipient;
        }
        if ( $outside !== [] ) {
            // No account to scope by: the schedule reads as the person who set it up.
            $deliveries[] = [ 'scope' => FactScope::forUser( $creator_id ), 'recipients' => $outside ];
        }

        return [ 'deliveries' => $deliveries, 'skipped' => $skipped ];
    }

    /**
     * Does this fact hold data about individual players? True when its rows
     * belong to a player, it can be broken down by player, or it is
     * unknown: an unknown fact fails closed.
     */
    public static function factCarriesPlayerData( string $fact_key ): bool {
        $fact = FactRegistry::find( $fact_key );
        if ( $fact === null ) return true;
        if ( $fact->entityScope === 'player' ) return true;
        foreach ( $fact->dimensions as $dimension ) {
            if ( $dimension->foreignTable === 'tt_players' ) return true;
        }
        return false;
    }

    /**
     * The account this address belongs to, when that account has a person
     * record in the current club; 0 otherwise. The lookup alone searches the
     * whole install.
     */
    private static function clubAccountFor( string $email ): int {
        $user_id = self::accountFor( $email );
        return $user_id > 0 && self::inClub( $user_id ) ? $user_id : 0;
    }

    /** The account this address belongs to anywhere on the install; 0 when none. */
    private static function accountFor( string $email ): int {
        $account = get_user_by( 'email', $email );
        if ( ! $account instanceof \WP_User || (int) $account->ID <= 0 ) return 0;
        return (int) $account->ID;
    }

    /** Does this account have a person record in the current club? */
    private static function inClub( int $user_id ): bool {
        return QueryHelpers::person_id_for_user( $user_id ) > 0;
    }

    /**
     * A member of a role who is not in this club, counted under the role and
     * never named: neither their address nor their account is this club's
     * to record.
     *
     * @return array{email: string, user_id: int, reason: string, via: string}
     */
    private static function notInClub( string $role ): array {
        return [ 'email' => '', 'user_id' => 0, 'reason' => 'not_in_club', 'via' => $role ];
    }

    private static function userRecipient( int $user_id, string $email ): Recipient {
        return new Recipient(
            $user_id,
            Recipient::KIND_COACH,
            null,
            $email,
            '',
            (string) get_user_meta( $user_id, 'locale', true )
        );
    }

    /**
     * #4874 — tell the schedule's owner it was not sent and why, with a link
     * to the schedule. In-app and by email as the owner's preferences allow,
     * in the owner's language, and held for quiet hours like any message.
     * An owner who has left the club is not told.
     *
     * @param array<string,mixed> $schedule
     */
    private static function tellOwnerNotSent( array $schedule, string $field ): void {
        $owner = (int) ( $schedule['created_by'] ?? 0 );
        if ( $owner <= 0 || ! self::inClub( $owner ) ) return;

        CommsDispatcher::dispatchSync(
            ScheduledReportNotSentTemplate::KEY,
            [
                'schedule_name' => (string) ( $schedule['name'] ?? '' ),
                'field_name'    => $field,
                'schedule_url'  => add_query_arg( [ 'tt_view' => 'scheduled-reports' ], RecordLink::dashboardUrl() ),
            ],
            [ self::userRecipient( $owner, (string) ( ContactResolver::emailForUser( $owner ) ?? '' ) ) ],
            [
                'message_type'   => MessageType::SCHEDULED_REPORT_NOT_SENT,
                'sender_user_id' => 0,
            ]
        );
    }

    /**
     * Build the CSV with `$scope`, stage it, and send it to `$recipients`.
     *
     * @param array<string,mixed> $schedule
     * @param list<Recipient>     $recipients
     */
    private static function sendKpiCsv( array $schedule, string $kpi_key, string $kpi_label, FactScope $scope, array $recipients ): bool {
        $csv = CsvExporter::forKpi( $kpi_key, $scope );

        // #3080 — the CSV is staged outside the web root, in a
        // directory whose name nobody can guess. See `stagingDir()`.
        $dir = self::stagingDir();
        if ( $dir === '' ) return false;

        $tmp_path = $dir . '/tt-report-' . sanitize_key( $kpi_key ) . '-' . gmdate( 'Y-m-d' ) . '.csv';

        try {
            // A failed write used to pass unnoticed: the adapter drops
            // an unreadable attachment silently, so the operator got a
            // "here is your report" email with no report in it. Treat
            // it as a failed run instead.
            if ( file_put_contents( $tmp_path, $csv ) === false ) {
                Logger::error(
                    'Scheduled report: could not write the CSV, send skipped.',
                    [ 'schedule_id' => (int) ( $schedule['id'] ?? 0 ), 'kpi_key' => $kpi_key ]
                );
                return false;
            }

            // #2604 — through Comms rather than a direct `wp_mail()`, so
            // the send is audited and an operator who muted scheduled
            // reports stops receiving them. The rendered CSV rides along
            // on the request's attachment paths. #3646 — the type
            // bypasses quiet hours, because a held message waits in a
            // queue that never keeps a file and this one is deleted
            // below as soon as the send returns.
            $results = CommsDispatcher::dispatchSync(
                ScheduledReportTemplate::KEY,
                [
                    'schedule_name' => (string) ( $schedule['name'] ?? '' ),
                    'kpi_label'     => $kpi_label,
                ],
                $recipients,
                [
                    'message_type'   => MessageType::SCHEDULED_REPORT,
                    'sender_user_id' => 0,
                    'attachments'    => [ $tmp_path ],
                ]
            );

            return CommsOutcomeSummary::sentCount( $results ) > 0;
        } finally {
            // `finally`, not the happy path: a throw anywhere above used
            // to leave the file behind for good. A leftover nobody is
            // told about is what turns a short window into a permanent
            // one, so the failure is logged rather than suppressed.
            self::discardStagingDir( $dir );
        }
    }

    /**
     * The line the schedules screen shows for recipients left out of a run.
     *
     * A recipient reached through a role key is counted under that key, never
     * named: the creator typed the role, not the addresses of its members.
     * Typed addresses are named until the note would pass the `last_error`
     * column's length, and the rest are counted. The audit row keeps the
     * full list.
     *
     * @param list<array{email: string, user_id: int, reason: string, via: string}> $skipped
     */
    private static function skippedNote( array $skipped ): string {
        $per_role = [];
        $typed    = [];
        foreach ( $skipped as $s ) {
            if ( $s['via'] !== '' ) {
                $per_role[ $s['via'] ] = ( $per_role[ $s['via'] ] ?? 0 ) + 1;
            } else {
                $typed[] = $s['email'];
            }
        }

        $parts = [];
        foreach ( $per_role as $role => $n ) {
            $parts[] = sprintf(
                /* translators: 1: a role key such as tt_parent, 2: how many of its members were left out */
                _n( '%1$s (%2$d member)', '%1$s (%2$d members)', $n, 'talenttrack' ),
                $role,
                $n
            );
        }
        $parts = array_merge( $parts, $typed );

        $shown = [];
        foreach ( $parts as $part ) {
            $candidate = array_merge( $shown, [ $part ] );
            if ( mb_strlen( self::noteText( count( $skipped ), $candidate, count( $parts ) - count( $candidate ) ) ) > self::NOTE_LIMIT ) break;
            $shown = $candidate;
        }
        if ( $shown === [] && $parts !== [] ) $shown = [ $parts[0] ];

        return self::noteText( count( $skipped ), $shown, count( $parts ) - count( $shown ) );
    }

    /**
     * @param list<string> $shown
     */
    private static function noteText( int $total, array $shown, int $more ): string {
        $who = implode( ', ', $shown );
        if ( $more > 0 ) {
            /* translators: 1: a comma-separated list of role keys and addresses, 2: how many more were left out */
            $who = sprintf( __( '%1$s and %2$d more', 'talenttrack' ), $who, $more );
        }
        return sprintf(
            /* translators: 1: number of recipients left out, 2: who they are: role keys with a count, and email addresses */
            _n(
                'Left out %1$d recipient who may not receive this report: %2$s',
                'Left out %1$d recipients who may not receive this report: %2$s',
                $total,
                'talenttrack'
            ),
            $total,
            $who
        );
    }

    /**
     * One team monthly schedule: render, stage, send, stamp.
     *
     * A run that cannot send says why on the schedules screen. One that must
     * not send — the team is gone, or its owner lost access — is also paused,
     * so it stays stopped until someone who can still read the team resumes
     * it. See `TeamMonthlyReportDelivery`.
     *
     * @param array<string,mixed> $schedule
     */
    public static function runTeamMonthly( ScheduledReportsRepository $repo, array $schedule, string $now_utc ): void {
        $composition = TeamMonthlyReportComposition::normalise( is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [] );
        $team_id     = $composition['team_id'];
        self::runPdf(
            $repo, $schedule, $now_utc,
            [ TeamMonthlyReportDelivery::class, 'render' ],
            // #4662 — the team report's own read rule, per recipient.
            static fn( int $user_id, array $result ): bool => TeamReportAccess::canRead( $user_id, $team_id )
        );
    }

    /**
     * #3891 — one player report schedule: the same render, stage, send and
     * stamp as the team monthly report, with the player report's delivery.
     *
     * @param array<string,mixed> $schedule
     */
    public static function runPlayerReport( ScheduledReportsRepository $repo, array $schedule, string $now_utc ): void {
        self::runPdf(
            $repo, $schedule, $now_utc,
            [ PlayerReportDelivery::class, 'render' ],
            // #4662 — the player report's own read rule, for every player in
            // the PDF: a squad round goes only to someone who reads them all.
            static function ( int $user_id, array $result ): bool {
                $players = is_array( $result['players'] ?? null ) ? $result['players'] : [];
                if ( $players === [] ) return false;
                foreach ( $players as $player_id ) {
                    if ( ! PlayerReportAccess::canRead( $user_id, (int) $player_id ) ) return false;
                }
                return true;
            }
        );
    }

    /**
     * Render a schedule's PDF with `$render` and mail it. A run that sent with
     * a `note` — a squad round that left a player out — keeps that note on the
     * schedules screen rather than clearing it, so the omission is visible.
     *
     * #4662 — only recipients who may read the report receive it
     * (`$may_receive`, asked per account after the render). A plain address
     * receives it only when it belongs to an account in this club. Whoever is
     * left out is named on the schedule and in the audit row, the way the KPI
     * path does it.
     *
     * @param array<string,mixed> $schedule
     * @param callable(array<string,mixed>, string, array{recipients: list<Recipient>, via: array<int, string>, skipped: list<array{email: string, user_id: int, reason: string, via: string}>, unresolved: list<string>}): array{ok:bool, stop:bool, error:string, bytes:string, filename:string, label:string, note?:string, players?:list<int>, reads?:array<string, array<int,int>>} $render
     * @param callable(int, array<string,mixed>): bool $may_receive
     * @param bool $tell_owner_on_stop #4963 — a run that pauses the schedule tells its owner, once per breakage
     */
    private static function runPdf( ScheduledReportsRepository $repo, array $schedule, string $now_utc, callable $render, callable $may_receive, bool $tell_owner_on_stop = false ): void {
        $id         = (int) ( $schedule['id'] ?? 0 );
        $resolved   = self::resolveRecipients( (array) ( $schedule['recipients'] ?? [] ) );
        $recipients = $resolved['recipients'];
        $skipped    = $resolved['skipped'];
        if ( $recipients === [] ) {
            $repo->recordError( $id, $skipped !== [] ? self::skippedNote( $skipped ) : __( 'Not sent: none of the recipients has an email address.', 'talenttrack' ) );
            self::auditLog( $schedule, 0, false, $skipped );
            $repo->markRun( $id, $now_utc );
            return;
        }

        $now_ts = strtotime( $now_utc . ' UTC' );
        // #4991 — the render is told who it is for, resolved this run, so a
        // shared PDF carries only what every recipient may read.
        $result = $render( $schedule, gmdate( 'Y-m-d', $now_ts !== false ? $now_ts : time() ), $resolved );
        if ( ! $result['ok'] ) {
            if ( $result['stop'] && $tell_owner_on_stop ) self::tellOwnerPaused( $schedule, mb_substr( $result['error'], 0, self::NOTE_LIMIT ) );
            $repo->recordError( $id, $result['error'] );
            self::auditLog( $schedule, count( $recipients ), false, $skipped );
            if ( $result['stop'] ) {
                $repo->setStatus( $id, ScheduledReportsRepository::STATUS_PAUSED );
            } else {
                $repo->markRun( $id, $now_utc );
            }
            return;
        }

        // #4718 — the PDF is a file: receiving one is a right of its own,
        // asked before the report's read rule. #5135 — one decision, the one
        // a Studio schedule's preview shows (`ScheduledReportReaders::sort()`).
        $sorted       = \TT\Modules\Analytics\Reports\ScheduledReportReaders::sort( $resolved, static fn( int $user_id ): bool => $may_receive( $user_id, $result ) );
        $skipped      = $sorted['skipped'];
        $recipients   = $sorted['allowed'];
        $skipped_note = $skipped !== [] ? self::skippedNote( $skipped ) : '';
        if ( $recipients === [] ) {
            $repo->recordError( $id, $skipped_note );
            self::auditLog( $schedule, 0, false, $skipped );
            $repo->markRun( $id, $now_utc );
            return;
        }

        $dir = self::stagingDir();
        if ( $dir === '' ) {
            $repo->recordError( $id, __( 'Not sent: the PDF could not be prepared for sending.', 'talenttrack' ) );
            self::auditLog( $schedule, count( $recipients ), false, $skipped );
            $repo->markRun( $id, $now_utc );
            return;
        }

        try {
            $path = $dir . '/' . $result['filename'];
            if ( file_put_contents( $path, $result['bytes'] ) === false ) {
                $repo->recordError( $id, __( 'Not sent: the PDF could not be prepared for sending.', 'talenttrack' ) );
                self::auditLog( $schedule, count( $recipients ), false, $skipped );
                $repo->markRun( $id, $now_utc );
                return;
            }

            $results = CommsDispatcher::dispatchSync(
                ScheduledReportTemplate::KEY,
                [
                    'schedule_name' => (string) ( $schedule['name'] ?? '' ),
                    'kpi_label'     => $result['label'],
                ],
                $recipients,
                [
                    'message_type'   => MessageType::SCHEDULED_REPORT,
                    'sender_user_id' => 0,
                    'attachments'    => [ $path ],
                ]
            );

            $sent = CommsOutcomeSummary::sentCount( $results ) > 0;
            // #4963 — the PDF was composed quietly; what it showed of a
            // player's injuries or sensitive journey entries is a read by
            // each account it went to, recorded once per recipient.
            $reads = $result['reads'] ?? [];
            if ( $sent && $reads !== [] ) {
                $to = [];
                foreach ( $recipients as $recipient ) $to[] = (int) $recipient->userId;
                foreach ( $reads as $action => $counts ) {
                    \TT\Infrastructure\Journey\InjuryReads::recordSent( $action, 'scheduled_report', $counts, $to, [ 'schedule_id' => $id ] );
                }
            }
            $note = trim( (string) ( $result['note'] ?? '' ) . ' ' . $skipped_note );
            if ( $sent && $note !== '' ) {
                $repo->recordError( $id, mb_substr( $note, 0, self::NOTE_LIMIT ) );
            } elseif ( $sent ) {
                $repo->clearError( $id );
            } else {
                $repo->recordError( $id, __( 'Not sent: no recipient could be emailed. Check their addresses and email preferences.', 'talenttrack' ) );
            }
            self::auditLog( $schedule, count( $recipients ), $sent, $skipped );
            $repo->markRun( $id, $now_utc );
        } finally {
            self::discardStagingDir( $dir );
        }
    }

    /**
     * A private, single-use directory to render one report into.
     *
     * The CSV used to be written straight into `wp_upload_dir()['basedir']`
     * — the served root of `wp-content/uploads/` — under the fully
     * predictable name `tt-report-<kpi>-<date>.csv`, and removed with a
     * suppressed `@unlink()`. Anyone who knew the site could guess today's
     * URL for any KPI, and a failed unlink left it there indefinitely.
     * These are reports about minors.
     *
     * Three properties, in the order they matter:
     *
     * - **Not served.** `get_temp_dir()` resolves `WP_TEMP_DIR`, then
     *   `sys_get_temp_dir()`, then PHP's upload tmp dir before its
     *   last-resort `WP_CONTENT_DIR` fallback. On any normal install the
     *   file is outside the web root entirely.
     * - **Not guessable.** The 20-character random directory component
     *   closes the hole even on an install that lands on that fallback,
     *   and two runs on the same day for the same KPI cannot collide.
     * - **Still a sensible attachment.** The human-readable filename lives
     *   *inside* the random directory. This is why it is not
     *   `wp_tempnam()`: that names the file `<stem>-<random>.tmp`, and the
     *   adapter passes the path straight to `wp_mail()`, so the recipient
     *   would receive a `.tmp` they cannot open.
     *
     * @return string Absolute path with no trailing slash, or '' on failure.
     */
    private static function stagingDir(): string {
        $dir = trailingslashit( get_temp_dir() ) . 'tt-report-' . wp_generate_password( 20, false );

        if ( ! wp_mkdir_p( $dir ) ) {
            Logger::error(
                'Scheduled report: could not create the staging directory, send skipped.',
                [ 'dir' => $dir ]
            );
            return '';
        }

        return untrailingslashit( $dir );
    }

    /**
     * Remove a staging directory and everything in it. Called from a
     * `finally`, so it must not throw; a failure is logged instead, because
     * a leftover file that nobody is told about is the actual problem.
     */
    private static function discardStagingDir( string $dir ): void {
        if ( $dir === '' || ! is_dir( $dir ) ) return;

        $leftovers = glob( $dir . '/*' );
        foreach ( is_array( $leftovers ) ? $leftovers : [] as $path ) {
            if ( ! is_file( $path ) ) continue;
            if ( ! unlink( $path ) ) {
                Logger::error(
                    'Scheduled report: could not delete the staged CSV. It is outside the web root, but remove it by hand.',
                    [ 'path' => $path ]
                );
            }
        }

        if ( ! rmdir( $dir ) ) {
            Logger::warning(
                'Scheduled report: could not remove the staging directory.',
                [ 'dir' => $dir ]
            );
        }
    }

    /**
     * The accounts in this club a PDF schedule could reach, and the entries
     * that reach nobody. A role key expands to its members with a person
     * record in this club; a plain address is the club account it belongs
     * to. The PDF names minors, so an address with no account here is left
     * out (`no_account`), and so is a member of the role from outside the
     * club (`not_in_club`), and a member in the club with no email address
     * (`no_email`, #5145). Empty and invalid entries are dropped; each
     * account is listed once.
     *
     * #4991 — public, and resolved again wherever it is asked (each run, each
     * view of the schedules list), so a role change between saving a
     * schedule and sending it is honoured. `unresolved` lists the role keys
     * that matched no account in this club with an address: a recipient
     * whose rights cannot be checked, which `TeamMonthlyReportRecipients`
     * counts as having none.
     *
     * @param string[] $entries
     * @return array{
     *     recipients: list<Recipient>,
     *     via: array<int, string>,
     *     skipped: list<array{email: string, user_id: int, reason: string, via: string}>,
     *     unresolved: list<string>
     * }
     */
    public static function resolveRecipients( array $entries ): array {
        $users      = [];
        $via        = [];
        $skipped    = [];
        $seen       = [];
        $unresolved = [];
        foreach ( $entries as $entry ) {
            $entry = trim( (string) $entry );
            if ( $entry === '' ) continue;

            // #4963 — an account by its id, as a Studio schedule names one,
            // so a changed address does not lose the recipient. Only an
            // account in this club with an address receives anything.
            $account = self::accountIdOf( $entry );
            if ( $account !== null ) {
                if ( isset( $users[ $account ] ) || isset( $seen[ $account ] ) ) continue;
                $email = $account > 0 && self::inClub( $account ) ? (string) ( ContactResolver::emailForUser( $account ) ?? '' ) : '';
                if ( $email === '' || ! is_email( $email ) ) {
                    // A named account that gets no mail reads nothing, so
                    // it does not narrow the PDF for the others: its own
                    // reason, not one whose rights are unknown.
                    $seen[ $account ] = true;
                    $skipped[]        = [ 'email' => '', 'user_id' => 0, 'reason' => self::ACCOUNT_UNREACHABLE, 'via' => '' ];
                    continue;
                }
                $users[ $account ] = self::userRecipient( $account, $email );
                $via[ $account ]   = '';
                continue;
            }

            if ( is_email( $entry ) ) {
                $user_id = self::clubAccountFor( $entry );
                if ( $user_id > 0 ) {
                    if ( ! isset( $users[ $user_id ] ) ) {
                        $users[ $user_id ] = self::userRecipient( $user_id, $entry );
                        $via[ $user_id ]   = '';
                    }
                } elseif ( ! isset( $seen[ strtolower( $entry ) ] ) ) {
                    $seen[ strtolower( $entry ) ] = true;
                    $skipped[] = [ 'email' => $entry, 'user_id' => 0, 'reason' => 'no_account', 'via' => '' ];
                }
                continue;
            }

            // Treat as a WP role key.
            $matched = false;
            foreach ( get_users( [ 'role' => $entry, 'fields' => [ 'ID' ] ] ) as $u ) {
                $user_id = (int) ( $u->ID ?? 0 );
                if ( $user_id <= 0 ) continue;
                if ( isset( $users[ $user_id ] ) ) {
                    $matched = true;
                    continue;
                }
                if ( isset( $seen[ $user_id ] ) ) continue;
                if ( ! self::inClub( $user_id ) ) {
                    $seen[ $user_id ] = true;
                    $skipped[]        = self::notInClub( $entry );
                    continue;
                }
                $email = (string) ( ContactResolver::emailForUser( $user_id ) ?? '' );
                if ( $email === '' || ! is_email( $email ) ) {
                    // #5145 — in the club, so recorded and named, not dropped.
                    $seen[ $user_id ] = true;
                    $skipped[]        = [ 'email' => '', 'user_id' => $user_id, 'reason' => self::NO_EMAIL, 'via' => $entry ];
                    continue;
                }
                $users[ $user_id ] = self::userRecipient( $user_id, $email );
                $via[ $user_id ]   = $entry;
                $matched           = true;
            }
            if ( ! $matched ) $unresolved[] = $entry;
        }
        return [ 'recipients' => array_values( $users ), 'via' => $via, 'skipped' => $skipped, 'unresolved' => $unresolved ];
    }

    /** #4963 — the prefix of a recipient entry that names an account by its id. */
    public const ACCOUNT_PREFIX = 'user:';

    /**
     * #4963 — the skip reason for a named account (`user:<id>`) that left the
     * club or has no address: it receives nothing and narrows nothing.
     */
    public const ACCOUNT_UNREACHABLE = 'account_unreachable';

    /**
     * #5145 — the skip reason for a role member with a person record here
     * and no email address: it receives nothing and narrows nothing.
     */
    public const NO_EMAIL = 'no_email';

    /** The account id an entry such as `user:12` names; null for any other entry. */
    public static function accountIdOf( string $entry ): ?int {
        return preg_match( '/^' . preg_quote( self::ACCOUNT_PREFIX, '/' ) . '(\d+)$/', $entry, $m ) === 1 ? (int) $m[1] : null;
    }

    /**
     * #4963 — one Studio schedule: the report's published version, composed
     * for the owner and every recipient together
     * (`ReportData::composeForReaders()`), rendered and sent like the monthly
     * report. A run that pauses the schedule tells the owner once.
     *
     * @param array<string,mixed> $schedule
     */
    public static function runStudio( ScheduledReportsRepository $repo, array $schedule, string $now_utc ): void {
        $report = \TT\Modules\ReportStudio\Domain\StudioScheduleDelivery::reportOf( $schedule );
        self::runPdf(
            $repo, $schedule, $now_utc,
            [ \TT\Modules\ReportStudio\Domain\StudioScheduleDelivery::class, 'render' ],
            static fn( int $user_id, array $result ): bool => $report !== null && \TT\Modules\ReportStudio\Domain\ReportAccess::canReceive( \TT\Modules\ReportStudio\Domain\ReportReader::for( $user_id ), $report ),
            true
        );
    }

    /**
     * #4874 / #4963 — tell the owner a schedule paused, and why, once per
     * breakage: only when the reason is new on the schedule.
     *
     * @param array<string,mixed> $schedule
     */
    private static function tellOwnerPaused( array $schedule, string $reason ): void {
        if ( (string) ( $schedule['last_error'] ?? '' ) === $reason ) return;
        $owner = (int) ( $schedule['created_by'] ?? 0 );
        if ( $owner <= 0 || ! self::inClub( $owner ) ) return;

        CommsDispatcher::dispatchSync(
            \TT\Modules\Comms\Templates\ScheduledReportPausedTemplate::KEY,
            [
                'schedule_name' => (string) ( $schedule['name'] ?? '' ),
                'reason'        => $reason,
                'schedule_url'  => add_query_arg( [ 'tt_view' => 'scheduled-reports' ], RecordLink::dashboardUrl() ),
            ],
            [ self::userRecipient( $owner, (string) ( ContactResolver::emailForUser( $owner ) ?? '' ) ) ],
            [
                'message_type'   => MessageType::SCHEDULED_REPORT_NOT_SENT,
                'sender_user_id' => 0,
            ]
        );
    }

    /**
     * @param array<string,mixed>                                                    $schedule
     * @param list<array{email: string, user_id: int, reason: string, via: string}> $skipped
     */
    private static function auditLog( array $schedule, int $recipient_count, bool $ok, array $skipped = [] ): void {
        if ( ! class_exists( '\\TT\\Infrastructure\\Audit\\AuditService' ) ) return;
        ( new \TT\Infrastructure\Audit\AuditService() )->record(
            'scheduled_report.run',
            'scheduled_report',
            (int) ( $schedule['id'] ?? 0 ),
            [
                'name'             => (string) ( $schedule['name'] ?? '' ),
                'report_key'       => (string) ( $schedule['report_key'] ?? ScheduledReportsRepository::REPORT_KPI ),
                'kpi_key'          => (string) ( $schedule['kpi_key'] ?? '' ),
                'recipients_count' => $recipient_count,
                'ok'               => $ok,
                'skipped'          => $skipped,
            ]
        );
    }
}
