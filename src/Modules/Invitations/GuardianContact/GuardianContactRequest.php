<?php
namespace TT\Modules\Invitations\GuardianContact;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Invitations\InvitationStatus;
use TT\Modules\Invitations\InvitationToken;
use TT\Modules\Invitations\InvitationsRepository;

/**
 * GuardianContactRequest (#3794) — ask a family for their own contact
 * details on a link, and take the answer straight onto the player record.
 *
 * Guardian contact is the worst-maintained data the academy holds: it
 * reaches the office on paper, gets retyped at the gate, and is stale
 * within a season. 310 players carry the `people.no_guardian_contact`
 * alert, which means nobody at home can be reached about them at all.
 * This is the office asking the family directly.
 *
 * WHY THE WRITE IS DIRECT
 *
 * The submission lands on `tt_players.guardian_*` immediately, with an
 * audit entry naming the old and new values. There is deliberately no
 * approval queue: a queue is a second inbox for the office that is
 * already behind on the paper, and it makes the link's value conditional
 * on somebody clearing it. An audited, revertable write is the safer
 * shape — the record is right the moment the family answers, and the
 * audit entry is what makes a wrong answer fixable.
 *
 * WHY IT RIDES `tt_invitations`
 *
 * That table already stores a 192-bit token with an expiry, a
 * sent/accepted status and an atomic claim. Nothing here needed a new
 * table, so there is no schema change: a request is a row whose `kind`
 * is {@see self::KIND}.
 *
 * That kind is deliberately **not** a valid `InvitationKind`. A guardian
 * request creates no account and grants nothing, and every account-
 * creating path refuses a kind it does not recognise, so one of these
 * tokens pasted into the invitation acceptance flow does nothing at all.
 *
 * WHAT THE PUBLIC PAGE MAY KNOW
 *
 * The child's name, and nothing else. The URL is unauthenticated: no
 * evaluations, no medical, no team, and never the details already on
 * file — those may describe the other parent, and the form is not a
 * window into the record (CLAUDE.md §1, privacy and dignity).
 */
final class GuardianContactRequest {

    /** `tt_invitations.kind` for a contact request. Not an account invite. */
    public const KIND = 'guardian_contact';

    /** The public page that renders the form. */
    public const VIEW_SLUG = 'guardian-contact';

    /** The player columns a family may fill in. */
    public const FIELDS = [ 'guardian_name', 'guardian_email', 'guardian_phone' ];

    /**
     * Asking a family is editing the player's contact details by proxy,
     * so it takes the capability that edit takes — and the same one the
     * `people.no_guardian_contact` alert requires of its audience.
     */
    public const CAP = 'tt_edit_players';

    /**
     * Create and send a request for one player.
     *
     * @return array{ok:bool, id:?int, token:?string, error:?string}
     */
    public static function send( int $player_id, string $email ): array {
        $email = sanitize_email( $email );
        if ( $email === '' || ! is_email( $email ) ) {
            return self::failure( __( 'Enter the email address to send the request to.', 'talenttrack' ) );
        }

        $player = self::player( $player_id );
        if ( $player === null ) {
            return self::failure( __( 'That player could not be found.', 'talenttrack' ) );
        }

        // Checked here as well as at both entry points: this method mails
        // a credential on the academy's behalf, and a future caller must
        // not be able to skip that by not being a controller.
        $actor = get_current_user_id();
        if ( $actor <= 0 || ! current_user_can( self::CAP ) ) {
            return self::failure( __( 'You are not allowed to send contact requests.', 'talenttrack' ) );
        }

        $repo = new InvitationsRepository();

        // The same daily ceiling the invitation flow uses, and the same
        // filter to raise it. A bulk sweep across an age group is the
        // intended use; a script mailing every family in the country is
        // not, and one accident is enough.
        $cap   = (int) apply_filters( 'tt_invitation_daily_cap', 50, $actor );
        $since = gmdate( 'Y-m-d H:i:s', time() - 24 * 3600 );
        if ( $repo->countCreatedByUserSince( $actor, $since ) >= $cap ) {
            return self::failure( __( 'You have reached today\'s limit for sending requests. Try again tomorrow.', 'talenttrack' ) );
        }

        $ttl   = max( 1, (int) ( new ConfigService() )->getInt( 'invite_token_ttl_days', 14 ) );
        $token = InvitationToken::generate();

        $id = $repo->insert( [
            'token'            => $token,
            'kind'             => self::KIND,
            'target_player_id' => $player_id,
            'prefill_email'    => $email,
            'locale'           => self::localeFor( $player ),
            'created_by'       => $actor,
            'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + $ttl * 86400 ),
            'sent_at'          => gmdate( 'Y-m-d H:i:s' ),
        ] );

        if ( $id <= 0 ) {
            return self::failure( __( 'The request could not be saved.', 'talenttrack' ) );
        }

        /**
         * A guardian-contact request has been created and is about to be
         * mailed (#3794).
         *
         * @param int $request_id `tt_invitations.id`.
         * @param int $player_id  The player it asks about.
         * @param string $email   Where it was sent.
         */
        do_action( 'tt_guardian_contact_requested', $id, $player_id, $email );

        return [ 'ok' => true, 'id' => $id, 'token' => $token, 'error' => null ];
    }

