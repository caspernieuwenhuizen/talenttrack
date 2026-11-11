<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportFilters;

/**
 * ReportStartRules (#4569, #4570, epic #4564) — the seven starting choices of
 * a Studio report and the rules between them. One answer for three callers:
 * the start wizard's steps (which options are switched off, and why),
 * `GET /reports/start-options`, and `POST /reports`.
 *
 * The choices are decided in order, each against the ones before it:
 * kind, scope, audience, binding, period, layout, sharing. Per option the
 * service answers `ok` or a translated reason:
 *
 * - a family or scout audience is about one player; a scout report is a
 *   report, and writing for a scout takes the right to publish; a parent
 *   reads a family report only through a shared snapshot, never live;
 * - the academy scope is for a builder for every team;
 * - a report about me always follows the viewer, one about the academy is
 *   always fixed, and only me, a player or a team can follow the viewer;
 * - a dashboard's period is never fixed;
 * - a grid is for a staff audience;
 * - a scheduled mail needs a report in rows over a period that is not chosen
 *   on opening;
 * - sharing with the family needs a family report;
 * - publishing for a role needs a binding that is not fixed (or the academy
 *   scope) and the right to publish.
 *
 * `normalise()` moves a choice an earlier one ruled out to the first option
 * that is still allowed, and says which and why; the wizard shows that.
 * `validate()` never moves anything a caller sent: it answers `invalid_field`
 * naming the later field. Capabilities decide, never persona names.
 */
final class ReportStartRules {

    public const KIND_REPORT    = 'report';
    public const KIND_DASHBOARD = 'dashboard';
    public const KINDS          = [ self::KIND_REPORT, self::KIND_DASHBOARD ];

    public const BINDING_FIXED   = 'fixed';
    public const BINDING_ON_OPEN = 'on_open';
    public const BINDING_VIEWER  = 'viewer';
    public const BINDINGS        = [ self::BINDING_FIXED, self::BINDING_ON_OPEN, self::BINDING_VIEWER ];

    public const PERIOD_FIXED   = 'fixed';
    public const PERIOD_ROLLING = 'rolling';
    public const PERIOD_ON_OPEN = 'on_open';
    public const PERIOD_MODES   = [ self::PERIOD_ON_OPEN, self::PERIOD_ROLLING, self::PERIOD_FIXED ];

    public const LAYOUT_ROWS = 'rows';
    public const LAYOUT_GRID = 'grid';
    public const LAYOUTS     = [ self::LAYOUT_ROWS, self::LAYOUT_GRID ];

    public const SHARING_ME       = 'me';
    public const SHARING_ROLE     = 'role';
    public const SHARING_SCHEDULE = 'schedule';
    public const SHARING_FAMILY   = 'family';
    public const SHARINGS         = [ self::SHARING_ME, self::SHARING_ROLE, self::SHARING_SCHEDULE, self::SHARING_FAMILY ];

    public const AUDIENCES = [ PlayerReportAudience::INTERNAL, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ];

    /** The choices, in the order they are decided. */
    public const FIELDS = [ 'kind', 'scope', 'audience', 'binding', 'period_mode', 'layout', 'sharing' ];

    /** Scopes a binding that follows the viewer can resolve. */
    public const VIEWER_SCOPES = [ ReportContext::SCOPE_ME, ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];

    /** The longest report name accepted. */
    public const MAX_NAME = 255;

