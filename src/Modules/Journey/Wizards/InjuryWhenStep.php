<?php
namespace TT\Modules\Journey\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — when, plus a note. Final step: persists the injury.
 *
 * `started_on` is the only required field in the whole wizard. An
 * expected return date is offered because it is what turns the record
 * into something the squad overview can flag when it passes, but a
 * coach who does not know yet leaves it empty.
 *
 * The write goes through `InjuryRepository::create()`, which emits the
 * `injury_started` journey event, so the timeline picks it up without
 * this step knowing anything about events.
 */
final class InjuryWhenStep implements WizardStepInterface {

    public function slug(): string  { return 'when'; }
    public function label(): string { return __( 'When', 'talenttrack' ); }

    public function render( array $state ): void {
        self::renderFields( [
            'started_on'      => (string) ( $state['started_on'] ?? current_time( 'Y-m-d' ) ),
            'expected_return' => (string) ( $state['expected_return'] ?? '' ),
            'notes'           => (string) ( $state['notes'] ?? '' ),
        ] );
    }

    /**
     * The date and note fields, shared by this step and the edit form on
     * `?tt_view=injuries&action=edit`. An edit does not move the day the
     * injury happened (the PUT route leaves `started_on` out on purpose),
     * so with `$start_editable` false it is shown, not asked.
     *
     * @param array{started_on:string, expected_return:string, notes:string} $values
     */
    public static function renderFields( array $values, bool $start_editable = true ): void {
        if ( $start_editable ) {
            echo DateInputComponent::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes
                'name'     => 'started_on',
                'label'    => __( 'Date of injury', 'talenttrack' ),
                'required' => true,
                'value'    => $values['started_on'],
            ] );
        } elseif ( $values['started_on'] !== '' ) {
            /* translators: %s is a date. */
            echo '<p class="tt-field-hint">' . esc_html( sprintf( __( 'Date of injury: %s', 'talenttrack' ), TTDate::date( $values['started_on'] ) ) ) . '</p>';
        }

        $expected_args = [
            'name'  => 'expected_return',
            'label' => __( 'Expected back', 'talenttrack' ),
            'value' => $values['expected_return'],
            'hint'  => __( 'Leave empty if you do not know yet. Setting it lets the squad overview flag the injury when the date passes.', 'talenttrack' ),
        ];
        if ( ! $start_editable && $values['started_on'] !== '' ) {
            $expected_args['min'] = $values['started_on'];
        }
        echo DateInputComponent::render( $expected_args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes

        $notes = $values['notes'];
        echo '<div class="tt-field">';
        echo '<label class="tt-field-label" for="tt-injury-notes">' . esc_html__( 'Note', 'talenttrack' ) . '</label>';
        echo '<textarea id="tt-injury-notes" class="tt-input" name="notes" rows="3" maxlength="1000">' . esc_textarea( $notes ) . '</textarea>';
        echo '<span class="tt-field-hint">' . esc_html__( 'What happened, and anything the next coach should know. Write it as if the family will read it.', 'talenttrack' ) . '</span>';
        echo '</div>';
    }

    public function validate( array $post, array $state ) {
        $started = isset( $post['started_on'] ) ? sanitize_text_field( wp_unslash( (string) $post['started_on'] ) ) : '';
        $expected = isset( $post['expected_return'] ) ? sanitize_text_field( wp_unslash( (string) $post['expected_return'] ) ) : '';
        $notes    = isset( $post['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $post['notes'] ) ) : '';

        if ( $started === '' ) {
            return new \WP_Error( 'started_on', __( 'A date of injury is required.', 'talenttrack' ) );
        }
        if ( InjuryRepository::expectedReturnBeforeStart( $started, $expected ) ) {
            return new \WP_Error( 'expected_return', __( 'The expected return cannot be before the date of injury.', 'talenttrack' ) );
        }

        return [
            'started_on'      => $started,
            'expected_return' => $expected,
            'notes'           => $notes,
        ];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $player_id = (int) ( $state['player_id'] ?? 0 );
        if ( $player_id <= 0 ) {
            return new \WP_Error( 'no_player', __( 'Please pick a player.', 'talenttrack' ) );
        }

        // The wizard's own cap gate answers "may this user record injuries
        // at all"; this answers "for THIS player". Same pair the REST
        // handler enforces, so a hand-built URL cannot outflank the form.
        if ( ! AuthorizationService::canRecordInjury( get_current_user_id(), $player_id, 'change' ) ) {
            return new \WP_Error( 'forbidden', __( 'You cannot record injuries for this player.', 'talenttrack' ) );
        }

        $id = ( new InjuryRepository() )->create( [
            'player_id'             => $player_id,
            'started_on'            => (string) ( $state['started_on'] ?? '' ),
            'expected_return'       => (string) ( $state['expected_return'] ?? '' ),
            'body_part_lookup_id'   => (int) ( $state['body_part_lookup_id'] ?? 0 ) ?: null,
            'injury_type_lookup_id' => (int) ( $state['injury_type_lookup_id'] ?? 0 ) ?: null,
            'severity_lookup_id'    => (int) ( $state['severity_lookup_id'] ?? 0 ) ?: null,
            'notes'                 => (string) ( $state['notes'] ?? '' ),
        ] );

        if ( $id <= 0 ) {
            return new \WP_Error( 'create_failed', __( 'Could not record the injury.', 'talenttrack' ) );
        }

        return [ 'redirect_url' => add_query_arg(
            [ 'tt_view' => 'players', 'id' => $player_id, 'tab' => 'injuries' ],
            \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
        ) ];
    }
}