    /**
     * The request a token names, when it may still be answered.
     *
     * Null covers every refusal — unknown, tampered, revoked, expired,
     * already answered — because the caller renders one message for all
     * of them. Telling a visitor which one they hit would tell them
     * whether the token exists.
     */
    public static function resolve( string $token ): ?object {
        if ( ! InvitationToken::isValidShape( $token ) ) return null;

        $repo = new InvitationsRepository();
        $repo->sweepExpired();

        $found = $repo->findByToken( $token );
        if ( ! $found ) return null;

        $row = (array) $found;
        if ( (string) ( $row['kind'] ?? '' ) !== self::KIND ) return null;
        if ( (string) ( $row['status'] ?? '' ) !== InvitationStatus::PENDING ) return null;
        if ( strtotime( (string) ( $row['expires_at'] ?? '' ) ) < time() ) return null;
        if ( self::player( (int) ( $row['target_player_id'] ?? 0 ) ) === null ) return null;

        return $found;
    }

    /**
     * Take a family's answer: claim the token, then write what they sent.
     *
     * The claim comes first and is atomic, so a double tap — or a second
     * visit to a link somebody forwarded — writes once. Validation runs
     * before the claim, so a typo does not burn the link.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool, error:?string, changes:array<string,array{from:string,to:string}>}
     */
    public static function submit( object $request, array $input ): array {
        $req        = (array) $request;
        $request_id = (int) ( $req['id'] ?? 0 );
        $player_id  = (int) ( $req['target_player_id'] ?? 0 );
        $player     = self::player( $player_id );
        if ( $player === null ) {
            return self::submitFailure( __( 'This link is no longer valid. Ask the academy to send you a new one.', 'talenttrack' ) );
        }

        if ( empty( $input['consent'] ) ) {
            return self::submitFailure( __( 'Please confirm the academy may use these details to contact you.', 'talenttrack' ) );
        }

        $name  = sanitize_text_field( (string) ( $input['guardian_name'] ?? '' ) );
        $email = sanitize_email( (string) ( $input['guardian_email'] ?? '' ) );
        $phone = self::cleanPhone( (string) ( $input['guardian_phone'] ?? '' ) );

        if ( trim( $name ) === '' ) {
            return self::submitFailure( __( 'Please fill in your name.', 'talenttrack' ) );
        }
        if ( $email !== '' && ! is_email( $email ) ) {
            return self::submitFailure( __( 'That email address does not look right. Please check it.', 'talenttrack' ) );
        }
        if ( $email === '' && $phone === '' ) {
            return self::submitFailure( __( 'Please fill in an email address or a phone number, so the academy can reach you.', 'talenttrack' ) );
        }
        if ( $phone !== '' && ! self::looksLikeAPhone( $phone ) ) {
            return self::submitFailure( __( 'That phone number does not look right. Please check it.', 'talenttrack' ) );
        }

        $submitted = [
            'guardian_name'  => trim( $name ),
            'guardian_email' => $email,
            'guardian_phone' => $phone,
        ];

        // Single use. The claim is an atomic pending → accepted flip, so
        // two submissions cannot both win it.
        $repo = new InvitationsRepository();
        if ( ! $repo->claimForAcceptance( $request_id, 0 ) ) {
            return self::submitFailure( __( 'This link has already been used. Ask the academy to send you a new one.', 'talenttrack' ) );
        }

        $current = (array) $player;
        $changes = [];
        $update  = [];
        foreach ( self::FIELDS as $field ) {
            $value = (string) $submitted[ $field ];
            if ( $value === '' ) continue;              // Absent means "leave it alone".
            $before = (string) ( $current[ $field ] ?? '' );
            if ( $before === $value ) continue;
            $update[ $field ]  = $value;
            $changes[ $field ] = [ 'from' => $before, 'to' => $value ];
        }

        if ( ! empty( $update ) ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'tt_players',
                $update,
                [ 'id' => $player_id, 'club_id' => CurrentClub::id() ]
            );
        }

