<?php
namespace TT\Modules\Pdp\Calendar;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpCalendarWriter — strategy seam for "a conversation was scheduled,
 * write it to the calendar somewhere."
 *
 * The native implementation just records a row in tt_pdp_calendar_links
 * so the file detail view can show a link. The Spond implementation
 * (#0031) calls the Spond API and stores the returned event id in the
 * same row. Same interface, swap-able via the `tt_pdp_calendar_writer`
 * filter — that's why this is an interface today even though only one
 * implementation ships.
 */
interface PdpCalendarWriter {

    /**
     * Called once per scheduled conversation. Writers are expected to be
     * idempotent on (conversation_id, provider) — the unique key on
     * tt_pdp_calendar_links enforces it at the DB level.
     */
    public function onConversationScheduled( int $conversation_id ): void;
}
