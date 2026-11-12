<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Analytics\Reports\ScheduledReportReaders;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportSchedules (#4963, epic #4564) — scheduling a published Report Studio
 * report: which reports can be, who may schedule one for whom, what a
 * schedule's fields hold, and what its recipients would get.
 *
 * A Studio schedule is a row in `tt_scheduled_reports` with `report_key =
 * studio` and `composition = { report_id, period }`. It points at the report
 * and is read at send time, so it always sends the published version.
 *
 * Every rule is here, out of the REST controller and the form, so both give
 * the same answer (CLAUDE.md §4).
 *
 * - **Which reports.** A report, not a dashboard, in rows, for staff, with a
 *   subject it stores: a fixed player or team, a group, or the academy. One
 *   PDF for many readers cannot follow each viewer or let each choose.
 * - **Who schedules.** Someone who may change the report. A schedule to
 *   anyone but yourself needs `tt_publish_reports` (epic decision 2).
 * - **Recipients.** Accounts (`user:<id>`) and staff roles only, never a bare
 *   address (the owner's answer on #4963): every recipient's rights are
 *   checked, every run. At most `MAX_RECIPIENTS` entries.
 * - **Period.** A report with a fixed or rolling period sends that period;
 *   one whose period is chosen when opened gets a rolling period on the
 *   schedule, from `ReportFilters::PERIODS`.
 *
 * @phpstan-import-type Report from ReportsRepository
 * @phpstan-type Found array{schedule: array<string,mixed>, report: Report}
 * @phpstan-type Preview array{blocks: list<array{id:string, label:string}>, left_out: list<array{id:string, label:string, reason:string}>, receivers: int, left_out_recipients: int, left_out_people: list<array{reason:string, count:int, message:string, names:list<string>}>, message: string}
 */
final class ReportSchedules {

    /** The most recipient entries one schedule holds. */
    public const MAX_RECIPIENTS = 25;

    /**
     * #5145 — the `left_out_people` group of the chosen roles nobody holds:
     * its `count` is roles, its `names` are the roles' names.
     */
    public const NO_MEMBERS = 'no_members';

    /** The most readers a run composes one PDF for, the owner included. */
    public const MAX_READERS = 40;

    /** The frequencies a schedule runs on. */
    public const FREQUENCIES = [
        ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY,
        ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
        ScheduledReportsRepository::FREQUENCY_SEASON_END,
    ];

    /** The period a schedule takes when the report leaves it open. */
    public const DEFAULT_PERIOD = 'last_month';

    /**
     * Both plan features a Studio schedule needs: the Studio, and scheduled
     * reports. Asked by every caller, the REST routes and the form alike.
     */
    public static function planAllows(): bool {
        return \TT\Modules\License\LicenseGate::allows( 'report_studio' ) && \TT\Modules\License\LicenseGate::allows( 'scheduled_reports' );
    }

    private static function planRefusal(): StudioError {
        return new StudioError( 'license_required', __( 'Scheduled reports are not part of your plan.', 'talenttrack' ), 402 );
    }

    /**
     * Why this report cannot be scheduled; null when it can.
     *
     * @param Report $report
     */
    public static function refusal( array $report ): ?string {
        if ( $report['kind'] !== ReportStartRules::KIND_REPORT ) return __( 'A scheduled mail sends a report, not a dashboard.', 'talenttrack' );
        if ( $report['layout'] !== ReportStartRules::LAYOUT_ROWS ) return __( 'A scheduled mail is laid out in rows.', 'talenttrack' );
        if ( $report['audience'] !== PlayerReportAudience::INTERNAL ) return __( 'A scheduled mail is for staff. A report for a family or a scout is shared through a snapshot.', 'talenttrack' );
        if ( $report['binding'] === ReportStartRules::BINDING_VIEWER ) return __( 'A scheduled mail is one copy for everyone, so it cannot follow whoever opens it.', 'talenttrack' );
        if ( in_array( $report['scope'], [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ], true ) && $report['binding'] !== ReportStartRules::BINDING_FIXED ) {
            return __( 'A scheduled mail needs a report that always shows the same player or team.', 'talenttrack' );
        }
        if ( $report['archived_at'] !== null ) return __( 'This report is archived.', 'talenttrack' );
        if ( $report['published_version_id'] === null ) return __( 'Publish the report before you schedule it: a schedule sends the published version.', 'talenttrack' );
        return null;
    }

    /**
     * May this reader schedule this report at all: someone who may change it.
     *
     * @param Report $report
     */
    public static function canSchedule( ReportReader $reader, array $report ): bool {
        return ReportAccess::canEdit( $reader, $report );
    }

    /**
     * May this reader see or change this schedule: its owner, or someone who
     * publishes reports, and in both cases someone who may change the report.
     *
     * @param array<string,mixed> $schedule
     * @param Report              $report
     */
    public static function canManage( ReportReader $reader, array $schedule, array $report ): bool {
        if ( ! self::canSchedule( $reader, $report ) ) return false;
        return (int) ( $schedule['created_by'] ?? 0 ) === $reader->userId || $reader->publishesReports();
    }

    /**
     * A schedule's fields from a request, checked. For an update
     * (`$update`), a field left out keeps its value: only the fields given
     * are returned.
     *
     * @param array<string,mixed> $in
     * @param Report              $report
     * @return array<string,mixed>|StudioError
     */
    public static function fields( array $in, array $report, ReportReader $reader, bool $update = false ): array|StudioError {
        $out = [];

        if ( array_key_exists( 'name', $in ) || ! $update ) {
            $name = trim( sanitize_text_field( is_scalar( $in['name'] ?? null ) ? (string) $in['name'] : '' ) );
            if ( $name === '' ) $name = $report['name'] !== '' ? $report['name'] : __( 'Scheduled report', 'talenttrack' );
            if ( mb_strlen( $name ) > ReportStartRules::MAX_NAME ) {
                /* translators: %d: maximum number of characters */
                return self::invalid( 'name', sprintf( __( 'A name is at most %d characters.', 'talenttrack' ), ReportStartRules::MAX_NAME ) );
            }
            $out['name'] = $name;
        }

        if ( array_key_exists( 'frequency', $in ) || ! $update ) {
            $raw       = $in['frequency'] ?? ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST;
            $frequency = is_string( $raw ) ? sanitize_key( $raw ) : '';
            if ( ! in_array( $frequency, self::FREQUENCIES, true ) ) {
                return self::invalid( 'frequency', __( 'Choose weekly, monthly or at the end of the season.', 'talenttrack' ) );
            }
            $out['frequency'] = $frequency;
        }

        if ( array_key_exists( 'recipients', $in ) || ! $update ) {
            $recipients = self::recipients( $in['recipients'] ?? null, $reader );
            if ( $recipients instanceof StudioError ) return $recipients;
            // A report kept for its owner is theirs alone (`canEdit()`): it
            // goes to nobody else on a schedule either.
            if ( $report['sharing'] === ReportStartRules::SHARING_ME && $recipients !== [ ScheduledReportsRunner::ACCOUNT_PREFIX . $report['owner_user_id'] ] ) {
                return new StudioError( 'invalid_field', self::keptForOwner(), 400, [ 'field' => 'recipients', 'reason' => 'kept_for_owner' ] );
            }
            $out['recipients'] = $recipients;
        }

        if ( array_key_exists( 'period', $in ) || ! $update ) {
            $period = self::period( $in['period'] ?? null, $report );
            if ( $period instanceof StudioError ) return $period;
            $out['composition'] = [ 'report_id' => $report['id'], 'period' => $period ];
        }

        if ( array_key_exists( 'status', $in ) && $update ) {
            $status = is_string( $in['status'] ) ? sanitize_key( $in['status'] ) : '';
            if ( ! in_array( $status, [ ScheduledReportsRepository::STATUS_ACTIVE, ScheduledReportsRepository::STATUS_PAUSED ], true ) ) {
                return self::invalid( 'status', __( 'A schedule is active or paused.', 'talenttrack' ) );
            }
            $out['status'] = $status;
        }
        return $out;
    }

    /**
     * Recipient entries, checked: an account in this club (`user:<id>`) or a
     * staff role key, nothing else. Anyone but the caller needs the
     * publishing right.
     *
     * @return list<string>|StudioError
     */
    public static function recipients( mixed $raw, ReportReader $reader ): array|StudioError {
        if ( ! is_array( $raw ) ) {
            return self::invalid( 'recipients', __( 'Choose who receives the report.', 'talenttrack' ) );
        }
        // The publishing right first, so a caller who may only schedule for
        // themselves learns nothing about which ids are accounts here.
        $self = ScheduledReportsRunner::ACCOUNT_PREFIX . $reader->userId;
        if ( ! $reader->publishesReports() ) {
            foreach ( $raw as $value ) {
                $entry = trim( is_scalar( $value ) ? (string) $value : '' );
                if ( $entry !== '' && $entry !== $self ) {
                    return new StudioError( 'forbidden', __( 'You may schedule this report for yourself. Sending it to others needs the right to publish reports.', 'talenttrack' ), 403, [ 'field' => 'recipients' ] );
                }
            }
        }
        $roles = self::roleOptions();
        $out   = [];
        foreach ( $raw as $value ) {
            $entry = trim( is_scalar( $value ) ? (string) $value : '' );
            if ( $entry === '' ) continue;
            $account = ScheduledReportsRunner::accountIdOf( $entry );
            if ( $account !== null ) {
                if ( $account <= 0 || get_userdata( $account ) === false || QueryHelpers::person_id_for_user( $account ) <= 0 ) {
                    return self::invalid( 'recipients', __( 'A recipient is an account in this club or a staff role.', 'talenttrack' ) );
                }
            } elseif ( ! isset( $roles[ $entry ] ) ) {
                return self::invalid( 'recipients', __( 'Recipients are accounts and staff roles only. Someone without an account needs one first.', 'talenttrack' ) );
            }
            $out[ $entry ] = $entry;
        }
        if ( $out === [] ) {
            return self::invalid( 'recipients', __( 'Choose who receives the report.', 'talenttrack' ) );
        }
        if ( count( $out ) > self::MAX_RECIPIENTS ) {
            /* translators: %d: the most recipients a schedule holds */
            return self::invalid( 'recipients', sprintf( __( 'A schedule goes to at most %d recipients. Choose a role instead of many people.', 'talenttrack' ), self::MAX_RECIPIENTS ) );
        }
        return array_values( $out );
    }

    /**
     * The period a schedule stores: empty when the report has its own, a
     * rolling period when the report leaves it open.
     *
     * @param Report $report
     */
    public static function period( mixed $raw, array $report ): string|StudioError {
        if ( $report['period_mode'] !== ReportStartRules::PERIOD_ON_OPEN ) return '';
        $value = is_string( $raw ) && $raw !== '' ? sanitize_key( $raw ) : self::DEFAULT_PERIOD;
        if ( ! in_array( $value, ReportFilters::PERIODS, true ) ) {
            return self::invalid( 'period', __( 'Choose last week, last month, this month or this season.', 'talenttrack' ) );
        }
        return $value;
    }

    /**
     * The window a run sends: the report's own period, or the schedule's
     * rolling one.
     *
     * @param Report              $report
     * @param array<string,mixed> $composition
     * @return array{from:string, to:string}|null null when the window cannot be worked out
     */
    public static function window( array $report, array $composition, string $today ): ?array {
        if ( $report['period_mode'] === ReportStartRules::PERIOD_FIXED ) {
            $parts = explode( '/', $report['period_value'] );
            return [ 'from' => $parts[0], 'to' => $parts[1] ?? $parts[0] ];
        }
        $period = $report['period_mode'] === ReportStartRules::PERIOD_ROLLING
            ? $report['period_value']
            : ( is_string( $composition['period'] ?? null ) ? (string) $composition['period'] : '' );
        if ( $period === '' ) $period = self::DEFAULT_PERIOD;
        $window = ReportFilters::periodWindow( $period, $today );
        return $window !== null ? [ 'from' => (string) $window['from'], 'to' => (string) $window['to'] ] : null;
    }

    /**
     * Everyone the PDF is composed for, the owner first: the owner and the
     * accounts that receive it. See `resolve()`.
     *
     * @param list<string> $entries
     * @param Report       $report
     * @return list<int>
     */
    public static function readers( array $entries, int $owner, array $report ): array {
        return self::resolve( ScheduledReportsRunner::resolveRecipients( $entries ), $owner, $report )['readers'];
    }

    /**
     * The receivers and the readers of one resolved recipient list, with the
     * receive rule asked once per account, every source it rests on read
     * once for the set (`ReportAccess::receiversAmong()`). Call it inside a
     * `ScopeMemo` window to keep those reads for the composition that follows.
     *
     * #5145 — the readers are the owner and the receivers, nobody else. A
     * Studio PDF goes only to an account `ReportAccess::canReceive()`
     * allows, and each of those is a reader, so the cut covers everyone it
     * reaches. A recipient that receives nothing — a role nobody holds, a
     * role member with no person record here, an address with no account —
     * reads nothing, so it does not narrow the PDF for the others. (The
     * monthly and player reports keep `ScheduledReportReaders::readers()`,
     * which counts such a recipient as a reader without rights.)
     *
     * @param array{recipients: list<\TT\Modules\Comms\Domain\Recipient>, via: array<int, string>, skipped: list<array{email: string, user_id: int, reason: string, via: string}>, unresolved: list<string>} $resolved
     * @param Report $report
     * @return array{receivers: list<int>, readers: list<int>, skipped: list<array{email: string, user_id: int, reason: string, via: string}>}
     */
    public static function resolve( array $resolved, int $owner, array $report ): array {
        $ids = [];
        foreach ( $resolved['recipients'] as $recipient ) $ids[] = (int) $recipient->userId;
        // The file right is asked per account: one read for all of them.
        cache_users( array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
        $allowed   = array_fill_keys( ReportAccess::receiversAmong( $ids, $report ), true );
        $receives  = static fn( int $user_id ): bool => isset( $allowed[ $user_id ] );
        // #5135 — one decision for who receives it and who is left out.
        $sorted    = ScheduledReportReaders::sort( $resolved, $receives );
        $receivers = ScheduledReportReaders::receiverIds( $sorted['allowed'] );
        return [
            'receivers' => $receivers,
            'readers'   => array_values( array_unique( array_merge( [ $owner ], $receivers ) ) ),
            'skipped'   => $sorted['skipped'],
        ];
    }

    /**
     * #5135 — who of the recipients does not receive the PDF, grouped by why:
     * the run's own skip list (`ScheduledReportReaders::sort()`), so the
     * preview shows exactly who the run will leave out. An account is named
     * by the academy's record of the person, as the form's people list is
     * (`AuthorNameResolver::recordNamesFor()`), never by its `display_name`,
     * which can be a login or an email address. An account with no record
     * here, such as a role member from outside the academy, is counted,
     * never named.
     *
     * @param list<array{email: string, user_id: int, reason: string, via: string}> $skipped
     * @return list<array{reason:string, count:int, message:string, names:list<string>}>
     */
    public static function leftOutGroups( array $skipped ): array {
        $groups = [];
        $names  = \TT\Infrastructure\Identity\AuthorNameResolver::recordNamesFor( array_column( $skipped, 'user_id' ) );
        foreach ( $skipped as $skip ) {
            $reason = $skip['reason'];
            if ( ! isset( $groups[ $reason ] ) ) $groups[ $reason ] = [ 'reason' => $reason, 'count' => 0, 'message' => '', 'names' => [] ];
            $groups[ $reason ]['count']++;
            if ( isset( $names[ $skip['user_id'] ] ) ) $groups[ $reason ]['names'][] = $names[ $skip['user_id'] ];
        }
        foreach ( $groups as $reason => $group ) {
            $groups[ $reason ]['message'] = self::leftOutMessage( $reason, $group['count'] );
            sort( $groups[ $reason ]['names'] );
        }
        return array_values( $groups );
    }

    /**
     * #5145 — the names of the chosen roles no account holds at all. A role
     * whose members are all left out for a reason of their own is not one:
     * those members are counted under that reason.
     *
     * @param list<string> $unresolved role keys that reached no account
     * @return list<string>
     */
    private static function emptyRoles( array $unresolved ): array {
        if ( $unresolved === [] ) return [];
        $labels = self::roleOptions();
        $out    = [];
        foreach ( $unresolved as $role ) {
            if ( get_users( [ 'role' => $role, 'fields' => 'ID', 'number' => 1 ] ) !== [] ) continue;
            $out[] = $labels[ $role ] ?? $role;
        }
        sort( $out );
        return $out;
    }

    private static function leftOutMessage( string $reason, int $n ): string {
        switch ( $reason ) {
            case 'no_read_access':
                /* translators: %d: number of people */
                return sprintf( _n( '%d person does not receive it: they may not read this report or what it is about.', '%d people do not receive it: they may not read this report or what it is about.', $n, 'talenttrack' ), $n );
            case 'no_file_access':
                /* translators: %d: number of people */
                return sprintf( _n( '%d person does not receive it: they may not receive files.', '%d people do not receive it: they may not receive files.', $n, 'talenttrack' ), $n );
            case 'not_in_club':
                // #5145 — what is checked is a person record in this academy;
                // an account of the academy can lack one, so say that.
                /* translators: %d: number of people */
                return sprintf( _n( '%d person in the role does not receive it: they have no person record in this academy.', '%d people in the role do not receive it: they have no person record in this academy.', $n, 'talenttrack' ), $n );
            case ScheduledReportsRunner::NO_EMAIL:
                /* translators: %d: number of people */
                return sprintf( _n( '%d person does not receive it: they have no email address.', '%d people do not receive it: they have no email address.', $n, 'talenttrack' ), $n );
            case self::NO_MEMBERS:
                return $n === 1 ? __( 'No one holds this role:', 'talenttrack' ) : __( 'No one holds these roles:', 'talenttrack' );
            case ScheduledReportsRunner::ACCOUNT_UNREACHABLE:
                /* translators: %d: number of people */
                return sprintf( _n( '%d person does not receive it: their account has left the academy or has no email address.', '%d people do not receive it: their accounts have left the academy or have no email address.', $n, 'talenttrack' ), $n );
        }
        /* translators: %d: number of people */
        return sprintf( _n( '%d person does not receive it.', '%d people do not receive it.', $n, 'talenttrack' ), $n );
    }

    /**
     * #4963 — composed for these readers, inside one `ScopeMemo` window (the
     * readers' scopes read once for the set, the subject check once per
     * reader), and quietly: a preview is not a read of anyone's injuries,
     * and a run records its reads per recipient after it sends.
     *
     * @param Report                        $report
     * @param array{from:string, to:string} $window
     * @param list<int>                     $readers
     * @return array<string,mixed>|StudioError
     */
    public static function compose( array $report, array $window, array $readers ): array|StudioError {
        return \TT\Infrastructure\Security\ScopeMemo::run( static function () use ( $report, $window, $readers ) {
            \TT\Infrastructure\Security\AuthorizationService::primeUsers( $readers );
            return \TT\Infrastructure\Journey\InjuryReads::quietly( static fn() => ReportData::composeForReaders( $report, $window, ReaderSet::of( $readers ) ) );
        } );
    }

    /**
     * What these recipients would get, before Save and on the list: the
     * blocks in the PDF, the blocks left out and why, and how many accounts
     * receive it. Composed by the same service the run uses.
     *
     * @param Report              $report
     * @param list<string>        $entries
     * @param array<string,mixed> $composition
     * @return Preview
     */
    public static function preview( array $report, array $entries, int $owner, array $composition ): array {
        $out = [ 'blocks' => [], 'left_out' => [], 'receivers' => 0, 'left_out_recipients' => 0, 'left_out_people' => [], 'message' => '' ];

        $resolved = ScheduledReportsRunner::resolveRecipients( $entries );
        $set      = self::resolve( $resolved, $owner, $report );
        $receivers = $set['receivers'];
        $out['receivers']           = count( $receivers );
        $out['left_out_recipients'] = count( $set['skipped'] );
        $out['left_out_people']     = self::leftOutGroups( $set['skipped'] );
        $empty                      = self::emptyRoles( $resolved['unresolved'] );
        if ( $empty !== [] ) {
            $out['left_out_people'][] = [ 'reason' => self::NO_MEMBERS, 'count' => count( $empty ), 'message' => self::leftOutMessage( self::NO_MEMBERS, count( $empty ) ), 'names' => $empty ];
        }

        $readers = $set['readers'];
        if ( count( $readers ) > self::MAX_READERS ) {
            $out['message'] = self::tooManyReaders();
            return $out;
        }
        $window = self::window( $report, $composition, (string) current_time( 'Y-m-d' ) );
        if ( $window === null ) {
            $out['message'] = ReportFilters::noPeriodMessage();
            return $out;
        }

        $doc = self::compose( $report, $window, $readers );
        if ( $doc instanceof StudioError ) {
            $out['message'] = $doc->message;
            return $out;
        }
        foreach ( (array) $doc['blocks'] as $block ) {
            $key             = is_array( $block ) ? (string) ( $block['key'] ?? '' ) : '';
            $found           = BlockRegistry::find( $key );
            $out['blocks'][] = [ 'id' => is_array( $block ) ? (string) ( $block['id'] ?? '' ) : '', 'label' => $found !== null ? $found->label() : $key ];
        }
        foreach ( (array) $doc['left_out'] as $left ) {
            if ( ! is_array( $left ) ) continue;
            $out['left_out'][] = [ 'id' => (string) ( $left['id'] ?? '' ), 'label' => (string) ( $left['label'] ?? '' ), 'reason' => (string) ( $left['reason'] ?? '' ) ];
        }
        return $out;
    }

    public static function tooManyReaders(): string {
        /* translators: %d: the most readers one scheduled PDF is composed for */
        return sprintf( __( 'This schedule reaches more than %d people. Choose fewer recipients, or a smaller role.', 'talenttrack' ), self::MAX_READERS );
    }

    public static function keptForOwner(): string {
        return __( 'This report is kept for its owner alone, so only they can receive it on a schedule.', 'talenttrack' );
    }

    /**
     * The roles a schedule can go to, by key, with their names: the
     * plugin's roles whose members read reports (`tt_view_reports`), decided
     * by capability, never by role name. A player's and a family's roles do
     * not hold it, so they are never offered; members of an offered role who
     * may not receive the report are still left out at every run.
     *
     * @return array<string,string>
     */
    public static function roleOptions(): array {
        $out = [];
        foreach ( wp_roles()->roles as $key => $role ) {
            $key  = (string) $key;
            $caps = is_array( $role['capabilities'] ?? null ) ? $role['capabilities'] : [];
            if ( strpos( $key, 'tt_' ) !== 0 || empty( $caps['tt_view_reports'] ) ) continue;
            $out[ $key ] = translate_user_role( is_string( $role['name'] ?? null ) ? $role['name'] : $key );
        }
        asort( $out );
        return $out;
    }

    /**
     * The accounts in this club that may read the report, by entry
     * (`user:<id>`), with their names: who a schedule can be sent to by name.
     *
     * @param Report $report
     * @return array<string,string>
     */
    public static function accountOptions( array $report ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT wp_user_id, first_name, last_name FROM {$wpdb->prefix}tt_people
              WHERE club_id = %d AND wp_user_id > 0 AND status = 'active' AND archived_at IS NULL AND trashed_at IS NULL
              ORDER BY last_name ASC, first_name ASC LIMIT 300",
            CurrentClub::id()
        ), ARRAY_A );
        $names = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $uid = (int) ( $row['wp_user_id'] ?? 0 );
            if ( $uid <= 0 || isset( $names[ $uid ] ) ) continue;
            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            $names[ $uid ] = $name !== '' ? $name : '#' . $uid;
        }
        // The receive rule for all of them at once.
        $out = [];
        foreach ( ReportAccess::receiversAmong( array_keys( $names ), $report ) as $uid ) {
            $out[ ScheduledReportsRunner::ACCOUNT_PREFIX . $uid ] = $names[ $uid ];
        }
        return $out;
    }

    /**
     * A Studio schedule of this club and its report, when this reader may
     * manage it.
     *
     * @return Found|StudioError
     */
    public static function findForReader( int $schedule_id, ReportReader $reader ): array|StudioError {
        $schedule = ( new ScheduledReportsRepository() )->findById( $schedule_id );
        if ( $schedule === null || $schedule['report_key'] !== ScheduledReportsRepository::REPORT_STUDIO || $schedule['status'] === ScheduledReportsRepository::STATUS_ARCHIVED ) {
            return new StudioError( 'not_found', __( 'There is no such schedule.', 'talenttrack' ), 404 );
        }
        // A schedule this reader may not manage answers as one that does not
        // exist, so an id cannot be walked to learn what the academy sends.
        $report = StudioScheduleDelivery::reportOf( $schedule );
        if ( $report === null || ! self::canManage( $reader, $schedule, $report ) ) {
            return new StudioError( 'not_found', __( 'There is no such schedule.', 'talenttrack' ), 404 );
        }
        return [ 'schedule' => $schedule, 'report' => $report ];
    }

    /**
     * The schedules of this report this reader may manage.
     *
     * @param Report $report
     * @return list<array<string,mixed>>
     */
    public static function listFor( array $report, ReportReader $reader ): array {
        $out = [];
        foreach ( ( new ScheduledReportsRepository() )->forStudioReport( $report['id'] ) as $schedule ) {
            if ( self::canManage( $reader, $schedule, $report ) ) $out[] = $schedule;
        }
        return $out;
    }

    /**
     * A new schedule for this report.
     *
     * @param Report              $report
     * @param array<string,mixed> $in
     */
    public static function create( array $report, array $in, ReportReader $reader ): int|StudioError {
        if ( ! self::planAllows() ) return self::planRefusal();
        if ( ! self::canSchedule( $reader, $report ) ) {
            return new StudioError( 'forbidden', __( 'You may not change this report.', 'talenttrack' ), 403 );
        }
        $refusal = self::refusal( $report );
        if ( $refusal !== null ) return new StudioError( 'not_schedulable', $refusal, 400, [ 'field' => 'report_id' ] );

        $fields = self::fields( $in, $report, $reader );
        if ( $fields instanceof StudioError ) return $fields;

        $id = ( new ScheduledReportsRepository() )->create( [
            'name'        => (string) $fields['name'],
            'report_key'  => ScheduledReportsRepository::REPORT_STUDIO,
            'frequency'   => (string) $fields['frequency'],
            'recipients'  => array_map( 'strval', (array) $fields['recipients'] ),
            'format'      => 'pdf',
            'composition' => (array) $fields['composition'],
        ], $reader->userId );
        if ( $id <= 0 ) return new StudioError( 'db_error', __( 'The schedule could not be saved.', 'talenttrack' ), 500 );
        self::audit( 'scheduled_report.created', $id, [], self::auditView( $fields ) );
        return $id;
    }

    /**
     * Change a schedule in place: only the fields given. It takes effect
     * from the next run. Recorded in the audit log with before and after.
     *
     * @param Found               $found
     * @param array<string,mixed> $in
     */
    public static function update( array $found, array $in, ReportReader $reader ): bool|StudioError {
        if ( ! self::planAllows() ) return self::planRefusal();
        $fields = self::fields( $in, $found['report'], $reader, true );
        if ( $fields instanceof StudioError ) return $fields;
        if ( $fields === [] ) return true;

        $before = [];
        foreach ( array_keys( $fields ) as $key ) $before[ $key ] = $found['schedule'][ $key ] ?? null;
        $id   = (int) $found['schedule']['id'];
        $repo = new ScheduledReportsRepository();
        if ( ! $repo->update( $id, $fields ) ) {
            return new StudioError( 'db_error', __( 'The schedule could not be saved.', 'talenttrack' ), 500 );
        }
        // A resumed schedule is told again when it breaks again.
        if ( ( $fields['status'] ?? '' ) === ScheduledReportsRepository::STATUS_ACTIVE ) $repo->clearError( $id );
        self::audit( 'scheduled_report.updated', $id, self::auditView( $before ), self::auditView( $fields ) );
        return true;
    }

    /**
     * Stop a schedule for good: it is archived and never runs again.
     *
     * @param Found $found
     */
    public static function archive( array $found ): bool|StudioError {
        $id = (int) $found['schedule']['id'];
        if ( ! ( new ScheduledReportsRepository() )->setStatus( $id, ScheduledReportsRepository::STATUS_ARCHIVED ) ) {
            return new StudioError( 'db_error', __( 'The schedule could not be saved.', 'talenttrack' ), 500 );
        }
        self::audit( 'scheduled_report.archived', $id, [ 'status' => $found['schedule']['status'] ?? '' ], [ 'status' => ScheduledReportsRepository::STATUS_ARCHIVED ] );
        return true;
    }

    /**
     * The schedule as the REST routes return it: its fields and, when asked,
     * what its recipients get now. Recipients as their entries.
     *
     * @param array<string,mixed> $schedule
     * @param Report              $report
     * @return array<string,mixed>
     */
    public static function present( array $schedule, array $report, bool $with_preview ): array {
        $composition = is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [];
        $recipients  = array_values( array_map( 'strval', (array) ( $schedule['recipients'] ?? [] ) ) );
        $out = [
            'id'          => (int) ( $schedule['id'] ?? 0 ),
            'uuid'        => (string) ( $schedule['uuid'] ?? '' ),
            'report_id'   => $report['id'],
            'name'        => (string) ( $schedule['name'] ?? '' ),
            'frequency'   => (string) ( $schedule['frequency'] ?? '' ),
            'period'      => is_string( $composition['period'] ?? null ) ? (string) $composition['period'] : '',
            'recipients'  => $recipients,
            'format'      => 'pdf',
            'status'      => (string) ( $schedule['status'] ?? '' ),
            'last_error'  => $schedule['last_error'] ?? null,
            'last_run_at' => $schedule['last_run_at'] ?? null,
            'next_run_at' => (string) ( $schedule['next_run_at'] ?? '' ),
            'owner_id'    => (int) ( $schedule['created_by'] ?? 0 ),
        ];
        if ( $with_preview ) {
            $out['preview'] = self::preview( $report, $recipients, (int) ( $schedule['created_by'] ?? 0 ), $composition );
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function auditView( array $fields ): array {
        $out = [];
        foreach ( $fields as $key => $value ) {
            if ( $key === 'composition' ) {
                $out['period'] = is_array( $value ) && is_string( $value['period'] ?? null ) ? (string) $value['period'] : '';
                continue;
            }
            $out[ (string) $key ] = $value;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function audit( string $action, int $id, array $before, array $after ): void {
        ( new \TT\Infrastructure\Audit\AuditService() )->record( $action, 'scheduled_report', $id, [
            'report_key' => ScheduledReportsRepository::REPORT_STUDIO,
            'before'     => $before,
            'after'      => $after,
        ] );
    }

    private static function invalid( string $field, string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => $field ] );
    }
}
