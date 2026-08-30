<?php
namespace TT\Modules\Invitations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Invitations\InvitationsRepository;
use TT\Shared\Frontend\FlashMessages;

/**
 * Handles `admin-post.php?action=tt_invitation_bulk_create` (#1770).
 *
 * Generates player invitations for every **unlinked** player on a team in
 * one action — the deferred "bulk invite by team" from the player↔account
 * mapping epic. Loops `InvitationService::create()` per player, which
 * already de-dupes a pending invite and enforces the daily cap; this
 * handler aggregates the outcome into one flash summary and stops cleanly
 * when the cap is hit (partial success is reported, not lost).
 */
class InvitationBulkCreateHandler {

    public static function handle(): void {
        if ( ! is_user_logged_in() ) wp_die( esc_html__( 'Not logged in.', 'talenttrack' ), 403 );
        if ( ! current_user_can( 'tt_send_invitation' ) ) wp_die( esc_html__( 'Insufficient permissions.', 'talenttrack' ), 403 );

        check_admin_referer( 'tt_invitation_bulk_create' );

        $kind = isset( $_POST['kind'] ) ? sanitize_key( (string) wp_unslash( $_POST['kind'] ) ) : InvitationKind::PLAYER;
        if ( ! InvitationKind::isValid( $kind ) ) {
            wp_die( esc_html__( 'Invalid kind.', 'talenttrack' ), 400 );
        }
        $team_id  = isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;
        $redirect = isset( $_POST['_redirect'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_redirect'] ) ) : home_url( '/' );

        if ( $team_id <= 0 ) {
            FlashMessages::add( 'error', __( 'Choose a team to invite.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        // #3161 — `tt_send_invitation` sits on the `tt_coach` role, and the
        // seed grants head_coach `invitations: c [team]`. The scope half was
        // never read here, so a forged POST bulk-created account invitations
        // for another team's squad — an out-of-scope write on minors'
        // records, addressed to their guardians.
        if ( ! self::mayInviteForTeam( get_current_user_id(), $team_id ) ) {
            wp_die( esc_html__( 'You do not coach this team.', 'talenttrack' ), 403 );
        }

        $players = self::unlinkedPlayers( $team_id );
        if ( empty( $players ) ) {
            FlashMessages::add( 'success', __( 'Every player on that team already has an account or a pending invite.', 'talenttrack' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        $service = new InvitationService();
        $repo    = new InvitationsRepository();
        $created = 0;
        $already = 0;
        $cap_hit = false;

        foreach ( $players as $pl ) {
            // create() de-dupes silently; pre-check so we can report the
            // already-invited count distinctly from the new ones.
            if ( $repo->findPendingFor( $kind, (int) $pl->id, null ) ) {
                $already++;
                continue;
            }
            $result = $service->create( [
                'kind'               => $kind,
                'target_player_id'   => (int) $pl->id,
                'target_team_id'     => $team_id,
                'prefill_first_name' => (string) ( $pl->first_name ?? '' ),
                'prefill_last_name'  => (string) ( $pl->last_name ?? '' ),
            ] );
            if ( ! empty( $result['cap_exceeded'] ) ) {
                $cap_hit = true;
                break;
            }
            if ( ! empty( $result['ok'] ) ) {
                $created++;
            }
        }

        $summary = sprintf(
            /* translators: 1: number of new invites, 2: number already invited */
            __( '%1$d new invite(s) generated; %2$d already had a pending invite.', 'talenttrack' ),
            $created,
            $already
        );
        if ( $cap_hit ) {
            $summary .= ' ' . __( 'Daily invite limit reached — invite the rest tomorrow, or raise the cap.', 'talenttrack' );
            FlashMessages::add( 'error', $summary );
        } else {
            FlashMessages::add( 'success', $summary );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * May this user bulk-invite for this team? (#3161)
     *
     * The wide branch asks the matrix whether the user may create
     * invitations at **global** scope — the question the seed actually
     * answers for Head of Development and Academy Admin, both of whom hold
     * `invitations: c [global]`. Deliberately not a `tt_edit_settings`
     * compare: a head of development lost that capability in #0071 and
     * would be locked out of a surface they own (#2866). Deliberately not
     * `AllTeamsScope` either — that asks for global *read*, which neither
     * persona holds on `invitations`; creating is the verb in play.
     *
     * Otherwise: the team must be one the user coaches.
     */
    public static function mayInviteForTeam( int $user_id, int $team_id ): bool {
        if ( $user_id <= 0 || $team_id <= 0 ) return false;

        if ( MatrixGate::can( $user_id, 'invitations', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL ) ) {
            return true;
        }

        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            if ( (int) ( $team->id ?? 0 ) === $team_id ) return true;
        }
        return false;
    }

    /**
     * Active players on the team that have no linked WP account.
     * (`create()` itself skips any that already hold a pending invite.)
     *
     * @return array<int, object>
     */
    private static function unlinkedPlayers( int $team_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name
               FROM {$p}tt_players
              WHERE team_id = %d
                AND club_id = %d
                AND archived_at IS NULL
                AND ( wp_user_id IS NULL OR wp_user_id = 0 )
              ORDER BY last_name ASC, first_name ASC",
            $team_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
