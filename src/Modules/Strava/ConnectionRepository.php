<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\CredentialEncryption;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ConnectionRepository (#2056) — per-player Strava connection store.
 *
 * Owns `tt_player_strava_connections`. Unlike Spond's single club-wide
 * service account, Strava is connected per athlete, so tokens are keyed
 * by player. Access + refresh tokens are stored as `CredentialEncryption`
 * envelopes (never plaintext) and never round-trip out of a REST
 * response.
 *
 * Strava rotates the refresh token on every refresh and kills the old
 * one immediately, so a torn write would lock the player out. Every
 * token mutation here is a single-row UPDATE/INSERT — the access token,
 * rotated refresh token, and new expiry land atomically (#2057 leans on
 * this).
 *
 * Every query is `club_id`-scoped (CLAUDE.md §4).
 */
final class ConnectionRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_player_strava_connections';
    }

    public function findByPlayerId( int $player_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE player_id = %d AND club_id = %d LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Lookup by Strava athlete id — the webhook payload carries the
     * athlete (`owner_id`), not our player id (#2059). Athlete-scoped,
     * still club-filtered.
     */
    public function findByAthleteId( int $athlete_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE strava_athlete_id = %d AND club_id = %d LIMIT 1",
            $athlete_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Resolve a connection by Strava athlete id across ALL clubs. The
     * webhook fires unauthenticated with no club context and the athlete
     * id is globally unique at Strava, so the resolver can't assume the
     * current tenant — the caller then pins `club_id` from the returned
     * row before doing any club-scoped work (#2059).
     */
    public function findByAthleteIdAnyClub( int $athlete_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE strava_athlete_id = %d ORDER BY id DESC LIMIT 1",
            $athlete_id
        ) );
        return $row ?: null;
    }

    /**
     * Create or refresh a player's connection after a successful OAuth
     * exchange. Tokens are encrypted here. Returns the connection id.
     */
    public function connect( int $player_id, array $data ): int {
        global $wpdb;
        $now      = current_time( 'mysql' );
        $existing = $this->findByPlayerId( $player_id );

        $fields = [
            'strava_athlete_id' => isset( $data['athlete_id'] ) ? (int) $data['athlete_id'] : null,
            'access_token_enc'  => CredentialEncryption::encrypt( (string) ( $data['access_token'] ?? '' ) ),
            'refresh_token_enc' => CredentialEncryption::encrypt( (string) ( $data['refresh_token'] ?? '' ) ),
            'token_expires_at'  => $this->expiryToMysql( (int) ( $data['expires_at'] ?? 0 ) ),
            'scope'             => (string) ( $data['scope'] ?? StravaConfig::SCOPE ),
            'status'            => 'connected',
            'connected_at'      => $now,
            'updated_at'        => $now,
        ];

        if ( $existing ) {
            $wpdb->update( $this->table(), $fields, [ 'id' => (int) $existing->id ] );
            return (int) $existing->id;
        }

        $fields['player_id']  = $player_id;
        $fields['club_id']    = CurrentClub::id();
        $fields['uuid']       = wp_generate_uuid4();
        $fields['created_at'] = $now;
        $wpdb->insert( $this->table(), $fields );
        return (int) $wpdb->insert_id;
    }

    /**
     * Atomically persist a rotated token set (#2057). Single UPDATE so
     * the access token, the new refresh token, and the expiry never tear.
     */
    public function rotateTokens( int $player_id, string $access_token, string $refresh_token, int $expires_at ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [
                'access_token_enc'  => CredentialEncryption::encrypt( $access_token ),
                'refresh_token_enc' => CredentialEncryption::encrypt( $refresh_token ),
                'token_expires_at'  => $this->expiryToMysql( $expires_at ),
                'status'            => 'connected',
                'updated_at'        => current_time( 'mysql' ),
            ],
            [ 'player_id' => $player_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    public function getAccessToken( int $player_id ): string {
        $row = $this->findByPlayerId( $player_id );
        if ( ! $row || $row->access_token_enc === null ) return '';
        return (string) CredentialEncryption::decrypt( (string) $row->access_token_enc );
    }

    public function getRefreshToken( int $player_id ): string {
        $row = $this->findByPlayerId( $player_id );
        if ( ! $row || $row->refresh_token_enc === null ) return '';
        return (string) CredentialEncryption::decrypt( (string) $row->refresh_token_enc );
    }

    public function markStatus( int $player_id, string $status ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ],
            [ 'player_id' => $player_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Disconnect: clear the encrypted tokens and flag the row. The row
     * itself is kept (athlete id + history) so a reconnect is clean and
     * the deauth audit trail survives.
     */
    public function disconnect( int $player_id, string $status = 'disconnected' ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [
                'access_token_enc'  => null,
                'refresh_token_enc' => null,
                'token_expires_at'  => null,
                'status'            => $status,
                'updated_at'        => current_time( 'mysql' ),
            ],
            [ 'player_id' => $player_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    public function touchSync( int $player_id ): void {
        global $wpdb;
        $wpdb->update(
            $this->table(),
            [ 'last_sync_at' => current_time( 'mysql' ) ],
            [ 'player_id' => $player_id, 'club_id' => CurrentClub::id() ]
        );
    }

    private function expiryToMysql( int $unix ): ?string {
        return $unix > 0 ? gmdate( 'Y-m-d H:i:s', $unix ) : null;
    }
}
