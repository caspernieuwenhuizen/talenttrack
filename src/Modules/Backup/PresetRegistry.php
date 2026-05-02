<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PresetRegistry — defines which `tt_*` tables each preset includes.
 *
 * Three named presets + a custom mode. Custom is opaque to this class
 * — the settings page reads `selected_tables` directly from the
 * settings option.
 *
 * Tables not yet present on a given install (older schemas, optional
 * modules) are filtered out at snapshot time by BackupSerializer's
 * SHOW TABLES check. The registry intentionally lists every table we
 * might want, including ones added by recent migrations.
 */
class PresetRegistry {

    public const MINIMAL  = 'minimal';
    public const STANDARD = 'standard';
    public const THOROUGH = 'thorough';
    public const CUSTOM   = 'custom';

    /**
     * @return array<string,string[]>
     */
    public static function map(): array {
        $minimal = [
            'tt_players',
            'tt_teams',
            'tt_evaluations',
            'tt_eval_ratings',
        ];
        $standard = array_merge( $minimal, [
            'tt_activities',
            'tt_attendance',
            'tt_goals',
            'tt_people',
            'tt_team_people',
            'tt_functional_role_types',
            'tt_functional_role_assignments',
        ] );
        $thorough = array_merge( $standard, [
            'tt_lookups',
            'tt_custom_fields',
            'tt_custom_values',
            'tt_eval_categories',
            'tt_eval_category_weights',
            'tt_audit_log',
            'tt_usage_events',
            'tt_demo_tags',
            'tt_config',
        ] );

        return [
            self::MINIMAL  => $minimal,
            self::STANDARD => $standard,
            self::THOROUGH => $thorough,
        ];
    }

    /** @return string[] */
    public static function tablesFor( string $preset ): array {
        $map = self::map();
        return $map[ $preset ] ?? [];
    }

    /** @return string[] */
    public static function all(): array {
        return [ self::MINIMAL, self::STANDARD, self::THOROUGH, self::CUSTOM ];
    }

    /** Translated label for a preset slug. */
    public static function label( string $preset ): string {
        switch ( $preset ) {
            case self::MINIMAL:  return __( 'Minimal',  'talenttrack' );
            case self::STANDARD: return __( 'Standard', 'talenttrack' );
            case self::THOROUGH: return __( 'Thorough', 'talenttrack' );
            case self::CUSTOM:   return __( 'Custom',   'talenttrack' );
        }
        return $preset;
    }

    /**
     * Translated description per preset — surfaced under the dropdown
     * on the backup-settings page, swapped client-side as the
     * selection changes.
     */
    public static function description( string $preset ): string {
        switch ( $preset ) {
            case self::MINIMAL:
                return __( 'Core data only: players, teams, evaluations and ratings. Smallest archive, fastest to restore.', 'talenttrack' );
            case self::STANDARD:
                return __( 'Everyday operational data: everything in Minimal plus activities, attendance, goals, people, team-assignments and functional roles. Recommended default.', 'talenttrack' );
            case self::THOROUGH:
                return __( 'Everything: Standard plus lookups, custom-field definitions and values, evaluation categories and weights, audit log, usage stats, demo tags and config. Largest archive, slowest to restore.', 'talenttrack' );
            case self::CUSTOM:
                return __( 'Use the table list below. Only the tables you list are backed up.', 'talenttrack' );
        }
        return '';
    }
}
