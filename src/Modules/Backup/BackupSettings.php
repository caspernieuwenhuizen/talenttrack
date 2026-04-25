<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackupSettings — single source of truth for the backup config option.
 *
 * Stored in `tt_backup_settings` (wp_options) as JSON:
 *
 *   {
 *     "preset":           "standard",
 *     "selected_tables":  ["tt_players", ...],   // only used when preset = "custom"
 *     "schedule":         "daily",                // daily | weekly | on_demand
 *     "retention":        30,                     // count
 *     "destinations": {
 *       "local":  {"enabled": true},
 *       "email":  {"enabled": false, "recipients": ["a@b.c"]}
 *     }
 *   }
 *
 * Defaults are conservative: standard preset, daily, 30 retained, local
 * on, email off. New installs get safe defaults the moment Sprint 1
 * activates.
 */
class BackupSettings {

    public const OPTION = 'tt_backup_settings';

    /**
     * @return array{
     *   preset:string,
     *   selected_tables:string[],
     *   schedule:string,
     *   retention:int,
     *   destinations:array<string,array<string,mixed>>
     * }
     */
    public static function get(): array {
        $raw = get_option( self::OPTION, '' );
        $decoded = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : null;
        if ( ! is_array( $decoded ) ) {
            return self::defaults();
        }
        return self::normalize( $decoded );
    }

    /** @param array<string,mixed> $data */
    public static function save( array $data ): void {
        $normalized = self::normalize( $data );
        update_option( self::OPTION, wp_json_encode( $normalized ), false );
    }

    /**
     * @return array{
     *   preset:string,
     *   selected_tables:string[],
     *   schedule:string,
     *   retention:int,
     *   destinations:array<string,array<string,mixed>>
     * }
     */
    public static function defaults(): array {
        return [
            'preset'          => PresetRegistry::STANDARD,
            'selected_tables' => [],
            'schedule'        => 'daily',
            'retention'       => 30,
            'destinations'    => [
                'local' => [ 'enabled' => true ],
                'email' => [ 'enabled' => false, 'recipients' => [] ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{
     *   preset:string,
     *   selected_tables:string[],
     *   schedule:string,
     *   retention:int,
     *   destinations:array<string,array<string,mixed>>
     * }
     */
    private static function normalize( array $data ): array {
        $defaults = self::defaults();

        $preset = isset( $data['preset'] ) && in_array( (string) $data['preset'], PresetRegistry::all(), true )
            ? (string) $data['preset']
            : $defaults['preset'];

        $selected_tables = [];
        if ( isset( $data['selected_tables'] ) && is_array( $data['selected_tables'] ) ) {
            foreach ( $data['selected_tables'] as $t ) {
                $clean = preg_replace( '/[^a-z0-9_]/i', '', (string) $t );
                if ( $clean !== '' && strpos( $clean, 'tt_' ) === 0 ) $selected_tables[] = $clean;
            }
            $selected_tables = array_values( array_unique( $selected_tables ) );
        }

        $schedule = isset( $data['schedule'] ) && in_array( (string) $data['schedule'], [ 'daily', 'weekly', 'on_demand' ], true )
            ? (string) $data['schedule']
            : $defaults['schedule'];

        $retention = isset( $data['retention'] ) ? max( 1, min( 365, (int) $data['retention'] ) ) : $defaults['retention'];

        $destinations = $defaults['destinations'];
        if ( isset( $data['destinations']['local']['enabled'] ) ) {
            $destinations['local']['enabled'] = ! empty( $data['destinations']['local']['enabled'] );
        }
        if ( isset( $data['destinations']['email'] ) && is_array( $data['destinations']['email'] ) ) {
            $destinations['email']['enabled'] = ! empty( $data['destinations']['email']['enabled'] );
            $recipients = [];
            $raw_rec = $data['destinations']['email']['recipients'] ?? [];
            if ( is_string( $raw_rec ) ) $raw_rec = preg_split( '/[\s,;]+/', $raw_rec ) ?: [];
            if ( is_array( $raw_rec ) ) {
                foreach ( $raw_rec as $email ) {
                    $clean = sanitize_email( (string) $email );
                    if ( $clean !== '' ) $recipients[] = $clean;
                }
            }
            $destinations['email']['recipients'] = array_values( array_unique( $recipients ) );
        }

        return [
            'preset'          => $preset,
            'selected_tables' => $selected_tables,
            'schedule'        => $schedule,
            'retention'       => $retention,
            'destinations'    => $destinations,
        ];
    }

    /**
     * Resolve which tables a snapshot run should include given the
     * current settings. Custom mode → selected_tables; named presets
     * → registry lookup.
     *
     * @return string[]
     */
    public static function resolveTables(): array {
        $cfg = self::get();
        if ( $cfg['preset'] === PresetRegistry::CUSTOM ) {
            return $cfg['selected_tables'];
        }
        return PresetRegistry::tablesFor( $cfg['preset'] );
    }
}
