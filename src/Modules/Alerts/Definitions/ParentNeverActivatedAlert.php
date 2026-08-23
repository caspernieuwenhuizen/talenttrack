<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * ParentNeverActivatedAlert (#2636, epic #2629) — a parent was invited and
 * never got an account.
 *
 * Which player question does this answer? *Where has this player come from,
 * and who at home can see it?* A parent with no account cannot read their
 * child's evaluations, cannot see the PDP conversations, and cannot answer
 * the club when it asks about consent. The invitation looks sent on every
 * screen; the family's side of the record simply never opened.
 *
 * ## What "never activated" is
 *
 * Two conditions together, because either alone is wrong. The invitation
 * has not been accepted (`accepted_at IS NULL`, not revoked) **and** the
 * player has no linked parent account at all. The second half is what stops
 * the alert firing when a parent was invited twice and accepted the other
 * invitation, or was linked directly by an admin — both leave a stale
 * `pending` row behind, and neither is a problem.
 *
 * `status IN ('pending','expired')` rather than `= 'pending'`: the expiry
 * sweep is lazy, so a row whose token ran out months ago can still read
 * `pending`, and one that was swept reads `expired`. Both mean the same
 * thing here — nobody ever used it.
 *
 * ## Boundary with `onboarding.invitation_stale`
 *
 * This definition covers `kind = 'parent'` only. Player and staff
 * invitations are the Onboarding definition's subject. The split is
 * deliberate: they go to different people, they say different things, and
 * one alert covering all three would be muted by whoever cared about only
 * one of them.
 */
final class ParentNeverActivatedAlert extends AbstractPlayerAlert {

    public const SUBJECT_TYPE = 'invitation';

    /** tt_config key: days after the invitation before the alert appears. */
    public const CONFIG_KEY_STALE_DAYS = 'alerts_parent_invite_stale_days';

    private const DEFAULT_STALE_DAYS = 14;

    public function key(): string {
        return 'people.parent_never_activated';
    }

    public function module(): string {
        return 'people';
    }

    public function label(): string {
        return __( 'Parent invited but never activated', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A parent was invited and never created their account, and the player still has no parent linked. The invitation looks sent everywhere; the family\'s side of the record never opened.', 'talenttrack' );
    }

    /**
     * The fix is sending the invitation again, so that is the capability
     * that gates receipt.
     */
    public function capRequired(): string {
        return 'tt_send_invitation';
    }

    public function subjectType(): string {
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
     * Whoever sent the invitation. The head coach of the player's team is
     * added by the base class.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        return [ (int) ( $row->created_by ?? 0 ) ];
    }

    protected function titleFor( object $row ): string {
        return sprintf(
            /* translators: %s: player name */
            __( '%s\'s parent was invited but never activated their account.', 'talenttrack' ),
            $this->playerName( $row )
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [ 'invited_at' => (string) ( $row->invited_at ?? '' ) ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->threshold( self::CONFIG_KEY_STALE_DAYS, self::DEFAULT_STALE_DAYS );

        // `tt_player_parents` has a composite primary key and no surrogate
        // id, so the NOT EXISTS selects a literal rather than a column.
        $sql = $wpdb->prepare(
            "SELECT i.id AS subject_id, i.target_player_id AS player_id,
                    i.created_at AS invited_at, i.created_by,
                    p.first_name, p.last_name, p.team_id
               FROM {$p}tt_invitations i
         INNER JOIN {$p}tt_players p ON p.id = i.target_player_id
              WHERE " . QueryHelpers::clubScopeWhere( 'i' ) . "
                AND i.kind = 'parent'
                AND i.accepted_at IS NULL
                AND i.revoked_at IS NULL
                AND i.status IN ( 'pending', 'expired' )
                AND i.created_at < DATE_SUB( NOW(), INTERVAL %d DAY )
                AND NOT EXISTS (
                    SELECT 1 FROM {$p}tt_player_parents pp
                     WHERE pp.player_id = i.target_player_id
                       AND pp.club_id = i.club_id
                )
                AND " . $this->activePlayerWhere( 'p' )
            . $context->applyScope( self::SUBJECT_TYPE, 'i.id' ) . "
              ORDER BY i.created_at ASC, i.id ASC",
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
