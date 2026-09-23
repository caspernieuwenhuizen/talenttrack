<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * NoGuardianContactAlert (#3576) — nobody at home can be reached about this
 * player.
 *
 * Which player question does this answer? *Who at home hears about this
 * player?* When a trial opens, a plan is published or a training is
 * cancelled, the message goes to the player's parents. A player with no
 * linked parent account and no guardian email or phone resolves to nobody,
 * and until now the only trace of that was a warning in the error log,
 * one per message, naming no child.
 *
 * ## The condition
 *
 * An active player — or one with an open or extended trial case, who is
 * exactly the player a welcome is sent about — with:
 *
 *   - no parent account linked (`tt_player_parents`), and
 *   - no guardian email and no guardian phone on the player record.
 *
 * That is precisely when `RecipientResolver::forPlayer()` has nobody to
 * send to. It resolves itself the moment either is filled in.
 *
 * ## Boundary with `people.parent_never_activated`
 *
 * A player whose parent has a pending or expired invitation that was
 * actually sent is skipped: the family has been asked, and that alert
 * says the invitation was never used. One player should not carry two
 * alerts about the same missing parent.
 *
 * An invitation that was created but never mailed does not count. The
 * family was never asked, `people.parent_never_activated` only looks at
 * sent invitations, and `onboarding.invitation_never_sent` stops looking
 * once a held invitation expires. Skipping the player on it would leave
 * them in none of the three.
 *
 * ## Audience
 *
 * The team's head coach (the base class default — they know the family)
 * and whoever can link a parent account. Resolved once per sweep, not per
 * row. A Head of Development sees the aggregate roll-up, not one per
 * player.
 *
 * The demo academy will show a lot of these: most demo players have no
 * guardian by design. That is an honest picture of the data, not noise to
 * special-case.
 *
 * ## The club-wide count (#4014)
 *
 * This definition raises one occurrence per unreachable player, at the
 * people who can fix that player. The board asks the same data a different
 * question — "how many of our families can we reach?" — and used to answer
 * it by calling four per-team dossier reports and adding up by hand.
 * `FamilyReachability` is that answer: the same union of guardian e-mail,
 * guardian phone and linked parent account, counted club-wide with a
 * per-team breakdown and no family named. It is the aggregate side of this
 * alert, not a second definition, and it is deliberately a census over
 * every player on the books rather than only the ones this alert speaks
 * for.
 */
final class NoGuardianContactAlert extends AbstractPlayerAlert {

    /** Most parent-account managers one sweep will address. */
    private const MAX_MANAGERS = 20;

    /** Accounts scanned looking for them. */
    private const SCAN_CEILING = 1000;

    /** @var list<int>|null */
    private ?array $managers = null;

    public function key(): string {
        return 'people.no_guardian_contact';
    }

    public function module(): string {
        return 'people';
    }

    public function label(): string {
        return __( 'Player with no guardian contact', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Nobody at home can be reached about this player: no parent account is linked and there is no guardian email or phone. Messages about them reach no one until one is added.', 'talenttrack' );
    }

    /**
     * The fix is editing the player's guardian details or linking an
     * account, so the audience must be able to edit players.
     */
    public function capRequired(): string {
        return 'tt_edit_players';
    }

    protected function titleFor( object $row ): string {
        return sprintf(
            /* translators: %s: player name */
            __( 'Nobody at home can be reached about %s.', 'talenttrack' ),
            $this->playerName( $row )
        );
    }

    /** Straight to the form with the guardian fields on it. */
    protected function urlFor( object $row ): string {
        return add_query_arg(
            [ 'tt_view' => 'players', 'action' => 'edit', 'id' => $this->playerIdFor( $row ) ],
            RecordLink::dashboardUrl()
        );
    }

    /** @return list<int> */
    protected function extraRecipientsFor( object $row ): array {
        return $this->managers ?? [];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // `tt_player_parents` has a composite key and no surrogate id, so
        // its NOT EXISTS selects a literal.
        $sql = "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id
                  FROM {$p}tt_players p
                 WHERE " . QueryHelpers::clubScopeWhere( 'p' ) . "
                   AND p.archived_at IS NULL
                   AND p.trashed_at IS NULL
                   AND (
                        p.status = 'active'
                        OR EXISTS (
                            SELECT 1 FROM {$p}tt_trial_cases tc
                             WHERE tc.player_id = p.id
                               AND tc.club_id = p.club_id
                               AND tc.archived_at IS NULL
                               AND tc.status IN ( 'open', 'extended' )
                        )
                   )
                   AND ( p.guardian_email IS NULL OR p.guardian_email = '' )
                   AND ( p.guardian_phone IS NULL OR p.guardian_phone = '' )
                   AND NOT EXISTS (
                        SELECT 1 FROM {$p}tt_player_parents pp
                         WHERE pp.player_id = p.id AND pp.club_id = p.club_id
                   )
                   AND NOT EXISTS (
                        SELECT 1 FROM {$p}tt_invitations i
                         WHERE i.target_player_id = p.id
                           AND i.club_id = p.club_id
                           AND i.kind = 'parent'
                           AND i.accepted_at IS NULL
                           AND i.revoked_at IS NULL
                           AND i.status IN ( 'pending', 'expired' )
                           AND i.sent_at IS NOT NULL
                   )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
                 ORDER BY p.id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        $rows = is_array( $rows ) ? array_values( array_filter( $rows, 'is_object' ) ) : [];
        if ( $rows === [] ) return [];

        $this->managers = $this->parentAccountManagers();
        return $rows;
    }

    /**
     * Users who can link a parent account.
     *
     * Enumerate-then-ask rather than `get_users( [ 'capability' => … ] )`:
     * most TalentTrack caps are matrix-derived at runtime, which a role
     * meta query never sees. Bounded both ways, like
     * `AbstractDataQualityAlert::custodians()`, so one definition cannot
     * turn the hourly sweep into an unbounded scan.
     *
     * @return list<int>
     */
    private function parentAccountManagers(): array {
        if ( ! function_exists( 'get_users' ) ) return [];

        $ids = get_users( [
            'fields'  => 'ID',
            'number'  => self::SCAN_CEILING,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ] );

        $out = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id <= 0 || ! user_can( $id, 'tt_manage_parent_accounts' ) ) continue;
            $out[] = $id;
            if ( count( $out ) >= self::MAX_MANAGERS ) break;
        }
        return $out;
    }
}
