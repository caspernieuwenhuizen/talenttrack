<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondAccount (#2286) — the resolved Spond login used to authenticate a
 * fetch. Either the club-wide account ({@see ClubSpondAccount}) or a
 * per-team override ({@see TeamSpondAccount}). SpondClient authenticates
 * against whichever it's handed, so per-team credentials "overrule" the
 * club account without the client caring which it is.
 *
 * The read/token surface mirrors the club CredentialsManager so both flavours
 * are interchangeable; each keeps its own encrypted token cache so a team
 * override never reuses the club's token (different account = different JWT).
 */
interface SpondAccount {

    /** True when both an email and a password are stored. */
    public function hasCredentials(): bool;

    public function getEmail(): string;

    /** Decrypted password (empty string when unset / undecryptable). */
    public function getPassword(): string;

    /** A still-valid cached JWT, or '' when missing / expired. */
    public function getCachedToken(): string;

    public function cacheToken( string $token, int $ttl_seconds ): void;

    public function clearToken(): void;

    /**
     * True when this is a per-team override, false for the club fallback.
     * Lets the UI show which account a team actually resolves to.
     */
    public function isTeamOverride(): bool;
}
