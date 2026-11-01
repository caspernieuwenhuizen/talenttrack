<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Authorization\MatrixGate;

/**
 * FactScope — whose rows a fact query may read (#4568).
 *
 * `FactQuery` filters by `club_id`; this is the row scope inside the club.
 * A reader with club-wide analytics read sees every team; everyone else sees
 * the teams `get_teams_for_coach()` names, and the engine applies that as a
 * `WHERE` on the fact's declared team or player column. Journey events are
 * further narrowed to the visibility levels the reader holds.
 *
 * `unrestricted()` is for code that answers no reader at all (a figure test,
 * a migration). A request on behalf of a person always uses `forUser()`.
 */
final class FactScope {

    /** @var array<int,self> */
    private static array $memo = [];

    /**
     * @param list<int>|null    $teamIds      null = every team in the club
     * @param list<string>|null $visibilities null = every journey visibility
     */
    private function __construct(
        private int $userId,
        private ?array $teamIds,
        private ?array $visibilities,
        private bool $system
    ) {}

    public static function forUser( int $user_id ): self {
        if ( $user_id <= 0 ) return new self( 0, [], [ RecordVisibility::LEVEL_PUBLIC ], false );
        if ( isset( self::$memo[ $user_id ] ) ) return self::$memo[ $user_id ];

        $club_wide = user_can( $user_id, 'manage_options' )
            || AllTeamsScope::canSeeClubWideAnalytics( $user_id );
        $teams = null;
        if ( ! $club_wide ) {
            $teams = [];
            foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
                $id = (int) ( $team->id ?? 0 );
                if ( $id > 0 ) $teams[] = $id;
            }
            $teams = array_values( array_unique( $teams ) );
        }
        return self::$memo[ $user_id ] = new self( $user_id, $teams, RecordVisibility::forJourney( $user_id ), false );
    }

    public static function unrestricted(): self {
        return new self( 0, null, null, true );
    }

    /** Forget the per-request memo; a test that changes a user's grants calls this. */
    public static function flush(): void {
        self::$memo = [];
    }

    /**
     * The same reader, narrowed to these teams. The result never widens the
     * reader: a team outside their scope is dropped, not added.
     *
     * @param list<int> $team_ids
     */
    public function narrowedToTeams( array $team_ids ): self {
        $wanted = array_values( array_unique( array_filter( array_map( 'intval', $team_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        $teams  = $this->teamIds === null ? $wanted : array_values( array_intersect( $this->teamIds, $wanted ) );
        return new self( $this->userId, $teams, $this->visibilities, $this->system );
    }

    public function userId(): int {
        return $this->userId;
    }

    /** Is every team in the club readable? */
    public function isClubWide(): bool {
        return $this->teamIds === null;
    }

    /** @return list<int>|null null = every team */
    public function teamIds(): ?array {
        return $this->teamIds;
    }

    /** @return list<string>|null null = every level */
    public function visibilities(): ?array {
        return $this->visibilities;
    }

    /**
     * May this reader read the fact's entity at all? The rows are narrowed
     * afterwards, so a team-scoped grant is enough here.
     */
    public function mayRead( string $entity ): bool {
        if ( $this->system ) return true;
        if ( $this->userId <= 0 ) return false;
        if ( $entity === '' || user_can( $this->userId, 'manage_options' ) ) return true;
        return MatrixGate::canAnyScope( $this->userId, $entity, MatrixGate::READ );
    }

    /** Part of the result cache key, so two readers never share an answer. */
    public function cacheKey(): string {
        return md5( (string) wp_json_encode( [
            'u' => $this->system ? 'system' : $this->userId,
            't' => $this->teamIds,
            'v' => $this->visibilities,
        ] ) );
    }
}
