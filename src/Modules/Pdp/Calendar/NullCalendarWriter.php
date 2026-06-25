<?php
namespace TT\Modules\Pdp\Calendar;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NullCalendarWriter — no-op writer returned by the factory when the
 * `pdp_calendar_integration` sub-feature (#1538) is off. PDP files,
 * conversations and verdicts keep working; only the calendar-feed
 * sidecar write is skipped.
 */
class NullCalendarWriter implements PdpCalendarWriter {

    public function onConversationScheduled( int $conversation_id ): void {
        // Intentionally does nothing — calendar integration is disabled.
    }
}
