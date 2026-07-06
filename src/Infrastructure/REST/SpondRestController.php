<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Spond\CredentialsManager;
use TT\Modules\Spond\SpondClient;
use TT\Modules\Spond\SpondParser;
use TT\Modules\Spond\SpondSync;
use TT\Modules\Spond\SpondTypeResolver;
use TT\Modules\Spond\TeamSpondAccount;

/**
 * SpondRestController (#0031, extended #1936) — REST surface for the
 * Spond integration.
 *
 *   POST /teams/{id}/spond/sync       — sync one team (existing, #0031)
 *   POST /spond/credentials           — save email + password (#1936)
 *   POST /spond/test                  — live login check (#1936)
 *   DELETE /spond/credentials         — disconnect / clear (#1936)
 *   POST /spond/base-url              — override / revert API base URL (#1936)
 *   POST /teams/{id}/spond/credentials   — save per-team override (#2286)
 *   DELETE /teams/{id}/spond/credentials — clear per-team override (#2286)
 *   POST /teams/{id}/spond/test          — live login for a team override (#2286)
 *
 * The per-team sync stays gated on `tt_edit_teams` (it edits team rows).
 * Credential + base-url mutations gate on `tt_edit_spond_credentials`
 * (the matrix `spond_integration → change` cap) — never a role-string
 * compare, never `__return_true`.
 *
 * Controllers stay thin: the encryption, keep-on-blank password, live
 * login, and override-write logic all live in `CredentialsManager` /
 * `SpondClient` / `QueryHelpers`. The frontend Spond view
 * (`?tt_view=spond`, FrontendSpondView) and the wp-admin page call the
 * same domain layer, so a future SaaS frontend gets identical behaviour.
 *
 * Secrets never round-trip: the password is accepted on save/test and
 * stored encrypted, but is never returned in any response. Test only
 * reports ok / a non-sensitive error message.
 */
