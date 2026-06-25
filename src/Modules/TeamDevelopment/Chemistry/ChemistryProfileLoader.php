<?php
namespace TT\Modules\TeamDevelopment\Chemistry;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\TeamDevelopment\Repositories\PlayerAttributesRepository;

/**
 * ChemistryProfileLoader (#1017 Phase 3) — turns a set of player ids into
 * the inputs the sub-engines need: a PlayerChemistryProfile each (attributes
 * + age + footedness) and a pairwise PairContext (shared completed
 * activities, shared completed games, team-tenure overlap).
 *
 * All the DB access lives here so the scorers stay pure. Everything is
 * pre-loaded once for the id set, then resolved per pair from memory.
 */
final class ChemistryProfileLoader {

    private PlayerAttributesRepository $attributes;

    /** @var array<int, array{date_of_birth:?string, foot:string}> */
    private array $playerMeta = [];
    /** @var array<int, array<int, true>> player_id → completed activity_id set */
    private array $activitySets = [];
    /** @var array<int, array<int, true>> player_id → completed game activity_id set */
    private array $gameSets = [];
    /** @var array<int, list<array{team_id:int, from:int, to:int}>> player_id → tenure spans (unix days) */
    private array $tenure = [];

    public function __construct( ?PlayerAttributesRepository $attributes = null ) {
        $this->attributes = $attributes ?? new PlayerAttributesRepository();
    }

    /**
     * Pre-load everything for a player-id set.
     *
     * @param list<int> $player_ids
     */
    public function load( array $player_ids ): void {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ), static fn( $i ) => $i > 0 ) ) );
        if ( empty( $ids ) ) return;

        global $wpdb;
        $p   = $wpdb->prefix;
        $in  = implode( ',', $ids );

        // Player meta — DOB + footedness.
        $rows = $wpdb->get_results(
            "SELECT id, date_of_birth, preferred_foot FROM {$p}tt_players WHERE id IN ($in)"
        );
        foreach ( (array) $rows as $r ) {
            $this->playerMeta[ (int) $r->id ] = [
                'date_of_birth' => $r->date_of_birth !== null ? (string) $r->date_of_birth : null,
                'foot'          => self::normaliseFoot( (string) ( $r->preferred_foot ?? '' ) ),
            ];
        }

        // Shared completed-activity attendance (present, non-guest).
        $att = $wpdb->get_results( $wpdb->prepare(
            "SELECT att.player_id, a.id AS activity_id, a.activity_type_key
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id IN ($in)
                AND att.is_guest = 0
                AND att.status = 'present'
                AND a.archived_at IS NULL
                AND a.plan_state = 'completed'
                AND ( a.club_id = %d OR a.club_id IS NULL )",
            CurrentClub::id()
        ) );
        foreach ( (array) $att as $r ) {
            $pid = (int) $r->player_id;
            $aid = (int) $r->activity_id;
            $this->activitySets[ $pid ][ $aid ] = true;
            if ( (string) $r->activity_type_key === 'game' ) {
                $this->gameSets[ $pid ][ $aid ] = true;
            }
        }

        // Team tenure spans.
        $hist = $wpdb->get_results(
            "SELECT player_id, team_id, joined_at, left_at
               FROM {$p}tt_player_team_history WHERE player_id IN ($in)"
        );
        $today = (int) floor( current_time( 'timestamp', true ) / 86400 );
        foreach ( (array) $hist as $r ) {
            $from = $r->joined_at ? (int) floor( (int) strtotime( (string) $r->joined_at ) / 86400 ) : null;
            if ( $from === null ) continue;
            $to = $r->left_at ? (int) floor( (int) strtotime( (string) $r->left_at ) / 86400 ) : $today;
            $this->tenure[ (int) $r->player_id ][] = [ 'team_id' => (int) $r->team_id, 'from' => $from, 'to' => $to ];
        }
    }

    public function profile( int $player_id ): PlayerChemistryProfile {
        $grouped = $this->attributes->forPlayer( $player_id );
        $attrs = [];
        foreach ( $grouped as $group => $items ) {
            foreach ( $items as $it ) {
                $attrs[ (string) $group ][ (string) $it['attr_key'] ] = $it['value'] !== null ? (int) $it['value'] : null;
            }
        }
        $meta = $this->playerMeta[ $player_id ] ?? [ 'date_of_birth' => null, 'foot' => '' ];
        return new PlayerChemistryProfile( $player_id, $attrs, self::ageFrom( $meta['date_of_birth'] ), (string) $meta['foot'] );
    }

    public function pairContext( int $a, int $b ): PairContext {
        $shared_sessions = $this->intersectCount( $this->activitySets[ $a ] ?? [], $this->activitySets[ $b ] ?? [] );
        $shared_games    = $this->intersectCount( $this->gameSets[ $a ] ?? [], $this->gameSets[ $b ] ?? [] );
        $overlap         = $this->tenureOverlapDays( $a, $b );
        return new PairContext( $shared_sessions, $overlap, $shared_games );
    }

    /**
     * @param array<int, true> $x
     * @param array<int, true> $y
     */
    private function intersectCount( array $x, array $y ): int {
        if ( empty( $x ) || empty( $y ) ) return 0;
        return count( array_intersect_key( $x, $y ) );
    }

    private function tenureOverlapDays( int $a, int $b ): int {
        $sa = $this->tenure[ $a ] ?? [];
        $sb = $this->tenure[ $b ] ?? [];
        $total = 0;
        foreach ( $sa as $spanA ) {
            foreach ( $sb as $spanB ) {
                if ( $spanA['team_id'] !== $spanB['team_id'] ) continue;
                $start = max( $spanA['from'], $spanB['from'] );
                $end   = min( $spanA['to'], $spanB['to'] );
                if ( $end > $start ) $total += ( $end - $start );
            }
        }
        return $total;
    }

    private static function ageFrom( ?string $dob ): ?float {
        if ( $dob === null || $dob === '' || $dob === '0000-00-00' ) return null;
        $ts = strtotime( $dob );
        if ( ! $ts ) return null;
        $days = ( current_time( 'timestamp', true ) - $ts ) / 86400;
        if ( $days <= 0 ) return null;
        return round( $days / 365.25, 1 );
    }

    private static function normaliseFoot( string $raw ): string {
        $r = strtolower( trim( $raw ) );
        if ( $r === 'left' || $r === 'links' || $r === 'l' ) return 'left';
        if ( $r === 'right' || $r === 'rechts' || $r === 'r' ) return 'right';
        if ( $r === 'both' || $r === 'beide' || $r === 'two-footed' ) return 'both';
        return '';
    }
}
