<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;

/**
 * ActivityLifecycle (#3081) — the rule tying the two lifecycle columns.
 *
 * `tt_activities` carries `activity_status_key` (what the reports read)
 * and `plan_state` (what the planner and the wizards read). Migration
 * 0144 made them distinct axes on purpose, but a terminal status implies
 * the matching terminal plan state: an activity that reads "Cancelled"
 * must not still be sitting in the planner's upcoming bucket.
 *
 * #1349 established that for the REST edit form, inline. The wp-admin
 * activities page posts the same field set and had the same defect, so
 * the rule lives here instead of being written twice and drifting.
 */
final class ActivityLifecycle {

    /**
     * The plan state a terminal status implies, or null when the status
     * is not terminal and the caller should leave `plan_state` alone.
     *
     * Only for the save paths that post `activity_status_key` without a
     * `plan_state` of their own. A caller that supplied an explicit
     * plan state means it, and this must not override it.
     */
    public static function planStateForTerminalStatus( string $status_key ): ?string {
        if ( $status_key === ActivityStatusKey::COMPLETED ) return 'completed';
        if ( $status_key === ActivityStatusKey::CANCELLED ) return 'cancelled';
        return null;
    }
}
