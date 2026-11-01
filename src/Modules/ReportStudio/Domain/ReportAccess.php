<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\AllTeamsScope;
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
 * route, through the player and team report rules, so a coach who leaves a
 * team stops reaching the reports about it.
 *
 * A recipient reads a published report when they hold one of the persona keys
 * it is published to **and** can read its subject (`canReadSubject()`).
 * Holding `reports` read is not enough: a scout holds it club-wide. Each
 * block's subject is checked again when the data is read.
 *
 * Every method takes a `ReportReader`, so a list resolves each question once.
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
     * Why this user may not build a report about this subject, or null when
     * they may.
     *
     * @param list<int> $cohort_team_ids
     */
    public static function buildRefusal( ReportReader $reader, string $scope, ?int $player_id, ?int $team_id, array $cohort_team_ids ): ?StudioError {
        $uid = $reader->userId;
        if ( ! user_can( $uid, 'tt_build_reports' ) ) return self::forbidden( 'forbidden', __( 'You do not build reports.', 'talenttrack' ) );

        $teams  = $reader->builderTeams();
        $covers = static fn( int $team ): bool => $teams === null || in_array( $team, $teams, true );
        if ( $teams === [] ) return self::forbidden( 'forbidden_scope', __( 'You do not build reports for any team.', 'talenttrack' ) );

        switch ( $scope ) {
            case ReportContext::SCOPE_ME:
                return null;
            case ReportContext::SCOPE_ACADEMY:
                return $teams === null && self::readsAcademy( $uid )
                    ? null
                    : self::forbidden( 'forbidden_scope', __( 'Only a builder for every team builds a report about the whole academy.', 'talenttrack' ) );
            case ReportContext::SCOPE_TEAM:
                if ( $team_id === null ) return null;
                return $covers( $team_id ) && $reader->readsTeam( $team_id )
                    ? null
                    : self::forbidden( 'forbidden_team', __( 'This team is not one you build reports for.', 'talenttrack' ) );
            case ReportContext::SCOPE_PLAYER:
                if ( $player_id === null ) return null;
                $team = $reader->teamOfPlayer( $player_id );
                return $team !== null && $covers( $team ) && $reader->readsPlayer( $player_id )
                    ? null
                    : self::forbidden( 'forbidden_player', __( 'This player is not one you build reports for.', 'talenttrack' ) );
            case ReportContext::SCOPE_GROUP:
                foreach ( $cohort_team_ids as $team ) {
                    if ( ! $covers( $team ) || ! $reader->readsTeam( $team ) ) {
                        return self::forbidden( 'forbidden_team', __( 'This team is not one you build reports for.', 'talenttrack' ) );
                    }
                }
                return null;
        }
        return self::forbidden( 'forbidden_scope', __( 'This report subject is not in your scope.', 'talenttrack' ) );
    }

    /**
     * May this user edit the report: its owner, or a builder for every team
     * once the report is shared beyond its owner, and only while its subject
     * is still one they build for. A report kept for its owner (`sharing:
     * me`) is theirs alone: nobody else opens, edits, archives or publishes
     * it.
     *
     * @param Report $report
     */
    public static function canEdit( ReportReader $reader, array $report ): bool {
        $uid = $reader->userId;
        if ( $uid <= 0 || ! user_can( $uid, 'tt_build_reports' ) ) return false;
        if ( $report['owner_user_id'] !== $uid ) {
            if ( $report['sharing'] === ReportStartRules::SHARING_ME ) return false;
            if ( $reader->builderTeams() !== null ) return false;
        }
        return self::buildRefusal(
            $reader,
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
    public static function canPublish( ReportReader $reader, array $report, array $persona_keys ): bool {
        if ( ! self::canEdit( $reader, $report ) ) return false;
        if ( $persona_keys === [] ) return true;
        return user_can( $reader->userId, 'tt_publish_reports' );
    }

    /**
     * May this user read what the report is about. A subject chosen when the
     * report is opened is checked then, so an unbound player or team passes
     * here; a group needs at least one of its teams; the academy needs
     * club-wide analytics.
     *
     * @param Report $report
     */
    public static function canReadSubject( ReportReader $reader, array $report ): bool {
        switch ( $report['scope'] ) {
            case ReportContext::SCOPE_ME:
                return true;
            case ReportContext::SCOPE_PLAYER:
                return $report['player_id'] === null || $reader->readsPlayer( $report['player_id'] );
            case ReportContext::SCOPE_TEAM:
                return $report['team_id'] === null || $reader->readsTeam( $report['team_id'] );
            case ReportContext::SCOPE_GROUP:
                foreach ( $report['cohort'] !== null ? $report['cohort']['team_ids'] : [] as $team ) {
                    if ( $reader->readsTeam( $team ) ) return true;
                }
                return false;
            case ReportContext::SCOPE_ACADEMY:
                return self::readsAcademy( $reader->userId );
        }
        return false;
    }

    /**
     * Is the report published to a persona this user holds, about a subject
     * they can read.
     *
     * @param Report $report
     */
    public static function isRecipient( ReportReader $reader, array $report ): bool {
        if ( $reader->userId <= 0 || $report['published_version_id'] === null || $report['archived_at'] !== null ) return false;
        $held = $reader->personas();
        if ( $held === [] ) return false;
        if ( array_intersect( $held, ( new ReportsRepository() )->personaKeys( $report['id'] ) ) === [] ) return false;
        return self::canReadSubject( $reader, $report );
    }

    /**
     * May this user open the report at all: an editor, or a recipient.
     *
     * @param Report $report
     */
    public static function canRead( ReportReader $reader, array $report ): bool {
        return self::canEdit( $reader, $report ) || self::isRecipient( $reader, $report );
    }

    /**
     * What the caller may do, for the payload. Capabilities, never roles.
     *
     * @param Report $report
     * @return array{can_edit:bool, can_publish:bool}
     */
    public static function permissions( ReportReader $reader, array $report ): array {
        $edit = self::canEdit( $reader, $report );
        return [
            'can_edit'    => $edit,
            'can_publish' => $edit && user_can( $reader->userId, 'tt_publish_reports' ),
        ];
    }

    private static function readsAcademy( int $user_id ): bool {
        return $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || AllTeamsScope::canSeeClubWideAnalytics( $user_id ) );
    }

    private static function forbidden( string $code, string $message ): StudioError {
        return new StudioError( $code, $message, 403 );
    }
}
