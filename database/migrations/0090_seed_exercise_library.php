<?php
/**
 * Migration 0090 — seed the v1 exercise library (#0016 Sprint 1
 * deferred deliverable).
 *
 * Sprint 1 spec § "Seeded exercises (15-20 shipped): common youth-
 * football drills covering each category." This migration ships
 * 18 reference drills, three per category (warmup, rondo,
 * possession, conditioned_game, finishing, set_piece) so a fresh
 * install has something useful in the picker on day one.
 *
 * Idempotent — `INSERT IGNORE` against the unique uuid index makes
 * re-runs no-ops, and operator-edited / archived rows survive.
 *
 * Each row uses a stable v5 UUID derived from the slug so re-runs
 * across installs produce the same uuid (helps cross-club reporting
 * if anyone ever joins exercise-library tables across installs).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0090_seed_exercise_library';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $exercises_table  = "{$p}tt_exercises";
        $categories_table = "{$p}tt_exercise_categories";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $exercises_table ) ) !== $exercises_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $categories_table ) ) !== $categories_table ) return;

        // Resolve category ids by slug.
        $cats = [];
        foreach ( [ 'warmup', 'rondo', 'possession', 'conditioned_game', 'finishing', 'set_piece' ] as $slug ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$categories_table} WHERE slug = %s AND club_id = 1 LIMIT 1",
                $slug
            ) );
            if ( $id > 0 ) $cats[ $slug ] = $id;
        }
        if ( empty( $cats ) ) return; // Migration 0088 hasn't seeded yet.

        $seed = [
            // Warm-up
            [ 'warmup', 'Dynamic stretching circuit',  10, 'Hip openers, leg swings, lunge walks, lateral shuffles. Players move continuously through 4 stations.' ],
            [ 'warmup', 'Square passing 2-touch',       8, 'Four players, square 8m sides. Two-touch passing, change direction every 30 seconds.' ],
            [ 'warmup', 'Activation 1v1',              10, 'Pairs in 5x5m squares — light 1v1 with a low-effort touch limit. Reverses every 45s.' ],
            // Rondo
            [ 'rondo', '4v1 rondo',                     8, 'Classic 4v1 in a 6-8m square. One-touch when comfortable. Defender out after a turnover.' ],
            [ 'rondo', '5v2 rondo',                     8, 'Two defenders centre, five attackers around 8-10m square. Encourages first-time pass.' ],
            [ 'rondo', '6v3 rondo with line targets',  10, 'Two zones; ball must arrive on a third-line teammate before counting. Emphasises switching.' ],
            // Possession
            [ 'possession', '4v4+2 possession',        12, 'Two neutrals support whichever team has the ball. 25x25m grid. Goal: 8 consecutive passes = point.' ],
            [ 'possession', 'End-zone possession',     12, 'Two teams, 30x20m, end-zones at each side. Pass into your end-zone teammate after 5 passes.' ],
            [ 'possession', '3-team rotation',         15, 'Three teams of 4. Two play, one rests. Loser rotates out. 90-second rounds.' ],
            // Conditioned game
            [ 'conditioned_game', '4v4 to small goals', 15, 'Half-pitch, two small goals each end. No goalkeepers. Restart from the goal.' ],
            [ 'conditioned_game', '7v7 with three thirds', 20, 'Three-thirds pitch. Ball must enter middle third before being played forward. Encourages build-up.' ],
            [ 'conditioned_game', 'Counter-attack 4v3', 15, 'Four-attacker waves vs three defenders. Reset on every shot or turnover. Tracks shots on target.' ],
            // Finishing
            [ 'finishing', 'Two-station shooting',     12, 'Servers feed wide; striker turns and finishes. Alternate left/right station every minute.' ],
            [ 'finishing', '1v1 to goal',              12, 'Striker receives 25m out, defender 5m behind. 1v1 to goal. Reset on goal/save/turnover.' ],
            [ 'finishing', 'Cross-and-finish drill',   15, 'Wide player crosses; two attackers + defender in the box. Cross from alternating sides.' ],
            // Set-piece
            [ 'set_piece', 'Corner routine — short',    8, 'Practice the team\'s default short-corner routine. Vary defending side after each rep.' ],
            [ 'set_piece', 'Corner routine — far post', 8, 'Far-post delivery rotation. Two strikers + one screen. Goalkeeper actively challenges.' ],
            [ 'set_piece', 'Free-kick wall positioning', 10, 'Defensive wall setup at 18-24m. Practice both wall + goalkeeper positioning. Includes a rehearsed indirect-FK option.' ],
        ];

        $sql = "INSERT IGNORE INTO {$exercises_table}
                  (uuid, club_id, name, description, duration_minutes, category_id, visibility, version, created_at, updated_at)
                VALUES (%s, %d, %s, %s, %d, %d, %s, %d, %s, %s)";

        $now = current_time( 'mysql' );
        foreach ( $seed as $row ) {
            [ $cat_slug, $name, $duration, $description ] = $row;
            if ( ! isset( $cats[ $cat_slug ] ) ) continue;

            $uuid = self::deterministicUuid( $cat_slug . ':' . $name );
            $wpdb->query( $wpdb->prepare(
                $sql,
                $uuid,
                1,
                $name,
                $description,
                $duration,
                $cats[ $cat_slug ],
                'club',
                1,
                $now,
                $now
            ) );
        }
    }

    /**
     * Deterministic UUID v5 derived from a stable seed string.
     * Re-running on the same install produces the same uuid;
     * across installs different namespaces would diverge —
     * acceptable for seed exercises. We use a constant namespace
     * so the seed library is uniformly addressable.
     */
    private static function deterministicUuid( string $seed ): string {
        // Namespace: a fixed v4 uuid acting as the v5 namespace
        // for all #0016 seeded exercises.
        $ns      = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $ns_hex  = str_replace( '-', '', $ns );
        $ns_bytes = pack( 'H*', $ns_hex );
        $hash    = sha1( $ns_bytes . $seed );
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr( $hash, 0, 8 ),
            substr( $hash, 8, 4 ),
            // Set version to 5 (sha1) and variant bits per RFC 4122.
            sprintf( '5%s', substr( $hash, 13, 3 ) ),
            sprintf( '%04x', ( hexdec( substr( $hash, 16, 4 ) ) & 0x3FFF ) | 0x8000 ),
            substr( $hash, 20, 12 )
        );
    }
};
