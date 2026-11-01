<?php
namespace TT\Modules\Activities\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\ActivitiesModule;
use TT\Modules\Activities\ActivityAccess;
use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockRegistry;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\CoachWeek\WeekSettings;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Dates\TTDate;

/**
 * CoachWeek (#4379) — the coach's week: every activity on the teams they
 * work with, and what each one still needs.
 *
 * The tile landing's "This week" panel and `GET me/week` both read this,
 * so the two cannot disagree. Who sees the week, its window, its teams and
 * which blocks are on per device follow the academy's settings (#4492,
 * `WeekSettings`); what each block contributes lives in its class under
 * `Modules\Activities\CoachWeek\Blocks`, asked through `WeekBlockRegistry`.
 * The view only turns the result into words and links.
 *
 * Availability is the neutral state only (#4005 decision 3): a count of
 * players who cannot play, never why.
 */
final class CoachWeek {

    /** `tt_config` key: the older JSON list of the personas that see the week, the fallback when `SETTINGS_KEY` is absent. */
    public const CONFIG_KEY = 'coach_week.personas';

    /** `tt_config` key: the per-persona settings (#4492). */
    public const SETTINGS_KEY = WeekSettings::CONFIG_KEY;

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

    public const WHEN_PAST     = WeekRow::WHEN_PAST;
    public const WHEN_TODAY    = WeekRow::WHEN_TODAY;
    public const WHEN_UPCOMING = WeekRow::WHEN_UPCOMING;

    public const ACTION_MARK_ATTENDANCE = 'mark_attendance';
    public const ACTION_MATCH_PREP      = 'match_prep';
    public const ACTION_START_MATCH     = 'start_match';
    public const ACTION_OPEN_ACTIVITY   = 'open_activity';
    public const ACTION_MINUTES_STATS   = 'minutes_stats';
    public const ACTION_RATE_PLAYERS    = 'rate_players';
    public const ACTION_MATCH_ANALYSIS  = 'match_analysis';

    /**
     * The personas that see the panel.
     *
     * @return list<string>
     */
    public static function configuredPersonas(): array {
        $out = [];
        foreach ( self::settings() as $persona => $settings ) {
            if ( $settings['on'] ) $out[] = $persona;
        }
        return $out;
    }

    /**
     * Every persona's settings, normalised.
     *
     * @return array<string, array{on:bool, phone_on:bool, window:string, teams:string, past:string, desktop:list<string>, phone:list<string>}>
     */
    public static function settings(): array {
        return WeekSettings::effective( self::PERSONAS, self::legacyPersonas() );
    }

    /**
     * Every persona's settings when the academy has not chosen, for the
     * settings screen's Reset.
     *
     * @return array<string, array{on:bool, phone_on:bool, window:string, teams:string, past:string, desktop:list<string>, phone:list<string>}>
     */
    public static function defaultSettings(): array {
        $out = [];
        foreach ( self::PERSONAS as $persona ) $out[ $persona ] = WeekSettings::defaultsFor( $persona );
        return $out;
    }

    /**
     * The stored form of the older persona list: canonical JSON of known
     * persona keys. Takes a JSON list or a comma-separated string.
     */
    public static function normaliseStored( string $raw ): string {
        return (string) wp_json_encode( self::decodePersonas( $raw ) );
    }

    /** The stored form of the settings: canonical JSON, known personas and blocks only. */
    public static function normaliseSettingsStored( string $raw ): string {
        return WeekSettings::normaliseStored( $raw, self::PERSONAS );
    }

    /**
     * True when this user, acting as their current persona, sees the week:
     * the panel is on for the persona and the week covers at least one team.
     */
    public static function visibleFor( int $user_id ): bool {
        $settings = self::settingsForUser( $user_id );
        return $settings !== null && self::teamsFor( $user_id, $settings['teams'] ) !== [];
    }

