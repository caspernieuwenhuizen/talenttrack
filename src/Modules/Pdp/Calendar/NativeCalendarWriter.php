<?php
namespace TT\Modules\Pdp\Calendar;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NativeCalendarWriter — default implementation. Records the event in
 * tt_pdp_calendar_links with provider='native'; no external API call.
 *
 * INSERT IGNORE leans on the (conversation_id, provider) unique key so
 * re-firing the hook for the same conversation is a safe no-op.
 */
class NativeCalendarWriter implements PdpCalendarWriter {

    public function onConversationScheduled( int $conversation_id ): void {
        if ( $conversation_id <= 0 ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'tt_pdp_calendar_links';
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
                (conversation_id, provider, provider_event_id, provider_payload, created_at)
             VALUES (%d, %s, NULL, NULL, %s)",
            $conversation_id,
            'native',
            current_time( 'mysql', true )
        ) );
    }
}
