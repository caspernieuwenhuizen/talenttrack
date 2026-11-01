<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportFilters;

/**
 * ReportContext — what a block is asked about (#4568): the subject (a scope
 * and its ids), the period, the audience and the viewing user.
 *
 * Built only through `fromInput()`, which enforces the Studio's limits so
 * every block can rely on them: a group of at most 60 players, a period of
 * at most one season plus one month, and an external audience only ever
 * about one player. The audience follows `PlayerReportAudience`: a reader
 * who reads as a scout gets the scout audience whatever they ask for.
 *
 * Access to the subject is not decided here; `ReportSubjectAccess` does
 * that on every call.
 */
final class ReportContext {

    public const SCOPE_ME      = 'me';
    public const SCOPE_PLAYER  = 'player';
    public const SCOPE_TEAM    = 'team';
    public const SCOPE_GROUP   = 'group';
    public const SCOPE_ACADEMY = 'academy';

    public const SCOPES = [ self::SCOPE_ME, self::SCOPE_PLAYER, self::SCOPE_TEAM, self::SCOPE_GROUP, self::SCOPE_ACADEMY ];

    /** The most players a group subject may name. */
    public const MAX_GROUP = 60;

    /** One season plus one month, so a period-over-period read stays inside two seasons. */
    public const MAX_PERIOD_DAYS = 396;

    /**
     * @param list<int> $playerIds
     */
    private function __construct(
        public readonly string $scope,
        public readonly int $playerId,
        public readonly int $teamId,
        public readonly array $playerIds,
        public readonly string $from,
        public readonly string $to,
        public readonly string $audience,
        public readonly int $userId
    ) {}

    /**
     * A context without a subject, for listing which blocks fit a scope and
     * an audience. Never rendered.
     */
    public static function forListing( string $scope, string $audience, int $user_id ): self {
        $window = ReportFilters::seasonDefaultWindow();
        return new self( $scope, 0, 0, [], $window['from'], $window['to'], self::resolveAudience( $audience, $user_id ), $user_id );
    }

    /**
     * @param array<string,mixed> $in scope, player_id, team_id, player_ids, from, to, audience
     */
    public static function fromInput( array $in, int $user_id ): self|StudioError {
        $scope = is_string( $in['scope'] ?? null ) ? (string) $in['scope'] : '';
        if ( ! in_array( $scope, self::SCOPES, true ) ) {
            return self::invalid( 'scope', __( 'The scope must be me, player, team, group or academy.', 'talenttrack' ) );
        }

        $requested = is_string( $in['audience'] ?? null ) && $in['audience'] !== '' ? (string) $in['audience'] : PlayerReportAudience::INTERNAL;
        if ( ! PlayerReportAudience::isValid( $requested ) ) {
            return self::invalid( 'audience', __( 'The audience must be internal, scout or family.', 'talenttrack' ) );
        }
        $audience = self::resolveAudience( $requested, $user_id );
        if ( PlayerReportAudience::isExternal( $audience ) && $scope !== self::SCOPE_PLAYER ) {
            return self::invalid( 'audience', __( 'A report for the family or a scout is about one player.', 'talenttrack' ) );
        }

        $player_id  = 0;
        $team_id    = 0;
        $player_ids = [];
        if ( $scope === self::SCOPE_PLAYER ) {
            $player_id = self::positiveInt( $in['player_id'] ?? null );
            if ( $player_id === 0 ) return self::invalid( 'player_id', __( 'Choose a player.', 'talenttrack' ) );
        } elseif ( $scope === self::SCOPE_TEAM ) {
            $team_id = self::positiveInt( $in['team_id'] ?? null );
            if ( $team_id === 0 ) return self::invalid( 'team_id', __( 'Choose a team.', 'talenttrack' ) );
        } elseif ( $scope === self::SCOPE_GROUP ) {
            $raw = $in['player_ids'] ?? [];
            if ( is_string( $raw ) ) $raw = explode( ',', $raw );
            foreach ( (array) $raw as $value ) {
                $id = self::positiveInt( is_string( $value ) ? trim( $value ) : $value );
                if ( $id === 0 ) return self::invalid( 'player_ids', __( 'Every player in the group must be a player id.', 'talenttrack' ) );
                $player_ids[ $id ] = $id;
            }
            $player_ids = array_values( $player_ids );
            if ( $player_ids === [] ) return self::invalid( 'player_ids', __( 'Choose at least one player.', 'talenttrack' ) );
            if ( count( $player_ids ) > self::MAX_GROUP ) {
                return self::invalid(
                    'player_ids',
                    /* translators: %d: maximum number of players */
                    sprintf( __( 'A group holds at most %d players.', 'talenttrack' ), self::MAX_GROUP )
                );
            }
        }

        $window = ReportFilters::seasonDefaultWindow();
        $from   = is_string( $in['from'] ?? null ) && $in['from'] !== '' ? (string) $in['from'] : $window['from'];
        $to     = is_string( $in['to'] ?? null ) && $in['to'] !== '' ? (string) $in['to'] : $window['to'];
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            return new StudioError( 'bad_date', __( 'The period needs a from and a to date (YYYY-MM-DD), from first.', 'talenttrack' ), 400, [ 'fields' => [ 'from', 'to' ] ] );
        }
        $days = (int) round( ( (int) strtotime( $to . ' 00:00:00 UTC' ) - (int) strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
        if ( $days > self::MAX_PERIOD_DAYS ) {
            return new StudioError(
                'bad_date',
                /* translators: %d: maximum number of days */
                sprintf( __( 'A period covers at most %d days: one season plus one month.', 'talenttrack' ), self::MAX_PERIOD_DAYS ),
                400,
                [ 'fields' => [ 'from', 'to' ], 'max_days' => self::MAX_PERIOD_DAYS ]
            );
        }

        return new self( $scope, $player_id, $team_id, $player_ids, $from, $to, $audience, $user_id );
    }

    /**
     * The same context with the group narrowed to these players (the ones
     * the reader may see).
     *
     * @param list<int> $player_ids
     */
    public function withPlayerIds( array $player_ids ): self {
        return new self( $this->scope, $this->playerId, $this->teamId, $player_ids, $this->from, $this->to, $this->audience, $this->userId );
    }

    public function isExternal(): bool {
        return PlayerReportAudience::isExternal( $this->audience );
    }

    /** Memo key for a shared read: the subject and the window. */
    public function subjectKey(): string {
        return $this->scope . ':' . $this->playerId . ':' . $this->teamId . ':' . implode( ',', $this->playerIds ) . ':' . $this->from . ':' . $this->to;
    }

    /**
     * A reader the rules place outside the staff room reads as a scout,
     * whatever they asked for (`PlayerReportAudience::forReader()`).
     */
    private static function resolveAudience( string $requested, int $user_id ): string {
        $own = PlayerReportAudience::forReader( $user_id );
        if ( PlayerReportAudience::isExternal( $own ) ) return $own;
        return PlayerReportAudience::isValid( $requested ) ? $requested : PlayerReportAudience::INTERNAL;
    }

    private static function invalid( string $field, string $message ): StudioError {
        return new StudioError( 'invalid_field', $message, 400, [ 'field' => $field ] );
    }

    private static function positiveInt( mixed $value ): int {
        if ( is_int( $value ) ) return $value > 0 ? $value : 0;
        if ( is_string( $value ) && preg_match( '/^\d+$/', $value ) === 1 ) return max( 0, (int) $value );
        return 0;
    }

    private static function isDate( string $value ): bool {
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) !== 1 ) return false;
        return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
    }
}
