<?php
namespace TT\Modules\Workflow\Resolvers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * PlayerOrParentResolver — minors-policy aware routing. Reads
 * tt_config.tt_workflow_minors_assignment_policy and the player's
 * date_of_birth + parent_user_id, returns the appropriate assignee(s).
 *
 * Policy values:
 *   - direct_only                    — always [player.wp_user_id]
 *   - parent_proxy                   — always [player.parent_user_id]
 *   - direct_with_parent_visibility  — [player.wp_user_id]
 *                                      (parent visibility is a UI
 *                                      concern handled by the inbox
 *                                      view when a player has a linked
 *                                      parent; nothing the resolver
 *                                      does differently)
 *   - age_based (default)            — <13: parent_proxy.
 *                                      13-15: direct_with_parent_visibility.
 *                                      16+: direct_only.
 *
 * If the policy says "use parent" but parent_user_id is null, the
 * resolver falls back to the player's own wp_user_id (better to send
 * the task somewhere than nowhere). If the player itself has no
 * wp_user_id either, returns an empty array.
 *
 * Multi-parent support is deferred to Phase 2 via a join table. This
 * resolver returns at most one user ID.
 */
class PlayerOrParentResolver implements AssigneeResolver {

    /** Lowest age that gets the direct_with_parent_visibility treatment. */
    private const ADOLESCENT_MIN_AGE = 13;

    /** Lowest age that gets the direct_only treatment. */
    private const ADULT_MIN_AGE = 16;

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        global $wpdb;
        if ( ! $context->player_id ) return [];

        $p = $wpdb->prefix;
        $player = $wpdb->get_row( $wpdb->prepare(
            "SELECT wp_user_id, parent_user_id, date_of_birth
             FROM {$p}tt_players WHERE id = %d LIMIT 1",
            (int) $context->player_id
        ), ARRAY_A );
        if ( ! is_array( $player ) ) return [];

        $player_uid = (int) ( $player['wp_user_id'] ?? 0 );
        $parent_uid = (int) ( $player['parent_user_id'] ?? 0 );
        $dob        = (string) ( $player['date_of_birth'] ?? '' );

        $policy = $this->loadPolicy();
        $effective = $policy === 'age_based'
            ? $this->ageBasedPolicy( $dob )
            : $policy;

        switch ( $effective ) {
            case 'parent_proxy':
                if ( $parent_uid > 0 ) return [ $parent_uid ];
                return $player_uid > 0 ? [ $player_uid ] : [];
            case 'direct_with_parent_visibility':
            case 'direct_only':
            default:
                return $player_uid > 0 ? [ $player_uid ] : ( $parent_uid > 0 ? [ $parent_uid ] : [] );
        }
    }

    private function loadPolicy(): string {
        $value = \TT\Infrastructure\Query\QueryHelpers::get_config( 'tt_workflow_minors_assignment_policy', 'age_based' );
        $valid = [ 'direct_only', 'parent_proxy', 'direct_with_parent_visibility', 'age_based' ];
        return in_array( $value, $valid, true ) ? $value : 'age_based';
    }

    /** Map dob → effective non-age-based policy. Empty/invalid dob defaults to direct_with_parent_visibility (safe middle). */
    private function ageBasedPolicy( string $dob ): string {
        if ( $dob === '' || $dob === '0000-00-00' ) {
            return 'direct_with_parent_visibility';
        }
        $age = $this->ageFromDob( $dob );
        if ( $age === null ) return 'direct_with_parent_visibility';
        if ( $age < self::ADOLESCENT_MIN_AGE ) return 'parent_proxy';
        if ( $age < self::ADULT_MIN_AGE ) return 'direct_with_parent_visibility';
        return 'direct_only';
    }

    private function ageFromDob( string $dob ): ?int {
        $ts = strtotime( $dob );
        if ( $ts === false ) return null;
        $now = current_time( 'timestamp' );
        if ( $ts > $now ) return null;
        // Year-difference minus 1 if the birthday hasn't happened yet this year.
        $birth_y = (int) date( 'Y', $ts );
        $birth_md = (int) date( 'md', $ts );
        $today_y = (int) date( 'Y', $now );
        $today_md = (int) date( 'md', $now );
        $age = $today_y - $birth_y;
        if ( $today_md < $birth_md ) $age--;
        return max( 0, $age );
    }
}
