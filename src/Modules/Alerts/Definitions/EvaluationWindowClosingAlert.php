<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Analytics\EvalWindowsRepository;

/**
 * EvaluationWindowClosingAlert (#2636, epic #2629) — an evaluation window
 * is about to close with players still uncovered.
 *
 * Which player question does this answer? *Where is this player now,
 * according to the review the academy promised to do?* An evaluation window
 * is the academy's own commitment that every player gets looked at in this
 * period. The Evaluation coverage report (#1380) already shows the gaps —
 * but only to whoever thinks to open it, and only while there is still time
 * if they happen to open it early enough. This turns that report from
 * something you consult into something that reaches you.
 *
 * The lead time is `alerts_eval_window_closing_days` in `tt_config`,
 * defaulting to three days. An academy running fortnightly windows wants a
 * shorter warning than one running six-week blocks.
 *
 * ## Only ever one window at a time
 *
 * Where two configured windows close inside the same lead time, the one
 * ending soonest wins and the other is ignored until it becomes the
 * soonest. That is not a shortcut: the occurrence's identity is
 * alert + subject + recipient, so a player uncovered in two closing windows
 * would collapse into one row anyway, and the row would then flip between
 * two titles from sweep to sweep. One window, one deadline, one message.
 */
final class EvaluationWindowClosingAlert extends AbstractPlayerAlert {

    /** tt_config key holding the lead time, in days. */
    public const CONFIG_KEY_LEAD_DAYS = 'alerts_eval_window_closing_days';

    private const DEFAULT_LEAD_DAYS = 3;

    /** @var EvalWindowsRepository */
    private $windows;

    public function __construct( ?EvalWindowsRepository $windows = null ) {
        $this->windows = $windows ?? new EvalWindowsRepository();
    }

    public function key(): string {
        return 'evaluations.window_closing';
    }

    public function module(): string {
        return 'evaluations';
    }

    public function label(): string {
        return __( 'Evaluation window closing', 'talenttrack' );
    }

    public function description(): string {
        return __( 'An evaluation window is about to close and some players in your team have not been evaluated in it. After it closes the gap is permanent — that period simply has no record for them.', 'talenttrack' );
    }

    public function capRequired(): string {
        return 'tt_evaluate_players';
    }

    /** Inside the last day there is no longer time to arrange anything. */
    protected function severityFor( object $row ): string {
        return $this->daysUntil( (string) ( $row->window_end ?? '' ) ) <= 1
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name   = $this->playerName( $row );
        $window = trim( (string) ( $row->window_name ?? '' ) );
        $days   = $this->daysUntil( (string) ( $row->window_end ?? '' ) );

        if ( $days <= 0 ) {
            return sprintf(
                /* translators: 1: player name, 2: evaluation window name */
                __( '%1$s has no evaluation in %2$s, which closes today.', 'talenttrack' ),
                $name,
                $window
            );
        }

        return sprintf(
            /* translators: 1: player name, 2: evaluation window name, 3: number of days until it closes */
            _n(
                '%1$s has no evaluation in %2$s, which closes in %3$d day.',
                '%1$s has no evaluation in %2$s, which closes in %3$d days.',
                $days,
                'talenttrack'
            ),
            $name,
            $window,
            $days
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'window_name'  => (string) ( $row->window_name ?? '' ),
            'window_start' => (string) ( $row->window_start ?? '' ),
            'window_end'   => (string) ( $row->window_end ?? '' ),
        ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        $window = $this->closingWindow();
        if ( $window === null ) return [];

        global $wpdb;
        $p = $wpdb->prefix;

        // NOT EXISTS rather than a LEFT JOIN + COUNT: it stops at the first
        // in-window evaluation for a player instead of aggregating all of
        // them, and it keeps the row count exactly one per uncovered player.
        //
        // `club_id IS NULL` is tolerated on the evaluations side because
        // rows predating the #0052 tenancy backfill can still carry it, and
        // treating those as another tenant's would report an evaluated
        // player as a gap.
        $sql = $wpdb->prepare(
            "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id
               FROM {$p}tt_players p
              WHERE " . $this->activePlayerWhere( 'p' ) . "
                AND p.team_id > 0
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_evaluations e
                     WHERE e.player_id = p.id
                       AND e.archived_at IS NULL
                       AND e.trashed_at IS NULL
                       AND ( e.club_id = %d OR e.club_id IS NULL )
                       AND e.eval_date BETWEEN %s AND %s
                )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
              ORDER BY p.id ASC",
            CurrentClub::id(),
            $window['start'],
            $window['end']
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            $row->window_name  = $window['name'];
            $row->window_start = $window['start'];
            $row->window_end   = $window['end'];
        }
        return $rows;
    }

    /**
     * The configured window closing soonest inside the lead time, or null.
     *
     * A window that has already closed is not "closing" — nothing can be
     * done about it any more, and an alert nobody can act on is noise. It
     * drops out here rather than ageing into a permanent nag.
     *
     * @return array{name:string,start:string,end:string}|null
     */
    private function closingWindow(): ?array {
        $lead     = $this->threshold( self::CONFIG_KEY_LEAD_DAYS, self::DEFAULT_LEAD_DAYS );
        $today    = current_time( 'Y-m-d' );
        $deadline = gmdate( 'Y-m-d', current_time( 'timestamp' ) + $lead * DAY_IN_SECONDS );

        // `all()` returns the list sorted by start date; the window closing
        // soonest is the one with the smallest end, which is not the same
        // ordering when windows overlap.
        $best = null;
        foreach ( $this->windows->all() as $window ) {
            if ( $window['end'] < $today || $window['end'] > $deadline ) continue;
            if ( $best === null || $window['end'] < $best['end'] ) {
                $best = $window;
            }
        }
        return $best;
    }
}
