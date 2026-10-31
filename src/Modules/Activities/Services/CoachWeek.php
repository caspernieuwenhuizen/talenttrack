<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Activities\ActivitiesModule;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\MatchExecution\MatchExecutionModule;
use TT\Modules\MatchPrep\MatchPrepModule;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\FormationLayoutResolver;
use TT\Modules\MatchPrep\Services\MatchPrepState;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\TrainingModule;
use TT\Shared\Dates\TTDate;

/**
 * CoachWeek (#4379) — the coach's week (Monday to Sunday unless the academy
 * starts its week on Sunday): every activity on
 * the teams they coach, and what each one still needs.
 *
 * The tile landing's "This week" panel and `GET me/week` both read this,
 * so the two cannot disagree. Every rule lives here: who sees the week,
 * the week's bounds, recorded or not, the planned squad, the training
 * plan, the match squad's availability and the line-up's progress. The
 * view only turns the rows into words and links.
 *
 * Availability is the neutral state only (#4005 decision 3): a count of
 * players who cannot play, never why.
 */
final class CoachWeek {

    /** `tt_config` key: a JSON list of the personas that see the week. */
    public const CONFIG_KEY = 'coach_week.personas';

    /** @var list<string> */
    public const DEFAULT_PERSONAS = [ 'head_coach', 'assistant_coach' ];

    /**
     * The personas the setting may name. Players and parents are not
     * among them: the week is a staff view of a squad's activities.
     *
     * @var list<string>
     */
    public const PERSONAS = [
        'academy_admin',
        'head_of_development',
        'head_coach',
        'assistant_coach',
        'team_manager',
        'scout',
        'readonly_observer',
    ];

    public const WHEN_PAST     = 'past';
    public const WHEN_TODAY    = 'today';
    public const WHEN_UPCOMING = 'upcoming';

    public const ACTION_MARK_ATTENDANCE = 'mark_attendance';
    public const ACTION_MATCH_PREP      = 'match_prep';
    public const ACTION_START_MATCH     = 'start_match';
    public const ACTION_OPEN_ACTIVITY   = 'open_activity';

    /**
     * The types with no register to fill in, as `ActivityRegisterProgress`
     * reads them.
     *
     * @var list<string>
     */
    private const NO_REGISTER_TYPES = [ ActivityTypeKey::MEETING, ActivityTypeKey::OTHER ];

    /**
     * The personas the academy has chosen, the default when it has not.
     *
     * @return list<string>
     */
    public static function configuredPersonas(): array {
        $raw = ( new ConfigService() )->get( self::CONFIG_KEY, '' );
        if ( trim( $raw ) === '' ) return self::DEFAULT_PERSONAS;
        return self::decodePersonas( $raw );
    }

    /**
     * The stored form of the setting: canonical JSON of known persona keys.
     * Takes a JSON list or a comma-separated string.
     */
    public static function normaliseStored( string $raw ): string {
        return (string) wp_json_encode( self::decodePersonas( $raw ) );
    }

    /**
     * True when this user, acting as their current persona, sees the week:
     * the persona is in the academy's list and they coach at least one team.
     */
    public static function visibleFor( int $user_id ): bool {
        return self::teamsIfVisible( $user_id ) !== [];
    }

