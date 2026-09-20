<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * RequestConsentForm (#3812) — what the scout actually did, and what came
 * back.
 *
 * Four fields and no more: the date, the club or coordinator that was
 * asked, the outcome, and free-text notes. **No family name, email,
 * address or phone number** — a family that has not consented is exactly
 * who this record must not describe, and the fields that hold family
 * contact live on `tt_prospects`, behind the consent rule that has always
 * guarded them.
 *
 * Submitting writes one row to `tt_prospect_consent_requests` through the
 * repository and audits it. The form composes; the repository decides
 * (CLAUDE.md §4).
 *
 * Markup carries `tt-` classes rather than inline styles: the older forms
 * in this folder predate the inline-style rule and are grandfathered, new
 * ones are not.
 */
class RequestConsentForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $disabled = self::completedAttr( $task );
        $prospect = self::prospectSummary( (int) ( $task['prospect_id'] ?? 0 ) );
        $outcome  = (string) ( $existing['outcome'] ?? ConsentOutcome::AWAITING );
        $asked_at = (string) ( $existing['asked_at'] ?? gmdate( 'Y-m-d' ) );

        ob_start();
        ?>
        <div class="tt-form tt-consent-request">
            <?php if ( $prospect !== '' ) : ?>
                <p class="tt-form-lead">
                    <?php echo esc_html( sprintf( /* translators: %s: the prospect's name */ __( 'Prospect: %s', 'talenttrack' ), $prospect ) ); ?>
                </p>
            <?php endif; ?>

            <p class="tt-field-hint">
                <?php esc_html_e( 'Record that the academy asked this child\'s club to pass a consent request on to the family. Do not enter the family\'s name or contact details here — only the route you used.', 'talenttrack' ); ?>
            </p>

            <div class="tt-field">
                <label for="tt-rc-date"><?php esc_html_e( 'Date asked', 'talenttrack' ); ?></label>
                <input type="date" id="tt-rc-date" name="asked_at" required
                       value="<?php echo esc_attr( $asked_at ); ?>" <?php echo esc_attr( $disabled ); ?> />
            </div>

            <div class="tt-field">
                <label for="tt-rc-who"><?php esc_html_e( 'Club or coordinator asked', 'talenttrack' ); ?></label>
                <input type="text" id="tt-rc-who" name="asked_of" required maxlength="255"
                       value="<?php echo esc_attr( (string) ( $existing['asked_of'] ?? '' ) ); ?>"
                       placeholder="<?php esc_attr_e( 'e.g. the youth coordinator at their club', 'talenttrack' ); ?>"
                       <?php echo esc_attr( $disabled ); ?> />
            </div>

            <div class="tt-field">
                <label for="tt-rc-outcome"><?php esc_html_e( 'Outcome', 'talenttrack' ); ?></label>
                <select id="tt-rc-outcome" name="outcome" <?php echo esc_attr( $disabled ); ?>>
                    <?php foreach ( ConsentOutcome::labels() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $outcome, $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-field">
                <label for="tt-rc-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-rc-notes" name="notes" rows="4" <?php echo esc_attr( $disabled ); ?>><?php
                    echo esc_textarea( (string) ( $existing['notes'] ?? '' ) );
                ?></textarea>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];

        $asked_at = trim( (string) ( $raw['asked_at'] ?? '' ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $asked_at ) ) {
            $errors['asked_at'] = __( 'Enter the date you asked, as YYYY-MM-DD.', 'talenttrack' );
        }

        if ( trim( (string) ( $raw['asked_of'] ?? '' ) ) === '' ) {
            $errors['asked_of'] = __( 'Name the club or coordinator you asked. This is the record that the academy went through them.', 'talenttrack' );
        }

        $outcome = (string) ( $raw['outcome'] ?? ConsentOutcome::AWAITING );
        if ( ! ConsentOutcome::isValid( $outcome ) ) {
            $errors['outcome'] = __( 'Pick one of the listed outcomes.', 'talenttrack' );
        }

        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $prospect_id = (int) ( $task['prospect_id'] ?? 0 );
        $asked_at    = trim( (string) ( $raw['asked_at'] ?? '' ) );
        $asked_of    = sanitize_text_field( (string) ( $raw['asked_of'] ?? '' ) );
        $outcome     = (string) ( $raw['outcome'] ?? ConsentOutcome::AWAITING );
        $notes       = sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) );

        $entry_id = 0;
        if ( $prospect_id > 0 ) {
            $entry_id = ( new ProspectConsentRequestsRepository() )
                ->create( $prospect_id, $asked_at, $asked_of, $outcome, $notes );

            if ( $entry_id > 0 ) {
                ( new AuditService() )->record( 'prospect.consent_requested', 'prospect', $prospect_id, [
                    'entry_id' => $entry_id,
                    'asked_at' => $asked_at,
                    'outcome'  => $outcome,
                ] );
            }
        }

        return [
            'prospect_id' => $prospect_id,
            'entry_id'    => $entry_id,
            'asked_at'    => $asked_at,
            'asked_of'    => $asked_of,
            'outcome'     => $outcome,
            'notes'       => $notes,
        ];
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }

    private static function prospectSummary( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        $row = ( new ProspectsRepository() )->find( $prospect_id );
        if ( $row === null ) return '';
        $data = (array) $row;
        $name = trim( (string) ( $data['first_name'] ?? '' ) . ' ' . (string) ( $data['last_name'] ?? '' ) );
        $club = (string) ( $data['current_club'] ?? '' );
        return $club !== '' ? $name . ' (' . $club . ')' : $name;
    }
}
