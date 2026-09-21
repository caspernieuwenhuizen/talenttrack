<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Prospects\Repositories\ProspectsRepository;

/**
 * ConsentGate (#3812) — may this prospect be invited to a test training?
 *
 * One rule, in one place, because the answer is about a child and the two
 * consumers must not be able to disagree: the invite form asks it before
 * it will accept a submit, and any future REST route that arranges a test
 * training asks the same method.
 *
 * ## A hard block, with no exception
 *
 * A child whose family has not agreed is not invited to a test training,
 * and there is no button that says otherwise. That was the locked decision
 * on #3812 and it is deliberately not a warning, not an override and not a
 * capability somebody can be granted. If consent genuinely arrived by a
 * route the system does not know about, the way forward is to **record**
 * it — as `consent_given_at` on the prospect, or as a log entry with
 * outcome `agreed` — not to bypass the gate.
 *
 * Two ways to satisfy it, because consent reaches an academy two ways:
 * directly from the family (the `consent_given_at` column, which predates
 * this) and through the child's own club (a log entry that came back
 * `agreed`, which is what this issue added).
 */
final class ConsentGate {

    /** Is there consent on record for this prospect, by either route? */
    public static function hasConsent( int $prospect_id ): bool {
        if ( $prospect_id <= 0 ) return false;

        $prospect = ( new ProspectsRepository() )->find( $prospect_id );
        if ( $prospect !== null ) {
            $given = (string) ( ( (array) $prospect )['consent_given_at'] ?? '' );
            if ( $given !== '' && $given !== '0000-00-00 00:00:00' ) return true;
        }

        // Installs that predate migration 0284 have no log to read, and
        // fall back to the column alone — which is exactly the behaviour
        // they had before this shipped.
        if ( ! ProspectConsentRequestsRepository::tableExists() ) return false;

        return ( new ProspectConsentRequestsRepository() )->hasAgreed( $prospect_id );
    }

    /**
     * The refusal an invite meets when consent is not on record. It names
     * what is missing and what would fix it, because "not allowed" leaves
     * a head of development with nothing to do about it.
     */
    public static function missingConsentMessage(): string {
        return __( 'This prospect cannot be invited yet: there is no consent on record. Record the family\'s consent on the prospect, or log a consent request that came back agreed, and then send the invitation.', 'talenttrack' );
    }
}
