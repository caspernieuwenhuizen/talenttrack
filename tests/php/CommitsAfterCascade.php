<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3986 — cleaning up after a test that runs a real cascade purge.
 *
 * `GenericCascadeDeleter::run()` wraps its work in `START TRANSACTION` /
 * `COMMIT`, which is right for production and fatal for the suite's
 * isolation: that `COMMIT` ends the per-test transaction WordPress opened,
 * so every fixture row the test wrote **before** the purge is committed and
 * survives into the rest of the run. #3949 found it the hard way, when a
 * purge test's committed teams turned up inside another test's age-group
 * count.
 *
 * A `tear_down()` DELETE does not fix it. The suite keeps autocommit off, so
 * the cleanup lands in the transaction that opens after the purge's commit
 * and is rolled back along with it — the cleanup undoes itself. That was
 * #3949's first attempt.
 *
 * So: record where the database stood before the fixtures (`markFixtureFloor()`
 * from `set_up()`, before anything is written), and after the purge delete
 * everything above that mark and commit *that* (`cleanUpAndCommit()`).
 *
 * The sweep covers every plugin table carrying an `id`, not a hand-listed
 * few: a test's footprint includes whatever the code under test wrote on its
 * way through — a workflow task, an event-log row, an audit entry — and a
 * list of tables goes stale the moment one of those gains a table. Only one
 * test runs at a time, so anything above the mark is this test's.
 *
 * Use `preview()` instead where the assertion is only about what a purge
 * would reach; this trait is for a test that has to observe the state a real
 * purge leaves behind.
 */
trait CommitsAfterCascade {

    /** @var list<string>|null the plugin's id-bearing tables, read from the schema once */
    private static ?array $cascade_tables = null;

    /** @var array<string,int> prefixed table => highest id before the fixtures */
    private array $cascade_floor = [];

    private int $cascade_user_floor = 0;

    /** @var array<string,string|null> config_key => value before the fixtures, null when it had no row */
    private array $cascade_config = [];

    /**
     * Where the database stood before this test wrote anything. Call it from
     * `set_up()`, straight after `parent::set_up()`.
     */
    protected function markFixtureFloor(): void {
        global $wpdb;

        $this->cascade_floor = [];
        foreach ( self::cascadeTables() as $table ) {
            $this->cascade_floor[ $table ] = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM `{$table}`" );
        }
        $this->cascade_user_floor = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->users}" );
    }

    /**
     * The `tt_config` keys the code under test writes on its way through a
     * purge. Call it from `set_up()` alongside `markFixtureFloor()`.
     *
     * The floor sweep is keyed on `id`, and `tt_config` has none — its primary
     * key is `(club_id, config_key)`. So a key the purge path wrote is
     * committed along with everything else and then survives the sweep, which
     * matters for `tt_recycle_bin_last_purge_date`: left behind, it tells every
     * later sweep in the run that today's has already happened. Name the keys
     * here and `cleanUpAndCommit()` puts them back the way it found them —
     * removed when there was no row, restored to the old value when there was.
     */
    protected function markConfigKeys( string ...$keys ): void {
        global $wpdb;

        $this->cascade_config = [];
        foreach ( $keys as $key ) {
            $value = $wpdb->get_var( $wpdb->prepare(
                "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = %d AND config_key = %s",
                CurrentClub::id(),
                $key
            ) );
            $this->cascade_config[ $key ] = $value === null ? null : (string) $value;
        }
    }

    /**
     * Delete everything written since the mark and commit it, so the purge's
     * own commit does not hand this test's fixtures to the next one.
     */
    protected function cleanUpAndCommit(): void {
        global $wpdb;

        foreach ( $this->cascade_floor as $table => $floor ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE id > %d", $floor ) );
        }
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id > %d", $this->cascade_user_floor ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->users} WHERE ID > %d", $this->cascade_user_floor ) );

        // The id-less tables the sweep above cannot reach. Empty unless the
        // test named keys with `markConfigKeys()`.
        foreach ( $this->cascade_config as $key => $value ) {
            if ( $value === null ) {
                $wpdb->delete( $wpdb->prefix . 'tt_config', [
                    'club_id'    => CurrentClub::id(),
                    'config_key' => $key,
                ] );
                continue;
            }
            $wpdb->replace( $wpdb->prefix . 'tt_config', [
                'club_id'      => CurrentClub::id(),
                'config_key'   => $key,
                'config_value' => $value,
            ] );
        }

        // Autocommit is off, so without this the cleanup is rolled back with
        // the transaction that opened after the purge committed.
        $wpdb->query( 'COMMIT' );
    }

    /**
     * What is still above the mark — empty when the cleanup did its job.
     *
     * @return array<string,int> prefixed table => surviving rows
     */
    protected function rowsAboveFixtureFloor(): array {
        global $wpdb;

        $left = [];
        foreach ( $this->cascade_floor as $table => $floor ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE id > %d", $floor ) );
            if ( $count > 0 ) $left[ $table ] = $count;
        }
        return $left;
    }

    /**
     * Every `tt_*` table with an `id` column. One schema read for the whole
     * class; the set does not change while the suite runs.
     *
     * @return list<string>
     */
    private static function cascadeTables(): array {
        global $wpdb;

        if ( self::$cascade_tables !== null ) return self::$cascade_tables;

        $like = $wpdb->esc_like( $wpdb->prefix . 'tt_' ) . '%';
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'id' AND TABLE_NAME LIKE %s
              ORDER BY TABLE_NAME",
            $like
        ) );

        self::$cascade_tables = array_values( array_map( 'strval', (array) $rows ) );
        return self::$cascade_tables;
    }
}
