<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;

/**
 * InjuryReads — the one place a report records that a reader was shown a
 * player's injury or sensitive journey details, as the injuries screens and
 * the journey timeline record theirs.
 *
 * - Only for the signed-in reader the details were composed for: a
 *   composition for several readers, or with nobody signed in, as a
 *   scheduled send is, records nothing.
 * - Once per action, player and reader in a request, whichever surface asks
 *   first: a route that composes and then returns a copy is one read.
 * - Nothing inside `quietly()`: a composition that is stored, not shown, as
 *   taking or sharing a snapshot is. Its read is recorded when it is opened.
 */
final class InjuryReads {

    public const INJURIES  = 'player.injuries_viewed';
    public const SENSITIVE = 'player.sensitive_timeline_viewed';

    /** @var array<string,true> */
    private static array $recorded = [];

    private static int $quiet = 0;

    /**
     * Run a composition that is stored rather than shown.
     *
     * @template T
     * @param callable():T $compose
     * @return T
     */
    public static function quietly( callable $compose ) {
        self::$quiet++;
        try {
            return $compose();
        } finally {
            self::$quiet--;
        }
    }

    /**
     * Record a read for each player shown.
     *
     * @param array<int,int>      $counts  player id => entries shown
     * @param list<int>           $readers who the composition is for
     * @param array<string,mixed> $extra   more payload, such as the snapshot uuid
     */
    public static function record( string $action, string $surface, array $counts, array $readers, array $extra = [] ): void {
        if ( self::$quiet > 0 ) return;
        $viewer = get_current_user_id();
        if ( $viewer <= 0 || array_values( array_map( 'intval', $readers ) ) !== [ $viewer ] ) return;

        $audit = null;
        foreach ( $counts as $player_id => $count ) {
            $player_id = (int) $player_id;
            if ( $player_id <= 0 || $count <= 0 ) continue;
            $key = $action . '|' . $player_id . '|' . $viewer;
            if ( isset( self::$recorded[ $key ] ) ) continue;
            self::$recorded[ $key ] = true;

            $audit ??= new AuditService();
            $audit->record( $action, 'player', $player_id, [ 'surface' => $surface, 'count' => $count ] + $extra );
        }
    }

    /** Forget what this request recorded, so a test can stand for a second request. */
    public static function flush(): void {
        self::$recorded = [];
    }
}
