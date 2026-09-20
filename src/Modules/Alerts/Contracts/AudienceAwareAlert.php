<?php
namespace TT\Modules\Alerts\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AudienceAwareAlert (#3795) — the opt-in half of the audience filter.
 *
 * Deliberately a separate interface rather than a method on
 * `AlertInterface`. Two reasons, and the second is the important one.
 *
 * A required method would mean editing every definition in the catalogue to
 * say the thing they already mean by saying nothing, which is churn on
 * twenty files for no behaviour change.
 *
 * And it would make "addresses a family" the *default* answer a new
 * definition has to remember to override. These are minors' records. The
 * safe default is that a definition reaches staff only, and reaching a
 * family is something a class says out loud — here, normally by using
 * `Definitions\FamilyAudienceTrait`.
 *
 * @see \TT\Modules\Alerts\Domain\AlertAudience
 */
interface AudienceAwareAlert {

    /**
     * The audiences this definition may address, from the
     * `AlertAudience` vocabulary.
     *
     * @return list<string>
     */
    public function audiences(): array;
}
