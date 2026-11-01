<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * ReportReader (#4569) — one user, for one request, with what `ReportAccess`
 * asks about them resolved once: the teams they build for, the personas they
 * hold, and the player and team subjects they read. A list of reports asks
 * the same questions for every row; this keeps it to one answer each, and
 * `primePlayers()` reads the players of a whole page in one go.
 */
final class ReportReader {

    private bool $teams_loaded = false;

    /** @var list<int>|null */
    private ?array $teams = null;

    /** @var list<string>|null */
    private ?array $personas = null;

    /** @var array<int,bool> player id => reads a report about them */
    private array $players = [];

    /** @var array<int,int> player id => their team, 0 for none */
    private array $player_teams = [];

    /** @var array<int,bool> team id => reads a report about it */
    private array $team_reads = [];

    private function __construct( public readonly int $userId ) {}

    public static function for( int $user_id ): self {
        return new self( $user_id );
    }

    /**
     * The teams this user builds reports about: null for every team, a list
     * for a team builder, an empty list for nobody.
     *
     * @return list<int>|null
     */
    public function builderTeams(): ?array {
        if ( $this->teams_loaded ) return $this->teams;
        $this->teams_loaded = true;

        $uid = $this->userId;
        if ( $uid <= 0 ) return $this->teams = [];
        if ( user_can( $uid, 'manage_options' )
            || MatrixGate::can( $uid, 'report_studio', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_GLOBAL ) ) {
            return $this->teams = null;
        }
        $teams = [];
        foreach ( QueryHelpers::get_teams_for_coach( $uid ) as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id > 0 && MatrixGate::can( $uid, 'report_studio', MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $id ) ) {
                $teams[ $id ] = $id;
            }
        }
        return $this->teams = array_values( $teams );
    }

    /** @return list<string> */
    public function personas(): array {
        if ( $this->personas === null ) {
            $this->personas = $this->userId > 0 ? array_values( array_map( 'strval', PersonaResolver::personasFor( $this->userId ) ) ) : [];
        }
        return $this->personas;
    }

    /**
     * Read these players' subjects in one go, for a page of reports.
     *
     * @param list<int> $player_ids
     */
    public function primePlayers( array $player_ids ): void {
        $missing = array_values( array_diff( array_unique( $player_ids ), array_keys( $this->players ) ) );
        if ( $missing === [] ) return;
        $many = PlayerReportAccess::canReadMany( $this->userId, $missing );
        foreach ( $missing as $id ) {
            $this->players[ $id ] = $many['read'][ $id ] ?? false;
            if ( isset( $many['teams'][ $id ] ) ) $this->player_teams[ $id ] = $many['teams'][ $id ];
        }
    }

    /** May this user read a report about this player (the player report's rule). */
    public function readsPlayer( int $player_id ): bool {
        if ( ! isset( $this->players[ $player_id ] ) ) $this->primePlayers( [ $player_id ] );
        return $this->players[ $player_id ] ?? false;
    }

    /** The player's team, 0 for none, null for a player outside the club. */
    public function teamOfPlayer( int $player_id ): ?int {
        if ( ! isset( $this->players[ $player_id ] ) ) $this->primePlayers( [ $player_id ] );
        return $this->player_teams[ $player_id ] ?? null;
    }

    /** May this user read a report about this team (the team report's rule). */
    public function readsTeam( int $team_id ): bool {
        if ( ! isset( $this->team_reads[ $team_id ] ) ) {
            $this->team_reads[ $team_id ] = TeamReportAccess::canRead( $this->userId, $team_id );
        }
        return $this->team_reads[ $team_id ];
    }
}