    /**
     * The week for `GET me/week` and the landing panel.
     *
     * @param string|null $today `Y-m-d`; site time when null.
     * @return array<string, mixed>
     */
    public static function forUser( int $user_id, ?string $today = null ): array {
        $today    = self::validDate( $today ) ?? AttendanceDateRule::today();
        $settings = self::settingsForUser( $user_id );
        $window   = $settings['window'] ?? WeekSettings::WINDOW_WEEK;
        [ $start, $end ] = self::windowBounds( $today, $window );

        $out = [
            'visible'        => false,
            'week_start'     => $start,
            'week_end'       => $end,
            'today'          => $today,
            'window'         => $window,
            'multiple_teams' => false,
            'has_match'      => false,
            'phone_on'       => false,
            'past'           => WeekSettings::PAST_SHOW,
            'blocks'         => [ WeekSettings::DEVICE_DESKTOP => [], WeekSettings::DEVICE_PHONE => [] ],
            'rows'           => [],
        ] + self::panelDefaults();
        if ( $settings === null ) return $out;

        $teams = self::teamsFor( $user_id, $settings['teams'] );
        if ( $teams === [] ) return $out;

        // Only blocks that can work on this install are listed or asked.
        $blocks    = WeekBlockRegistry::available();
        $available = static fn( array $keys ): array => array_values( array_filter( $keys, static fn( string $k ): bool => isset( $blocks[ $k ] ) ) );
        $desktop   = $available( $settings['desktop'] );
        $phone     = $settings['phone_on'] ? $available( $settings['phone'] ) : [];

        $out['visible']        = true;
        $out['multiple_teams'] = count( $teams ) > 1;
        $out['phone_on']       = $settings['phone_on'];
        $out['past']           = $settings['past'];
        $out['blocks']         = [ WeekSettings::DEVICE_DESKTOP => $desktop, WeekSettings::DEVICE_PHONE => $phone ];

        $on = array_fill_keys( array_merge( $desktop, $phone ), true );

        $activities = [];
        foreach ( ( new ActivitiesRepository() )->listForTeamsInWindow( array_keys( $teams ), $start, $end ) as $a ) {
            $activities[] = (array) $a;
        }

        $week = new WeekContext(
            $user_id,
            (string) PersonaResolver::landingPersona( $user_id ),
            $today,
            $start,
            $end,
            $teams,
            $activities,
            $on
        );

        $type_labels = [];
        foreach ( $week->rows() as $row ) {
            if ( $row->isMatch ) $out['has_match'] = true;
            if ( $row->when === self::WHEN_PAST && $settings['past'] === WeekSettings::PAST_HIDE ) continue;

            if ( ! isset( $type_labels[ $row->type ] ) ) {
                $type_labels[ $row->type ] = (string) ( LabelTranslator::activityType( $row->type ) ?? ucfirst( str_replace( '_', ' ', $row->type ) ) );
            }
            $fields  = self::baseRow( $row, $teams, $type_labels[ $row->type ] ) + self::rowDefaults();
            $actions = [];

            foreach ( $blocks as $key => $block ) {
                if ( ! $week->isOn( $key ) ) continue;
                // A block writes only the fields it owns.
                $fields = array_replace( $fields, array_intersect_key( $block->rowFields( $row, $week ), $block->rowDefaults() ) );
                if ( $block instanceof ActionBlock ) {
                    $action = $block->actionFor( $row, $week );
                    if ( $action !== null && ! in_array( $action, $actions, true ) ) $actions[] = $action;
                }
            }
            $fields['actions'] = $actions;
            $out['rows'][]     = $fields;
        }

        foreach ( $blocks as $key => $block ) {
            if ( ! $week->isOn( $key ) ) continue;
            $out = array_replace( $out, array_intersect_key( $block->panelFields( $week ), $block->panelDefaults() ) );
        }

        return $out;
    }

    /**
     * Every block's own row fields at their empty value, so a row has the
     * same shape whatever is switched on.
     *
     * @return array<string, mixed>
     */
    public static function rowDefaults(): array {
        $out = [];
        foreach ( WeekBlockRegistry::all() as $block ) $out += $block->rowDefaults();
        return $out;
    }

    /**
     * Every block's own top-level fields at their empty value.
     *
     * @return array<string, mixed>
     */
    public static function panelDefaults(): array {
        $out = [];
        foreach ( WeekBlockRegistry::all() as $block ) $out += $block->panelDefaults();
        return $out;
    }

