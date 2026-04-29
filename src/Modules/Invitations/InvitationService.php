<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * InvitationService — create / accept / revoke / list orchestration.
 *
 * The single chokepoint that:
 *   - Generates tokens, sets expiry, fires `tt_invitation_created`.
 *   - Resolves locale per the precedence chain (target row → club default → inviter).
 *   - Enforces the 50/day soft cap (filterable + override path).
 *   - On accept: creates the WP user, runs the linking step, fires
 *     `tt_invitation_accepted`.
 *   - On revoke: marks revoked, fires `tt_invitation_revoked`.
 */
class InvitationService {

    private InvitationsRepository $repo;
    private PlayerParentsRepository $parents;
    private ConfigService $config;

    public function __construct(
        ?InvitationsRepository $repo = null,
        ?PlayerParentsRepository $parents = null,
        ?ConfigService $config = null
    ) {
        $this->repo    = $repo    ?? new InvitationsRepository();
        $this->parents = $parents ?? new PlayerParentsRepository();
        $this->config  = $config  ?? new ConfigService();
    }

    // Create

    /**
     * Generate a new invitation. Returns ['ok'=>bool, 'id'=>?int,
     * 'token'=>?string, 'error'=>?string, 'cap_exceeded'=>?bool].
     *
     * @param array{
     *   kind:string,
     *   target_player_id?:int,
     *   target_person_id?:int,
     *   target_team_id?:int,
     *   target_functional_role_key?:string,
     *   prefill_first_name?:string,
     *   prefill_last_name?:string,
     *   prefill_email?:string,
     *   override_cap?:bool,
     *   override_reason?:string
     * } $args
     * @return array{ok:bool, id:?int, token:?string, error:?string, cap_exceeded:?bool}
     */
    public function create( array $args ): array {
        $kind = (string) ( $args['kind'] ?? '' );
        if ( ! InvitationKind::isValid( $kind ) ) {
            return [ 'ok' => false, 'id' => null, 'token' => null, 'error' => __( 'Invalid invitation kind.', 'talenttrack' ), 'cap_exceeded' => false ];
        }

        $userId = get_current_user_id();
        if ( $userId <= 0 ) {
            return [ 'ok' => false, 'id' => null, 'token' => null, 'error' => __( 'Not logged in.', 'talenttrack' ), 'cap_exceeded' => false ];
        }

        // Rate limit. Filter lets hosts permanently bump the cap; the
        // override flag is the per-request "Continue anyway" path.
        $cap = (int) apply_filters( 'tt_invitation_daily_cap', 50, $userId );
        $since = gmdate( 'Y-m-d H:i:s', time() - 24 * 3600 );
        $count = $this->repo->countCreatedByUserSince( $userId, $since );
        if ( $count >= $cap && empty( $args['override_cap'] ) ) {
            return [
                'ok'           => false,
                'id'           => null,
                'token'        => null,
                'error'        => sprintf(
                    /* translators: 1: cap, 2: actual count */
                    __( 'Daily invitation cap reached (%1$d). Already created %2$d in the last 24 hours.', 'talenttrack' ),
                    $cap,
                    $count
                ),
                'cap_exceeded' => true,
            ];
        }

        // De-dupe: if a pending invitation already exists for this
        // target + kind, return its row instead of generating a second.
        $existing = $this->repo->findPendingFor(
            $kind,
            isset( $args['target_player_id'] ) ? (int) $args['target_player_id'] : null,
            isset( $args['target_person_id'] ) ? (int) $args['target_person_id'] : null
        );
        if ( $existing ) {
            return [
                'ok'           => true,
                'id'           => (int) $existing->id,
                'token'        => (string) $existing->token,
                'error'        => null,
                'cap_exceeded' => false,
            ];
        }

        $ttl = max( 1, (int) $this->config->getInt( 'invite_token_ttl_days', 14 ) );
        $expires = gmdate( 'Y-m-d H:i:s', time() + $ttl * 86400 );
        $token = InvitationToken::generate();
        $locale = $this->resolveLocale( $kind, $args );

        $id = $this->repo->insert( [
            'token'                       => $token,
            'kind'                        => $kind,
            'target_player_id'            => isset( $args['target_player_id'] ) ? (int) $args['target_player_id'] : null,
            'target_person_id'            => isset( $args['target_person_id'] ) ? (int) $args['target_person_id'] : null,
            'target_team_id'              => isset( $args['target_team_id'] )   ? (int) $args['target_team_id']   : null,
            'target_functional_role_key'  => isset( $args['target_functional_role_key'] ) ? (string) $args['target_functional_role_key'] : null,
            'prefill_first_name'          => isset( $args['prefill_first_name'] ) ? (string) $args['prefill_first_name'] : null,
            'prefill_last_name'           => isset( $args['prefill_last_name'] )  ? (string) $args['prefill_last_name']  : null,
            'prefill_email'               => isset( $args['prefill_email'] )      ? (string) $args['prefill_email']      : null,
            'locale'                      => $locale,
            'created_by'                  => $userId,
            'expires_at'                  => $expires,
        ] );

        if ( $id <= 0 ) {
            return [ 'ok' => false, 'id' => null, 'token' => null, 'error' => __( 'The invitation could not be saved.', 'talenttrack' ), 'cap_exceeded' => false ];
        }

        if ( ! empty( $args['override_cap'] ) && ! empty( $args['override_reason'] ) ) {
            do_action( 'tt_invitation_cap_overridden', $userId, $id, (string) $args['override_reason'] );
        }

        do_action( 'tt_invitation_created', $id, $kind );

        return [
            'ok'           => true,
            'id'           => $id,
            'token'        => $token,
            'error'        => null,
            'cap_exceeded' => false,
        ];
    }

