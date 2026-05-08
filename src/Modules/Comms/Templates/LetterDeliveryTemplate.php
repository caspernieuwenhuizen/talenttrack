<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 13 — Letter delivery.
 *
 * Formal letters that have to be printable and signable
 * (selection / non-selection / contract). Comms attaches the
 * rendered PDF (via `request->attachedExportId` pointing at the
 * #0063 Export render); the email is the cover note.
 *
 * Sender: HoD. Recipients: parent. Editable per club (top-5).
 *
 * Tokens: {player_name} {letter_subject} {letter_summary} {hod_name}
 */
final class LetterDeliveryTemplate extends AbstractTemplate {

    public function key(): string { return 'letter_delivery'; }
    public function label(): string { return __( 'Letter delivery', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }
    public function isEditable(): bool { return true; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( '{letter_subject} — {player_name}', 'talenttrack' ),
                __( "Dear {recipient_first_name},\n\nPlease find the attached letter regarding {player_name}.\n\n{letter_summary}\n\nKind regards,\n{hod_name}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( '{letter_subject} — {player_name}', 'talenttrack' ),
                __( "Beste {recipient_first_name},\n\nIn de bijlage vind je een brief over {player_name}.\n\n{letter_summary}\n\nMet vriendelijke groet,\n{hod_name}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
