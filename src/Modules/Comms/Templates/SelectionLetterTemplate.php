<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 2 — Selection decision letter (selected / not selected).
 *
 * Sender: HoD or coach. Recipients: player + parent (resolved by
 * RecipientResolver per #0042 youth-contact rules). Wording is owned
 * by #0017; this template is the delivery shell. The actual letter
 * PDF is produced via #0063 use case 14 (scouting report) or a future
 * "selection letter" exporter — Comms can attach it via
 * `request->attachedExportId`.
 *
 * Editable per club (top-5).
 *
 * Tokens: {player_name} {team_name} {decision_label} {coach_name}
 */
final class SelectionLetterTemplate extends AbstractTemplate {

    public function key(): string { return 'selection_letter'; }
    public function label(): string { return __( 'Selection decision letter', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'inapp' ]; }
    public function isEditable(): bool { return true; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Selection decision for {player_name} — {team_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nWe have made a decision regarding {player_name}'s place at {team_name}: {decision_label}.\n\nThe attached letter has the full details.\n\n{coach_name}\nThe coaching team", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Selectiebesluit voor {player_name} — {team_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nWe hebben een besluit genomen over de plek van {player_name} bij {team_name}: {decision_label}.\n\nDe bijgevoegde brief bevat de volledige details.\n\n{coach_name}\nHet coaching team", 'talenttrack' ),
            ],
        ], $locale );
    }
}
