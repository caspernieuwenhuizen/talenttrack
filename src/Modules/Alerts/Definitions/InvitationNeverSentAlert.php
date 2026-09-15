<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Invitations\InvitationStatus;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * InvitationNeverSentAlert (#3387, epic #2629) — invitations were created
 * and held, and nobody ever pressed send.
 *
 * The setup wizard's staff step (#3261) creates invitations and holds them
 * on purpose: an admin adds their coaches, looks around, and sends when
 * ready. That step says so plainly. Nothing outside it ever does. An
 * operator who adds four coaches and closes the tab has four people on the
 * staff list, four invitations created and zero emails sent — every screen
 * looks correct, the coaches are simply never told, and the academy
 * concludes the invitations failed.
 *
 * ## Why this is not `onboarding.invitation_stale`
 *
 * That definition asks *did it arrive?* and measures the gap between
 * sending and acceptance. A held invitation has never been sent, so there
 * is no `sent_at` for staleness to measure from and it falls through that
 * definition entirely. This one asks *was it ever sent?* — a different
 * question, and the boundary between alert definitions is drawn by the
 * question each one raises, so it is a sibling rather than a special case.
 *
 * The two cannot double-report the same invitation: this one requires
 * `sent_at IS NULL`, and its sibling requires the opposite.
 *
 * ## One alert carrying a count, not one per invitation
 *
 * The failure mode is an academy concluding that invitations failed, so
 * the job is to turn *nothing happened* into *nothing happened yet, and
 * here is the button*. A count makes that specific — it tells an operator
 * whether they forgot one person or a whole coaching staff — and one row
 * per held invitation would bury that under a list of names they already
 * know they typed.
 *
 * ## Sweep-only, like `comms.messaging_never_configured`
 *
 * The subject is the backlog rather than any one invitation, so nothing
 * invalidates it and the hourly reconcile is what re-runs it. That is soon
 * enough for a condition measured in days. The `isFullSweep()` guard keeps
 * a narrowed run from reaching a verdict about the whole install.
 *
 * It self-resolves for the same reason every definition in the epic does:
 * the reconcile returns the current truth and stamps `resolved_at` on what
 * is absent. Sending the invitations — or deleting them — empties the
 * query, and the alert goes without anybody dismissing it.
 *
 * ## Which player question does this answer?
 *
 * *Where has this player come from?* — one step removed. A coach who was
 * never invited is a coach who cannot sign in, and their players' records
 * go unwritten. What separates this failure from the sibling's is that it
 * is entirely inside the academy's own control, and currently invisible.
 */
final class InvitationNeverSentAlert extends AbstractDataQualityAlert {

    /**
     * The backlog, not any one invitation — see the class docblock. A
     * subject nothing invalidates, deliberately: pointing it at
     * `invitation` would put a whole-install verdict inside a narrowed run
     * about one row.
     */
    public const SUBJECT_TYPE = 'invitation_backlog';

    /** There is one backlog per install, so the subject id is a constant. */
    private const SUBJECT_ID = 1;

    /** tt_config key: days an invitation may sit unsent before this fires. */
    public const CONFIG_KEY_UNSENT_DAYS = 'alerts_invitation_unsent_days';

    /**
     * Held for the first hours is the feature working — the operator is
     * still adding people. A day is the floor at which "not yet" has become
     * "forgotten", and the key above is how an academy that works in longer
     * batches moves it.
     */
    private const DEFAULT_UNSENT_DAYS = 1;

    public function key(): string {
        return 'onboarding.invitation_never_sent';
    }

    /** Grouped with its sibling, where somebody looking for it would look. */
    public function module(): string {
        return 'onboarding';
    }

    public function label(): string {
        return __( 'Invitations waiting to be sent', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Invitations were created and held, and nobody has sent them. The people they are for have not been told anything at all.', 'talenttrack' );
    }

    /** The fix is pressing send, so that is what gates receipt. */
    public function capRequired(): string {
        return 'tt_send_invitation';
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /** People are waiting to be let in: louder than a data-quality gap. */
    public function defaultSeverity(): string {
        return Severity::ATTENTION;
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ Surface::BADGE, Surface::BANNER ];
    }

    protected function titleFor( object $row ): string {
        $count = (int) ( $row->unsent_count ?? 0 );
        return sprintf(
            /* translators: %s: number of invitations that have been created but not sent */
            _n(
                '%s invitation is ready to send.',
                '%s invitations are ready to send.',
                $count,
                'talenttrack'
            ),
            number_format_i18n( $count )
        );
    }

    protected function urlFor( object $row ): string {
        return add_query_arg( [ 'tt_view' => 'invitations-config' ], RecordLink::dashboardUrl() );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'unsent_count'  => (int) ( $row->unsent_count ?? 0 ),
            'oldest_waited' => (int) ( $row->oldest_days ?? 0 ),
        ];
    }

    /** About the install's backlog, not about a player. */
    protected function playerIdFor( object $row ): ?int {
        return null;
    }

    /**
     * One synthetic row carrying the count, or none.
     *
     * `status = pending` + `sent_at IS NULL` is exactly the set
     * `InvitationService::send()` will act on, so the alert can never name
     * a number larger than the Send all button would deliver. An
     * invitation that was accepted, revoked or swept to `expired` has left
     * that set and leaves the alert with it.
     *
     * @return list<object>
     */
    protected function rows( AlertContext $context ): array {
        // See the class docblock: sweep-only.
        if ( ! $context->isFullSweep() ) return [];

        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->threshold( self::CONFIG_KEY_UNSENT_DAYS, self::DEFAULT_UNSENT_DAYS );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS unsent_count,
                    COALESCE( MAX( DATEDIFF( NOW(), i.created_at ) ), 0 ) AS oldest_days
               FROM {$p}tt_invitations i
              WHERE " . QueryHelpers::clubScopeWhere( 'i' ) . "
                AND i.sent_at IS NULL
                AND i.status = %s
                AND i.accepted_at IS NULL
                AND i.revoked_at IS NULL
                AND i.created_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
            InvitationStatus::PENDING,
            $days
        ) );

        $count = $row !== null ? (int) $row->unsent_count : 0;
        if ( $count <= 0 ) return [];

        return [ (object) [
            'subject_id'   => self::SUBJECT_ID,
            'unsent_count' => $count,
            'oldest_days'  => $row !== null ? (int) $row->oldest_days : 0,
        ] ];
    }
}
