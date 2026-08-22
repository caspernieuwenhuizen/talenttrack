<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * InvitationStaleAlert (#2636, epic #2629) — an invitation was sent and
 * nothing ever came of it.
 *
 * Which player question does this answer? *Where has this player come
 * from?* An invitation is the first step of a player's journey through the
 * academy's own systems, and an unaccepted one is a journey that never
 * started: the player has no account, cannot see their own evaluations,
 * cannot read the feedback their coach wrote for them. For a staff
 * invitation it is the same failure one step removed — a coach who cannot
 * sign in is a coach whose players' records go unwritten.
 *
 * The failure mode this catches is specific: the email went to spam, or to
 * a typo'd address, and nothing anywhere says so. `tt_invitations` records
 * a send and an acceptance, and the gap between them is invisible on every
 * screen until somebody thinks to look at the invitations list.
 *
 * ## Boundary with `people.parent_never_activated`
 *
 * This definition covers `kind IN ('player','staff')`. Parent invitations
 * have their own alert, because the question they raise is different — a
 * parent invitation that was never accepted may still be fine if the family
 * is linked another way, which is why that definition also checks for a
 * linked parent. Splitting them keeps each message specific; one alert
 * covering all three kinds would be muted by whoever cared about only one.
 *
 * ## Invitations the system has already made redundant
 *
 * A player invitation is dropped once the player has a linked account, and
 * a staff invitation once the person does, however that link was made — an
 * admin creating the account directly leaves a `pending` row behind, and
 * chasing it would be chasing something already done.
 *
 * `status IN ('pending','expired')`: the expiry sweep is lazy, so a row
 * whose token ran out months ago can still read `pending` and a swept one
 * reads `expired`. Both mean nobody used it.
 */
final class InvitationStaleAlert extends AbstractPlayerAlert {

    public const SUBJECT_TYPE = 'invitation';

    /** tt_config key: days after sending before the alert appears. */
    public const CONFIG_KEY_STALE_DAYS = 'alerts_invitation_stale_days';

    private const DEFAULT_STALE_DAYS = 14;

    public function key(): string {
        return 'onboarding.invitation_stale';
    }

    public function module(): string {
        return 'onboarding';
    }

    public function label(): string {
        return __( 'Invitation never accepted', 'talenttrack' );
    }

    public function description(): string {
        return __( 'An invitation was sent and never accepted. Usually the email went to spam or to a mistyped address, and nothing anywhere says so.', 'talenttrack' );
    }

    /**
     * The fix is sending the invitation again, so that is the capability
     * that gates receipt.
     */
    public function capRequired(): string {
        return 'tt_send_invitation';
    }

    protected function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /** Twice the threshold and the invitation is not late, it is lost. */
    protected function severityFor( object $row ): string {
        $days = $this->threshold( self::CONFIG_KEY_STALE_DAYS, self::DEFAULT_STALE_DAYS );
        return $this->daysSince( (string) ( $row->invited_at ?? '' ) ) >= ( $days * 2 )
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    /**
     * Whoever sent it. The head coach of the player's team is added by the
     * base class when the invitation is about a player; a staff invitation
     * has no team, so the sender is the whole audience.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        return [ (int) ( $row->created_by ?? 0 ) ];
    }

    protected function urlFor( object $row ): string {
        $player_id = (int) ( $row->player_id ?? 0 );
        if ( $player_id > 0 ) {
            return RecordLink::detailUrlFor( 'players', $player_id );
        }

        $person_id = (int) ( $row->target_person_id ?? 0 );
        if ( $person_id > 0 ) {
            return RecordLink::detailUrlFor( 'people', $person_id );
        }

        return RecordLink::dashboardUrl();
    }

    protected function titleFor( object $row ): string {
        return sprintf(
            /* translators: %s: name or email address of the person who was invited */
            __( '%s was invited but has never accepted.', 'talenttrack' ),
            $this->inviteeName( $row )
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'invited_at'      => (string) ( $row->invited_at ?? '' ),
            'invitation_kind' => (string) ( $row->kind ?? '' ),
            'invitee_name'    => $this->inviteeName( $row ),
        ];
    }

    /**
     * Who the invitation was for.
     *
     * The prefilled name comes first because it is what the sender typed
     * and therefore what they will recognise; the linked player's name is
     * the fallback, and the email address the last resort. A staff
     * invitation has no player at all, so the base class's `playerName()`
     * would call them "this player" — which is why this exists.
     */
    private function inviteeName( object $row ): string {
        $prefill = trim(
            (string) ( $row->prefill_first_name ?? '' ) . ' ' . (string) ( $row->prefill_last_name ?? '' )
        );
        if ( $prefill !== '' ) return $prefill;

        $player = trim(
            (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' )
        );
        if ( $player !== '' ) return $player;

        $email = trim( (string) ( $row->prefill_email ?? '' ) );
        if ( $email !== '' ) return $email;

        return __( 'Someone', 'talenttrack' );
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->threshold( self::CONFIG_KEY_STALE_DAYS, self::DEFAULT_STALE_DAYS );

        // Both targets are LEFT-joined because an invitation has at most
        // one of them: `target_player_id` for a player invite,
        // `target_person_id` for a staff invite. The joins carry the
        // lifecycle filters, so a released player's invitation drops out
        // through `pl.id IS NULL` rather than needing its own clause.
        //
        // `COALESCE(..., 0)` on the player columns is what lets the base
        // class treat a staff invitation as having no player and no team
        // without a second code path.
        $sql = $wpdb->prepare(
            "SELECT i.id AS subject_id, i.kind, i.created_at AS invited_at, i.created_by,
                    i.prefill_first_name, i.prefill_last_name, i.prefill_email,
                    i.target_person_id,
                    COALESCE( pl.id, 0 )      AS player_id,
                    COALESCE( pl.team_id, 0 ) AS team_id,
                    pl.first_name, pl.last_name
               FROM {$p}tt_invitations i
          LEFT JOIN {$p}tt_players pl
                 ON pl.id = i.target_player_id
                AND pl.club_id = i.club_id
                AND pl.status = 'active'
                AND pl.archived_at IS NULL
                AND pl.trashed_at IS NULL
          LEFT JOIN {$p}tt_people pe
                 ON pe.id = i.target_person_id
                AND pe.club_id = i.club_id
              WHERE " . QueryHelpers::clubScopeWhere( 'i' ) . "
                AND i.kind IN ( 'player', 'staff' )
                AND i.accepted_at IS NULL
                AND i.revoked_at IS NULL
                AND i.status IN ( 'pending', 'expired' )
                AND i.created_at < DATE_SUB( NOW(), INTERVAL %d DAY )
                AND ( i.target_player_id IS NULL OR i.target_player_id = 0 OR pl.id IS NOT NULL )
                AND COALESCE( pl.wp_user_id, 0 ) = 0
                AND COALESCE( pe.wp_user_id, 0 ) = 0"
            . $context->applyScope( self::SUBJECT_TYPE, 'i.id' ) . "
              ORDER BY i.created_at ASC, i.id ASC",
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
