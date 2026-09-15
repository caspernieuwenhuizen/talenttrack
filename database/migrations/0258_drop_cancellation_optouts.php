<?php
/**
 * Migration 0258 — clear opt-outs for `training_cancelled` (#3382).
 *
 * `TRAINING_CANCELLED` is now operational: a recipient cannot mute it. The
 * product already treated it that way in one direction — it has bypassed
 * quiet hours since the module shipped, so it will wake a family at 23:00 —
 * while still letting that same family switch it off entirely. One of those
 * two judgements was wrong, and it was not the first: a muted cancellation
 * means a child dropped at a pitch nobody came to.
 *
 * WHAT THIS DOES, AND WHY IT IS NOT JUST TIDYING
 *
 * `OptOutPolicy` reads `tt_comms_optouts` as *presence means opted out*. Now
 * that `optOutable()` no longer lists this type, the preferences screen stops
 * offering it — so a parent who muted it before today would keep a stored row
 * they can no longer see and can no longer clear. The row would be inert
 * (`CommsService` asks `isOperational()` first), but "inert" is the kind of
 * claim that stops being true after a refactor, and a preference nobody can
 * reach is exactly the silence #2602 set out to remove.
 *
 * Deleting it makes the stored state match what the screen shows.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not touch `tt_comms_log`. Those rows record what happened at the
 * time and stay as they are — including sends that were suppressed by an
 * opt-out that was valid when it was applied. Rewriting an audit trail to
 * match a policy adopted later is how a log stops being evidence.
 *
 * Idempotent: a DELETE over a condition that is already empty is a no-op, and
 * nothing writes the row back once `optOutable()` stops listing the type.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;
use TT\Modules\Comms\Domain\MessageType;

return new class extends Migration {

    public function getName(): string {
        return '0258_drop_cancellation_optouts';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_optouts';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $removed = $wpdb->delete( $table, [ 'message_type' => MessageType::TRAINING_CANCELLED ] );

        Logger::info( 'migration.0258.summary', [
            'cancellation_optouts_removed' => $removed === false ? 0 : (int) $removed,
        ] );
    }
};