final class SpondRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/spond/sync', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'syncTeam' ],
            'permission_callback' => [ __CLASS__, 'canEdit' ],
        ] );

        // #2284 — dry-run preview for the Spond integration monitor.
        // Fetches Spond live, parses + classifies, and diffs each event
        // against the stored tt_activities row WITHOUT writing anything.
        // Gated on the same tt_edit_teams cap the sync route uses.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/spond/preview', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_preview' ],
            'permission_callback' => [ __CLASS__, 'canEdit' ],
        ] );

        // #2286 — per-team Spond account override. Same credential cap as
        // the club routes (tt_edit_spond_credentials); a set override
        // overrules the club account for that team's syncs. The password
        // and cached token never round-trip.
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/spond/credentials', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'route_save_team_credentials' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'route_delete_team_credentials' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
        ] );

        register_rest_route( self::NS, '/teams/(?P<id>\d+)/spond/test', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_test_team_credentials' ],
            'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
        ] );

        register_rest_route( self::NS, '/spond/credentials', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'saveCredentials' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'clearCredentials' ],
                'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
            ],
        ] );

        register_rest_route( self::NS, '/spond/test', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'testConnection' ],
            'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
        ] );

        register_rest_route( self::NS, '/spond/base-url', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'saveBaseUrl' ],
            'permission_callback' => [ __CLASS__, 'canEditCredentials' ],
        ] );
    }

    public static function canEdit(): bool {
        return current_user_can( 'tt_edit_teams' );
    }

    public static function canEditCredentials(): bool {
        return current_user_can( 'tt_edit_spond_credentials' );
    }

    public static function syncTeam( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        $result = SpondSync::syncTeam( $team_id );
        return RestResponse::success( $result );
    }

    /** Activity types that carry kickoff + presence times (mirrors SpondSync). */
    private const MATCH_TYPES = [ 'game', 'match', 'friendly', 'tournament' ];

    /**
     * Dry-run preview of what a Spond sync would do for one team (#2284).
     *
     * Read-only: fetches Spond live, parses + classifies, and diffs each
     * incoming event against the stored tt_activities row. NEVER writes to
     * the DB — it's a diagnostic surface to explain "why does the printed
     * activity differ from what I set in Spond". The diff mirrors
     * SpondSync's Spond-wins field set and its UTC→site-local time
     * conversion, so the preview matches what a real sync would store.
     */
    public static function route_preview( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();

        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, spond_group_id FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
            $team_id, $club
        ) );
        if ( ! $team ) {
            return RestResponse::notFound(
                'team_not_found',
                __( 'Team not found.', 'talenttrack' )
            );
        }

        $group_id = (string) ( $team->spond_group_id ?? '' );
        if ( $group_id === '' ) {
            // 200 envelope with ok:false so the UI can render the reason
            // inline rather than treating it as a hard HTTP failure.
            return RestResponse::success( [
                'ok'            => false,
                'error_code'    => 'no_group_linked',
                'error_message' => __( 'This team has no Spond group linked. Pick a group on the team edit form first.', 'talenttrack' ),
            ] );
        }

        // #2286 — preview through the team's own Spond account when set,
        // else the club account (same resolution a real sync uses).
        $fetch = SpondClient::fetchEvents( $group_id, CredentialsManager::forTeam( $team_id ) );
        if ( empty( $fetch['ok'] ) ) {
            return RestResponse::success( [
                'ok'            => false,
                'group_id'      => $group_id,
                'error_code'    => (string) ( $fetch['error_code']    ?? 'fetch_failed' ),
                'error_message' => (string) ( $fetch['error_message'] ?? __( 'Could not fetch events from Spond.', 'talenttrack' ) ),
                'http_code'     => (int)    ( $fetch['http_code']     ?? 0 ),
            ] );
        }

        $parsed  = SpondParser::parse( (array) ( $fetch['events'] ?? [] ) );
        $events  = [];
        $seen    = [];
        $counts  = [ 'new' => 0, 'update' => 0, 'archive' => 0 ];

        foreach ( $parsed as $event ) {
            $uid = (string) ( $event['uid'] ?? '' );
            if ( $uid === '' ) continue;
            $seen[ $uid ] = true;

            $title       = (string) ( $event['summary'] ?? '' );
            $location    = (string) ( $event['location'] ?? '' );
            $description = (string) ( $event['description'] ?? '' );
            $dtstart     = (string) ( $event['dtstart'] ?? '' );
            $dtend       = (string) ( $event['dtend'] ?? '' );
            $meetup      = (string) ( $event['meetup'] ?? '' );

            $type = SpondTypeResolver::classify( $title, $description );

            // Local-time conversion mirrors SpondSync::localParts().
            [ , $start_time ] = self::localParts( $dtstart );
            [ , $end_time ]   = self::localParts( $dtend );
            [ , $meet_time ]  = self::localParts( $meetup );

            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, title, location, activity_type_key, session_date,
                        start_time, end_time, kickoff_time, time_of_presence
                   FROM {$p}tt_activities
                  WHERE external_id = %s
                    AND activity_source_key = %s
                    AND club_id = %d
                  LIMIT 1",
                $uid, 'spond', $club
            ) );

            if ( $existing ) {
                $status  = 'update';
                $changes = self::diffChanges(
                    $existing, $title, $location, $start_time, $end_time, $meet_time
                );
                $counts['update']++;
            } else {
                $status  = 'new';
                $changes = [];
                $counts['new']++;
            }

            $events[] = [
                'uid'         => $uid,
                'summary'     => $title,
                'type'        => $type,
                'dtstart'     => $dtstart,
                'dtend'       => $dtend,
                'meetup'      => $meetup,
                'start_time'  => $start_time,
                'end_time'    => $end_time,
                'meet_time'   => $meet_time,
                'location'    => $location,
                'description' => $description,
                'status'      => $status,
                'activity_id' => $existing ? (int) $existing->id : null,
                'changes'     => $changes,
            ];
        }

        // Archive candidates: stored Spond rows for this team, not
        // archived, whose external_id is no longer in the fetched set.
        $archive = [];
        $rows    = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, external_id
               FROM {$p}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND activity_source_key = 'spond'
                AND archived_at IS NULL",
            $team_id, $club
        ) );
        foreach ( (array) $rows as $row ) {
            $ext = (string) ( $row->external_id ?? '' );
            if ( $ext !== '' && isset( $seen[ $ext ] ) ) continue;
            $archive[] = [
                'activity_id' => (int) $row->id,
                'title'       => (string) ( $row->title ?? '' ),
            ];
        }
        $counts['archive'] = count( $archive );

        return RestResponse::success( [
            'ok'            => true,
            'group_id'      => $group_id,
            'fetched_count' => count( $parsed ),
            'counts'        => $counts,
            'events'        => $events,
            'archive'       => $archive,
        ] );
    }

    /**
     * Build the field-diff list for an existing activity row against the
     * incoming Spond values. Compares only the Spond-wins fields SpondSync
     * overwrites: title, location, and the local-time columns
     * (start / end / kickoff / presence). activity_type_key and notes are
     * TalentTrack-wins after first import, so they are deliberately not
     * diffed here. A change is emitted only when the stored value actually
     * differs from what the sync would write.
     *
     * @return list<array{field:string,from:string,to:string}>
     */
    private static function diffChanges(
        object $existing,
        string $title,
        string $location,
        string $start_time,
        string $end_time,
        string $meet_time
    ): array {
        $changes = [];

        $compare = static function ( string $field, string $from, string $to ) use ( &$changes ): void {
            if ( trim( $from ) !== trim( $to ) ) {
                $changes[] = [ 'field' => $field, 'from' => $from, 'to' => $to ];
            }
        };

        $compare( 'title',    (string) ( $existing->title ?? '' ),    $title );
        $compare( 'location', (string) ( $existing->location ?? '' ), $location );

        // start_time / end_time are stored for every activity type.
        $compare( 'start_time', self::hhmm( (string) ( $existing->start_time ?? '' ) ), self::hhmm( $start_time ) );
        $compare( 'end_time',   self::hhmm( (string) ( $existing->end_time   ?? '' ) ), self::hhmm( $end_time ) );

        // kickoff_time / time_of_presence only apply to match-type rows —
        // exactly the rows SpondSync writes those columns for.
        $type = (string) ( $existing->activity_type_key ?? '' );
        if ( in_array( $type, self::MATCH_TYPES, true ) ) {
            $compare( 'kickoff_time',     self::hhmm( (string) ( $existing->kickoff_time     ?? '' ) ), self::hhmm( $start_time ) );
            $compare( 'time_of_presence', self::hhmm( (string) ( $existing->time_of_presence ?? '' ) ), self::hhmm( $meet_time ) );
        }

        return $changes;
    }

    /** Normalise a stored/incoming time to HH:MM for a stable comparison. */
    private static function hhmm( string $time ): string {
        $time = trim( $time );
        if ( $time === '' ) return '';
        // Accept "HH:MM:SS" or "HH:MM" — compare on HH:MM.
        if ( preg_match( '/^(\d{1,2}):(\d{2})/', $time, $m ) ) {
            return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
        }
        return $time;
    }

    /**
     * Split a UTC `Y-m-d H:i:s` into a local `[ date, time ]` pair using
     * the site timezone — identical to SpondSync::localParts() so the
     * preview's times match what a real sync would store. Returns
     * `[ '', '' ]` for empty / unparseable input.
     *
     * @return array{0:string,1:string}
     */
    private static function localParts( string $utc ): array {
        $utc = trim( $utc );
        if ( $utc === '' ) return [ '', '' ];
        try {
            $dt = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
            $dt = $dt->setTimezone( wp_timezone() );
            return [ $dt->format( 'Y-m-d' ), $dt->format( 'H:i:s' ) ];
        } catch ( \Exception $e ) {
            return [ '', '' ];
        }
    }

    /**
     * Save (or rotate) the per-club Spond credentials. A blank password
     * keeps the stored one — mirrors the wp-admin "leave blank to keep"
     * behaviour. The password is encrypted at rest by CredentialsManager
     * and never echoed back; the response only reports the email + the
     * connected flag.
     */
    public static function saveCredentials( \WP_REST_Request $r ): \WP_REST_Response {
        $email    = sanitize_email( (string) ( $r->get_param( 'email' ) ?? '' ) );
        $password = trim( (string) ( $r->get_param( 'password' ) ?? '' ) );

        if ( $email === '' ) {
            return RestResponse::error(
                'email_required',
                __( 'A Spond email address is required.', 'talenttrack' ),
                422
            );
        }

        if ( $password === '' && CredentialsManager::hasCredentials() ) {
            $password = CredentialsManager::getPassword();
        }

        if ( $password === '' ) {
            return RestResponse::error(
                'password_required',
                __( 'A Spond password is required.', 'talenttrack' ),
                422
            );
        }

        CredentialsManager::save( $email, $password );
        Logger::info( 'rest.spond.credentials_saved', [ 'user' => get_current_user_id() ] );

        return RestResponse::success( [
            'email'     => CredentialsManager::getEmail(),
            'connected' => CredentialsManager::hasCredentials(),
        ] );
    }

    /**
     * Live login check against Spond via SpondClient. Uses the posted
     * email + password when present, otherwise the stored credentials.
     * On success caches the token so the next sync skips a login. The
     * response never contains the password or the token — only ok /
     * a non-sensitive error message.
     */
    public static function testConnection( \WP_REST_Request $r ): \WP_REST_Response {
        $email = CredentialsManager::getEmail();
        $posted_email = sanitize_email( (string) ( $r->get_param( 'email' ) ?? '' ) );
        if ( $posted_email !== '' ) {
            $email = $posted_email;
        }

        $password = trim( (string) ( $r->get_param( 'password' ) ?? '' ) );
        if ( $password === '' ) {
            $password = CredentialsManager::getPassword();
        }

        $result = SpondClient::login( $email, $password );

        if ( ! empty( $result['ok'] ) ) {
            CredentialsManager::cacheToken( (string) $result['token'] );
            Logger::info( 'rest.spond.test_ok', [ 'user' => get_current_user_id() ] );
            return RestResponse::success( [ 'ok' => true ] );
        }

        Logger::warning( 'rest.spond.test_failed', [
            'user' => get_current_user_id(),
            'code' => (string) ( $result['error_code'] ?? 'login_failed' ),
        ] );
        return RestResponse::error(
            (string) ( $result['error_code'] ?? 'login_failed' ),
            (string) ( $result['error_message'] ?? __( 'Spond login failed.', 'talenttrack' ) ),
            422
        );
    }

    /**
     * Disconnect — clear the stored credentials + cached token. Per-team
     * group selections are kept on file (they live on tt_teams), so a
     * reconnect resumes syncing without re-picking groups.
     */
    public static function clearCredentials( \WP_REST_Request $r ): \WP_REST_Response {
        CredentialsManager::clear();
        Logger::info( 'rest.spond.credentials_cleared', [ 'user' => get_current_user_id() ] );
        return RestResponse::success( [ 'connected' => false ] );
    }

    /**
     * Override / revert the Spond API base URL. An empty value clears
     * the override and reverts to SpondClient::DEFAULT_BASE_URL. A
     * non-empty value must look like an http(s) URL.
     */
    public static function saveBaseUrl( \WP_REST_Request $r ): \WP_REST_Response {
        $raw       = trim( (string) ( $r->get_param( 'api_base_url' ) ?? '' ) );
        $sanitised = $raw === '' ? '' : esc_url_raw( $raw );

        if ( $sanitised !== '' && ! preg_match( '#^https?://[^\s]+$#i', $sanitised ) ) {
            return RestResponse::error(
                'invalid_url',
                __( 'That URL does not look like a valid http(s) endpoint.', 'talenttrack' ),
                422
            );
        }

        QueryHelpers::set_config( 'spond.api_base_url', $sanitised );
        Logger::info( 'rest.spond.base_url_saved', [
            'user'    => get_current_user_id(),
            'cleared' => $sanitised === '',
        ] );

        return RestResponse::success( [
            'base_url'   => SpondClient::baseUrl(),
            'is_default' => $sanitised === '',
        ] );
    }

    /**
     * Resolve a club-scoped team id, returning null (and letting the caller
     * emit a 404) when it doesn't exist for the current club. Mirrors the
     * lookup route_preview does before touching the team.
     */
    private static function teamExists( int $team_id ): bool {
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d",
            $team_id, CurrentClub::id()
        ) );
        return ! empty( $found );
    }

    /**
     * Save (or rotate) a per-team Spond account override (#2286). A blank
     * email clears the override entirely (team falls back to the club
     * account); a non-empty email with a blank password keeps the stored
     * password — TeamSpondAccount::save handles both. The password is
     * encrypted at rest and never echoed; the response reports only the
     * stored email, the connected flag, and override:true.
     */
    public static function route_save_team_credentials( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        if ( ! self::teamExists( $team_id ) ) {
            return RestResponse::notFound( 'team_not_found', __( 'Team not found.', 'talenttrack' ) );
        }

        $email    = sanitize_email( (string) ( $r->get_param( 'email' ) ?? '' ) );
        $password = trim( (string) ( $r->get_param( 'password' ) ?? '' ) );

        $account = new TeamSpondAccount( $team_id );
        $account->save( $email, $password );

        Logger::info( 'rest.spond.team_credentials_saved', [
            'user' => get_current_user_id(),
            'team' => $team_id,
        ] );

        return RestResponse::success( [
            'ok'        => true,
            'email'     => $account->getEmail(),
            'connected' => $account->hasCredentials(),
            'override'  => true,
        ] );
    }

    /**
     * Clear a per-team Spond override (#2286) — the team reverts to the
     * club account.
     */
    public static function route_delete_team_credentials( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        if ( ! self::teamExists( $team_id ) ) {
            return RestResponse::notFound( 'team_not_found', __( 'Team not found.', 'talenttrack' ) );
        }

        ( new TeamSpondAccount( $team_id ) )->clear();
        Logger::info( 'rest.spond.team_credentials_cleared', [
            'user' => get_current_user_id(),
            'team' => $team_id,
        ] );

        return RestResponse::success( [ 'ok' => true, 'override' => false ] );
    }

    /**
     * Live login check for a per-team override (#2286). Uses the posted
     * email + password when present, otherwise the team's stored override
     * credentials. On success caches the token on the team account so the
     * next sync skips a login. The response never contains the password or
     * the token — only ok / a non-sensitive error message.
     */
    public static function route_test_team_credentials( \WP_REST_Request $r ): \WP_REST_Response {
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        if ( ! self::teamExists( $team_id ) ) {
            return RestResponse::notFound( 'team_not_found', __( 'Team not found.', 'talenttrack' ) );
        }

        $account = new TeamSpondAccount( $team_id );

        $email        = $account->getEmail();
        $posted_email = sanitize_email( (string) ( $r->get_param( 'email' ) ?? '' ) );
        if ( $posted_email !== '' ) {
            $email = $posted_email;
        }

        $password = trim( (string) ( $r->get_param( 'password' ) ?? '' ) );
        if ( $password === '' ) {
            $password = $account->getPassword();
        }

        $result = SpondClient::login( $email, $password );

        if ( ! empty( $result['ok'] ) ) {
            $account->cacheToken( (string) $result['token'], CredentialsManager::TOKEN_CACHE_SECONDS );
            Logger::info( 'rest.spond.team_test_ok', [
                'user' => get_current_user_id(),
                'team' => $team_id,
            ] );
            return RestResponse::success( [ 'ok' => true ] );
        }

        Logger::warning( 'rest.spond.team_test_failed', [
            'user' => get_current_user_id(),
            'team' => $team_id,
            'code' => (string) ( $result['error_code'] ?? 'login_failed' ),
        ] );
        return RestResponse::error(
            (string) ( $result['error_code'] ?? 'login_failed' ),
            (string) ( $result['error_message'] ?? __( 'Spond login failed.', 'talenttrack' ) ),
            422
        );
    }
}