    /**
     * The starting choices, normalised, or the first rule they break.
     *
     * A choice the caller left out takes the first option still allowed; a
     * choice the caller sent that an earlier one rules out is refused, naming
     * that later field.
     *
     * @param array<string,mixed> $in kind, scope, binding, audience,
     *        period_mode, period_value, layout, sharing, name, player_id,
     *        team_id, cohort
     * @return array{kind:string, scope:string, binding:string, audience:string, period_mode:string, period_value:string, layout:string, sharing:string, name:string, player_id:?int, team_id:?int, cohort:?array{team_ids:list<int>, age_group:string, position:string}}|StudioError
     */
    public static function validate( array $in, int $user_id ): array|StudioError {
        $reader   = ReportReader::for( $user_id );
        $resolved = self::resolve( $in, $reader, false );
        if ( $resolved instanceof StudioError ) return $resolved;
        $c = $resolved['choices'];

        $name = is_string( $in['name'] ?? null ) ? trim( (string) $in['name'] ) : '';
        if ( mb_strlen( $name ) > self::MAX_NAME ) {
            /* translators: %d: maximum number of characters */
            return self::invalid( 'name', sprintf( __( 'A report name is at most %d characters.', 'talenttrack' ), self::MAX_NAME ) );
        }

        $scope   = $c['scope'] ?? ReportContext::SCOPE_ME;
        $binding = $c['binding'] ?? self::BINDING_FIXED;

        $period_value = self::periodValue( $c['period_mode'] ?? self::PERIOD_ON_OPEN, $in['period_value'] ?? null );
        if ( $period_value instanceof StudioError ) return $period_value;

        $player_id = null;
        $team_id   = null;
        $cohort    = null;
        if ( $binding === self::BINDING_FIXED ) {
            if ( $scope === ReportContext::SCOPE_PLAYER ) {
                $player_id = self::positiveInt( $in['player_id'] ?? null );
                if ( $player_id === null ) return self::invalid( 'player_id', __( 'Choose a player.', 'talenttrack' ) );
            } elseif ( $scope === ReportContext::SCOPE_TEAM ) {
                $team_id = self::positiveInt( $in['team_id'] ?? null );
                if ( $team_id === null ) return self::invalid( 'team_id', __( 'Choose a team.', 'talenttrack' ) );
            }
        }
        if ( $scope === ReportContext::SCOPE_GROUP ) {
            // A group is a rule, not a subject: it is stored whatever the binding.
            $cohort = self::cohort( $in['cohort'] ?? null );
            if ( $cohort instanceof StudioError ) return $cohort;
        }

        $refused = ReportAccess::buildRefusal( $reader, $scope, $player_id, $team_id, $cohort !== null ? $cohort['team_ids'] : [] );
        if ( $refused !== null ) return $refused;

        return [
            'kind'         => $c['kind'] ?? self::KIND_REPORT,
            'scope'        => $c['scope'] ?? ReportContext::SCOPE_ME,
            'binding'      => $c['binding'] ?? self::BINDING_FIXED,
            'audience'     => $c['audience'] ?? PlayerReportAudience::INTERNAL,
            'period_mode'  => $c['period_mode'] ?? self::PERIOD_ON_OPEN,
            'period_value' => $period_value,
            'layout'       => $c['layout'] ?? self::LAYOUT_ROWS,
            'sharing'      => $c['sharing'] ?? self::SHARING_ME,
            'name'         => $name,
            'player_id'    => $player_id,
            'team_id'      => $team_id,
            'cohort'       => $cohort,
        ];
    }

    /**
     * The choices with every one an earlier choice rules out moved to the
     * first option still allowed, and which moved, with the reason.
     *
     * @param array<string,mixed> $in
     * @return array{choices:array<string,string>, moved:array<string,string>}|StudioError
     */
    public static function normalise( array $in, int $user_id ): array|StudioError {
        return self::resolve( $in, ReportReader::for( $user_id ), true );
    }

    /**
     * Every option of every choice, each `ok` or with the reason it is
     * switched off, decided against the normalised earlier choices.
     *
     * @param array<string,mixed> $in
     * @return array{choices:array<string,string>, moved:array<string,string>, options:array<string,list<array{value:string, label:string, ok:bool, reason:?string}>>}|StudioError
     */
    public static function options( array $in, int $user_id ): array|StudioError {
        $reader   = ReportReader::for( $user_id );
        $resolved = self::resolve( $in, $reader, true );
        if ( $resolved instanceof StudioError ) return $resolved;

        $options = [];
        $before  = [];
        foreach ( self::FIELDS as $field ) {
            $list = [];
            foreach ( self::values( $field ) as $value ) {
                $reason = self::reason( $field, $value, $before, $reader );
                $list[] = [ 'value' => $value, 'label' => self::label( $field, $value ), 'ok' => $reason === null, 'reason' => $reason ];
            }
            $options[ $field ] = $list;
            if ( isset( $resolved['choices'][ $field ] ) ) $before[ $field ] = $resolved['choices'][ $field ];
        }
        return $resolved + [ 'options' => $options ];
    }