    /**
     * The week for `GET me/week` and the landing panel.
     *
     * @param string|null $today `Y-m-d`; site time when null.
     * @return array{
     *   visible: bool, week_start: string, week_end: string, today: string,
     *   multiple_teams: bool, has_match: bool, rows: list<array<string, mixed>>
     * }
     */
    public static function forUser( int $user_id, ?string $today = null ): array {
        $today = self::validDate( $today ) ?? AttendanceDateRule::today();
        [ $start, $end ] = self::weekBounds( $today );

        $out = [
            'visible'        => false,
            'week_start'     => $start,
            'week_end'       => $end,
            'today'          => $today,
            'multiple_teams' => false,
            'has_match'      => false,
            'rows'           => [],
        ];

        $teams = self::teamsIfVisible( $user_id );
        if ( $teams === [] ) return $out;

        $out['visible']        = true;
        $out['multiple_teams'] = count( $teams ) > 1;

        $repo       = new ActivitiesRepository();
        $activities = $repo->listForTeamsInWindow( array_keys( $teams ), $start, $end );
        if ( $activities === [] ) return $out;

        $ids = [];
        foreach ( $activities as $a ) $ids[] = (int) ( ( (array) $a )['id'] ?? 0 );

        ActivityRegisterProgress::prime( $activities );
        $planned = $repo->plannedStatusCounts( $ids );
        $rosters = self::rostersByTeam( array_keys( $teams ) );
        $runs    = ModuleRegistry::isEnabled( TrainingModule::class )
            ? ( new TrainingPlanRunsRepository() )->activityIdsWithRun( $ids )
            : null;

        $can_edit     = AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_activities' );
        $prep_on      = ModuleRegistry::isEnabled( MatchPrepModule::class );
        $execution_on = ModuleRegistry::isEnabled( MatchExecutionModule::class );
        $type_labels  = [];

        // The matches still to play, read in one batch per source rather
        // than per row: a tournament week can hold a dozen fixtures.
        $matches = [];
        foreach ( $activities as $activity ) {
            $a    = (array) $activity;
            $date = substr( (string) ( $a['session_date'] ?? '' ), 0, 10 );
            $type = strtolower( trim( (string) ( $a['activity_type_key'] ?? '' ) ) );
            if ( $date < $today || ! ActivityTypeKey::isMatchLike( $type ) || self::isCompleted( $a ) ) continue;
            $team_id = (int) ( $a['team_id'] ?? 0 );
            $matches[ (int) ( $a['id'] ?? 0 ) ] = [
                'team_id'    => $team_id,
                'type'       => $type,
                'date'       => $date,
                'player_ids' => $rosters[ $team_id ] ?? [],
            ];
        }
        $unavailable = $matches !== [] ? PlayerAvailability::unavailableForActivities( $matches ) : [];
        $lineups     = ( $matches !== [] && $prep_on ) ? self::prepProgressFor( $matches ) : [];

        foreach ( $activities as $activity ) {
            $a        = (array) $activity;
            $aid      = (int) ( $a['id'] ?? 0 );
            $team_id  = (int) ( $a['team_id'] ?? 0 );
            $date     = substr( (string) ( $a['session_date'] ?? '' ), 0, 10 );
            $type     = strtolower( trim( (string) ( $a['activity_type_key'] ?? '' ) ) );
            $is_match = ActivityTypeKey::isMatchLike( $type );
            if ( $is_match ) $out['has_match'] = true;
            $when     = $date < $today ? self::WHEN_PAST : ( $date === $today ? self::WHEN_TODAY : self::WHEN_UPCOMING );
            $register = ! in_array( $type, self::NO_REGISTER_TYPES, true );
            $roster   = $rosters[ $team_id ] ?? [];

            if ( ! isset( $type_labels[ $type ] ) ) {
                $type_labels[ $type ] = (string) ( LabelTranslator::activityType( $type ) ?? ucfirst( str_replace( '_', ' ', $type ) ) );
            }

            $row = [
                'activity_id'         => $aid,
                'team_id'             => $team_id,
                'team_name'           => $teams[ $team_id ] ?? '',
                'date'                => $date,
                'when'                => $when,
                'type_key'            => $type,
                'type_label'          => $type_labels[ $type ],
                'is_match'            => $is_match,
                'title'               => (string) ( $a['title'] ?? '' ),
                'opponent'            => $is_match ? (string) ( $a['opponent'] ?? '' ) : '',
                'home_away'           => $is_match ? self::homeAway( (string) ( $a['home_away'] ?? '' ) ) : '',
                'start_time'          => self::hhmm( (string) ( $a['start_time'] ?? '' ) ),
                'end_time'            => self::hhmm( (string) ( $a['end_time'] ?? '' ) ),
                'meet_time'           => self::hhmm( (string) ( $a['time_of_presence'] ?? '' ) ),
                'location'            => (string) ( $a['location'] ?? '' ),
                'attendance_recorded' => null,
                'planned'             => null,
                'training_plan'       => null,
                'availability'        => null,
                'lineup'              => null,
                'actions'             => [],
            ];

            // #4486 — a completed match is done whatever day it falls on: a
            // coach who recorded it by hand gets the record, never a live start.
            $done = $is_match && self::isCompleted( $a );

            if ( $when === self::WHEN_PAST || $done ) {
                if ( $register ) {
                    $recorded                   = ActivityRegisterProgress::recordedCount( $aid ) > 0;
                    $row['attendance_recorded'] = $recorded;
                    if ( ! $recorded && $can_edit ) $row['actions'][] = self::ACTION_MARK_ATTENDANCE;
                }
                if ( $done && $when !== self::WHEN_PAST ) $row['actions'][] = self::ACTION_OPEN_ACTIVITY;
            } elseif ( $is_match ) {
                $out_count           = count( $unavailable[ $aid ] ?? [] );
                $row['availability'] = [ 'available' => count( $roster ) - $out_count, 'unavailable' => $out_count ];
                $prep                = $lineups[ $aid ] ?? null;
                $row['lineup']       = $prep;
                if ( $can_edit && $prep_on ) {
                    $row['actions'][] = ( $when === self::WHEN_TODAY && $prep !== null && $execution_on )
                        ? self::ACTION_START_MATCH
                        : self::ACTION_MATCH_PREP;
                }
                $row['actions'][] = self::ACTION_OPEN_ACTIVITY;
            } else {
                $row['planned'] = self::planned( $planned[ $aid ] ?? [], count( $roster ) );
                if ( $type === ActivityTypeKey::TRAINING && $runs !== null ) {
                    $row['training_plan'] = isset( $runs[ $aid ] );
                }
                if ( $when === self::WHEN_TODAY && $register && $can_edit ) {
                    $row['actions'][] = self::ACTION_MARK_ATTENDANCE;
                }
            }

            $out['rows'][] = $row;
        }

        return $out;
    }

