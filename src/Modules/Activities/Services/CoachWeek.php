<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
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
use TT\Modules\MatchPrep\Services\MatchPrepState;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\TrainingModule;

/**
 * CoachWeek (#4379) — the coach's week, Monday to Sunday: every activity on
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

            if ( $when === self::WHEN_PAST ) {
                if ( $register ) {
                    $recorded                   = ActivityRegisterProgress::recordedCount( $aid ) > 0;
                    $row['attendance_recorded'] = $recorded;
                    if ( ! $recorded && $can_edit ) $row['actions'][] = self::ACTION_MARK_ATTENDANCE;
                }
            } elseif ( $is_match ) {
                $row['availability'] = self::availability( $roster, $aid, $date );
                $prep                = $prep_on ? self::prepProgress( $aid, $team_id ) : null;
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
     * Monday and Sunday of the week holding `$today`.
     *
     * @return array{0: string, 1: string}
     */
    public static function weekBounds( string $today ): array {
        $day    = new \DateTimeImmutable( $today . ' 12:00:00', new \DateTimeZone( 'UTC' ) );
        $offset = (int) $day->format( 'N' ) - 1;
        $monday = $day->modify( '-' . $offset . ' days' );
        return [ $monday->format( 'Y-m-d' ), $monday->modify( '+6 days' )->format( 'Y-m-d' ) ];
    }

    /**
     * The persona the user is acting as: the switcher's choice, else the
     * first they hold, as `FrontendTileGrid` resolves it.
     */
    public static function activePersona( int $user_id ): string {
        $active = PersonaResolver::activePersona( $user_id );
        if ( $active !== null && $active !== '' ) return $active;
        $all = PersonaResolver::personasFor( $user_id );
        return (string) ( $all[0] ?? '' );
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
        if ( ! in_array( self::activePersona( $user_id ), self::configuredPersonas(), true ) ) return [];

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
     * How many of the roster can and cannot play in this match. Counts only.
     *
     * @param list<int> $roster
     * @return array{available:int, unavailable:int}
     */
    private static function availability( array $roster, int $activity_id, string $date ): array {
        if ( $roster === [] ) return [ 'available' => 0, 'unavailable' => 0 ];
        $out = count( array_intersect_key(
            PlayerAvailability::unavailableForActivity( $roster, $activity_id, $date ),
            array_flip( $roster )
        ) );
        return [ 'available' => count( $roster ) - $out, 'unavailable' => $out ];
    }

    /**
     * Starters placed in the first half against the formation's target, or
     * null when no match prep exists yet.
     *
     * @return array{placed:int, target:int}|null
     */
    private static function prepProgress( int $activity_id, int $team_id ): ?array {
        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) return null;

        $placed = 0;
        foreach ( $repo->listLineupForPrep( $prep ) as $slot ) {
            $s = (array) $slot;
            if ( (int) ( $s['half'] ?? 0 ) === 1 && (int) ( $s['player_id'] ?? 0 ) > 0 ) $placed++;
        }
        $target = MatchPrepState::starterTarget( (int) ( ( (array) $prep )['formation_template_id'] ?? 0 ), $team_id );
        return [ 'placed' => min( $placed, $target ), 'target' => $target ];
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
