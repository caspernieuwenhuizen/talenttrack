<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Recipients\PlayerGuardianLookup;
use TT\Modules\Alerts\Domain\AlertAudience;

/**
 * FamilyAudienceTrait (#3795) — the three lines a player-shaped definition
 * adds to also reach the child's family.
 *
 * Used with `AudienceAwareAlert` on a subclass of `AbstractPlayerAlert`.
 * The base class does the work: it already resolves the row set, and this
 * trait supplies the batched guardian lookup it asks for when a definition
 * says it addresses families.
 *
 * ## The family occurrence is a different sentence about the same fact
 *
 * A definition using this trait writes two texts. `titleFor()` is addressed
 * to the person who can fix the thing — "the evaluation recorded for Bas has
 * no feedback for the player". `familyTitleFor()` is addressed to the
 * family, in the family's terms, and says only what the condition itself
 * states: no staff names, no ratings, no notes, no internal detail that the
 * alert did not already have to mention. When in doubt the family sentence
 * says less (CLAUDE.md §1).
 *
 * ## It carries no extra access
 *
 * The occurrence lands in the parent's alert list and links to their own
 * child's record, which they could already open. The alert tells a family
 * *when* to look, never something they could not have looked at.
 */
trait FamilyAudienceTrait {

    /**
     * @return list<string>
     */
    public function audiences(): array {
        return [ AlertAudience::STAFF, AlertAudience::PARENT ];
    }

    /**
     * Linked guardians for every player in the result set, in one query.
     *
     * @param list<int> $player_ids
     * @return array<int,list<int>> player_id => list of wp_user_id
     */
    protected function familyRecipients( array $player_ids ): array {
        return PlayerGuardianLookup::forPlayers( $player_ids );
    }
}