    /**
     * First and last day of the week holding `$today`. The week starts on the
     * academy's configured first day (`TTDate::weekStartsMonday()`, Monday by
     * default), the same snap the team planner applies, so "this week" is
     * one week on the landing and in the planner.
     *
     * @param bool|null $starts_monday The academy setting when null.
     * @return array{0: string, 1: string}
     */
    public static function weekBounds( string $today, ?bool $starts_monday = null ): array {
        $starts_monday = $starts_monday ?? TTDate::weekStartsMonday();
        $day   = new \DateTimeImmutable( $today . ' 12:00:00', new \DateTimeZone( 'UTC' ) );
        $dow   = (int) $day->format( 'N' ); // 1 (Mon) - 7 (Sun)
        $back  = $starts_monday ? $dow - 1 : $dow % 7;
        $first = $day->modify( '-' . $back . ' days' );
        return [ $first->format( 'Y-m-d' ), $first->modify( '+6 days' )->format( 'Y-m-d' ) ];
    }

    /**
     * The user's coached teams, id => name, when the week is theirs to see;
     * empty otherwise.
     *
     * @return array<int, string>
     */
    private static function teamsIfVisible( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( ! ModuleRegistry::isEnabled( ActivitiesModule::class ) ) return [];
        if ( ! in_array( PersonaResolver::landingPersona( $user_id ), self::configuredPersonas(), true ) ) return [];

        $teams = [];
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            $fields = (array) $team;
            $id     = (int) ( $fields['id'] ?? 0 );
            if ( $id > 0 ) $teams[ $id ] = (string) ( $fields['name'] ?? '' );
        }
        return $teams;
    }

    /**
     * @return list<string>
     */
    private static function decodePersonas( string $raw ): array {
        $raw     = trim( $raw );
        $decoded = json_decode( $raw, true );
        $parts   = is_array( $decoded ) ? $decoded : ( $raw === '' ? [] : explode( ',', $raw ) );
        $out     = [];
        foreach ( $parts as $key ) {
            $key = trim( is_scalar( $key ) ? (string) $key : '' );
            if ( in_array( $key, self::PERSONAS, true ) && ! in_array( $key, $out, true ) ) $out[] = $key;
        }
        return $out;
    }

    /**
     * Team id => the ids of its current players, one query for every team.
     *
     * @param list<int> $team_ids
     * @return array<int, list<int>>
     */
    private static function rostersByTeam( array $team_ids ): array {
        $out = [];
        foreach ( QueryHelpers::get_players_for_teams( $team_ids ) as $player ) {
            $fields = (array) $player;
            $pid    = (int) ( $fields['id'] ?? 0 );
            if ( $pid > 0 ) $out[ (int) ( $fields['team_id'] ?? 0 ) ][] = $pid;
        }
        return $out;
    }

    /**
     * Planned squad against the roster, or null when no plan was captured.
     * A stored Absent is "not coming" and Excused is "maybe"; anything else
     * is expected, as `GET activities/{id}/planned-attendance` reads it.
     *
     * @param array<string, int> $statuses stored status => count
     * @return array{expected:int, roster:int}|null
     */
    private static function planned( array $statuses, int $roster ): ?array {
        if ( $statuses === [] ) return null;
        $expected = 0;
        foreach ( $statuses as $status => $n ) {
            $canonical = AttendanceStatus::normalise( (string) $status );
            if ( $canonical === AttendanceStatus::ABSENT || $canonical === AttendanceStatus::EXCUSED ) continue;
            $expected += $n;
        }
        return [ 'expected' => $expected, 'roster' => $roster ];
    }

    /**
     * Per match with a prep: first-half starters placed on the slots the
     * formation draws, against the formation's starter target. The same
     * count `MatchPrepRepository::listLineupForPrep()` and
     * `MatchPrepState::starterTarget()` give one match at a time, read in
     * two queries for the week, with each formation's layout resolved once.
     *
     * @param array<int, array{team_id:int, type:string, date:string, player_ids:list<int>}> $matches activity id => facts
     * @return array<int, array{placed:int, target:int}> activity id => progress; absent when no prep
     */
    private static function prepProgressFor( array $matches ): array {
        $repo    = new MatchPrepRepository();
        $preps   = $repo->findByActivities( array_keys( $matches ) );
        if ( $preps === [] ) return [];

        $prep_ids = [];
        foreach ( $preps as $prep ) $prep_ids[] = (int) ( ( (array) $prep )['id'] ?? 0 );
        $lineups = $repo->listLineupForPreps( $prep_ids );

        $layouts = [];
        $out     = [];
        foreach ( $preps as $aid => $prep ) {
            $p           = (array) $prep;
            $template_id = (int) ( $p['formation_template_id'] ?? 0 );
            $team_id     = (int) ( $matches[ $aid ]['team_id'] ?? 0 );
            $key         = $template_id . '|' . $team_id;
            if ( ! isset( $layouts[ $key ] ) ) {
                $drawn = [];
                foreach ( FormationLayoutResolver::layoutFor( $template_id, $team_id ) as $slot ) {
                    $drawn[ (int) $slot['num'] ] = true;
                }
                $layouts[ $key ] = $drawn;
            }
            $drawn  = $layouts[ $key ];
            $target = $drawn !== [] ? count( $drawn ) : MatchPrepState::FALLBACK_STARTER_TARGET;

            $placed = 0;
            foreach ( $lineups[ (int) ( $p['id'] ?? 0 ) ] ?? [] as $slot ) {
                $s = (array) $slot;
                if ( (int) ( $s['half'] ?? 0 ) !== 1 || (int) ( $s['player_id'] ?? 0 ) <= 0 ) continue;
                if ( isset( $drawn[ (int) ( $s['slot_number'] ?? 0 ) ] ) ) $placed++;
            }
            $out[ (int) $aid ] = [ 'placed' => min( $placed, $target ), 'target' => $target ];
        }
        return $out;
    }

    /**
     * The activity's status is completed, compared case-insensitively as
     * `Infrastructure\Query\ActivityLifecycle::completedClause()` does.
     *
     * @param array<string, mixed> $activity
     */
    private static function isCompleted( array $activity ): bool {
        return strtolower( trim( (string) ( $activity['activity_status_key'] ?? '' ) ) ) === ActivityStatusKey::COMPLETED;
    }

    private static function homeAway( string $value ): string {
        $value = strtolower( trim( $value ) );
        return in_array( $value, [ 'home', 'away' ], true ) ? $value : '';
    }

    private static function hhmm( string $time ): string {
        return preg_match( '/^(\d{2}:\d{2})/', trim( $time ), $m ) === 1 ? $m[1] : '';
    }

    private static function validDate( ?string $date ): ?string {
        $date = substr( trim( (string) $date ), 0, 10 );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) === 1 ? $date : null;
    }
}