        /**
         * A family has answered a guardian-contact request (#3794).
         *
         * @param int $request_id `tt_invitations.id`.
         * @param int $player_id  The player whose record was written.
         * @param array<string,array{from:string,to:string}> $changes What moved.
         */
        do_action( 'tt_guardian_contact_submitted', $request_id, $player_id, $changes );

        return [ 'ok' => true, 'error' => null, 'changes' => $changes ];
    }

    /** The public URL for a token, on the dashboard page. */
    public static function url( string $token ): string {
        return self::pageUrl( [ 'token' => $token ] );
    }

    /**
     * The public page, with whatever the caller needs on it.
     *
     * @param array<string,string> $args
     */
    public static function pageUrl( array $args = [] ): string {
        $page_id = (int) ( new ConfigService() )->getInt( 'dashboard_page_id', 0 );
        $base    = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
        if ( ! is_string( $base ) || $base === '' ) $base = home_url( '/' );
        return add_query_arg( [ 'tt_view' => self::VIEW_SLUG ] + $args, $base );
    }

    /**
     * The child's name — the one piece of the record the public page may
     * show. The family already knows it; everything else would be a leak
     * on an unauthenticated URL.
     */
    public static function playerName( int $player_id ): string {
        $player = self::player( $player_id );
        if ( $player === null ) return '';
        $row = (array) $player;
        return trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
    }

    /** An active player in this club, or null. */
    public static function player( int $player_id ): ?object {
        if ( $player_id <= 0 ) return null;

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, first_name, last_name, locale, guardian_name, guardian_email, guardian_phone
               FROM {$wpdb->prefix}tt_players
              WHERE id = %d AND club_id = %d AND status = 'active'
              LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        return is_object( $row ) ? $row : null;
    }

    private static function localeFor( object $player ): ?string {
        $row    = (array) $player;
        $locale = (string) ( $row['locale'] ?? '' );
        if ( $locale !== '' ) return $locale;
        $club_default = ( new ConfigService() )->get( 'invite_default_locale', '' );
        return $club_default !== '' ? $club_default : null;
    }

    /** Spaces and punctuation are not part of a number; the shape is. */
    private static function cleanPhone( string $raw ): string {
        $trimmed = trim( sanitize_text_field( $raw ) );
        if ( $trimmed === '' ) return '';
        return (string) ( preg_replace( '/\s+/', ' ', $trimmed ) ?? '' );
    }

    /**
     * Loose on purpose. The column is free text an admin types at the
     * gate, and a Dutch family writing `06 12 34 56 78` is answering the
     * question correctly — refusing them over a missing country code
     * would lose the answer the academy has waited three weeks for.
     */
    private static function looksLikeAPhone( string $candidate ): bool {
        $digits = preg_replace( '/\D+/', '', $candidate ) ?? '';
        return strlen( $digits ) >= 6 && strlen( $digits ) <= 20;
    }

    /** @return array{ok:bool, id:?int, token:?string, error:?string} */
    private static function failure( string $message ): array {
        return [ 'ok' => false, 'id' => null, 'token' => null, 'error' => $message ];
    }

    /** @return array{ok:bool, error:?string, changes:array<string,array{from:string,to:string}>} */
    private static function submitFailure( string $message ): array {
        return [ 'ok' => false, 'error' => $message, 'changes' => [] ];
    }
}
