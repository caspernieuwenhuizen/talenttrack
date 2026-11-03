<?php
namespace TT\Infrastructure\Search;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;

/**
 * SearchTextSweep (#4671) — folds what a write path missed, and the backlog
 * after an upgrade, on the workflow engine's heartbeat (CLAUDE.md §4: no
 * `wp_cron` of its own; the `AlertSweepCron` pattern).
 *
 * Per club and kind, per tick:
 *
 *   1. fold up to {@see self::BATCH} rows never folded or folded by an older
 *      `SearchText::VERSION` — the backfill;
 *   2. then advance an id cursor over {@see self::BATCH} more rows, recompute
 *      their fold and write only the ones that changed — the drift check,
 *      for a write that changed a name without calling `SearchText::refresh()`.
 *
 * Bounded per tick and idempotent: a row already right is read and not
 * written. A missed fold costs a missed match, never a disclosure, because
 * access never depends on these columns.
 */
final class SearchTextSweep {

    public const BATCH = 500;

    /** tt_config key prefix of the drift cursor, per kind. */
    public const CURSOR_KEY = 'tt_search_text_cursor_';

    public static function init(): void {
        add_action( CronDispatcher::TICK_HOOK, [ self::class, 'onTick' ], 40 );
    }

    public static function onTick(): void {
        ( new self() )->runAllClubs();
    }

    /** @return array<int, array<string, array{folded:int, drift:int}>> club => kind => rows written */
    public function runAllClubs(): array {
        $out = [];
        foreach ( ClubIteration::clubIds() as $club_id ) {
            $out[ $club_id ] = ClubIteration::withClub( $club_id, fn() => $this->runForCurrentClub() );
        }
        return $out;
    }

    /** @return array<string, array{folded:int, drift:int}> */
    public function runForCurrentClub(): array {
        $stats = [];
        foreach ( array_keys( SearchText::KINDS ) as $kind ) {
            if ( ! SearchText::ready( $kind ) ) continue;
            $stats[ $kind ] = [ 'folded' => $this->backfill( $kind ), 'drift' => $this->drift( $kind ) ];
        }
        return $stats;
    }

    private function backfill( string $kind ): int {
        global $wpdb;
        $spec  = SearchText::KINDS[ $kind ];
        $table = $wpdb->prefix . $spec['table'];
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$this->columns( $kind )} FROM {$table}
              WHERE club_id = %d AND ( search_v IS NULL OR search_v < %d )
              ORDER BY id ASC LIMIT %d",
            \TT\Infrastructure\Tenancy\CurrentClub::id(), SearchText::VERSION, self::BATCH
        ), ARRAY_A );
        return SearchText::writeRows( $kind, is_array( $rows ) ? $rows : [] );
    }

    private function drift( string $kind ): int {
        global $wpdb;
        $spec   = SearchText::KINDS[ $kind ];
        $table  = $wpdb->prefix . $spec['table'];
        $config = new ConfigService();
        $cursor = (int) $config->get( self::CURSOR_KEY . $kind, '0' );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT {$this->columns( $kind )} FROM {$table}
              WHERE club_id = %d AND id > %d
              ORDER BY id ASC LIMIT %d",
            \TT\Infrastructure\Tenancy\CurrentClub::id(), $cursor, self::BATCH
        ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : [];

        $last = $rows === [] ? 0 : (int) $rows[ count( $rows ) - 1 ]['id'];
        // A short page means the end of the table: start over next tick.
        $config->set( self::CURSOR_KEY . $kind, (string) ( count( $rows ) < self::BATCH ? 0 : $last ) );
        return SearchText::writeRows( $kind, $rows );
    }

    private function columns( string $kind ): string {
        $spec = SearchText::KINDS[ $kind ];
        return implode( ', ', array_merge( [ 'id', 'search_title', 'search_text', 'search_v' ], $spec['title'], $spec['text'] ) );
    }
}
