<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EventEmitter — write API for journey events.
 *
 * Idempotency: enforced by uk_natural (source_module, source_entity_type,
 * source_entity_id, event_type). Re-emitting an event for the same
 * source row is a no-op; this lets repository hooks fire on every save
 * without creating duplicates.
 *
 * Defensive validation: bad payloads land with payload_valid=0 and a
 * logged warning. We never reject an emit because the schema drifted —
 * losing an event silently is worse than persisting an under-validated
 * one.
 */
final class EventEmitter {

    /**
     * @param array<string, mixed> $payload
     */
    public static function emit(
        int $player_id,
        string $event_type,
        string $event_date,
        string $summary,
        array $payload = [],
        string $source_module = '',
        string $source_entity_type = '',
        ?int $source_entity_id = null,
        ?string $visibility = null,
        ?int $created_by = null
    ): ?int {
        if ( $player_id <= 0 || $event_type === '' || $source_module === '' || $source_entity_type === '' ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_events';

        // Idempotency — uk_natural lookup before insert.
        if ( $source_entity_id !== null ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table}
                  WHERE source_module = %s
                    AND source_entity_type = %s
                    AND source_entity_id = %d
                    AND event_type = %s
                    AND club_id = %d",
                $source_module,
                $source_entity_type,
                $source_entity_id,
                $event_type,
                CurrentClub::id()
            ) );
            if ( $existing > 0 ) return $existing;
        }

        $valid = EventTypeRegistry::validatePayload( $event_type, $payload );
        if ( ! $valid ) {
            Logger::warning( 'journey.payload_invalid', [
                'event_type' => $event_type,
                'player_id'  => $player_id,
                'payload'    => $payload,
            ] );
        }

        $resolved_visibility = $visibility ?: EventTypeRegistry::defaultVisibilityFor( $event_type );
        $created_by_id       = $created_by ?? get_current_user_id();

        $ok = $wpdb->insert( $table, [
            'club_id'            => CurrentClub::id(),
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $player_id,
            'event_type'         => $event_type,
            'event_date'         => $event_date !== '' ? $event_date : current_time( 'mysql' ),
            'summary'            => mb_substr( $summary, 0, 500 ),
            'payload'            => (string) wp_json_encode( $payload ),
            'payload_valid'      => $valid ? 1 : 0,
            'visibility'         => $resolved_visibility,
            'source_module'      => $source_module,
            'source_entity_type' => $source_entity_type,
            'source_entity_id'   => $source_entity_id,
            'created_by'         => (int) $created_by_id,
        ] );

        if ( $ok === false ) {
            Logger::error( 'journey.emit.failed', [
                'event_type' => $event_type,
                'player_id'  => $player_id,
                'db_error'   => (string) $wpdb->last_error,
            ] );
            return null;
        }

        $event_id = (int) $wpdb->insert_id;

        do_action( 'tt_player_event_emitted', $event_id, $event_type, $player_id );

        return $event_id;
    }

    /**
     * Soft-correct: link an existing event to a freshly emitted replacement.
     * Both rows persist — default timeline reads filter superseded events
     * unless the caller passes `?include_superseded=1`.
     */
    public static function supersede( int $original_event_id, int $replacement_event_id ): bool {
        if ( $original_event_id <= 0 || $replacement_event_id <= 0 ) return false;
        global $wpdb;
        $ok = $wpdb->update(
            $wpdb->prefix . 'tt_player_events',
            [
                'superseded_by_event_id' => $replacement_event_id,
                'superseded_at'          => current_time( 'mysql' ),
            ],
            [ 'id' => $original_event_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }
}
