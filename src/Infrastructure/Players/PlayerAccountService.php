<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerAccountService (#1771) — domain logic for linking / unlinking a
 * WP account to a player, and reporting a player's account status.
 *
 * The PHP view and the REST controller both call this — no link/unlink
 * logic lives in either, so a future non-WordPress front end consuming
 * the REST API gets the same answers (CLAUDE.md §4).
 *
 * Invariant (enforced by the #1772 UNIQUE (club_id, wp_user_id) index and
 * belt-and-braces here): one WP account links to at most one player and
 * is never double-bound to a person too.
 */
final class PlayerAccountService {

    public const STATUS_LINKED  = 'linked';
    public const STATUS_INVITED = 'invited';
    public const STATUS_NONE    = 'none';

    /** Player-account role granted on link. */
    private const PLAYER_ROLE = 'tt_player';

    /**
     * WP users eligible to be linked to a player: every user, minus those
     * already linked to a player or a person in this club (no double-bind).
     *
     * @return array<int,object> WP_User-lite rows (ID, display_name, user_email).
     */
    public function eligibleUsers(): array {
        global $wpdb;
        $club = CurrentClub::id();

        $linked = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id IS NOT NULL AND wp_user_id > 0 AND club_id = %d
             UNION
             SELECT wp_user_id FROM {$wpdb->prefix}tt_people
              WHERE wp_user_id IS NOT NULL AND wp_user_id > 0 AND club_id = %d",
            $club, $club
        ) );

        return get_users( [
            'fields'  => [ 'ID', 'display_name', 'user_email' ],
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'exclude' => array_map( 'intval', $linked ),
        ] );
    }

    /**
     * Link a WP user to a player.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function link( int $player_id, int $wp_user_id ): array {
        global $wpdb;
        $club = CurrentClub::id();

        if ( $player_id <= 0 || $wp_user_id <= 0 ) {
            return $this->err( 'bad_request', __( 'A player and a WordPress user are both required.', 'talenttrack' ) );
        }

        $player = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, $club
        ) );
        if ( ! $player ) {
            return $this->err( 'not_found', __( 'Player not found.', 'talenttrack' ) );
        }
        if ( ! get_userdata( $wp_user_id ) ) {
            return $this->err( 'no_user', __( 'That WordPress user no longer exists.', 'talenttrack' ) );
        }

        // App-layer guard (#1772 step 5) — reject an account already bound
        // to another player or a person, with a clear message, before the
        // DB UNIQUE would reject it with a generic error.
        $other_player = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE wp_user_id = %d AND club_id = %d AND id <> %d
              ORDER BY id DESC LIMIT 1",
            $wp_user_id, $club, $player_id
        ) );
        if ( $other_player > 0 ) {
            return $this->err( 'already_linked_player', __( 'That account is already linked to another player.', 'talenttrack' ) );
        }
        $other_person = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND club_id = %d LIMIT 1",
            $wp_user_id, $club
        ) );
        if ( $other_person > 0 ) {
            return $this->err( 'already_linked_person', __( 'That account is already linked to a staff/parent record.', 'talenttrack' ) );
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = %d WHERE id = %d AND club_id = %d",
            $wp_user_id, $player_id, $club
        ) );

        $user = get_userdata( $wp_user_id );
        if ( $user ) $user->add_role( self::PLAYER_ROLE );

        return [ 'ok' => true, 'code' => 'linked', 'message' => __( 'Account linked.', 'talenttrack' ) ];
    }

    /**
     * #1847 — directly create a brand-new WP player account and link it to a
     * player. Default is a "set your password" email; pass a non-empty
     * `$temp_password` for the no-usable-email case — the caller MUST gate
     * that behind an explicit confirmation for a child account (CLAUDE.md §1).
     * Audit-logged.
     *
     * @return array{ok:bool,code:string,message:string,user_id?:int}
     */
    public function directCreate( int $player_id, string $first, string $last, string $email, ?string $temp_password = null ): array {
        global $wpdb;
        $club  = CurrentClub::id();
        $email = sanitize_email( $email );
        $first = sanitize_text_field( $first );
        $last  = sanitize_text_field( $last );

        if ( $player_id <= 0 ) {
            return $this->err( 'bad_request', __( 'A player is required.', 'talenttrack' ) );
        }
        if ( $email === '' || ! is_email( $email ) ) {
            return $this->err( 'bad_email', __( 'A valid email address is required.', 'talenttrack' ) );
        }
        if ( email_exists( $email ) ) {
            return $this->err( 'email_exists', __( 'An account with that email already exists. Link it instead of creating a new one.', 'talenttrack' ) );
        }
        $player = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, $club
        ) );
        if ( ! $player ) {
            return $this->err( 'not_found', __( 'Player not found.', 'talenttrack' ) );
        }
        if ( (int) ( $player->wp_user_id ?? 0 ) > 0 ) {
            return $this->err( 'already_linked', __( 'This player already has an account.', 'talenttrack' ) );
        }

        $login    = $this->generateLogin( $first, $last, $email );
        $use_temp = is_string( $temp_password ) && $temp_password !== '';
        $password = $use_temp ? $temp_password : wp_generate_password( 24, true, true );

        $uid = wp_insert_user( [
            'user_login'   => $login,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ) !== '' ? trim( $first . ' ' . $last ) : $login,
            'role'         => self::PLAYER_ROLE,
        ] );
        if ( is_wp_error( $uid ) ) {
            return $this->err( 'insert_failed', (string) $uid->get_error_message() );
        }
        $uid = (int) $uid;

        $link = $this->link( $player_id, $uid );
        if ( empty( $link['ok'] ) ) {
            wp_delete_user( $uid );
            return $this->err( 'link_failed', (string) $link['message'] );
        }

        if ( ! $use_temp ) {
            wp_new_user_notification( $uid, null, 'user' );
        }

        ( new \TT\Infrastructure\Audit\AuditService() )->record(
            'player_account.direct_created', 'player', $player_id,
            [ 'wp_user_id' => $uid, 'role' => self::PLAYER_ROLE, 'method' => $use_temp ? 'temp_password' : 'email_set_password' ]
        );

        return [
            'ok'      => true,
            'code'    => 'created',
            'message' => $use_temp
                ? __( 'Player account created and linked with a temporary password.', 'talenttrack' )
                : __( 'Player account created and linked. A set-password email has been sent.', 'talenttrack' ),
            'user_id' => $uid,
        ];
    }

    /** Generate a unique WP login from a name / email. */
    private function generateLogin( string $first, string $last, string $email ): string {
        $base = sanitize_user( strtolower( $first . $last ), true );
        if ( $base === '' ) $base = sanitize_user( strtolower( (string) ( strstr( $email, '@', true ) ?: 'player' ) ), true );
        if ( $base === '' ) $base = 'player';
        $candidate = $base;
        $i = 1;
        while ( username_exists( $candidate ) ) {
            $candidate = $base . $i;
            $i++;
        }
        return $candidate;
    }

    /**
     * Unlink whatever account is on a player.
     *
     * @return array{ok:bool,code:string,message:string}
     */
    public function unlink( int $player_id ): array {
        global $wpdb;
        $club = CurrentClub::id();

        if ( $player_id <= 0 ) {
            return $this->err( 'bad_request', __( 'A player is required.', 'talenttrack' ) );
        }
        $former_uid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, $club
        ) );
        if ( $former_uid <= 0 ) {
            return [ 'ok' => true, 'code' => 'noop', 'message' => __( 'That player has no account to unlink.', 'talenttrack' ) ];
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_players SET wp_user_id = NULL WHERE id = %d AND club_id = %d",
            $player_id, $club
        ) );

        // Strip the tt_player role ONLY when the account isn't also a
        // player elsewhere or a person — don't demote a coach-who-was-a-
        // player out of their other roles.
        if ( ! $this->userLinkedElsewhere( $former_uid ) ) {
            $user = get_userdata( $former_uid );
            if ( $user ) $user->remove_role( self::PLAYER_ROLE );
        }

        return [ 'ok' => true, 'code' => 'unlinked', 'message' => __( 'Account unlinked.', 'talenttrack' ) ];
    }

    /**
     * Account status for a player row (must carry id + wp_user_id).
     */
    public function accountStatus( object $player ): string {
        if ( (int) ( $player->wp_user_id ?? 0 ) > 0 ) {
            return self::STATUS_LINKED;
        }
        global $wpdb;
        $pending = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_invitations
              WHERE kind = 'player' AND target_player_id = %d AND status = 'pending' AND club_id = %d
              LIMIT 1",
            (int) $player->id, CurrentClub::id()
        ) );
        return $pending > 0 ? self::STATUS_INVITED : self::STATUS_NONE;
    }

    /** Is this WP user still linked to another player or any person? */
    private function userLinkedElsewhere( int $wp_user_id ): bool {
        global $wpdb;
        $club = CurrentClub::id();
        $hit  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND club_id = %d
             UNION
             SELECT 1 FROM {$wpdb->prefix}tt_people  WHERE wp_user_id = %d AND club_id = %d
             LIMIT 1",
            $wp_user_id, $club, $wp_user_id, $club
        ) );
        return $hit === 1;
    }

    /**
     * @return array{ok:bool,code:string,message:string}
     */
    private function err( string $code, string $message ): array {
        return [ 'ok' => false, 'code' => $code, 'message' => $message ];
    }
}
