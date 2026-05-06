<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Domain\QrCodeRenderer;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — Secret. Generates a fresh TOTP shared secret (lazy / idempotent
 * across re-renders), persists it encrypted, and renders the QR code
 * + manual-entry fallback. The user adds the entry to their authenticator
 * app and clicks Continue to advance to the verify step.
 *
 * Secret lifecycle here:
 *   - Render is the chokepoint. If no `tt_user_mfa` row exists for the
 *     current user, or the row has no `secret_encrypted`, we generate
 *     a fresh 20-byte secret and persist it via `upsertSecret()` with
 *     an empty backup-codes array.
 *   - `upsertSecret()` resets `enrolled_at` if a previous enrollment
 *     existed, so re-enrollment cleanly replaces the old secret.
 *   - On the next visit / Back navigation, we re-read the existing row
 *     and decrypt the secret — the QR shown is identical to the one
 *     the user scanned the first time.
 *
 * Why generate-in-render is acceptable here:
 *   - The operation is idempotent: existing row → reuse; no row → create.
 *   - There's no user-input to validate before generation; the only
 *     interaction is "I scanned, continue".
 *   - Wizard validate() runs only on POST, but render runs on GET. Doing
 *     the work at render keeps the GET / POST split clean.
 */
final class SecretStep implements WizardStepInterface {

    public function slug(): string { return 'secret'; }
    public function label(): string { return __( 'Add to authenticator', 'talenttrack' ); }

    public function render( array $state ): void {
        $user_id = get_current_user_id();
        $repo    = new MfaSecretsRepository();
        $row     = $repo->findByUserId( $user_id );

        // Lazy-create / re-create the secret if there's nothing usable
        // on disk. Re-enrollment from an enrolled state replaces the
        // secret (upsertSecret resets `enrolled_at`).
        $needs_fresh = ( $row === null )
            || empty( $row['secret'] )
            || ! empty( $row['enrolled_at'] );
        if ( $needs_fresh ) {
            $fresh_secret = TotpService::generateSecret();
            $repo->upsertSecret( $user_id, $fresh_secret, [] );
            $row = $repo->findByUserId( $user_id );
        }

        $secret      = (string) ( $row['secret'] ?? '' );
        $user        = wp_get_current_user();
        $account     = (string) ( $user->user_email ?: $user->user_login );
        $issuer      = self::issuerLabel();
        $otpauth_uri = TotpService::otpauthUri( $secret, $account, $issuer );
        $qr_svg      = QrCodeRenderer::svg( $otpauth_uri, 6 );

        echo '<p>' . esc_html__( "Open your authenticator app, tap +, and scan this QR code. The app will start showing 6-digit codes that change every 30 seconds — that's how you'll sign in from now on.", 'talenttrack' ) . '</p>';

        echo '<div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start; margin:24px 0;">';

        // QR code (mobile-first: full width on phones, side-by-side ≥640px).
        echo '<div style="flex:0 0 240px; max-width:100%;">';
        echo '<div style="background:#ffffff; padding:8px; display:inline-block; border:1px solid #ddd;">';
        // The SVG is generated server-side from a trusted internal URI;
        // safe to echo without escaping its tags. KSES would strip the
        // <path> attributes we need.
        echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
        echo '<p style="font-size:12px; color:#5b6e75; margin:8px 0 0;">'
            . esc_html__( 'Scan with your authenticator app.', 'talenttrack' )
            . '</p>';
        echo '</div>';

        // Manual-entry fallback.
        echo '<div style="flex:1 1 320px; min-width:280px;">';
        echo '<h3 style="margin:0 0 12px; font-size:14px;">' . esc_html__( "Can't scan? Enter manually:", 'talenttrack' ) . '</h3>';
        echo '<dl style="margin:0; display:grid; grid-template-columns:auto 1fr; gap:6px 12px; font-size:14px;">';
        echo '<dt style="font-weight:600;">' . esc_html__( 'Account', 'talenttrack' ) . '</dt>';
        echo '<dd style="margin:0;"><code>' . esc_html( $account ) . '</code></dd>';
        echo '<dt style="font-weight:600;">' . esc_html__( 'Issuer', 'talenttrack' ) . '</dt>';
        echo '<dd style="margin:0;"><code>' . esc_html( $issuer ) . '</code></dd>';
        echo '<dt style="font-weight:600;">' . esc_html__( 'Secret', 'talenttrack' ) . '</dt>';
        echo '<dd style="margin:0;"><code style="word-break:break-all; font-size:13px;">' . esc_html( self::chunk4( $secret ) ) . '</code></dd>';
        echo '<dt style="font-weight:600;">' . esc_html__( 'Type', 'talenttrack' ) . '</dt>';
        echo '<dd style="margin:0;"><code>' . esc_html__( 'Time-based (TOTP), 30s, 6 digits, SHA-1', 'talenttrack' ) . '</code></dd>';
        echo '</dl>';
        echo '</div>';

        echo '</div>';

        echo '<p style="margin-top:16px; padding:12px 16px; background:#f0f6fc; border-left:4px solid #2271b1;">'
            . '<strong>' . esc_html__( 'Keep this page open.', 'talenttrack' ) . '</strong> '
            . esc_html__( "On the next step you'll type the first code your authenticator shows, to confirm it's set up correctly.", 'talenttrack' )
            . '</p>';
    }

    public function validate( array $post, array $state ) {
        return [];
    }

    public function nextStep( array $state ): ?string {
        return 'verify';
    }

    public function submit( array $state ) {
        return null;
    }

    /**
     * Display the issuer in the QR / manual-entry as "TalentTrack — <site name>"
     * when the site has a non-default name, otherwise just "TalentTrack".
     * The em-dash + site-name suffix lets a user with multiple TalentTrack
     * installs (e.g. dev + production) tell them apart in their authenticator
     * app's account list.
     */
    private static function issuerLabel(): string {
        $site_name = trim( (string) get_bloginfo( 'name' ) );
        if ( $site_name === '' || strcasecmp( $site_name, 'TalentTrack' ) === 0 ) {
            return 'TalentTrack';
        }
        return 'TalentTrack — ' . $site_name;
    }

    /**
     * Insert spaces every 4 base32 characters so the secret is easier to
     * read aloud / type into a manual-entry field.
     */
    private static function chunk4( string $secret ): string {
        return trim( chunk_split( $secret, 4, ' ' ) );
    }
}
