<?php
namespace TT\Modules\Trials\Alerts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Definitions\AbstractDataQualityAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Trials\Services\TrialDecisionDeadline;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TrialDecisionDueSoonAlert (#3801, epic #2629) — a trial is about to end
 * and nobody has decided.
 *
 * Which player question does this answer? *Where are they going?* — asked
 * while there is still time to answer it. A trial window closing with no
 * decision is a child and a family waiting on an academy that has lost
 * track of them, and until now nothing in the product compared `end_date`
 * against `decision IS NULL`. The head of development found out by
 * looking, or did not.
 *
 * ## Why a definition rather than a mailer
 *
 * `TrialReminderScheduler` already nudges *panellists* to hand their input
 * in. This addresses the **decider**, and it is state-derived: it fires
 * from the condition, and it goes away when the condition does. Recording
 * the decision resolves it. Extending the case moves `end_date` out of the
 * lead time and resolves it too. There is no dismissal and no stale
 * warning to clear, which is the whole reason #2629 exists.
 *
 * ## Audience is a capability, not a relationship
 *
 * Deciding a trial is the head of development's job, and it is the one job
 * on the case nobody else can do — so the recipients are whoever holds
 * `tt_manage_trials`, which is what `AbstractDataQualityAlert` resolves.
 * That parent is named for the two records-are-incomplete definitions it
 * shipped with, but what it actually owns is the custodian audience, and
 * getting that resolution right is subtle enough (most TalentTrack caps
 * are matrix-derived and invisible to a `get_users()` meta query) that a
 * second copy of it here would be a privacy bug waiting to happen.
 *
 * Louder than its siblings on purpose: `attention` and a banner, ageing to
 * `urgent` inside the last day. A missing team assignment can wait; a
 * family waiting on an answer about their child cannot.
 *
 * ## Who is still missing rides in the payload
 *
 * The sentence names the panellists who have not submitted, because the
 * decision usually waits on exactly that and the deadline is the moment it
 * matters. Collected for the whole result set in one query — the contract
 * forbids a lookup per row, and this sweep runs hourly across every club.
 */
final class TrialDecisionDueSoonAlert extends AbstractDataQualityAlert {

    /** Most panellist names printed in the sentence before it elides. */
    private const MAX_NAMED = 3;

    public function key(): string {
        return 'trials.decision_due_soon';
    }

    public function module(): string {
        return 'trials';
    }

    public function subjectType(): string {
        return 'trial_case';
    }

    public function label(): string {
        return __( 'Trial ending without a decision', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A trial period is about to end and no decision has been recorded. The family is waiting on an answer, and the panellists who have not submitted their input are named so you know what the decision is waiting for.', 'talenttrack' );
    }

    public function capRequired(): string {
        return 'tt_manage_trials';
    }

    public function defaultSeverity(): string {
        return Severity::ATTENTION;
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ Surface::BADGE, Surface::BANNER ];
    }

    /** Inside the last day, and past it, there is no slack left. */
    protected function severityFor( object $row ): string {
        $days = TrialDecisionDeadline::daysRemaining( (string) ( $row->end_date ?? '' ) );
        return ( $days === null || $days <= 1 ) ? Severity::URGENT : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
        if ( $name === '' ) $name = __( 'A trialist', 'talenttrack' );
        $days    = TrialDecisionDeadline::daysRemaining( (string) ( $row->end_date ?? '' ) );
        $missing = $this->missingSentence( $row );

        if ( $days !== null && $days < 0 ) {
            return trim( sprintf(
                /* translators: 1: player name, 2: number of days since the trial ended */
                _n(
                    '%1$s\'s trial ended %2$d day ago with no decision recorded.',
                    '%1$s\'s trial ended %2$d days ago with no decision recorded.',
                    abs( $days ),
                    'talenttrack'
                ),
                $name,
                abs( $days )
            ) . $missing );
        }

        if ( $days === null || $days <= 0 ) {
            return trim( sprintf(
                /* translators: %s: player name */
                __( '%s\'s trial ends today and no decision has been recorded.', 'talenttrack' ),
                $name
            ) . $missing );
        }

        return trim( sprintf(
            /* translators: 1: player name, 2: number of days until the trial ends */
            _n(
                '%1$s\'s trial ends in %2$d day and no decision has been recorded.',
                '%1$s\'s trial ends in %2$d days and no decision has been recorded.',
                $days,
                'talenttrack'
            ),
            $name,
            $days
        ) . $missing );
    }

    /**
     * " Still waiting on Sanne Visser." — or nothing, when the panel is
     * complete and the decision is waiting on nobody but the reader.
     */
    private function missingSentence( object $row ): string {
        $names = $this->missingFor( $row );
        if ( $names === [] ) return '';

        $extra = count( $names ) - self::MAX_NAMED;
        if ( $extra > 0 ) {
            $names = array_slice( $names, 0, self::MAX_NAMED );
            $names[] = sprintf(
                /* translators: %d: number of further panellists not named in the sentence */
                _n( '%d other', '%d others', $extra, 'talenttrack' ),
                $extra
            );
        }

        return ' ' . sprintf(
            /* translators: %s: comma-separated list of panellist names */
            __( 'Still waiting on %s.', 'talenttrack' ),
            implode( ', ', array_map( 'strval', $names ) )
        );
    }

    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'trial-case', (int) ( $row->subject_id ?? 0 ) );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        $missing = $this->missingFor( $row );

        return [
            'player_name'   => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
            'end_date'      => (string) ( $row->end_date ?? '' ),
            'missing_count' => count( $missing ),
            'missing_names' => $missing,
        ];
    }

    /**
     * Undecided cases whose window closes inside the lead time.
     *
     * Two queries, both set-based: the cases, then the panellists who owe
     * an input across all of them. Archived and trashed cases drop out —
     * a case somebody put in the bin is not a decision anybody owes.
     *
     * @return list<object>
     */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $lead = TrialDecisionDeadline::leadDays();

        $sql = $wpdb->prepare(
            "SELECT c.id AS subject_id, c.player_id, c.end_date,
                    pl.first_name, pl.last_name
               FROM {$p}tt_trial_cases c
               JOIN {$p}tt_players pl ON pl.id = c.player_id AND pl.club_id = c.club_id
              WHERE c.club_id = %d
                AND c.decision IS NULL
                AND c.archived_at IS NULL
                AND c.trashed_at IS NULL
                AND pl.archived_at IS NULL
                AND pl.trashed_at IS NULL
                AND c.status IN ( 'open', 'extended' )
                AND c.end_date IS NOT NULL
                AND c.end_date <= DATE_ADD( CURDATE(), INTERVAL %d DAY )"
            . $context->applyScope( $this->subjectType(), 'c.id' ) . "
              ORDER BY c.end_date ASC, c.id ASC",
            CurrentClub::id(),
            $lead
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->get_results( $sql );
        if ( ! is_array( $result ) || $result === [] ) return [];

        /** @var list<object> $rows */
        $rows = array_values( array_filter( $result, 'is_object' ) );

        // Stashed on the definition rather than tacked onto each row as a
        // dynamic property: `$wpdb` hands back plain `stdClass` rows, and a
        // property invented on one is invisible to everything that reads
        // the object afterwards, static analysis included.
        $this->missing = $this->missingByCase( array_map(
            static fn( object $row ): int => (int) ( $row->subject_id ?? 0 ),
            $rows
        ) );

        return $rows;
    }

    /**
     * Panellists who owe an input, keyed by case id, for the current
     * `rows()` result set.
     *
     * @var array<int,list<string>>
     */
    private array $missing = [];

    /**
     * The names this row's sentence should list.
     *
     * @return list<string>
     */
    private function missingFor( object $row ): array {
        return $this->missing[ (int) ( $row->subject_id ?? 0 ) ] ?? [];
    }

    /**
     * Panellists assigned to each case who have not submitted, keyed by
     * case id, in one query plus one `get_users()` call.
     *
     * @param list<int> $case_ids
     * @return array<int,list<string>>
     */
    private function missingByCase( array $case_ids ): array {
        $ids = array_values( array_unique( array_filter( $case_ids, static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $p = $wpdb->prefix;

        // Every id is an int by construction, so the list is safe to inline.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.case_id, s.user_id
               FROM {$p}tt_trial_case_staff s
              WHERE s.case_id IN (" . implode( ',', $ids ) . ")
                AND s.club_id = %d
                AND s.unassigned_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_trial_case_staff_inputs i
                     WHERE i.case_id = s.case_id
                       AND i.user_id = s.user_id
                       AND i.club_id = s.club_id
                       AND i.submitted_at IS NOT NULL
                )",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) || $rows === [] ) return [];
        $rows = array_values( array_filter( $rows, 'is_object' ) );

        $user_ids = array_values( array_unique( array_map(
            static fn( object $row ): int => (int) ( $row->user_id ?? 0 ),
            $rows
        ) ) );

        $names = [];
        foreach ( get_users( [ 'include' => $user_ids, 'fields' => [ 'ID', 'display_name' ] ] ) as $user ) {
            $u = (array) $user;
            $names[ (int) ( $u['ID'] ?? 0 ) ] = (string) ( $u['display_name'] ?? '' );
        }

        $out = [];
        foreach ( $rows as $row ) {
            $case = (int) ( $row->case_id ?? 0 );
            $uid  = (int) ( $row->user_id ?? 0 );
            $name = $names[ $uid ] ?? '';
            if ( $name === '' ) continue;
            $out[ $case ][] = $name;
        }
        return $out;
    }
}
