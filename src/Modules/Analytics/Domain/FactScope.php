<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Authorization\MatrixGate;

/**
 * FactScope — whose rows a fact query may read (#4568).
 *
 * `FactQuery` filters by `club_id`; this is the row scope inside the club,
 * and it follows each fact's own read entity:
 *
 * - global read on the entity: every row in the club;
 * - otherwise, team-scoped read: the reader's assigned teams on which the
 *   entity is granted;
 * - otherwise, self-scoped read: the reader's own player record;
 * - otherwise nothing.
 *
 * The engine applies the answer as a `WHERE` on the fact's team or player
 * column. Journey events are further narrowed to the visibility levels the
 * reader holds.
 *
 * `unrestricted()` is for code that answers no reader at all (a figure test,
 * a migration). A request on behalf of a person always uses `forUser()`.
 */
final class FactScope {

    public const ALL     = 'all';
    public const TEAMS   = 'teams';
    public const PLAYERS = 'players';
    public const NONE    = 'none';

    /** @var array<int,self> */
    private static array $memo = [];

    /** @var array<string,array{0:string,1:list<int>}> */
    private array $access = [];

    /**
     * @param list<int>|null    $narrowTeams  null = no extra narrowing
     * @param list<string>|null $visibilities null = every journey visibility
     */
    private function __construct(
        private int $userId,
        private ?array $narrowTeams,
        private ?array $visibilities,
        private bool $system
    ) {}

    public static function forUser( int $user_id ): self {
        if ( $user_id <= 0 ) return new self( 0, null, [ RecordVisibility::LEVEL_PUBLIC ], false );
        if ( isset( self::$memo[ $user_id ] ) ) return self::$memo[ $user_id ];
        return self::$memo[ $user_id ] = new self( $user_id, null, RecordVisibility::forJourney( $user_id ), false );
    }

    public static function unrestricted(): self {
        return new self( 0, null, null, true );
    }

    /** Forget the per-request memo; a test that changes a user's grants calls this. */
    public static function flush(): void {
        self::$memo = [];
    }

    /**
     * The same reader, with a team-scoped read narrowed to these teams. It
     * never widens the reader, and a global read is left alone: the
     * caller's own filters pick the rows there.
     *
     * @param list<int> $team_ids
     */
    public function narrowedToTeams( array $team_ids ): self {
        $wanted = array_values( array_unique( array_filter( array_map( 'intval', $team_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        $teams  = $this->narrowTeams === null ? $wanted : array_values( array_intersect( $this->narrowTeams, $wanted ) );
        return new self( $this->userId, $teams, $this->visibilities, $this->system );
    }

    /** The same reader, with journey events at the public level only. */
    public function publicOnly(): self {
        return new self( $this->userId, $this->narrowTeams, [ RecordVisibility::LEVEL_PUBLIC ], $this->system );
    }

    public function userId(): int {
        return $this->userId;
    }

    /**
     * How this reader may read rows under `$entity`: `[ ALL, [] ]`,
     * `[ TEAMS, team ids ]`, `[ PLAYERS, player ids ]` or `[ NONE, [] ]`.
     * A fact that names no entity is refused: the rule fails closed.
     *
     * @return array{0:string,1:list<int>}
     */
    public function access( string $entity ): array {
        if ( $entity === '' ) return [ self::NONE, [] ];
        if ( isset( $this->access[ $entity ] ) ) return $this->access[ $entity ];
        return $this->access[ $entity ] = $this->resolve( $entity );
    }

    /** May this reader read any row under the entity? */
    public function mayRead( string $entity ): bool {
        return $this->access( $entity )[0] !== self::NONE;
    }

    /** @return list<string>|null null = every level */
    public function visibilities(): ?array {
        return $this->visibilities;
    }

    /** Part of the result cache key, so two readers never share an answer. */
    public function cacheKey(): string {
        return md5( (string) wp_json_encode( [
            'u' => $this->system ? 'system' : $this->userId,
            'n' => $this->narrowTeams,
            'v' => $this->visibilities,
        ] ) );
    }

    /**
     * @return array{0:string,1:list<int>}
     */
    private function resolve( string $entity ): array {
        if ( $this->system ) return [ self::ALL, [] ];
        $uid = $this->userId;
        if ( $uid <= 0 ) return [ self::NONE, [] ];

        if ( user_can( $uid, 'manage_options' )
            || MatrixGate::can( $uid, $entity, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL ) ) {
            return [ self::ALL, [] ];
        }

        $teams = [];
        foreach ( QueryHelpers::get_teams_for_coach( $uid ) as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id > 0 && MatrixGate::can( $uid, $entity, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $id ) ) $teams[ $id ] = $id;
        }
        $teams = array_values( $teams );
        if ( $teams !== [] ) {
            if ( $this->narrowTeams !== null ) $teams = array_values( array_intersect( $teams, $this->narrowTeams ) );
            return $teams === [] ? [ self::NONE, [] ] : [ self::TEAMS, $teams ];
        }

        if ( MatrixGate::can( $uid, $entity, MatrixGate::READ, MatrixGate::SCOPE_SELF, $uid ) ) {
            $own = QueryHelpers::get_player_for_user( $uid );
            $pid = $own ? (int) ( $own->id ?? 0 ) : 0;
            if ( $pid > 0 ) return [ self::PLAYERS, [ $pid ] ];
        }

        return [ self::NONE, [] ];
    }
}
