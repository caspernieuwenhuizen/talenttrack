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
        $viewer = self::viewer( $readers );
        if ( $viewer === 0 ) return;

        $payloads = [];
        foreach ( $counts as $player_id => $count ) {
            $player_id = (int) $player_id;
            if ( $player_id <= 0 || $count <= 0 ) continue;
            $key = $action . '|' . $player_id . '|' . $viewer;
            if ( isset( self::$recorded[ $key ] ) ) continue;
            self::$recorded[ $key ] = true;
            $payloads[ $player_id ] = [ 'surface' => $surface, 'count' => $count ] + $extra;
        }
        // One row per player, written together.
        ( new AuditService() )->recordMany( $action, 'player', $payloads );
    }

    /**
     * How many of these journey entries of one player are medical or
     * safeguarding entries, in one statement. The report sections carry the
     * entries without their visibility, so it is read back by id.
     *
     * @param list<int> $event_ids
     */
    public static function sensitiveCount( int $player_id, array $event_ids ): int {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $player_id <= 0 || $ids === [] ) return 0;

        global $wpdb;
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND club_id = %d AND id IN ($in) AND visibility IN ('medical', 'safeguarding')",
            ...array_merge( [ $player_id, \TT\Infrastructure\Tenancy\CurrentClub::id() ], $ids )
        ) );
    }

    /**
     * The journey section's medical and safeguarding entries, as shown to
     * this reader, recorded as a read of the player's sensitive timeline.
     *
     * @param array<string,mixed> $section the journey section payload, after the reader's cut
     * @param list<int>           $readers
     * @param array<string,mixed> $extra
     */
    public static function recordJourney( string $surface, int $player_id, array $section, array $readers, array $extra = [] ): void {
        if ( self::viewer( $readers ) === 0 ) return;
        $items = is_array( $section['items'] ?? null ) ? $section['items'] : [];
        if ( $items === [] ) return;
        $ids = [];
        foreach ( $items as $item ) {
            if ( is_array( $item ) ) $ids[] = (int) ( $item['id'] ?? 0 );
        }
        self::record( self::SENSITIVE, $surface, [ $player_id => self::sensitiveCount( $player_id, $ids ) ], $readers, $extra );
    }

    /**
     * The signed-in reader a composition for these readers is shown to, or 0:
     * quiet, nobody signed in, or composed for anyone else or for several.
     *
     * @param list<int> $readers
     */
    private static function viewer( array $readers ): int {
        if ( self::$quiet > 0 ) return 0;
        $viewer = get_current_user_id();
        return $viewer > 0 && array_map( 'intval', $readers ) === [ $viewer ] ? $viewer : 0;
    }

    /** Forget what this request recorded, so a test can stand for a second request. */
    public static function flush(): void {
        self::$recorded = [];
    }
}
