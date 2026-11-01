<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportAccess (#4569) — who may build, edit, publish and read one stored
 * Studio report.
 *
 * `tt_build_reports` and `tt_publish_reports` are club-wide, so holding one
 * never answers *which* report. Building is granted on `report_studio` at
 * global scope (head of development, academy admin) or per team (head and
 * assistant coach); a team builder builds only about their own teams' players
 * and teams, and never about the academy. The subject is asked again on every
 * route, through `TeamReportAccess` / `PlayerReportAccess`, so a coach who
 * leaves a team stops reaching the reports about it.
 *
 * A recipient reads a published report when they hold one of the persona keys
 * it is published to. Holding `reports` read is not enough: a scout holds it
 * club-wide. Each block's subject is checked again when the data is read.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportAccess {

    /** The personas a report may be published to. Families read snapshots, never a live report. */
    public const PUBLISHABLE_PERSONAS = [
        'head_of_development', 'academy_admin', 'head_coach', 'assistant_coach',
        'team_manager', 'readonly_observer', 'staff',
    ];

    /**
     * The teams this user builds reports about: null for every team, a list
     * for a team builder, an empty list for nobody.
     *
     * @return list<int>|null
     */
    public static function builderTeams( int $user_id ): ?array {
        if ( $user_id <= 0 ) return [];
        if ( user_can( $user_id, 'manage_options' )
            || MatrixGate::can( $user_id, 'report_studio', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL ) ) {
            return null;
        }
        $teams = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id > 0 && MatrixGate::can( $user_id, 'report_studio', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $id ) ) {
                $teams[ $id ] = $id;
            }
        }
        return array_values( $teams );
    }

    /**
     * Why this user may not build a report about this subject, or null when
     * they may.
     *
     * @param list<int> $cohort_team_ids
     */
    public static function buildRefusal( int $user_id, string $scope, ?int $player_id, ?int $team_id, array $cohort_team_ids ): ?StudioError {
        if ( ! user_can( $user_id, 'tt_build_reports' ) ) return self::forbidden( 'forbidden', __( 'You do not build reports.', 'talenttrack' ) );

        $teams  = self::builderTeams( $user_id );
        $covers = static fn( int $team ): bool => $teams === null || in_array( $team, $teams, true );
        if ( $teams === [] ) return self::forbidden( 'forbidden_scope', __( 'You do not build reports for any team.', 'talenttrack' ) );

        switch ( $scope ) {
            case ReportContext::SCOPE_ME:
                return null;
            case ReportContext::SCOPE_ACADEMY:
                return $teams === null && ( user_can( $user_id, 'manage_options' ) || AllTeamsScope::canSeeClubWideAnalytics( $user_id ) )
                    ? null
                    : self::forbidden( 'forbidden_scope', __( 'Only a builder for every team builds a report about the whole academy.', 'talenttrack' ) );
            case ReportContext::SCOPE_TEAM:
                if ( $team_id === null ) return null;
                return $covers( $team_id ) && TeamReportAccess::canRead( $user_id, $team_id )
                    ? null
                    : self::forbidden( 'forbidden_team', __( 'This team is not one you build reports for.', 'talenttrack' ) );
            case ReportContext::SCOPE_PLAYER:
                if ( $player_id === null ) return null;
                $player = QueryHelpers::get_player( $player_id );
                $team   = $player ? (int) ( $player->team_id ?? 0 ) : 0;
                return $player && $covers( $team ) && PlayerReportAccess::canRead( $user_id, $player_id )
                    ? null
                    : self::forbidden( 'forbidden_player', __( 'This player is not one you build reports for.', 'talenttrack' ) );
            case ReportContext::SCOPE_GROUP:
                foreach ( $cohort_team_ids as $team ) {
                    if ( ! $covers( $team ) || ! TeamReportAccess::canRead( $user_id, $team ) ) {
                        return self::forbidden( 'forbidden_team', __( 'This team is not one you build reports for.', 'talenttrack' ) );
                    }
                }
                return null;
        }
        return self::forbidden( 'forbidden_scope', __( 'This report subject is not in your scope.', 'talenttrack' ) );
    }

    /**
     * May this user edit the report: its owner, or a builder for every team,
     * and only while its subject is still one they build for.
     *
     * @param Report $report
     */
    public static function canEdit( int $user_id, array $report ): bool {
        if ( $user_id <= 0 || ! user_can( $user_id, 'tt_build_reports' ) ) return false;
        if ( $report['owner_user_id'] !== $user_id && self::builderTeams( $user_id ) !== null ) return false;
        return self::buildRefusal(
            $user_id,
            $report['scope'],
            $report['player_id'],
            $report['team_id'],
            $report['cohort'] !== null ? $report['cohort']['team_ids'] : []
        ) === null;
    }

    /**
     * May this user publish the report to these personas. A report shared
     * with no one but its owner needs only the build right; reaching anyone
     * else needs `tt_publish_reports`.
     *
     * @param Report       $report
     * @param list<string> $persona_keys
     */
    public static function canPublish( int $user_id, array $report, array $persona_keys ): bool {
        if ( ! self::canEdit( $user_id, $report ) ) return false;
        if ( $persona_keys === [] ) return true;
        return user_can( $user_id, 'tt_publish_reports' );
    }

    /**
     * Is the report published to a persona this user holds.
     *
     * @param Report $report
     */
    public static function isRecipient( int $user_id, array $report ): bool {
        if ( $user_id <= 0 || $report['published_version_id'] === null || $report['archived_at'] !== null ) return false;
        $held = PersonaResolver::personasFor( $user_id );
        if ( $held === [] ) return false;
        return array_intersect( $held, ( new ReportsRepository() )->personaKeys( $report['id'] ) ) !== [];
    }

    /**
     * May this user open the report at all: an editor, or a recipient.
     *
     * @param Report $report
     */
    public static function canRead( int $user_id, array $report ): bool {
        return self::canEdit( $user_id, $report ) || self::isRecipient( $user_id, $report );
    }

    /**
     * What the caller may do, for the payload. Capabilities, never roles.
     *
     * @param Report $report
     * @return array{can_edit:bool, can_publish:bool}
     */
    public static function permissions( int $user_id, array $report ): array {
        $edit = self::canEdit( $user_id, $report );
        return [
            'can_edit'    => $edit,
            'can_publish' => $edit && user_can( $user_id, 'tt_publish_reports' ),
        ];
    }

    private static function forbidden( string $code, string $message ): StudioError {
        return new StudioError( $code, $message, 403 );
    }
}
