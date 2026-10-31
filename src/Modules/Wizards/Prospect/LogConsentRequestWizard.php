<?php
namespace TT\Modules\Wizards\Prospect;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Domain\ProspectRecord;
use TT\Shared\Wizards\WizardInterface;

/**
 * LogConsentRequestWizard (#4257) — record that the academy asked a
 * child's own club to pass a consent request on to the family, at
 * `?tt_view=wizard&tt_wizard=log-consent-request`.
 *
 * Steps: prospect → who was asked, when, notes → confirm, which writes
 * through `ConsentRequestLog`. Opened from a prospect's record page the
 * first step is skipped: the wizard arrives with `prospect_id`, checked
 * here against the same gate the final step asks.
 *
 * Gated on `tt_edit_prospects`. `POST prospects/{id}/consent-requests` is
 * the API route to the same entry; the `request_consent` workflow task is
 * the other.
 */
final class LogConsentRequestWizard implements WizardInterface {

    public const SLUG = 'log-consent-request';

    public function slug(): string { return self::SLUG; }

    public function label(): string { return __( 'Log consent request', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_edit_prospects'; }

    public function firstStepSlug(): string { return 'prospect'; }

    /** @return \TT\Shared\Wizards\WizardStepInterface[] */
    public function steps(): array {
        return [
            new ConsentRequestProspectStep(),
            new ConsentRequestDetailsStep(),
            new ConsentRequestConfirmStep(),
        ];
    }

    /**
     * Seed `prospect_id` from the entry URL, but only a prospect this user
     * may log a request for: a hand-edited URL does not skip the step for a
     * prospect outside their scope.
     *
     * @param array<string, mixed> $get
     * @return array<string, mixed>
     */
    public function initialState( array $get ): array {
        $prospect_id = isset( $get['prospect_id'] ) ? absint( $get['prospect_id'] ) : 0;
        if ( $prospect_id <= 0 ) return [];
        if ( ! ProspectRecord::canLogConsent( get_current_user_id(), $prospect_id ) ) return [];
        return [ 'prospect_id' => $prospect_id ];
    }
}