    /**
     * The block key behind each action, so a client can tell which device
     * an action is on for.
     *
     * @return array<string, string> action key => block key
     */
    public static function actionBlocks(): array {
        return [
            self::ACTION_MARK_ATTENDANCE => 'action_mark_attendance',
            self::ACTION_MATCH_PREP      => 'action_match_prep',
            self::ACTION_START_MATCH     => 'action_start_match',
            self::ACTION_OPEN_ACTIVITY   => 'action_open_activity',
            self::ACTION_MINUTES_STATS   => 'action_minutes_stats',
            self::ACTION_RATE_PLAYERS    => 'action_rate_players',
            self::ACTION_MATCH_ANALYSIS  => 'action_match_analysis',
        ];
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
     * The window's first and last day: the calendar week, or today and the
     * six days after it.
     *
     * @return array{0: string, 1: string}
     */
    public static function windowBounds( string $today, string $window ): array {
        if ( $window !== WeekSettings::WINDOW_ROLLING ) return self::weekBounds( $today );
        $day = new \DateTimeImmutable( $today . ' 12:00:00', new \DateTimeZone( 'UTC' ) );
        return [ $day->format( 'Y-m-d' ), $day->modify( '+6 days' )->format( 'Y-m-d' ) ];
    }

    /**
     * The settings of the persona this user acts as, when the panel is on
     * for it; null otherwise.
     *
     * @return array{on:bool, phone_on:bool, window:string, teams:string, past:string, desktop:list<string>, phone:list<string>}|null
     */
    private static function settingsForUser( int $user_id ): ?array {
        if ( $user_id <= 0 ) return null;
        if ( ! ModuleRegistry::isEnabled( ActivitiesModule::class ) ) return null;
        $persona  = PersonaResolver::landingPersona( $user_id );
        if ( $persona === null ) return null;
        $settings = self::settings()[ $persona ] ?? null;
        return ( $settings !== null && $settings['on'] ) ? $settings : null;
    }

    /**
     * The week's teams, id => name: the ones the user works with, or every
     * academy team when the setting asks for that and the user may read
     * activities academy-wide. Without that read, "all" falls back to their
     * own teams.
     *
     * @return array<int, string>
     */
    private static function teamsFor( int $user_id, string $scope ): array {
        $source = ( $scope === WeekSettings::TEAMS_ALL && ActivityAccess::hasGlobalRead( $user_id ) )
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( $user_id );

        $teams = [];
        foreach ( $source as $team ) {
            $fields = (array) $team;
            $id     = (int) ( $fields['id'] ?? 0 );
            if ( $id > 0 ) $teams[ $id ] = (string) ( $fields['name'] ?? '' );
        }
        return $teams;
    }

    /**
     * The identity fields every row carries, whatever is switched on. The
     * fields a block computes come from its `rowDefaults()` and `rowFields()`,
     * and `actions` is set last. No block may write these.
     *
     * @param array<int, string> $teams
     * @return array<string, mixed>
     */
    private static function baseRow( WeekRow $row, array $teams, string $type_label ): array {
        $a = $row->activity;
        return [
            'activity_id'         => $row->activityId,
            'team_id'             => $row->teamId,
            'team_name'           => $teams[ $row->teamId ] ?? '',
            'date'                => $row->date,
            'when'                => $row->when,
            'type_key'            => $row->type,
            'type_label'          => $type_label,
            'is_match'            => $row->isMatch,
            'title'               => (string) ( $a['title'] ?? '' ),
            'opponent'            => $row->isMatch ? (string) ( $a['opponent'] ?? '' ) : '',
            'home_away'           => $row->isMatch ? self::homeAway( (string) ( $a['home_away'] ?? '' ) ) : '',
            'start_time'          => self::hhmm( (string) ( $a['start_time'] ?? '' ) ),
            'end_time'            => self::hhmm( (string) ( $a['end_time'] ?? '' ) ),
            'meet_time'           => self::hhmm( (string) ( $a['time_of_presence'] ?? '' ) ),
            'location'            => (string) ( $a['location'] ?? '' ),
        ];
    }

    /**
     * The older persona list, the academy's choice when the settings were
     * never saved; the default when that list was never saved either.
     *
     * @return list<string>
     */
    private static function legacyPersonas(): array {
        $raw = ( new ConfigService() )->get( self::CONFIG_KEY, '' );
        if ( trim( $raw ) === '' ) return self::DEFAULT_PERSONAS;
        return self::decodePersonas( $raw );
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