    /**
     * Why this option of this choice is switched off, given the choices
     * decided before it, or null when it is allowed.
     *
     * @param array<string,string> $c the earlier choices
     */
    public static function reason( string $field, string $value, array $c, ReportReader $reader ): ?string {
        $kind     = $c['kind'] ?? '';
        $scope    = $c['scope'] ?? '';
        $audience = $c['audience'] ?? '';
        $binding  = $c['binding'] ?? '';
        $period   = $c['period_mode'] ?? '';
        $layout   = $c['layout'] ?? '';
        $uid      = $reader->userId;

        switch ( $field ) {
            case 'scope':
                if ( $value === ReportContext::SCOPE_ME ) return null;
                $teams = $reader->builderTeams();
                if ( $teams === [] ) return __( 'You do not build reports for any team.', 'talenttrack' );
                if ( $value === ReportContext::SCOPE_ACADEMY && $teams !== null ) {
                    return __( 'Only a builder for every team builds a report about the whole academy.', 'talenttrack' );
                }
                return null;

            case 'audience':
                if ( $value === PlayerReportAudience::FAMILY && $scope !== ReportContext::SCOPE_PLAYER ) {
                    return __( 'A family report is about one player, and a parent reads it only through a snapshot you share, never live.', 'talenttrack' );
                }
                if ( $value === PlayerReportAudience::SCOUT ) {
                    if ( $scope !== ReportContext::SCOPE_PLAYER ) return __( 'A report for a scout is about one player.', 'talenttrack' );
                    if ( $kind !== self::KIND_REPORT ) return __( 'A report for a scout is a report, not a dashboard.', 'talenttrack' );
                    if ( ! user_can( $uid, 'tt_publish_reports' ) ) return __( 'Only someone who publishes reports writes one for a scout.', 'talenttrack' );
                }
                return null;

            case 'binding':
                if ( $scope === ReportContext::SCOPE_ME && $value !== self::BINDING_VIEWER ) {
                    return __( 'A report about me always follows the viewer.', 'talenttrack' );
                }
                if ( $scope === ReportContext::SCOPE_ACADEMY && $value !== self::BINDING_FIXED ) {
                    return __( 'A report about the whole academy is always fixed.', 'talenttrack' );
                }
                if ( $value === self::BINDING_VIEWER && ! in_array( $scope, self::VIEWER_SCOPES, true ) ) {
                    return __( 'Only a report about me, a player or a team can follow the viewer.', 'talenttrack' );
                }
                return null;

            case 'period_mode':
                if ( $value === self::PERIOD_FIXED && $kind === self::KIND_DASHBOARD ) {
                    return __( 'A dashboard follows the calendar: its period cannot be fixed.', 'talenttrack' );
                }
                return null;

            case 'layout':
                if ( $value === self::LAYOUT_GRID && $audience !== PlayerReportAudience::INTERNAL ) {
                    return __( 'A grid is laid out for staff screens; a report for a family or a scout is laid out in rows.', 'talenttrack' );
                }
                return null;

            case 'sharing':
                if ( $value === self::SHARING_ROLE ) {
                    if ( $binding === self::BINDING_FIXED && $scope !== ReportContext::SCOPE_ACADEMY ) {
                        return __( 'A report published for a role is chosen when opened or follows the viewer, so each reader sees their own subject.', 'talenttrack' );
                    }
                    if ( ! user_can( $uid, 'tt_publish_reports' ) ) {
                        return __( 'You may build this report for yourself, not publish it for a role.', 'talenttrack' );
                    }
                }
                if ( $value === self::SHARING_SCHEDULE ) {
                    if ( $kind !== self::KIND_REPORT ) return __( 'A scheduled mail sends a report, not a dashboard.', 'talenttrack' );
                    if ( $layout !== self::LAYOUT_ROWS ) return __( 'A scheduled mail is laid out in rows.', 'talenttrack' );
                    if ( $period === self::PERIOD_ON_OPEN ) return __( 'A scheduled mail needs a period it can choose by itself: fixed or rolling.', 'talenttrack' );
                }
                if ( $value === self::SHARING_FAMILY ) {
                    if ( $audience !== PlayerReportAudience::FAMILY ) return __( 'Sharing with the family needs a report for the family.', 'talenttrack' );
                    if ( $kind !== self::KIND_REPORT ) return __( 'The family gets a report, not a dashboard.', 'talenttrack' );
                }
                return null;
        }
        return null;
    }

    /** @return list<string> */
    public static function values( string $field ): array {
        switch ( $field ) {
            case 'kind':        return self::KINDS;
            case 'scope':       return ReportContext::SCOPES;
            case 'audience':    return self::AUDIENCES;
            case 'binding':     return self::BINDINGS;
            case 'period_mode': return self::PERIOD_MODES;
            case 'layout':      return self::LAYOUTS;
            case 'sharing':     return self::SHARINGS;
        }
        return [];
    }

