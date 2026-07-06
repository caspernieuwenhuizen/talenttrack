<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\CredentialEncryption;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamSpondAccount (#2286) — a per-team Spond override, backed by the
 * tt_teams columns added in migration 0197 (spond_email, spond_password_enc,
 * spond_token_enc, spond_token_expires_at). Club-scoped. The email is
 * plaintext (not secret alone); password + cached token are
 * CredentialEncryption envelopes. Each team keeps its OWN token cache so an
 * override never reuses the club's JWT.
 *
 * When a team has no email+password stored, {@see CredentialsManager::forTeam}
 * hands back the {@see ClubSpondAccount} instead, so this class only ever
 * represents a genuine override.
 */
final class TeamSpondAccount implements SpondAccount {

    private int $team_id;

    public function __construct( int $team_id ) {
        $this->team_id = $team_id;
    }

    private function row(): ?object {
        global $wpdb;
        if ( $this->team_id <= 0 ) return null;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT spond_email, spond_password_enc, spond_token_enc, spond_token_expires_at
               FROM {$wpdb->prefix}tt_teams
              WHERE id = %d AND club_id = %d",
            $this->team_id, CurrentClub::id()
        ) );
    }

    public function hasCredentials(): bool {
        return $this->getEmail() !== '' && $this->getPassword() !== '';
    }

    public function getEmail(): string {
        $row = $this->row();
        return $row ? (string) ( $row->spond_email ?? '' ) : '';
    }

    public function getPassword(): string {
        $row = $this->row();
        $enc = $row ? (string) ( $row->spond_password_enc ?? '' ) : '';
        if ( $enc === '' ) return '';
        return (string) CredentialEncryption::decrypt( $enc );
    }

    public function getCachedToken(): string {
        $row = $this->row();
        if ( ! $row ) return '';
        $expires = (int) ( $row->spond_token_expires_at ?? 0 );
        if ( $expires <= time() ) return '';
        $enc = (string) ( $row->spond_token_enc ?? '' );
        if ( $enc === '' ) return '';
        return (string) CredentialEncryption::decrypt( $enc );
    }

    public function cacheToken( string $token, int $ttl_seconds = CredentialsManager::TOKEN_CACHE_SECONDS ): void {
        $this->update( [
            'spond_token_enc'        => $token === '' ? null : (string) CredentialEncryption::encrypt( $token ),
            'spond_token_expires_at' => $token === '' ? null : ( time() + max( 60, $ttl_seconds ) ),
        ] );
    }

    public function clearToken(): void {
        $this->update( [ 'spond_token_enc' => null, 'spond_token_expires_at' => null ] );
    }

    public function isTeamOverride(): bool {
        return true;
    }

    /**
     * Persist (or rotate) the per-team account. An empty email+password pair
     * clears the override entirely (so the team falls back to the club
     * account); a non-empty email with an empty password keeps the stored
     * password — mirroring the club "leave blank to keep" behaviour. Any
     * credential write clears the cached token.
     */
    public function save( string $email, string $password ): void {
        $email = trim( $email );
        if ( $email === '' ) {
            $this->clear();
            return;
        }
        $data = [
            'spond_email'            => $email,
            'spond_token_enc'        => null,
            'spond_token_expires_at' => null,
        ];
        if ( $password !== '' ) {
            $data['spond_password_enc'] = (string) CredentialEncryption::encrypt( $password );
        }
        $this->update( $data );
    }

    /** Remove the override — team reverts to the club account. */
    public function clear(): void {
        $this->update( [
            'spond_email'            => null,
            'spond_password_enc'     => null,
            'spond_token_enc'        => null,
            'spond_token_expires_at' => null,
        ] );
    }

    /** @param array<string,mixed> $data */
    private function update( array $data ): void {
        global $wpdb;
        if ( $this->team_id <= 0 ) return;
        $wpdb->update(
            "{$wpdb->prefix}tt_teams",
            $data,
            [ 'id' => $this->team_id, 'club_id' => CurrentClub::id() ]
        );
    }
}
