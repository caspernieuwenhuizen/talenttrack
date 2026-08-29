<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #2604 — a confidential player report sent to an external scout.
 *
 * The only Comms template whose recipient is deliberately outside the
 * academy: the address belongs to a scout at another club and has no
 * account here. That is exactly why the send has to be audited — a
 * report about a minor leaving the building is the strongest case in
 * the product for a log row naming who sent what, to whom, and when.
 *
 * Tokens: {club_name} {player_name} {report_url} {expiry_date}
 * {cover_message}
 */
final class ScoutReportDeliveryTemplate extends AbstractTemplate {

    public const KEY = 'scout_report_delivery';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Player report for a scout', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( '{club_name} — Player report for {player_name}', 'talenttrack' ),
                __( "Hello, you have been sent a confidential player report from {club_name}.\n\n{cover_message}\n\n{report_url}\n\nThis link is valid until {expiry_date}. Do not forward.", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( '{club_name} — Spelersrapport van {player_name}', 'talenttrack' ),
                __( "Hallo, je hebt een vertrouwelijk spelersrapport ontvangen van {club_name}.\n\n{cover_message}\n\n{report_url}\n\nDeze link is geldig tot {expiry_date}. Niet doorsturen.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
