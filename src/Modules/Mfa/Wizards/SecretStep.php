<?php
namespace TT\Modules\Mfa\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Mfa\Domain\QrCodeRenderer;
use TT\Modules\Mfa\Domain\TotpService;
use TT\Modules\Mfa\MfaSecretsRepository;
use TT\Shared\Wizards\WizardState;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — Secret. Stages a fresh TOTP shared secret (lazy / idempotent
 * across re-renders) in the row's pending columns and renders the QR code
 * + manual-entry fallback. The user adds the entry to their authenticator
 * app and clicks Continue to advance to the verify step.
 *
 * Secret lifecycle here:
 *   - Render is the chokepoint. With no staged secret inside the TTL, a
 *     fresh 20-byte secret is staged via `stagePendingSecret()`. The
 *     working secret, backup codes and counters are never written here.
 *   - An enrolled account sees nothing until its current code was
 *     confirmed in this run (`MfaEnrollmentWizard::reauthSatisfied()`).
 *   - On the next visit / Back navigation the staged secret is read back,
 *     so the QR shown is identical to the one the user scanned.
 *
 * Why generate-in-render is acceptable here:
 *   - The operation is idempotent: staged secret → reuse; none → stage.
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

        if ( ! MfaEnrollmentWizard::reauthSatisfied( $user_id, $state ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Confirm a code from your current setup first. Go back one step to enter it.', 'talenttrack' ) . '</p>';
            return;
        }
        $secret = self::stagedSecret( $user_id );
        if ( $secret === '' ) {
            echo '<p class="tt-notice">' . esc_html__( 'The new secret could not be prepared. Reload the page to try again.', 'talenttrack' ) . '</p>';
            return;
        }

        $user    = wp_get_current_user();
        $account = (string) ( $user->user_email ?: $user->user_login );
        $issuer  = self::issuerLabel();

        // #1393 — keep the composed otpauth URI inside QR version 8
        // (192 bytes at ECC L). The issuer appears twice in the URI
        // (label prefix + issuer= param), so a long academy name can
        // push a scannable v6–v7 code into the dense v9–v10 range —
        // or past the encoder ceiling entirely. When the decorated
        // issuer doesn't fit, fall back to the bare brand rather than
        // shipping a denser (or truncated) code.
        $otpauth_uri = TotpService::otpauthUri( $secret, $account, $issuer );
        if ( strlen( $otpauth_uri ) > 192 && $issuer !== 'TalentTrack' ) {
            $issuer      = 'TalentTrack';
            $otpauth_uri = TotpService::otpauthUri( $secret, $account, $issuer );
        }
        $qr_svg = QrCodeRenderer::svg( $otpauth_uri, 6 );

        echo '<p>' . esc_html__( "Open your authenticator app, tap +, and scan this QR code. The app will start showing 6-digit codes that change every 30 seconds — that's how you'll sign in from now on.", 'talenttrack' ) . '</p>';

        echo '<div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start; margin:24px 0;">';

        // QR code (mobile-first: full width on phones, side-by-side ≥640px).
        // #1393 — box bumped 240px → 300px and the SVG fills it
        // (style="width:100%" on the root), so even a v8 code clears
        // ~4.5 px/module — readable for phone cameras.
        if ( $qr_svg !== '' ) {
            echo '<div style="flex:0 0 300px; max-width:100%;">';
            echo '<div style="background:#ffffff; padding:8px; border:1px solid #ddd;">';
            // The SVG is generated server-side from a trusted internal URI;
            // safe to echo without escaping its tags. KSES would strip the
            // <path> attributes we need.
            echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</div>';
            echo '<p style="font-size:12px; color:#5b6e75; margin:8px 0 0;">'
                . esc_html__( 'Scan with your authenticator app.', 'talenttrack' )
                . '</p>';
            echo '</div>';
        } else {
            // #1393 — over-capacity payload: the renderer refuses to
            // emit a truncated (invalid) QR. Steer to manual entry.
            echo '<div style="flex:0 0 300px; max-width:100%;">';
            echo '<p class="tt-notice">'
                . esc_html__( 'The QR code could not be generated for this account — use the manual entry details instead. Both set up the same secret.', 'talenttrack' )
                . '</p>';
            echo '</div>';
        }

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
        if ( ! MfaEnrollmentWizard::reauthSatisfied( get_current_user_id(), $state ) ) {
            return new \WP_Error(
                'mfa_current_not_confirmed',
                __( 'Confirm a code from your current setup first. Go back one step to enter it.', 'talenttrack' )
            );
        }
        return [];
    }

    public function nextStep( array $state ): ?string {
        return 'verify';
    }

    /**
     * The staged secret, staged now when none is (or it has expired), so
     * Back and a reload show the same QR code. A fresh secret voids an
     * earlier verified code and backup codes in this run: they belonged to
     * the secret it replaces.
     */
    private static function stagedSecret( int $user_id ): string {
        $repo   = new MfaSecretsRepository();
        $secret = $repo->pendingSecret( $user_id );
        if ( $secret !== '' ) return $secret;

        $secret = TotpService::generateSecret();
        if ( ! $repo->stagePendingSecret( $user_id, $secret ) ) return '';
        WizardState::merge( $user_id, MfaEnrollmentWizard::SLUG, [
            'mfa_verified_at'        => 0,
            'backup_codes_plaintext' => [],
        ] );
        return $secret;
    }

    public function submit( array $state ) {
        return null;
    }

    /**
     * Display the issuer in the QR / manual-entry as "TalentTrack <site name>"
     * when the site has a non-default name, otherwise just "TalentTrack".
     * The site-name suffix lets a user with multiple TalentTrack installs
     * (e.g. dev + production) tell them apart in their authenticator
     * app's account list.
     *
     * #1393 — was "TalentTrack — <site>": the em-dash is a 3-byte UTF-8
     * char that percent-encodes to 9 chars, TWICE (label prefix +
     * issuer= param), inflating every real install's QR by ~16 bytes
     * and pushing it a version denser than needed. Plain ASCII space
     * now. The render path additionally caps the composed URI at the
     * v8 budget (see render()).
     *
     * #2426 — a site name that already opens with the brand is used as-is.
     * Matching only the exact string "TalentTrack" meant "TalentTrack Local"
     * became "TalentTrack TalentTrack Local", which is what the user then
     * sees in their authenticator app for good. The duplication also cost
     * the URI budget twice over, since the issuer appears in both the label
     * prefix and the issuer= param.
     */
    private static function issuerLabel(): string {
        $site_name = trim( (string) get_bloginfo( 'name' ) );
        if ( $site_name === '' || strcasecmp( $site_name, 'TalentTrack' ) === 0 ) {
            return 'TalentTrack';
        }
        if ( stripos( $site_name, 'TalentTrack' ) === 0 ) {
            return $site_name;
        }
        return 'TalentTrack ' . $site_name;
    }

    /**
     * Insert spaces every 4 base32 characters so the secret is easier to
     * read aloud / type into a manual-entry field.
     */
    private static function chunk4( string $secret ): string {
        return trim( chunk_split( $secret, 4, ' ' ) );
    }
}