    /** What an option is called on screen. */
    public static function label( string $field, string $value ): string {
        $labels = [
            'kind'        => [
                self::KIND_REPORT    => __( 'A report', 'talenttrack' ),
                self::KIND_DASHBOARD => __( 'A dashboard', 'talenttrack' ),
            ],
            'scope'       => [
                ReportContext::SCOPE_ME      => _x( 'Me', 'report studio scope', 'talenttrack' ),
                ReportContext::SCOPE_PLAYER  => _x( 'One player', 'report studio scope', 'talenttrack' ),
                ReportContext::SCOPE_TEAM    => _x( 'One team', 'report studio scope', 'talenttrack' ),
                ReportContext::SCOPE_GROUP   => _x( 'A group of players', 'report studio scope', 'talenttrack' ),
                ReportContext::SCOPE_ACADEMY => _x( 'The academy', 'report studio scope', 'talenttrack' ),
            ],
            'audience'    => [
                PlayerReportAudience::INTERNAL => _x( 'Staff', 'report studio audience', 'talenttrack' ),
                PlayerReportAudience::SCOUT    => _x( 'An external scout', 'report studio audience', 'talenttrack' ),
                PlayerReportAudience::FAMILY   => _x( 'The player and their family', 'report studio audience', 'talenttrack' ),
            ],
            'binding'     => [
                self::BINDING_FIXED   => _x( 'Always the same subject', 'report studio binding', 'talenttrack' ),
                self::BINDING_ON_OPEN => _x( 'Chosen when it is opened', 'report studio binding', 'talenttrack' ),
                self::BINDING_VIEWER  => _x( 'Follows whoever opens it', 'report studio binding', 'talenttrack' ),
            ],
            'period_mode' => [
                self::PERIOD_ON_OPEN => _x( 'Chosen when it is opened', 'report studio period', 'talenttrack' ),
                self::PERIOD_ROLLING => _x( 'Rolling', 'report studio period', 'talenttrack' ),
                self::PERIOD_FIXED   => _x( 'Fixed dates', 'report studio period', 'talenttrack' ),
            ],
            'layout'      => [
                self::LAYOUT_ROWS => _x( 'Rows', 'report studio layout', 'talenttrack' ),
                self::LAYOUT_GRID => _x( 'Grid', 'report studio layout', 'talenttrack' ),
            ],
            'sharing'     => [
                self::SHARING_ME       => _x( 'Only me', 'report studio sharing', 'talenttrack' ),
                self::SHARING_ROLE     => _x( 'Published for a role', 'report studio sharing', 'talenttrack' ),
                self::SHARING_SCHEDULE => _x( 'A scheduled mail', 'report studio sharing', 'talenttrack' ),
                self::SHARING_FAMILY   => _x( 'Shared with the family', 'report studio sharing', 'talenttrack' ),
            ],
        ];
        return $labels[ $field ][ $value ] ?? $value;
    }

    /**
     * Walk the choices in order. A missing choice takes the first option
     * still allowed. A sent one that is ruled out moves there too when
     * `$move`, and otherwise is refused, naming its field.
     *
     * @param array<string,mixed> $in
     * @return array{choices:array<string,string>, moved:array<string,string>}|StudioError
     */
    private static function resolve( array $in, ReportReader $reader, bool $move ): array|StudioError {
        $choices = [];
        $moved   = [];
        foreach ( self::FIELDS as $field ) {
            $sent = array_key_exists( $field, $in ) && $in[ $field ] !== null && $in[ $field ] !== '';
            if ( $sent ) {
                $value = is_string( $in[ $field ] ) ? $in[ $field ] : '';
                if ( ! in_array( $value, self::values( $field ), true ) ) {
                    return self::invalid( $field, self::vocabularyMessage( $field ) );
                }
                $reason = self::reason( $field, $value, $choices, $reader );
                if ( $reason === null ) {
                    $choices[ $field ] = $value;
                    continue;
                }
                if ( ! $move ) {
                    // Not being allowed a scope at all is a permission refusal,
                    // the one `ReportAccess` answers; every other rule is about
                    // how the choices fit together.
                    return $field === 'scope'
                        ? new StudioError( 'forbidden_scope', $reason, 403, [ 'field' => 'scope' ] )
                        : self::invalid( $field, $reason );
                }
                $moved[ $field ] = $reason;
            } elseif ( $field === 'scope' ) {
                // There is no sensible default subject: the scope is always asked.
                return self::invalid( 'scope', self::vocabularyMessage( 'scope' ) );
            }

            $first = null;
            $why   = null;
            foreach ( self::values( $field ) as $candidate ) {
                $reason = self::reason( $field, $candidate, $choices, $reader );
                if ( $reason === null ) {
                    $first = $candidate;
                    break;
                }
                $why = $why ?? $reason;
            }
            if ( $first === null ) return self::invalid( $field, $why ?? self::vocabularyMessage( $field ) );
            $choices[ $field ] = $first;
        }
        return [ 'choices' => $choices, 'moved' => $moved ];
    }

