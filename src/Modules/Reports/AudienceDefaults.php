<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AudienceDefaults — per-audience defaults the wizard pre-fills.
 *
 * Sprint 4 (#0014). Each audience picks a default scope, section
 * whitelist, privacy posture, and tone variant. The user can override
 * any of these inside the wizard.
 */
final class AudienceDefaults {

    /**
     * @return array{
     *   scope: string,
     *   sections: string[],
     *   privacy: PrivacySettings,
     *   tone_variant: string
     * }
     */
    public static function defaultsFor( string $audience ): array {
        switch ( $audience ) {
            case AudienceType::PARENT_MONTHLY:
                return [
                    'scope'        => 'last_month',
                    'sections'     => [ 'profile', 'ratings', 'goals', 'attendance' ],
                    'privacy'      => new PrivacySettings( false, false, true, false, 0.0 ),
                    'tone_variant' => 'warm',
                ];
            case AudienceType::INTERNAL_DETAILED:
                return [
                    'scope'        => 'all_time',
                    'sections'     => ReportConfig::allSections(),
                    'privacy'      => new PrivacySettings( true, true, true, true, 0.0 ),
                    'tone_variant' => 'formal',
                ];
            case AudienceType::PLAYER_PERSONAL:
                return [
                    'scope'        => 'last_season',
                    'sections'     => [ 'profile', 'ratings', 'goals' ],
                    'privacy'      => new PrivacySettings( false, false, true, false, 0.0 ),
                    'tone_variant' => 'fun',
                ];
            case AudienceType::SCOUT:
                return [
                    'scope'        => 'all_time',
                    'sections'     => [ 'profile', 'ratings' ],
                    'privacy'      => new PrivacySettings( false, false, true, false, 0.0 ),
                    'tone_variant' => 'formal',
                ];
            case AudienceType::STANDARD:
            default:
                return [
                    'scope'        => 'all_time',
                    'sections'     => ReportConfig::allSections(),
                    'privacy'      => new PrivacySettings( false, false, true, false, 0.0 ),
                    'tone_variant' => 'default',
                ];
        }
    }

    /**
     * Resolve a scope keyword to date_from / date_to filter values.
     *
     * @return array{date_from:string, date_to:string}
     */
    public static function resolveScope( string $scope ): array {
        $today = current_time( 'Y-m-d' );
        switch ( $scope ) {
            case 'last_month':
                $from = (string) wp_date( 'Y-m-d', strtotime( '-1 month', current_time( 'timestamp' ) ) );
                return [ 'date_from' => $from, 'date_to' => $today ];
            case 'last_season':
                $from = (string) wp_date( 'Y-m-d', strtotime( '-9 months', current_time( 'timestamp' ) ) );
                return [ 'date_from' => $from, 'date_to' => $today ];
            case 'year_to_date':
                $year = (string) current_time( 'Y' );
                return [ 'date_from' => $year . '-01-01', 'date_to' => $today ];
            case 'all_time':
            default:
                return [ 'date_from' => '', 'date_to' => '' ];
        }
    }

    /**
     * @return array<string, string> scope => human label
     */
    public static function scopeOptions(): array {
        return [
            'last_month'   => __( 'Last month', 'talenttrack' ),
            'last_season'  => __( 'Last season (9 months)', 'talenttrack' ),
            'year_to_date' => __( 'Year to date', 'talenttrack' ),
            'all_time'     => __( 'All time', 'talenttrack' ),
            'custom'       => __( 'Custom range', 'talenttrack' ),
        ];
    }

    /**
     * @return array<string, string> section key => human label
     */
    public static function sectionLabels(): array {
        return [
            'profile'     => __( 'Profile', 'talenttrack' ),
            'ratings'     => __( 'Ratings', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'sessions'    => __( 'Sessions', 'talenttrack' ),
            'attendance'  => __( 'Attendance', 'talenttrack' ),
            'coach_notes' => __( 'Coach notes', 'talenttrack' ),
        ];
    }
}
