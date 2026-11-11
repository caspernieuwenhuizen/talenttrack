<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * StudioHome (#4962, epic #4564) — a Report Studio dashboard as a persona's
 * home: the first screen everyone holding that persona sees after logging in.
 *
 * Only the personas a report can be published to get one
 * (`ReportAccess::PUBLISHABLE_PERSONAS`). A family never reads a live report,
 * so the player's and the parent's home stay on the persona dashboard editor.
 * The dashboard must be published to that persona, with a subject each reader
 * resolves for themselves: the rule a report published for a role already
 * follows (`ReportStartRules`, sharing "role").
 *
 * The choice is a `tt_config` row per club, next to the persona dashboard's
 * own `persona_dashboard.<persona>` keys, holding the report id. It is checked
 * again on every read: a dashboard that was archived, unpublished or taken
 * off the persona stops being the home, and the persona's own template shows.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class StudioHome {

    /**
     * The `tt_config` key for one persona's Studio home.
     */
    public static function configKey( string $persona ): string {
        return 'persona_dashboard.' . sanitize_key( $persona ) . '.studio_report';
    }

    /** The stored report id for this persona in this club, 0 when none. */
    public static function storedId( string $persona ): int {
        if ( ! in_array( $persona, ReportAccess::PUBLISHABLE_PERSONAS, true ) ) return 0;
        $raw = QueryHelpers::get_config( self::configKey( $persona ), '' );
        return ctype_digit( $raw ) ? (int) $raw : 0;
    }

    /**
     * The dashboard this persona lands on, while it still may be its home.
     *
     * @return Report|null
     */
    public static function reportFor( string $persona ): ?array {
        $id = self::storedId( $persona );
        if ( $id <= 0 ) return null;
        $report = ( new ReportsRepository() )->find( $id );
        return self::refusal( $persona, $report ) === null ? $report : null;
    }

    /**
     * Why this report cannot be this persona's home, or null when it can.
     *
     * @param Report|null $report
     */
    public static function refusal( string $persona, ?array $report ): ?StudioError {
        $persona_refused = self::personaRefusal( $persona );
        if ( $persona_refused !== null ) return $persona_refused;
        if ( $report === null || $report['archived_at'] !== null ) {
            return new StudioError( 'not_found', __( 'This dashboard does not exist, or it was removed.', 'talenttrack' ), 404, [ 'field' => 'report_id' ] );
        }
        if ( $report['kind'] !== ReportStartRules::KIND_DASHBOARD ) {
            return self::invalid( __( 'Only a dashboard can be a home page, not a report.', 'talenttrack' ) );
        }
        if ( $report['binding'] === ReportStartRules::BINDING_FIXED && $report['scope'] !== ReportContext::SCOPE_ACADEMY ) {
            // The start rule's own words for a role report with a fixed subject.
            $reason = ReportStartRules::reason( 'sharing', ReportStartRules::SHARING_ROLE, [ 'binding' => $report['binding'], 'scope' => $report['scope'] ], ReportReader::for( 0 ) );
            return self::invalid( $reason ?? __( 'A home page is chosen when opened or follows the viewer, so each reader sees their own subject.', 'talenttrack' ) );
        }
        if ( $report['published_version_id'] === null ) {
            return self::invalid( __( 'Publish the dashboard before it becomes a home page.', 'talenttrack' ) );
        }
        if ( ! in_array( $persona, ( new ReportsRepository() )->personaKeys( $report['id'] ), true ) ) {
            return self::invalid( __( 'This dashboard is not published to that role.', 'talenttrack' ) );
        }
        return null;
    }

    /** Why this persona cannot have a Studio home at all, or null when it can. */
    public static function personaRefusal( string $persona ): ?StudioError {
        if ( in_array( $persona, ReportAccess::PUBLISHABLE_PERSONAS, true ) ) return null;
        return new StudioError(
            'invalid_field',
            __( 'A Studio dashboard can be the home of a staff role a report is published to. The player\'s and the parent\'s home stay on the dashboard editor.', 'talenttrack' ),
            400,
            [ 'field' => 'persona', 'allowed' => ReportAccess::PUBLISHABLE_PERSONAS ]
        );
    }

    /**
     * Make this report the persona's home. The caller decides who may; this
     * decides whether the report may be one.
     *
     * @return Report|StudioError
     */
    public static function set( string $persona, int $report_id ): array|StudioError {
        $report  = $report_id > 0 ? ( new ReportsRepository() )->find( $report_id ) : null;
        $refused = self::refusal( $persona, $report );
        if ( $refused !== null || $report === null ) return $refused ?? self::invalid( __( 'Choose a dashboard.', 'talenttrack' ) );
        QueryHelpers::set_config( self::configKey( $persona ), (string) $report['id'] );
        return $report;
    }

    /** The persona's home goes back to its persona dashboard template. */
    public static function clear( string $persona ): void {
        QueryHelpers::delete_config( self::configKey( $persona ) );
    }

    private static function invalid( string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => 'report_id' ] );
    }
}
