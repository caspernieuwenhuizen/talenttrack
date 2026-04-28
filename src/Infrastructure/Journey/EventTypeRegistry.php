<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EventTypeRegistry — taxonomy lookups for journey event types.
 *
 * Reads from `tt_lookups[lookup_type='journey_event_type']` (cached per
 * request). v1 payload schemas live in PHP because they bind to caller
 * code; the lookup row carries icon / color / severity / visibility /
 * group rendering meta and is admin-extensible.
 */
final class EventTypeRegistry {

    /** @var array<string, EventTypeDefinition>|null */
    private static ?array $cache = null;

    /** @var array<string, array<string, string>> */
    private const PAYLOAD_SCHEMAS = [
        'team_changed' => [
            'from_team_id'   => 'int',
            'to_team_id'     => 'int',
            'from_team_name' => 'string',
            'to_team_name'   => 'string',
        ],
        'position_changed' => [
            'from' => 'string',
            'to'   => 'string',
        ],
        'age_group_promoted' => [
            'from_team_id'   => 'int',
            'to_team_id'     => 'int',
            'from_age_group' => 'string',
            'to_age_group'   => 'string',
        ],
        'injury_started' => [
            'injury_id'          => 'int',
            'expected_weeks_out' => 'int',
            'severity_key'       => 'string',
            'body_part'          => 'string',
        ],
        'injury_ended' => [
            'injury_id'     => 'int',
            'days_out'      => 'int',
            'expected_days' => 'int',
        ],
        'evaluation_completed' => [
            'evaluation_id' => 'int',
            'overall'       => 'float',
        ],
        'pdp_verdict_recorded' => [
            'pdp_file_id' => 'int',
            'decision'    => 'string',
        ],
        'trial_ended' => [
            'trial_case_id' => 'int',
            'decision'      => 'string',
            'context'       => 'string',
        ],
        'released' => [
            'context' => 'string',
        ],
    ];

    public static function clearCache(): void {
        self::$cache = null;
    }

    /** @return array<string, EventTypeDefinition> */
    public static function all(): array {
        if ( self::$cache !== null ) return self::$cache;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT name, description, meta FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s ORDER BY sort_order, id",
            'journey_event_type'
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $key  = (string) $row->name;
            $meta = is_string( $row->meta ) ? json_decode( $row->meta, true ) : [];
            if ( ! is_array( $meta ) ) $meta = [];

            $out[ $key ] = new EventTypeDefinition(
                $key,
                (string) ( $row->description ?? $key ),
                (string) ( $meta['severity'] ?? EventTypeDefinition::SEVERITY_INFO ),
                (string) ( $meta['default_visibility'] ?? EventTypeDefinition::VISIBILITY_PUBLIC ),
                (string) ( $meta['group'] ?? 'other' ),
                (string) ( $meta['icon'] ?? 'note' ),
                (string) ( $meta['color'] ?? '#5b6e75' ),
                self::PAYLOAD_SCHEMAS[ $key ] ?? []
            );
        }

        self::$cache = $out;
        return $out;
    }

    public static function find( string $key ): ?EventTypeDefinition {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    /**
     * Default visibility for a type, falling back to public when the
     * type is unknown (admin-added types without meta still emit).
     */
    public static function defaultVisibilityFor( string $key ): string {
        $def = self::find( $key );
        return $def ? $def->defaultVisibility : EventTypeDefinition::VISIBILITY_PUBLIC;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validatePayload( string $key, array $payload ): bool {
        $def = self::find( $key );
        return $def ? $def->validatePayload( $payload ) : true;
    }
}
