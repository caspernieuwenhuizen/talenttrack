<?php
namespace TT\Shared\Sharing;

use TT\Infrastructure\Tenancy\CurrentClub;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ShareViewRecorder (#3096) — counts the people who opened a share link,
 * without learning who they are.
 *
 * A share page has no session and no capability check; the signed URL is
 * the authorisation. So "has anyone read this?" has to be answered without
 * an identity, and the honest way to do that is to count browsers and say
 * so.
 *
 * ## How a visitor is recognised
 *
 * First choice is a **first-party cookie** holding a random id, scoped to
 * one subject. It is set on first open and read on every later one, so a
 * coach refreshing the page they shared does not inflate their own count.
 * The cookie carries no cross-site identifier and exists purely to avoid
 * double-counting, which is what makes it strictly functional — no consent
 * banner, and that reasoning is written into `docs/match-analysis.md`
 * rather than left implicit here.
 *
 * Where a cookie cannot be set or read — headers already sent, a browser
 * refusing them — the fallback is `hash_hmac` over ip|user-agent, keyed on
 * `wp_salt('auth')` plus a per-subject seed. **Neither the IP nor the
 * user-agent is stored**, in that hash or beside it; the hash is one-way
 * and cannot be recomputed without the install's salt.
 *
 * The imprecision this buys is real and worth stating: one person on a
 * phone and a laptop counts twice, and two people behind one club NAT
 * running the same browser version can collapse into one on the fallback
 * path. A count of browsers is what is being offered, and the surface's
 * wording is chosen to match.
 *
 * ## What is never recorded
 *
 * - **A failed probe.** `record()` is called only after the token resolves.
 *   Recording an invalid, revoked or tampered URL would turn this table
 *   into the oracle that `renderShareNotFound()`'s identical wording exists
 *   to deny.
 * - **A fetch that is not a person.** Link unfurlers in WhatsApp, Slack and
 *   the like open every URL pasted into a chat, and counting them would
 *   report an audience that never existed. Bots by user-agent and any
 *   non-GET request are skipped.
 */
final class ShareViewRecorder {

    /** Subjects that mint share URLs from the same HMAC construction. */
    public const SUBJECT_MATCH_ANALYSIS = 'match_analysis';
    public const SUBJECT_MATCH_PREP     = 'match_prep';
    public const SUBJECT_TEAM_BLUEPRINT = 'team_blueprint';

    /** Cookie lifetime. Long enough that a staff member re-reading a link */
    /** next week is still the same person; short enough to expire well */
    /** inside the row's own 90-day retention. */
    private const COOKIE_DAYS = 60;

    /**
     * Mint the visitor cookie, if this browser has not got one for this
     * link yet.
     *
     * Split out from `record()` and called on `template_redirect` because
     * of where the share page renders: inside `the_content`, long after the
     * response headers have gone out. `setcookie()` there is a no-op, so a
     * recorder that only tried at render time would fall through to the
     * IP+UA path on every single visit — the exact opposite of the
     * decision, and a strictly worse answer.
     *
     * Keyed on the link's uuid, which is what both call sites have in
     * common: the guard reads it off the URL before anything is verified,
     * and the recorder gets it back on the resolved analysis.
     *
     * Runs before the token is checked, and deliberately does not care
     * whether it is valid. Every request to a share URL gets a cookie, so
     * the response to a forged link is indistinguishable from the response
     * to a real one — and nothing is written to the database either way.
     */
    public static function primeCookie( string $scope_key ): void {
        if ( $scope_key === '' || headers_sent() ) return;

        $recorder = new self();
        if ( ! $recorder->isCountableRequest() ) return;

        $name = $recorder->cookieName( $scope_key );
        if ( $recorder->cookieValue( $name ) !== '' ) return;

        $minted = hash( 'sha256', wp_generate_password( 64, false, false ) . '|' . $scope_key );

        setcookie(
            $name,
            $minted,
            [
                'expires'  => time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS ),
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // So this same request can read what it just set: PHP does not
        // fold a Set-Cookie back into $_COOKIE.
        $_COOKIE[ $name ] = $minted;
    }

    /**
     * Record one open. Silent on every failure — a share page that 500s
     * because a counter could not be written would trade the document for
     * the statistic.
     */
    public function record( string $subject_type, int $subject_id, int $club_id, string $scope_key = '' ): void {
        if ( $subject_type === '' || $subject_id <= 0 ) return;
        if ( ! $this->isCountableRequest() ) return;

        $hash = $this->visitorHash( $subject_type, $subject_id, $scope_key );
        if ( $hash === '' ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'tt_share_views';
        $now   = current_time( 'mysql' );
        $club  = $club_id > 0 ? $club_id : CurrentClub::id();

        // Upsert on uk_visitor: one row per visitor per subject, so "seen
        // by" is a COUNT(*) and a keen reader does not outweigh a quiet one.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table}
                (club_id, subject_type, subject_id, visitor_hash, first_seen_at, last_seen_at, open_count)
             VALUES (%d, %s, %d, %s, %s, %s, 1)
             ON DUPLICATE KEY UPDATE
                last_seen_at = VALUES(last_seen_at),
                open_count   = open_count + 1",
            $club,
            $subject_type,
            $subject_id,
            $hash,
            $now,
            $now
        ) );
    }

    /**
     * A person, arriving with a browser. `HEAD` is what a preview fetcher
     * sends when it is being polite, and the user-agent list catches the
     * ones that are not.
     */
    private function isCountableRequest(): bool {
        $method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
            : 'GET';
        if ( $method !== 'GET' ) return false;

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) )
            : '';
        if ( $ua === '' ) return false;

        $bots = [
            'bot', 'crawler', 'spider', 'slurp', 'preview', 'facebookexternalhit',
            'whatsapp', 'telegram', 'slackbot', 'discordbot', 'twitterbot',
            'linkedinbot', 'embedly', 'quora link preview', 'skypeuripreview',
            'headlesschrome', 'python-requests', 'curl/', 'wget/',
        ];
        foreach ( $bots as $needle ) {
            if ( strpos( $ua, $needle ) !== false ) return false;
        }

        return true;
    }

    /**
     * The cookie id if there is one, otherwise the salted fallback. Empty
     * string means "do not count this open" rather than "count it as
     * someone new" — an uncounted visit understates, a mis-counted one
     * invents a reader.
     */
    private function visitorHash( string $subject_type, int $subject_id, string $scope_key ): string {
        $key = $scope_key !== '' ? $scope_key : $subject_type . '|' . $subject_id;

        $cookie = $this->cookieValue( $this->cookieName( $key ) );
        if ( $cookie !== '' ) return $cookie;

        return $this->fallbackHash( $subject_type, $subject_id );
    }

    /** A cookie value only counts if it is the shape this class writes. */
    private function cookieValue( string $name ): string {
        if ( ! isset( $_COOKIE[ $name ] ) ) return '';

        $value = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $_COOKIE[ $name ] ) );
        if ( ! is_string( $value ) || strlen( $value ) !== 64 ) return '';

        return $value;
    }

    /**
     * ip|user-agent, salted per install and per subject. The per-subject
     * seed means the same visitor's hash differs between two shared
     * analyses, so the table cannot be walked to build a picture of one
     * person's reading across the club.
     */
    private function fallbackHash( string $subject_type, int $subject_id ): string {
        $ip = '';
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) continue;
            $raw = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
            $ip  = trim( explode( ',', $raw )[0] );
            if ( $ip !== '' ) break;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
            : '';

        if ( $ip === '' && $ua === '' ) return '';

        return hash_hmac(
            'sha256',
            $ip . '|' . $ua,
            wp_salt( 'auth' ) . '|' . $subject_type . '|' . $subject_id
        );
    }

    private function cookieName( string $scope_key ): string {
        return 'tt_sv_' . substr( hash( 'sha256', $scope_key ), 0, 16 );
    }
}
