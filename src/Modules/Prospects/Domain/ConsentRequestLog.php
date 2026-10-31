<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;

/**
 * ConsentRequestLog (#4257) — record that the academy asked a child's own
 * club to pass a consent request on to the family.
 *
 * The rules are the ones `POST prospects/{id}/consent-requests` applies: a
 * real date no later than today (`ConsentRequestDate`, #4267), a named club
 * or coordinator, the outcome starting as "awaiting", and an audit entry.
 * On top of those it asks `ProspectRecord::canLogConsent()`, so the entry
 * can only be written for a prospect the user may open.
 */
final class ConsentRequestLog {

    /** @return int|\WP_Error the new entry's id */
    public static function record( int $user_id, int $prospect_id, string $asked_at, string $asked_of, string $notes = '' ) {
        if ( ! ProspectRecord::canLogConsent( $user_id, $prospect_id ) ) {
            return new \WP_Error( 'prospect_not_found', __( 'Prospect not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }

        $asked_at   = trim( $asked_at );
        $date_error = ConsentRequestDate::validate( $asked_at );
        if ( $date_error !== null ) return $date_error;

        $asked_of = trim( sanitize_text_field( $asked_of ) );
        if ( $asked_of === '' ) {
            return new \WP_Error( 'missing_asked_of', __( 'Name the club or coordinator that was asked.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $notes = sanitize_textarea_field( $notes );
        $id    = ( new ProspectConsentRequestsRepository() )
            ->create( $prospect_id, $asked_at, $asked_of, ConsentOutcome::AWAITING, $notes );
        if ( $id <= 0 ) {
            return new \WP_Error( 'consent_request_failed', __( 'Could not record the consent request.', 'talenttrack' ), [ 'status' => 500 ] );
        }

        ( new AuditService() )->record( 'prospect.consent_requested', 'prospect', $prospect_id, [
            'entry_id' => $id,
            'asked_at' => $asked_at,
            'outcome'  => ConsentOutcome::AWAITING,
            'surface'  => 'wizard',
        ] );
        return $id;
    }
}