    // Accept

    /**
     * Accept a pending invitation. Caller has already validated the
     * token; this method runs the WP user creation + linking + accepts.
     *
     * @param array{
     *   password:string,
     *   recovery_email:string,
     *   jersey_number?:string,
     *   relationship_label?:string,
     *   notify_on_progress?:bool
     * } $payload
     * @return array{ok:bool, user_id:?int, error:?string}
     */
    public function accept( object $invitation, array $payload ): array {
        if ( (string) $invitation->status !== InvitationStatus::PENDING ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'This invitation is no longer pending.', 'talenttrack' ) ];
        }
        if ( strtotime( (string) $invitation->expires_at ) < time() ) {
            $this->repo->update( (int) $invitation->id, [ 'status' => InvitationStatus::EXPIRED ] );
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'This invitation has expired.', 'talenttrack' ) ];
        }

        $email = sanitize_email( (string) ( $payload['recovery_email'] ?? '' ) );
        if ( $email === '' || ! is_email( $email ) ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'Please provide a valid recovery email.', 'talenttrack' ) ];
        }
        $password = (string) ( $payload['password'] ?? '' );
        if ( strlen( $password ) < 8 ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'Password must be at least 8 characters.', 'talenttrack' ) ];
        }

        if ( email_exists( $email ) ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'An account with that email already exists. Sign in instead and the invitation will link silently.', 'talenttrack' ) ];
        }

        $first = (string) ( $invitation->prefill_first_name ?? '' );
        $last  = (string) ( $invitation->prefill_last_name  ?? '' );
        $login = $this->generateUniqueLogin( $first, $last, $email );

        $userId = wp_insert_user( [
            'user_login'   => $login,
            'user_pass'    => $password,
            'user_email'   => $email,
            'first_name'   => $first,
            'last_name'    => $last,
            'display_name' => trim( $first . ' ' . $last ) !== '' ? trim( $first . ' ' . $last ) : $login,
            'role'         => $this->resolveWpRoleForKind( (string) $invitation->kind, (string) ( $invitation->target_functional_role_key ?? '' ) ),
        ] );
        if ( is_wp_error( $userId ) ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => (string) $userId->get_error_message() ];
        }

        // Atomic accept-flip — guards two simultaneous clicks.
        if ( ! $this->repo->claimForAcceptance( (int) $invitation->id, (int) $userId ) ) {
            wp_delete_user( (int) $userId );
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'This invitation was just accepted by someone else.', 'talenttrack' ) ];
        }

        $this->runLinking( $invitation, (int) $userId, $payload );

        do_action( 'tt_invitation_accepted', (int) $invitation->id, (string) $invitation->kind, (int) $userId );

        return [ 'ok' => true, 'user_id' => (int) $userId, 'error' => null ];
    }

    /**
     * Silent-link path: the visitor was already logged in and their
     * email matches the invitation's prefill_email. Just run the
     * linking step against their existing user, mark accepted.
     */
    public function silentLink( object $invitation, int $existingUserId ): array {
        if ( (string) $invitation->status !== InvitationStatus::PENDING ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'This invitation is no longer pending.', 'talenttrack' ) ];
        }
        if ( ! $this->repo->claimForAcceptance( (int) $invitation->id, $existingUserId ) ) {
            return [ 'ok' => false, 'user_id' => null, 'error' => __( 'Already accepted.', 'talenttrack' ) ];
        }
        $this->runLinking( $invitation, $existingUserId, [] );
        do_action( 'tt_invitation_accepted', (int) $invitation->id, (string) $invitation->kind, $existingUserId );
        return [ 'ok' => true, 'user_id' => $existingUserId, 'error' => null ];
    }

    // Revoke

    public function revoke( int $invitationId ): bool {
        $row = $this->repo->find( $invitationId );
        if ( ! $row ) return false;
        if ( (string) $row->status !== InvitationStatus::PENDING ) return false;
        $ok = $this->repo->update( $invitationId, [
            'status'      => InvitationStatus::REVOKED,
            'revoked_at'  => current_time( 'mysql' ),
            'revoked_by'  => get_current_user_id(),
        ] );
        if ( $ok ) {
            do_action( 'tt_invitation_revoked', $invitationId );
        }
        return $ok;
    }

    // Linking

    /**
     * Attach the new (or existing) WP user to the right entity row.
     *
     * @param array<string,mixed> $payload
     */
    private function runLinking( object $invitation, int $userId, array $payload ): void {
        global $wpdb;
        $kind = (string) $invitation->kind;
        $playerId = (int) ( $invitation->target_player_id ?? 0 );
        $personId = (int) ( $invitation->target_person_id ?? 0 );

        if ( $kind === InvitationKind::PLAYER && $playerId > 0 ) {
            $jersey = isset( $payload['jersey_number'] ) ? sanitize_text_field( (string) $payload['jersey_number'] ) : '';
            $update = [ 'wp_user_id' => $userId ];
            if ( $jersey !== '' ) {
                $update['jersey_number'] = $jersey;
            }
            $wpdb->update( $wpdb->prefix . 'tt_players', $update, [ 'id' => $playerId, 'club_id' => CurrentClub::id() ] );
        } elseif ( $kind === InvitationKind::PARENT && $playerId > 0 ) {
            $existing = $this->parents->parentsForPlayer( $playerId );
            $isPrimary = empty( $existing );
            $this->parents->link( $playerId, $userId, $isPrimary );
        } elseif ( $kind === InvitationKind::STAFF && $personId > 0 ) {
            $wpdb->update( $wpdb->prefix . 'tt_people', [ 'wp_user_id' => $userId ], [ 'id' => $personId, 'club_id' => CurrentClub::id() ] );
            // Functional-role assignment is left to whoever wires the
            // PeopleModule's assignment surface; the invitation only
            // records the *intent* via target_functional_role_key, the
            // actual `tt_functional_role_assignments` row stays the
            // existing PeopleModule's responsibility on save.
        }
    }

    // Helpers

    /**
     * Locale precedence: target row's `locale` → club default → inviter's WP locale.
     *
     * @param array<string,mixed> $args
     */
    public function resolveLocale( string $kind, array $args ): ?string {
        global $wpdb;

        if ( in_array( $kind, [ InvitationKind::PLAYER, InvitationKind::PARENT ], true )
            && ! empty( $args['target_player_id'] ) ) {
            $row_locale = $wpdb->get_var( $wpdb->prepare(
                "SELECT locale FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
                (int) $args['target_player_id'], CurrentClub::id()
            ) );
            if ( $row_locale && is_string( $row_locale ) && $row_locale !== '' ) {
                return $row_locale;
            }
        }
        if ( $kind === InvitationKind::STAFF && ! empty( $args['target_person_id'] ) ) {
            $row_locale = $wpdb->get_var( $wpdb->prepare(
                "SELECT locale FROM {$wpdb->prefix}tt_people WHERE id = %d AND club_id = %d",
                (int) $args['target_person_id'], CurrentClub::id()
            ) );
            if ( $row_locale && is_string( $row_locale ) && $row_locale !== '' ) {
                return $row_locale;
            }
        }

        $club_default = $this->config->get( 'invite_default_locale', '' );
        if ( $club_default !== '' ) return $club_default;

        $inviter = get_current_user_id();
        if ( $inviter > 0 && function_exists( 'get_user_locale' ) ) {
            return get_user_locale( $inviter );
        }
        return get_locale();
    }

    /**
     * Render the WhatsApp / share message for a given invitation by
     * expanding placeholders against the resolved locale's template.
     */
    public function renderShareMessage( object $invitation ): string {
        $locale   = (string) ( $invitation->locale ?? $this->config->get( 'invite_default_locale', 'nl_NL' ) );
        $kind     = (string) $invitation->kind;
        $template = $this->config->get( "invite_message_{$kind}_{$locale}", '' );
        if ( $template === '' ) {
            // Fall back to en_US if a locale's row was wiped.
            $template = $this->config->get( "invite_message_{$kind}_en_US", '' );
        }

        $club    = $this->config->get( 'academy_name', 'TalentTrack' );
        $sender  = $this->displayNameFor( (int) $invitation->created_by );
        $url     = $this->acceptanceUrl( (string) $invitation->token );
        $ttl     = max( 1, (int) ceil( ( strtotime( (string) $invitation->expires_at ) - time() ) / 86400 ) );

        $player = '';
        $team   = '';
        $role   = InvitationKind::label( $kind );
        if ( ! empty( $invitation->target_player_id ) ) {
            $player = $this->playerName( (int) $invitation->target_player_id );
        }
        if ( ! empty( $invitation->target_team_id ) ) {
            $team = $this->teamName( (int) $invitation->target_team_id );
        }

        return strtr( $template, [
            '{club}'     => $club,
            '{role}'     => $role,
            '{team}'     => $team,
            '{player}'   => $player,
            '{sender}'   => $sender,
            '{url}'      => $url,
            '{ttl_days}' => (string) $ttl,
        ] );
    }

    public function acceptanceUrl( string $token ): string {
        // Frontend route on the dashboard shortcode page. ConfigService
        // stores the dashboard page id; if it's not set, fall back to home.
        $page_id = (int) $this->config->getInt( 'dashboard_page_id', 0 );
        $base = $page_id > 0 ? get_permalink( $page_id ) : home_url( '/' );
        if ( ! is_string( $base ) || $base === '' ) {
            $base = home_url( '/' );
        }
        return add_query_arg( [ 'tt_view' => 'accept-invite', 'token' => $token ], $base );
    }

    private function resolveWpRoleForKind( string $kind, string $functionalRoleKey ): string {
        if ( $kind === InvitationKind::PLAYER ) return 'tt_player';
        if ( $kind === InvitationKind::PARENT ) return 'tt_parent';
        // Staff: map the functional role key to a WP role. Default to tt_staff.
        if ( $kind === InvitationKind::STAFF ) {
            $map = (array) apply_filters( 'tt_invitation_staff_role_map', [
                'head_coach'      => 'tt_coach',
                'assistant_coach' => 'tt_coach',
                'team_manager'    => 'tt_staff',
                'physio'          => 'tt_staff',
                'scout'           => 'tt_scout',
                'head_dev'        => 'tt_head_dev',
                'club_admin'      => 'tt_club_admin',
            ] );
            if ( isset( $map[ $functionalRoleKey ] ) ) {
                return (string) $map[ $functionalRoleKey ];
            }
            return 'tt_staff';
        }
        return 'tt_player';
    }

    private function generateUniqueLogin( string $first, string $last, string $email ): string {
        $base = sanitize_user( strtolower( $first . $last ), true );
        if ( $base === '' ) {
            $base = sanitize_user( strtolower( strstr( $email, '@', true ) ?: 'user' ), true );
        }
        if ( $base === '' ) {
            $base = 'user';
        }
        $candidate = $base;
        $i = 1;
        while ( username_exists( $candidate ) ) {
            $candidate = $base . $i;
            $i++;
            if ( $i > 999 ) {
                $candidate = $base . wp_generate_password( 6, false, false );
                break;
            }
        }
        return $candidate;
    }

    private function displayNameFor( int $userId ): string {
        $u = get_userdata( $userId );
        return $u ? (string) $u->display_name : '';
    }

    private function playerName( int $playerId ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $playerId, CurrentClub::id()
        ) );
        if ( ! $row ) return '';
        return trim( (string) $row->first_name . ' ' . (string) $row->last_name );
    }

    private function teamName( int $teamId ): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
            $teamId, CurrentClub::id()
        ) );
        return $name ? (string) $name : '';
    }
}
