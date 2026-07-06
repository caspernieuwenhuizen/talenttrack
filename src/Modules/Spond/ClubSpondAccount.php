<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ClubSpondAccount (#2286) — the club-wide Spond account, backed by the
 * existing static {@see CredentialsManager} (credentials + token cache in
 * club-scoped tt_config). This is the fallback a team uses when it has no
 * per-team override.
 */
final class ClubSpondAccount implements SpondAccount {

    public function hasCredentials(): bool {
        return CredentialsManager::hasCredentials();
    }

    public function getEmail(): string {
        return CredentialsManager::getEmail();
    }

    public function getPassword(): string {
        return CredentialsManager::getPassword();
    }

    public function getCachedToken(): string {
        return CredentialsManager::getCachedToken();
    }

    public function cacheToken( string $token, int $ttl_seconds ): void {
        CredentialsManager::cacheToken( $token, $ttl_seconds );
    }

    public function clearToken(): void {
        CredentialsManager::clearToken();
    }

    public function isTeamOverride(): bool {
        return false;
    }
}
