<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportFilters;

/**
 * ReportStartRules (#4569, epic #4564) — the seven starting choices of a
 * Studio report and the rules between them, in one place so the REST route
 * and the start wizard (#4570) refuse the same things.
 *
 * The rules:
 *
 * - a family or scout audience is about one player;
 * - the academy scope is a staff reading, chosen only by a builder who builds
 *   for every team;
 * - a dashboard's period is never fixed;
 * - anything mailed or shared with the family is laid out in rows;
 * - publishing for a role needs a binding that is not fixed, and the right to
 *   publish;
 * - a fixed binding names its subject, and the subject is one the builder
 *   builds for (`ReportAccess::canBuildFor()`).
 *
 * `validate()` answers with the first rule broken, naming the field.
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
    public const PERIOD_MODES   = [ self::PERIOD_FIXED, self::PERIOD_ROLLING, self::PERIOD_ON_OPEN ];

    public const LAYOUT_ROWS = 'rows';
    public const LAYOUT_GRID = 'grid';
    public const LAYOUTS     = [ self::LAYOUT_ROWS, self::LAYOUT_GRID ];

    public const SHARING_ME       = 'me';
    public const SHARING_ROLE     = 'role';
    public const SHARING_SCHEDULE = 'schedule';
    public const SHARING_FAMILY   = 'family';
    public const SHARINGS         = [ self::SHARING_ME, self::SHARING_ROLE, self::SHARING_SCHEDULE, self::SHARING_FAMILY ];

    /** Scopes a binding that follows the viewer can resolve. */
    public const VIEWER_SCOPES = [ ReportContext::SCOPE_ME, ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];

    /** The longest report name accepted. */
    public const MAX_NAME = 255;

    /**
     * The starting choices, normalised, or the first rule they break.
     *
     * @param array<string,mixed> $in kind, scope, binding, audience,
     *        period_mode, period_value, layout, sharing, name, player_id,
     *        team_id, cohort
     * @return array{kind:string, scope:string, binding:string, audience:string, period_mode:string, period_value:string, layout:string, sharing:string, name:string, player_id:?int, team_id:?int, cohort:?array{team_ids:list<int>, age_group:string, position:string}}|StudioError
     */
    public static function validate( array $in, int $user_id ): array|StudioError {
        $vocabulary = [
            'kind'        => [ self::KINDS, self::KIND_REPORT ],
            'scope'       => [ ReportContext::SCOPES, '' ],
            'binding'     => [ self::BINDINGS, self::BINDING_FIXED ],
            'audience'    => [ [ PlayerReportAudience::INTERNAL, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ], PlayerReportAudience::INTERNAL ],
            'period_mode' => [ self::PERIOD_MODES, self::PERIOD_ON_OPEN ],
            'layout'      => [ self::LAYOUTS, self::LAYOUT_ROWS ],
            'sharing'     => [ self::SHARINGS, self::SHARING_ME ],
        ];
        $choices = [];
        foreach ( $vocabulary as $field => [ $allowed, $default ] ) {
            $value = self::choice( $in, $field, $allowed, $default );
            if ( $value === null ) return self::invalid( $field, self::vocabularyMessage( $field ) );
            $choices[ $field ] = $value;
        }
        $kind     = $choices['kind'];
        $scope    = $choices['scope'];
        $binding  = $choices['binding'];
        $audience = $choices['audience'];
        $period   = $choices['period_mode'];
        $layout   = $choices['layout'];
        $sharing  = $choices['sharing'];

        $name = is_string( $in['name'] ?? null ) ? trim( (string) $in['name'] ) : '';
        if ( mb_strlen( $name ) > self::MAX_NAME ) {
            /* translators: %d: maximum number of characters */
            return self::invalid( 'name', sprintf( __( 'A report name is at most %d characters.', 'talenttrack' ), self::MAX_NAME ) );
        }

        if ( PlayerReportAudience::isExternal( $audience ) && $scope !== ReportContext::SCOPE_PLAYER ) {
            return self::invalid( 'audience', __( 'A report for the family or a scout is about one player.', 'talenttrack' ) );
        }
        if ( $scope === ReportContext::SCOPE_ACADEMY && $audience !== PlayerReportAudience::INTERNAL ) {
            return self::invalid( 'scope', __( 'A report about the whole academy is for staff only.', 'talenttrack' ) );
        }
        if ( $kind === self::KIND_DASHBOARD && $period === self::PERIOD_FIXED ) {
            return self::invalid( 'period_mode', __( 'A dashboard follows the calendar: its period cannot be fixed.', 'talenttrack' ) );
        }
        $printed = in_array( $sharing, [ self::SHARING_SCHEDULE, self::SHARING_FAMILY ], true ) || $audience === PlayerReportAudience::FAMILY;
        if ( $printed && $layout !== self::LAYOUT_ROWS ) {
            return self::invalid( 'layout', __( 'A report that is mailed or shared with the family is laid out in rows.', 'talenttrack' ) );
        }
        if ( $sharing === self::SHARING_ROLE ) {
            if ( $binding === self::BINDING_FIXED ) {
                return self::invalid( 'binding', __( 'A report published for a role is chosen when opened or follows the viewer, so each reader sees their own subject.', 'talenttrack' ) );
            }
            if ( ! user_can( $user_id, 'tt_publish_reports' ) ) {
                return self::invalid( 'sharing', __( 'You may build this report for yourself, not publish it for a role.', 'talenttrack' ) );
            }
        }
        if ( $binding === self::BINDING_VIEWER && ! in_array( $scope, self::VIEWER_SCOPES, true ) ) {
            return self::invalid( 'binding', __( 'Only a report about me, a player or a team can follow the viewer.', 'talenttrack' ) );
        }

        $period_value = self::periodValue( $period, $in['period_value'] ?? null );
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
            } elseif ( $scope === ReportContext::SCOPE_GROUP ) {
                $cohort = self::cohort( $in['cohort'] ?? null );
                if ( $cohort instanceof StudioError ) return $cohort;
            }
        } elseif ( $scope === ReportContext::SCOPE_GROUP ) {
            // A group is a rule, not a subject: it is stored whatever the binding.
            $cohort = self::cohort( $in['cohort'] ?? null );
            if ( $cohort instanceof StudioError ) return $cohort;
        }

        $refused = ReportAccess::buildRefusal( $user_id, $scope, $player_id, $team_id, $cohort !== null ? $cohort['team_ids'] : [] );
        if ( $refused !== null ) return $refused;

        return [
            'kind'         => $kind,
            'scope'        => $scope,
            'binding'      => $binding,
            'audience'     => $audience,
            'period_mode'  => $period,
            'period_value' => $period_value,
            'layout'       => $layout,
            'sharing'      => $sharing,
            'name'         => $name,
            'player_id'    => $player_id,
            'team_id'      => $team_id,
            'cohort'       => $cohort,
        ];
    }

    /**
     * A period mode's value: `from/to` for a fixed period, a period key for a
     * rolling one, empty when the period is chosen on opening.
     */
    private static function periodValue( string $mode, mixed $raw ): string|StudioError {
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
        $position = is_string( $raw['position'] ?? null ) ? strtoupper( sanitize_key( (string) $raw['position'] ) ) : '';

        return [ 'team_ids' => array_values( $teams ), 'age_group' => $age, 'position' => $position ];
    }

    /**
     * @param list<string> $allowed
     * @param array<string,mixed> $in
     */
    private static function choice( array $in, string $field, array $allowed, string $default ): ?string {
        if ( ! array_key_exists( $field, $in ) || $in[ $field ] === null || $in[ $field ] === '' ) {
            return $default !== '' ? $default : null;
        }
        $value = is_string( $in[ $field ] ) ? $in[ $field ] : '';
        return in_array( $value, $allowed, true ) ? $value : null;
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
