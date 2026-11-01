<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportData (#4569) — every block of a stored report, read for one reader.
 *
 * Each block goes through `BlockEngine::preview()`, so each is checked on its
 * own: the block's capability, the subject (`ReportSubjectAccess`, which
 * reads facts through `FactScope`) and the audience cut. A subject refusal
 * refuses the whole report; a block that cannot be read for this subject
 * says so in its own slot and the rest still read.
 *
 * The subject and the period come from the report, never from the request,
 * unless the report leaves them open. A recipient cannot swap the subject or
 * the period of a report that fixes them; a builder can, but only to a
 * subject they build for.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportData {

    /** The refusals that are about the subject, not about one block. */
    private const SUBJECT_CODES = [ 'forbidden_player', 'forbidden_team', 'forbidden_scope', 'bad_date' ];

    /** Context fields whose refusal holds for every block alike. */
    private const CONTEXT_FIELDS = [ 'scope', 'audience', 'player_id', 'team_id', 'player_ids' ];

    /**
     * @param Report $report
     * @param array{source:string, player_id:?int, team_id:?int, from:string, to:string} $in
     * @return array<string,mixed>|StudioError
     */
    public static function compose( array $report, array $in, ReportReader $reader ): array|StudioError {
        $user_id = $reader->userId;
        $editor  = ReportAccess::canEdit( $reader, $report );
        if ( ! $editor && ! ReportAccess::isRecipient( $reader, $report ) ) {
            return new StudioError( 'forbidden', __( 'This report is not shared with you.', 'talenttrack' ), 403 );
        }

        $definition = self::definition( $report, $in['source'], $editor, $user_id );
        if ( $definition instanceof StudioError ) return $definition;

        $subject = self::subject( $report, $in, $editor, $reader );
        if ( $subject instanceof StudioError ) return $subject;

        $period = self::period( $report, $in, $editor );
        if ( $period instanceof StudioError ) return $period;

        $context = [
            'scope'      => $report['scope'],
            'player_id'  => $subject['player_id'],
            'team_id'    => $subject['team_id'],
            'player_ids' => $subject['player_ids'],
            'from'       => $period['from'],
            'to'         => $period['to'],
            'audience'   => $report['audience'],
        ];

        // The subject is checked once for the report, so a report with no
        // blocks yet refuses an out-of-scope subject too; each block checks
        // it again below.
        $checked = ReportContext::fromInput( $context, $user_id );
        if ( $checked instanceof StudioError ) return $checked;
        $checked = ReportSubjectAccess::check( $checked );
        if ( $checked instanceof StudioError ) return $checked;
        // A group is narrowed to the players this reader may see; the blocks
        // and the payload get that list, never the cohort's whole roster.
        $context['player_ids'] = $checked->playerIds;

        $blocks = [];
        foreach ( ReportDefinition::blocks( $definition['definition'] ) as $block ) {
            $read = BlockEngine::preview( $block['key'], $context, self::stringKeys( $block['options'] ), $user_id );
            if ( $read instanceof StudioError ) {
                if ( in_array( $read->code, self::SUBJECT_CODES, true )
                    || in_array( (string) ( $read->details['field'] ?? '' ), self::CONTEXT_FIELDS, true ) ) {
                    return $read;
                }
                $blocks[] = [ 'id' => $block['id'], 'key' => $block['key'], 'error' => [ 'code' => $read->code, 'message' => $read->message ] ];
                continue;
            }
            $blocks[] = [ 'id' => $block['id'], 'key' => $block['key'], 'options' => $read['options'], 'data' => $read['data'] ];
        }

        return [
            'report_id'  => $report['id'],
            'uuid'       => $report['uuid'],
            'source'     => $definition['source'],
            'version_no' => $definition['version_no'],
            'context'    => [
                'scope'      => $report['scope'],
                'player_id'  => $subject['player_id'],
                'team_id'    => $subject['team_id'],
                'player_ids' => $checked->playerIds,
                'from'       => $period['from'],
                'to'         => $period['to'],
                'audience'   => $report['audience'],
            ],
            'blocks'     => $blocks,
        ];
    }

    /**
     * The definition this reader reads: a builder's own draft by default, the
     * published version for everyone else.
     *
     * @param Report $report
     * @return array{definition:array<mixed>, source:string, version_no:?int}|StudioError
     */
    private static function definition( array $report, string $source, bool $editor, int $user_id ): array|StudioError {
        $repo = new ReportsRepository();
        if ( $source === '' ) $source = $editor ? 'draft' : 'published';

        if ( $source === 'draft' ) {
            if ( ! $editor ) {
                return new StudioError( 'forbidden', __( 'A draft is read only by the people who build the report.', 'talenttrack' ), 403 );
            }
            return [ 'definition' => $repo->ensureDraft( $report, $user_id )['definition'], 'source' => 'draft', 'version_no' => null ];
        }

        $version = $report['published_version_id'] !== null ? $repo->versionById( $report['id'], $report['published_version_id'] ) : null;
        if ( $version === null ) {
            return new StudioError( 'not_published', __( 'This report has not been published yet.', 'talenttrack' ), 404 );
        }
        return [ 'definition' => $version['definition'], 'source' => 'published', 'version_no' => $version['version_no'] ];
    }

    /**
     * @param Report $report
     * @param array{source:string, player_id:?int, team_id:?int, from:string, to:string} $in
     * @return array{player_id:?int, team_id:?int, player_ids:list<int>}|StudioError
     */
    private static function subject( array $report, array $in, bool $editor, ReportReader $reader ): array|StudioError {
        $user_id = $reader->userId;
        $scope   = $report['scope'];
        $none    = [ 'player_id' => null, 'team_id' => null, 'player_ids' => [] ];
        $binding = $report['binding'];

        if ( $scope === ReportContext::SCOPE_ME || $scope === ReportContext::SCOPE_ACADEMY ) return $none;

        if ( $scope === ReportContext::SCOPE_GROUP ) {
            if ( $report['cohort'] === null ) return self::missing( 'cohort', __( 'This group has no teams.', 'talenttrack' ) );
            // The cap is per reader: each gets the cohort's players they may
            // see, up to the group limit, so a large cohort is not refused for
            // everyone.
            $cohort  = ( new ReportsRepository() )->cohortPlayers( $report['cohort'] );
            $allowed = AuthorizationService::canViewPlayers( $user_id, $cohort );
            $visible = array_values( array_filter( $cohort, static fn( int $pid ): bool => ! empty( $allowed[ $pid ] ) ) );
            if ( $visible === [] ) {
                return new StudioError( 'forbidden_player', __( 'This report subject is not in your scope.', 'talenttrack' ), 403 );
            }
            return [ 'player_id' => null, 'team_id' => null, 'player_ids' => array_slice( $visible, 0, ReportContext::MAX_GROUP ) ];
        }

        $is_player = $scope === ReportContext::SCOPE_PLAYER;
        $asked     = $is_player ? $in['player_id'] : $in['team_id'];
        $field     = $is_player ? 'player_id' : 'team_id';

        if ( $binding === ReportStartRules::BINDING_FIXED ) {
            $stored = $is_player ? $report['player_id'] : $report['team_id'];
            $id     = $stored;
            if ( $asked !== null && $asked !== $stored ) {
                if ( ! $editor ) {
                    return new StudioError( 'fixed_subject', __( 'This report is about a fixed subject.', 'talenttrack' ), 403, [ 'field' => $field ] );
                }
                $refused = ReportAccess::buildRefusal( $reader, $scope, $is_player ? $asked : null, $is_player ? null : $asked, [] );
                if ( $refused !== null ) return $refused;
                $id = $asked;
            }
        } elseif ( $binding === ReportStartRules::BINDING_VIEWER ) {
            $id = $is_player ? self::viewersPlayer( $user_id ) : self::viewersTeam( $user_id, $asked );
        } else {
            $id = $asked;
        }

        if ( $id === null ) {
            return $is_player
                ? self::missing( 'player_id', __( 'Choose a player.', 'talenttrack' ) )
                : self::missing( 'team_id', __( 'Choose a team.', 'talenttrack' ) );
        }
        return $is_player
            ? [ 'player_id' => $id, 'team_id' => null, 'player_ids' => [] ]
            : [ 'player_id' => null, 'team_id' => $id, 'player_ids' => [] ];
    }

    /**
     * @param Report $report
     * @param array{source:string, player_id:?int, team_id:?int, from:string, to:string} $in
     * @return array{from:string, to:string}|StudioError
     */
    private static function period( array $report, array $in, bool $editor ): array|StudioError {
        $asked = $in['from'] !== '' || $in['to'] !== '';
        if ( $asked ) {
            if ( $report['period_mode'] !== ReportStartRules::PERIOD_ON_OPEN && ! $editor ) {
                return new StudioError( 'fixed_period', __( 'This report covers a set period.', 'talenttrack' ), 403, [ 'fields' => [ 'from', 'to' ] ] );
            }
            return [ 'from' => $in['from'], 'to' => $in['to'] ];
        }

        if ( $report['period_mode'] === ReportStartRules::PERIOD_FIXED ) {
            $parts = explode( '/', $report['period_value'] );
            return [ 'from' => $parts[0], 'to' => $parts[1] ?? $parts[0] ];
        }
        if ( $report['period_mode'] === ReportStartRules::PERIOD_ROLLING ) {
            $window = ReportFilters::periodWindow( $report['period_value'], (string) current_time( 'Y-m-d' ) );
            if ( $window !== null ) return [ 'from' => $window['from'], 'to' => $window['to'] ];
        }
        // Chosen when opened, and opened without a choice: the season so
        // far, the same default a block preview takes.
        $window = ReportFilters::seasonDefaultWindow();
        return [ 'from' => $window['from'], 'to' => $window['to'] ];
    }

    private static function viewersPlayer( int $user_id ): ?int {
        $player = QueryHelpers::get_player_for_user( $user_id );
        $id     = $player ? (int) ( $player->id ?? 0 ) : 0;
        return $id > 0 ? $id : null;
    }

    /** The team asked for when it is one the viewer coaches, else their first. */
    private static function viewersTeam( int $user_id, ?int $asked ): ?int {
        $ids = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id > 0 ) $ids[] = $id;
        }
        if ( $asked !== null && in_array( $asked, $ids, true ) ) return $asked;
        return $ids[0] ?? null;
    }

    private static function missing( string $field, string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => $field ] );
    }

    /**
     * @param array<mixed> $in
     * @return array<string,mixed>
     */
    private static function stringKeys( array $in ): array {
        $out = [];
        foreach ( $in as $key => $value ) $out[ (string) $key ] = $value;
        return $out;
    }
}
