<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 2 — what happened?
 *
 * Body part, injury type and severity, all three from `tt_lookups`
 * (seeded by migration 0037) and labelled through `LookupTranslator`,
 * so an academy that renames or extends the vocabulary gets its own
 * words rather than a hardcoded English list.
 *
 * Everything here is optional. A coach at the side of a pitch knows
 * "hamstring" long before they know the grade, and an injury with a
 * date and nothing else is still worth more on the journey than an
 * injury nobody recorded.
 */
final class InjuryDetailsStep implements WizardStepInterface {

    public function slug(): string  { return 'details'; }
    public function label(): string { return __( 'What happened', 'talenttrack' ); }

    public function render( array $state ): void {
        // #4270 — say who the injury is for; entered from a player's file
        // the player step is skipped, so this is the only place it shows.
        $player = QueryHelpers::get_player( (int) ( $state['player_id'] ?? 0 ) );
        if ( $player ) {
            echo '<p class="tt-injury-wizard-player">' . sprintf(
                /* translators: %s: player name */
                esc_html__( 'Injury for %s', 'talenttrack' ),
                '<strong>' . esc_html( QueryHelpers::player_display_name( $player ) ) . '</strong>'
            ) . '</p>';
        }

        self::renderFields( [
            'body_part_lookup_id'   => (int) ( $state['body_part_lookup_id'] ?? 0 ),
            'injury_type_lookup_id' => (int) ( $state['injury_type_lookup_id'] ?? 0 ),
            'severity_lookup_id'    => (int) ( $state['severity_lookup_id'] ?? 0 ),
        ] );
    }

    /**
     * The three pickers, shared by this step and the edit form on
     * `?tt_view=injuries&action=edit`, so both ask the same questions in
     * the same words.
     *
     * @param array{body_part_lookup_id:int, injury_type_lookup_id:int, severity_lookup_id:int} $values
     */
    public static function renderFields( array $values ): void {
        self::picker(
            'body_part_lookup_id',
            __( 'Body part', 'talenttrack' ),
            'body_part',
            $values['body_part_lookup_id']
        );
        self::picker(
            'injury_type_lookup_id',
            __( 'Type of injury', 'talenttrack' ),
            'injury_type',
            $values['injury_type_lookup_id']
        );
        self::picker(
            'severity_lookup_id',
            __( 'Severity', 'talenttrack' ),
            'injury_severity',
            $values['severity_lookup_id']
        );
    }

    private static function picker( string $name, string $label, string $lookup_type, int $current ): void {
        $rows = QueryHelpers::get_lookups( $lookup_type );

        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="' . esc_attr( 'tt-injury-' . $name ) . '">' . esc_html( $label ) . '</label>';
        echo '<select id="' . esc_attr( 'tt-injury-' . $name ) . '" class="tt-input" name="' . esc_attr( $name ) . '">';
        echo '<option value="0">' . esc_html__( '— Not recorded —', 'talenttrack' ) . '</option>';
        foreach ( $rows as $row ) {
            echo '<option value="' . (int) $row->id . '" ' . selected( $current, (int) $row->id, false ) . '>'
                . esc_html( LookupTranslator::name( $row ) ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        return [
            'body_part_lookup_id'   => isset( $post['body_part_lookup_id'] )   ? absint( $post['body_part_lookup_id'] )   : 0,
            'injury_type_lookup_id' => isset( $post['injury_type_lookup_id'] ) ? absint( $post['injury_type_lookup_id'] ) : 0,
            'severity_lookup_id'    => isset( $post['severity_lookup_id'] )    ? absint( $post['severity_lookup_id'] )    : 0,
        ];
    }

    public function nextStep( array $state ): ?string { return 'when'; }

    public function submit( array $state ) { return null; }
}
