<?php
namespace TT\Modules\Analytics\Reports\PlayerReportComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * The player report's minutes block (#4590): appearances and minutes from the
 * evidence packet, and the share of the minutes available against the
 * position group and the team.
 */
final class MinutesComposer {

    /** @return array<string,mixed> */
    public static function compose( PlayerReportContext $ctx ): array {
        $packet = $ctx->packet() ?? [];
        return self::minutes( (array) ( $packet['minutes'] ?? [] ) ) + self::minutesShare( $ctx->player, $ctx->from, $ctx->to );
    }

    /**
     * @param array<string,mixed> $minutes
     * @return array<string,mixed>
     */
    private static function minutes( array $minutes ): array {
        $breakdown = (array) ( $minutes['breakdown'] ?? [] );
        return [
            'apps'    => (int) ( $minutes['apps'] ?? 0 ),
            'minutes' => (int) ( $minutes['minutes'] ?? 0 ),
            'matches' => count( $breakdown ),
        ];
    }

    /**
     * #3991 — playing time as a share of the minutes available, against the
     * position group and the team. Read from `MinutesQuery::forTeam()` for the
     * player's current team — the call the team minutes report makes — so the
     * numbers agree with it for the same window. No new query beyond the
     * roster, which says who the team is today.
     *
     * The comparison is staff-only: `PlayerReportAudience::apply()` strips it
     * for a family or a scout, who keep the player's own share.
     *
     * @return array{share:?int, comparison:?array{team:int, position:?array{positions:list<string>, count:int, share:int}, player_positions:list<string>}}
     */
    private static function minutesShare( object $player, string $from, string $to ): array {
        $none    = [ 'share' => null, 'comparison' => null ];
        $team_id = (int) ( $player->team_id ?? 0 );
        if ( $team_id <= 0 ) return $none;

        $rows      = ( new MinutesQuery() )->forTeam( $team_id, $from, $to );
        $available = $rows !== [] ? (int) $rows[0]['available_minutes'] : 0;
        if ( $available <= 0 ) return $none;

        $played = [];
        foreach ( $rows as $row ) {
            $played[ (int) $row['player_id'] ] = (int) $row['total_minutes'];
        }

        global $wpdb;
        $roster_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.preferred_positions
               FROM {$wpdb->prefix}tt_players p
              WHERE p.team_id = %d
                AND p.club_id = %d
                AND p.status = 'active'
                AND " . \TT\Infrastructure\Archive\ArchiveRepository::filterClause( 'active', 'p' ),
            $team_id, CurrentClub::id()
        ) );
        $roster = [];
        foreach ( (array) $roster_rows as $r ) {
            $roster[ (int) $r->id ] = self::positionCodes( (string) ( $r->preferred_positions ?? '' ) );
        }
        $player_id = (int) ( $player->id ?? 0 );
        if ( ! isset( $roster[ $player_id ] ) ) {
            $roster[ $player_id ] = self::positionCodes( (string) ( $player->preferred_positions ?? '' ) );
        }

        return self::shareComparison( $player_id, $roster, $played, $available );
    }

    /**
     * The arithmetic of #3991, apart from the database so a fixture squad can
     * pin it.
     *
     * - share: the player's minutes over the minutes available, whole percent.
     * - team: the mean share of the roster, the player included, each player
     *   once; a roster player who did not play counts as 0.
     * - position: the mean share of roster players who share at least one
     *   profile position with the player, the player excluded, with those
     *   positions and the group's size. Null when the player has no profile
     *   position or nobody shares one.
     *
     * @param array<int,list<string>> $roster    player id => profile position codes
     * @param array<int,int>          $played    player id => minutes in the window
     * @return array{share:?int, comparison:?array{team:int, position:?array{positions:list<string>, count:int, share:int}, player_positions:list<string>}}
     */
    public static function shareComparison( int $player_id, array $roster, array $played, int $available ): array {
        if ( $available <= 0 || $roster === [] ) return [ 'share' => null, 'comparison' => null ];

        $fraction = static fn( int $pid ): float => min( 1.0, ( $played[ $pid ] ?? 0 ) / $available );

        $team = 0.0;
        foreach ( array_keys( $roster ) as $pid ) {
            $team += $fraction( (int) $pid );
        }
        $team /= count( $roster );

        $mine     = $roster[ $player_id ] ?? [];
        $position = null;
        if ( $mine !== [] ) {
            $group  = [];
            $shared = [];
            foreach ( $roster as $pid => $codes ) {
                if ( (int) $pid === $player_id ) continue;
                $common = array_values( array_intersect( $mine, $codes ) );
                if ( $common === [] ) continue;
                $group[] = (int) $pid;
                $shared  = array_merge( $shared, $common );
            }
            if ( $group !== [] ) {
                $sum = 0.0;
                foreach ( $group as $pid ) $sum += $fraction( $pid );
                $position = [
                    // The player's own positions that someone in the group
                    // shares, in the player's order.
                    'positions' => array_values( array_intersect( $mine, array_unique( $shared ) ) ),
                    'count'     => count( $group ),
                    'share'     => (int) round( $sum / count( $group ) * 100 ),
                ];
            }
        }

        return [
            'share'      => (int) round( $fraction( $player_id ) * 100 ),
            'comparison' => [
                'team'             => (int) round( $team * 100 ),
                'position'         => $position,
                'player_positions' => $mine,
            ],
        ];
    }

    /**
     * A stored `preferred_positions` value as its position codes: a JSON
     * array on modern rows, a comma-separated string on legacy ones.
     *
     * @return list<string>
     */
    private static function positionCodes( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        $codes   = is_array( $decoded ) ? $decoded : explode( ',', $raw );
        $out     = [];
        foreach ( $codes as $code ) {
            $code = is_scalar( $code ) ? strtoupper( trim( (string) $code ) ) : '';
            if ( $code !== '' && ! in_array( $code, $out, true ) ) $out[] = $code;
        }
        return $out;
    }
}
