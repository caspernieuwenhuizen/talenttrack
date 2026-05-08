<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 10 — Methodology / activity-plan delivered.
 *
 * "This week's activity plan is published, here's the focus" (#0027).
 * Sender: HoD. Recipients: coaches.
 *
 * Tokens: {plan_title} {focus_summary} {deep_link} {hod_name}
 */
final class MethodologyDeliveredTemplate extends AbstractTemplate {

    public function key(): string { return 'methodology_delivered'; }
    public function label(): string { return __( 'Methodology / activity plan delivered', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'New activity plan published — {plan_title}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nThe activity plan \"{plan_title}\" is published.\n\nFocus: {focus_summary}\n\nOpen: {deep_link}\n\n{hod_name}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Nieuw activiteitsplan gepubliceerd — {plan_title}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nHet activiteitsplan \"{plan_title}\" is gepubliceerd.\n\nFocus: {focus_summary}\n\nOpen: {deep_link}\n\n{hod_name}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
