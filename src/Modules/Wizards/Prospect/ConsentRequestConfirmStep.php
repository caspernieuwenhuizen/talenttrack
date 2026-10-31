<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Domain\ConsentRequestLog;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * Step 3 — read it back, then log it. Final step: writes through
 * `ConsentRequestLog`, which asks the same scope and date rules again, and
 * lands on the prospect's record page, where the new entry heads the log.
 */
final class ConsentRequestConfirmStep implements WizardStepInterface {

    public function slug(): string  { return 'confirm'; }
    public function label(): string { return __( 'Confirm', 'talenttrack' ); }

    public function render( array $state ): void {
        wp_enqueue_style(
            'tt-frontend-prospect-record',
            TT_PLUGIN_URL . 'assets/css/frontend-prospect-record.css',
            [],
            TT_VERSION
        );
        $prospect =( new ProspectsRepository() )->find( (int) ( $state['prospect_id'] ?? 0 ) );
        $name     = $prospect ? trim( (string) ( $prospect->first_name ?? '' ) . ' ' . (string) ( $prospect->last_name ?? '' ) ) : '';
        $asked_at = (string) ( $state['asked_at'] ?? '' );
        $notes    = trim( (string) ( $state['notes'] ?? '' ) );

        echo '<dl class="tt-consent-request-review">';
        echo '<dt>' . esc_html__( 'Prospect', 'talenttrack' ) . '</dt><dd>' . esc_html( $name !== '' ? $name : '—' ) . '</dd>';
        echo '<dt>' . esc_html__( 'Date asked', 'talenttrack' ) . '</dt><dd>' . esc_html( $asked_at !== '' ? TTDate::date( $asked_at ) : '—' ) . '</dd>';
        echo '<dt>' . esc_html__( 'Club or coordinator asked', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) ( $state['asked_of'] ?? '' ) ) . '</dd>';
        if ( $notes !== '' ) {
            echo '<dt>' . esc_html__( 'Notes', 'talenttrack' ) . '</dt><dd>' . esc_html( $notes ) . '</dd>';
        }
        echo '</dl>';
        echo '<p class="tt-field-hint">' . esc_html__( 'The request is logged as awaiting an answer. The waiting counter starts from the date asked.', 'talenttrack' ) . '</p>';
    }

    /**
     * Nothing to answer here; the read-back is the step.
     *
     * @return array<string,mixed>
     */
    public function validate( array $post, array $state ): array {
        return [];
    }

    public function nextStep( array $state ): ?string { return null; }

    public function submit( array $state ) {
        $prospect_id = (int) ( $state['prospect_id'] ?? 0 );
        $result      = ConsentRequestLog::record(
            get_current_user_id(),
            $prospect_id,
            (string) ( $state['asked_at'] ?? '' ),
            (string) ( $state['asked_of'] ?? '' ),
            (string) ( $state['notes'] ?? '' )
        );
        if ( is_wp_error( $result ) ) return $result;

        return [ 'redirect_url' => RecordLink::detailUrlFor( 'prospect', $prospect_id ) ];
    }
}