    /**
     * A period mode's value: `from/to` for a fixed period, a period key for a
     * rolling one, empty when the period is chosen on opening.
     */
    public static function periodValue( string $mode, mixed $raw ): string|StudioError {
        $value = is_string( $raw ) ? trim( $raw ) : '';
        if ( $mode === self::PERIOD_ON_OPEN ) return '';

        if ( $mode === self::PERIOD_ROLLING ) {
            if ( ! in_array( $value, ReportFilters::PERIODS, true ) ) {
                return self::invalid( 'period_value', __( 'Choose which period the report rolls with.', 'talenttrack' ) );
            }
            return $value;
        }

        $parts = explode( '/', $value );
        $bad   = __( 'A fixed period is a from and a to date (YYYY-MM-DD/YYYY-MM-DD), from first.', 'talenttrack' );
        if ( count( $parts ) !== 2 || ! self::isDate( $parts[0] ) || ! self::isDate( $parts[1] ) || $parts[0] > $parts[1] ) {
            return self::invalid( 'period_value', $bad );
        }
        $days = (int) round( ( (int) strtotime( $parts[1] . ' 00:00:00 UTC' ) - (int) strtotime( $parts[0] . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
        if ( $days > ReportContext::MAX_PERIOD_DAYS ) {
            /* translators: %d: maximum number of days */
            return self::invalid( 'period_value', sprintf( __( 'A period covers at most %d days: one season plus one month.', 'talenttrack' ), ReportContext::MAX_PERIOD_DAYS ) );
        }
        return $parts[0] . '/' . $parts[1];
    }

    /**
     * A group's cohort rule: one or more teams, optionally narrowed to an age
     * group or a position. Never a list of players.
     *
     * @return array{team_ids:list<int>, age_group:string, position:string}|StudioError
     */
    public static function cohort( mixed $raw ): array|StudioError {
        if ( ! is_array( $raw ) ) {
            return self::invalid( 'cohort', __( 'A group is chosen by team, with an age group or a position if you want one.', 'talenttrack' ) );
        }
        $teams = [];
        foreach ( (array) ( $raw['team_ids'] ?? [] ) as $value ) {
            $id = self::positiveInt( $value );
            if ( $id === null ) return self::invalid( 'cohort', __( 'Every team in the group must be a team id.', 'talenttrack' ) );
            $teams[ $id ] = $id;
        }
        if ( $teams === [] ) return self::invalid( 'cohort', __( 'Choose at least one team for the group.', 'talenttrack' ) );

        $age      = is_string( $raw['age_group'] ?? null ) ? sanitize_text_field( (string) $raw['age_group'] ) : '';
        // #4964 — one of the academy's position codes, matched against a
        // player's primary position. A code the academy doesn't have is
        // refused rather than giving an empty group.
        $position = is_string( $raw['position'] ?? null ) ? strtoupper( trim( sanitize_text_field( (string) $raw['position'] ) ) ) : '';
        if ( $position !== '' && ! isset( ReportSubjectOptions::positions()[ $position ] ) ) {
            return self::invalid( 'cohort', __( 'Choose one of the academy\'s positions for the group.', 'talenttrack' ) );
        }

        return [ 'team_ids' => array_values( $teams ), 'age_group' => $age, 'position' => $position ];
    }

    private static function vocabularyMessage( string $field ): string {
        switch ( $field ) {
            case 'kind':        return __( 'A report is a report or a dashboard.', 'talenttrack' );
            case 'scope':       return __( 'The scope must be me, player, team, group or academy.', 'talenttrack' );
            case 'binding':     return __( 'The subject is fixed, chosen when opened, or follows the viewer.', 'talenttrack' );
            case 'audience':    return __( 'The audience must be internal, scout or family.', 'talenttrack' );
            case 'period_mode': return __( 'The period is fixed, rolling, or chosen when opened.', 'talenttrack' );
            case 'layout':      return __( 'The layout is rows or grid.', 'talenttrack' );
        }
        return __( 'Sharing is only me, a role, a scheduled mail, or the family.', 'talenttrack' );
    }

    private static function invalid( string $field, string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => $field ] );
    }

    private static function positiveInt( mixed $value ): ?int {
        if ( is_int( $value ) ) return $value > 0 ? $value : null;
        if ( is_string( $value ) && preg_match( '/^\d+$/', $value ) === 1 && (int) $value > 0 ) return (int) $value;
        return null;
    }

    private static function isDate( string $value ): bool {
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) !== 1 ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }
}
