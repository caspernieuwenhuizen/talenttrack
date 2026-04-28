<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * ScoutDelivery — generates a one-time scout link, persists the
 * rendered report (with photos base64-inlined), and emails the link.
 *
 * #0014 Sprint 5. Token is a 64-char URL-safe random string. Expiry
 * is captured as an absolute datetime (now() + days). Revocation +
 * audit live in {@see ScoutReportsRepository}.
 */
class ScoutDelivery {

    /**
     * @return array{ok: bool, report_id?: int, error?: string}
     */
    public function emailLink( object $player, ReportConfig $config, string $recipient_email, int $expiry_days, string $cover_message ): array {
        $expiry_days = max( 1, min( 60, $expiry_days ) );

        // Force the scout-default privacy footprint for emailed reports.
        // The wizard exposes per-report toggles, but if a generator left
        // them at unsafe values for the scout audience, we still apply
        // the audience floor.
        $defaults = AudienceDefaults::defaultsFor( AudienceType::SCOUT );
        $config->audience     = AudienceType::SCOUT;
        $config->tone_variant = (string) $defaults['tone_variant'];

        // Render once; freeze the HTML in storage so revocation is
        // simple + the link page doesn't re-query the DB on every hit.
        $renderer = new PlayerReportRenderer();
        $html     = $renderer->render( $config );
        $html     = $this->wrapForEmail( $html, $recipient_email );
        $html     = PhotoInliner::inline( $html );

        $token   = $this->generateToken();
        $expires = ( new \DateTimeImmutable( 'now' ) )->modify( '+' . $expiry_days . ' days' );

        $repo = new ScoutReportsRepository();
        $id   = $repo->createEmailedLink(
            (int) $player->id,
            $config->generated_by,
            $config,
            $html,
            $token,
            $recipient_email,
            $cover_message,
            $expires
        );
        if ( $id === false ) {
            return [ 'ok' => false, 'error' => 'persist_failed' ];
        }

        $sent = $this->sendEmail(
            $recipient_email,
            $token,
            (string) QueryHelpers::player_display_name( $player ),
            $cover_message,
            $expires
        );
        if ( ! $sent ) {
            return [ 'ok' => false, 'error' => 'mail_failed', 'report_id' => $id ];
        }
        return [ 'ok' => true, 'report_id' => $id ];
    }

    /**
     * Wraps the rendered report body in a confidential watermark
     * specific to the recipient + the academy. Used only for emailed
     * scout links; in-product previews show the body verbatim.
     */
    private function wrapForEmail( string $body, string $recipient_email ): string {
        $watermark = sprintf(
            /* translators: %s: recipient email address */
            __( 'Confidential — for %s only', 'talenttrack' ),
            $recipient_email
        );
        return '<div class="tt-scout-watermark" style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#888;text-align:center;padding:6px;border-bottom:1px solid #eee;margin-bottom:14px;">'
            . esc_html( $watermark )
            . '</div>'
            . $body;
    }

    private function generateToken(): string {
        if ( function_exists( 'wp_generate_password' ) ) {
            $raw = wp_generate_password( 64, false, false );
            // wp_generate_password is alphanumeric when special_chars=false;
            // already URL-safe.
            return $raw;
        }
        return bin2hex( random_bytes( 32 ) );
    }

    private function sendEmail( string $to, string $token, string $player_name, string $cover_message, \DateTimeImmutable $expires_at ): bool {
        $club_name = trim( (string) QueryHelpers::get_config( 'academy_name', '' ) );
        $club      = $club_name !== '' ? $club_name : __( 'TalentTrack', 'talenttrack' );

        $subject = sprintf(
            /* translators: 1: club name, 2: player name */
            __( '%1$s — Player report for %2$s', 'talenttrack' ),
            $club,
            $player_name
        );

        $link = add_query_arg( 'tt_scout_token', $token, home_url( '/' ) );

        $intro = sprintf(
            /* translators: %s: club name */
            __( 'Hello, you have been sent a confidential player report from %s.', 'talenttrack' ),
            $club
        );
        $expiry_line = sprintf(
            /* translators: %s: human-readable expiry date */
            __( 'This link is valid until %s. Do not forward.', 'talenttrack' ),
            wp_date( get_option( 'date_format' ) ?: 'Y-m-d', $expires_at->getTimestamp() )
        );

        $body  = $intro . "\n\n";
        if ( $cover_message !== '' ) {
            $body .= $cover_message . "\n\n";
        }
        $body .= $link . "\n\n";
        $body .= $expiry_line . "\n";

        $headers = [];
        return (bool) wp_mail( $to, $subject, $body, $headers );
    }
}
