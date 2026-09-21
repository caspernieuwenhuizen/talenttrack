<?php
/**
 * Migration 0290 — the status verdict and the potential band are staff-only
 * (#3978).
 *
 * The status traffic light and the potential band are the academy's own
 * judgement of a child: how they are doing and how far they are expected to
 * go. `GET /players/{id}/status` and `/potential` gate on the
 * `player_status` entity, and the default seed granted it to the `parent`
 * persona over its own children and to the `player` persona over its own
 * record, so both routes answered the family. That contradicts the rule
 * `AuthorizationService::isStaffForPlayer()` already states for these
 * surfaces, and which the profile's Behaviour & potential card and the
 * family report allowlist (#3955) already follow.
 *
 * The seed no longer grants it. This removes the same rows from existing
 * installs, in the conservative shape of 0154 and 0285: only
 * `is_default = 1` rows are touched, so an academy that deliberately granted
 * families access through the Authorization admin keeps its own row.
 * Idempotent, forward-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Authorization\Matrix\MatrixRepository;

return new class extends Migration {

    public function getName(): string {
        return '0290_player_status_staff_only';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $written = $this->exec(
            "DELETE FROM {$table}
              WHERE entity = 'player_status'
                AND persona IN ('parent', 'player')
                AND is_default = 1"
        );

        // #3854 — zero is the healthy repeat run, or an install whose rows an
        // operator already customised; say which this was.
        $this->report(
            $written,
            $written > 0
                ? 'removed the default family player_status grants'
                : 'no default family player_status grant left to remove'
        );

        if ( class_exists( MatrixRepository::class ) ) {
            MatrixRepository::clearCache();
        }
    }

    public function down(): void {
        // Forward-only. Restoring the rows would hand families the academy's
        // judgement of their child again. An academy that wants that grants
        // it through the Authorization admin, which writes `is_default = 0`.
    }
};
