<?php
namespace TT\Modules\Knowledge\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — the deadline (#2649).
 *
 * Optional, and the wizard says so rather than pre-filling a date. A due date
 * drives the overdue roll-up and, from the alerts side, a nudge; inventing one
 * because the field looked empty would put every coach on an overdue list
 * nobody chose to create.
 */
final class AssignDueDateStep implements WizardStepInterface {

    public const FIELD = 'due_at';

    public function slug(): string { return 'due'; }

    public function label(): string { return __( 'Deadline', 'talenttrack' ); }

    public function render( array $state ): void {
        $value = (string) ( $state[ self::FIELD ] ?? '' );

        echo '<label class="tt-field" for="tt-assign-due">';
        echo '<span class="tt-field__label">' . esc_html__( 'Finish by', 'talenttrack' ) . '</span>';
        echo '<span class="tt-field__hint">'
            . esc_html__( 'Optional. Leave empty for no deadline.', 'talenttrack' ) . '</span>';
        printf(
            '<input type="date" id="tt-assign-due" name="%1$s" class="tt-input" value="%2$s" />',
            esc_attr( self::FIELD ),
            esc_attr( $value )
        );
        echo '</label>';
    }

    public function validate( array $post, array $state ) {
        $raw = isset( $post[ self::FIELD ] ) ? trim( (string) $post[ self::FIELD ] ) : '';

        if ( $raw === '' ) {
            return [ self::FIELD => '' ];
        }

        $ts = strtotime( $raw );
        if ( $ts === false ) {
            return new \WP_Error( 'bad_date', __( 'That date could not be understood.', 'talenttrack' ) );
        }

        // A deadline in the past is almost always a typo, and it would put
        // everybody on the overdue list the moment the wizard finished.
        if ( gmdate( 'Y-m-d', $ts ) < current_time( 'Y-m-d' ) ) {
            return new \WP_Error( 'past_date', __( 'That deadline has already passed. Pick a date in the future.', 'talenttrack' ) );
        }

        return [ self::FIELD => gmdate( 'Y-m-d H:i:s', $ts ) ];
    }

    public function nextStep( array $state ): ?string { return 'confirm'; }

    public function submit( array $state ) { return null; }
}
