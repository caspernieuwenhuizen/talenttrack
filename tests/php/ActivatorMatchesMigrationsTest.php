<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\Activator;
use TT\Infrastructure\Evaluations\RatingScale;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Workflow\Forms\RecordTestTrainingOutcomeForm;

/**
 * #5020 — the install routine matches the migrations.
 *
 * `Activator::ensureSchema()` runs `schemaQueries()` through dbDelta on
 * activation and on "Retry migrations now", over tables the migrations have
 * already shaped. A CREATE that disagrees with a migration puts the old shape
 * back: `tt_players.wp_user_id` went back to `DEFAULT 0` after migration 0170
 * made it nullable and unique per club.
 *
 * The suite's schema is what an install gets: wp-env activates the plugin
 * (which runs `ensureSchema()` and the migrations) before PHPUnit starts.
 *
 * Nothing here alters a real table. Missing tables, columns, indexes and
 * defaults come from dbDelta's dry run. A column dbDelta would re-declare
 * ("Changed type of …") is judged on a temporary copy of its table: the
 * copy takes the CREATE for real and is compared with itself before. On
 * MariaDB, which wp-env runs, dbDelta reports every integer column declared
 * without a display width as a type change, and re-applying that column's
 * definition is how a stale default or NOT NULL comes back, so the copy is
 * where that shows.
 */
final class ActivatorMatchesMigrationsTest extends WP_UnitTestCase {

    private const PROBE = 'tt_probe_5020';

    public function test_a_reactivation_changes_no_tt_table(): void {
        $drift = $this->dryRun();
        foreach ( $this->schemaQueries() as $sql ) {
            $probe = $this->reapply( $sql );
            if ( $probe === null ) continue;
            foreach ( $probe['drift'] as $line ) $drift[] = $line;
            $this->dropProbe();
        }

        $this->assertSame( [], $drift, "A re-activation would change what the migrations built:\n" . implode( "\n", $drift ) );
    }

    public function test_a_reactivation_keeps_one_account_per_player(): void {
        global $wpdb;
        $probe = $this->reapply( $this->queryFor( 'tt_players' ) );
        $this->assertNotNull( $probe, 'tt_players exists and the install routine declares it' );
        $table = $wpdb->prefix . self::PROBE;

        $column = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` LIKE 'wp_user_id'" );
        $index  = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'uniq_club_user'" );
        $this->dropProbe();

        $this->assertNotNull( $column );
        $this->assertSame( 'YES', $column->Null );
        $this->assertContains( $column->Default, [ null, 'NULL' ], 'no account is NULL, not 0' );

        $this->assertSame( [ 'club_id', 'wp_user_id' ], array_map( static fn( $r ): string => (string) $r->Column_name, $index ) );
        $this->assertSame( '0', (string) ( $index[0]->Non_unique ?? '' ) );
    }

    public function test_two_promotions_in_one_club_both_succeed_after_a_reactivation(): void {
        // A re-activation leaves tt_players as the migrations built it, so
        // promoting on the suite's table is promoting after one.
        $probe = $this->reapply( $this->queryFor( 'tt_players' ) );
        $this->assertNotNull( $probe );
        $this->dropProbe();
        $this->assertSame( [], $probe['drift'], 'a re-activation leaves tt_players unchanged' );

        $id = ( new TrialTracksRepository() )->create( [ 'name' => 'Reactivation', 'sort_order' => 0 ] );
        $this->assertGreaterThan( 0, $id );

        $form    = new RecordTestTrainingOutcomeForm();
        $players = [];
        foreach ( [ 'Sem', 'Noor' ] as $first ) {
            $written   = $form->onCompleted( [ 'prospect_id' => $this->prospect( $first ) ], [], [ 'recommendation' => 'admit_to_trial' ] );
            $players[] = (int) ( $written['player_id'] ?? 0 );
        }

        $this->assertGreaterThan( 0, $players[0] );
        $this->assertGreaterThan( 0, $players[1], 'the second promotion in the club is not refused by the unique key' );
        $this->assertNotSame( $players[0], $players[1] );

        global $wpdb;
        $accounts = $wpdb->get_col( "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id IN (" . (int) $players[0] . ',' . (int) $players[1] . ')' );
        $this->assertSame( [ null, null ], $accounts, 'a promoted player has no account yet' );
    }

    public function test_a_fresh_install_seeds_the_default_rating_scale_and_no_colours(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        // An empty install: the seed runs only when there are no lookups.
        $wpdb->query( "DELETE FROM {$p}tt_lookups" );
        $wpdb->query( "DELETE FROM {$p}tt_config WHERE club_id = 1 AND config_key IN ( 'rating_min', 'rating_max', 'rating_step', 'primary_color', 'secondary_color' )" );

        $seed = new \ReflectionMethod( Activator::class, 'seedDefaultsIfEmpty' );
        $seed->setAccessible( true );
        $seed->invoke( null );

        $config = $wpdb->get_results( "SELECT config_key, config_value FROM {$p}tt_config WHERE club_id = 1 AND config_key IN ( 'rating_min', 'rating_max', 'rating_step', 'primary_color', 'secondary_color' )", OBJECT_K );

        $this->assertSame( RatingScale::DEFAULT_MIN, $config['rating_min']->config_value ?? null );
        $this->assertSame( RatingScale::DEFAULT_MAX, $config['rating_max']->config_value ?? null );
        $this->assertSame( RatingScale::DEFAULT_STEP, $config['rating_step']->config_value ?? null );
        $this->assertArrayNotHasKey( 'primary_color', $config, 'a missing colour row is the theme default' );
        $this->assertArrayNotHasKey( 'secondary_color', $config );
    }

    /** @return list<string> */
    private function schemaQueries(): array {
        $queries = new \ReflectionMethod( Activator::class, 'schemaQueries' );
        $queries->setAccessible( true );
        return (array) $queries->invoke( null );
    }

    private function queryFor( string $bare ): string {
        global $wpdb;
        $found = '';
        foreach ( $this->schemaQueries() as $sql ) {
            if ( $this->tableOf( $sql ) === $wpdb->prefix . $bare ) $found = $sql;
        }
        $this->assertNotSame( '', $found, "The install routine declares {$bare}." );
        return $found;
    }

    private function tableOf( string $sql ): string {
        return preg_match( '/CREATE TABLE\s+(\S+)\s*\(/', $sql, $m ) === 1 ? $m[1] : '';
    }

    /**
     * What dbDelta reports without running anything, less the type changes
     * `reapply()` judges.
     *
     * @return list<string>
     */
    private function dryRun(): array {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $out = [];
        foreach ( (array) dbDelta( $this->schemaQueries(), false ) as $message ) {
            $message = (string) $message;
            if ( strpos( $message, 'Changed type of ' ) === 0 ) continue;
            $out[] = $message;
        }
        return $out;
    }

    /**
     * Runs one CREATE for real on a temporary copy of its table and reports
     * how the copy changed. Null when the table does not exist; the dry run
     * reports that one. The copy stays until `dropProbe()`.
     *
     * @return array{drift: list<string>}|null
     */
    private function reapply( string $sql ): ?array {
        global $wpdb;
        $table = $this->tableOf( $sql );
        if ( $table === '' ) return null;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) return null;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $probe = $wpdb->prefix . self::PROBE;
        $this->dropProbe();
        $wpdb->query( "CREATE TEMPORARY TABLE `{$probe}` LIKE `{$table}`" );
        $before = $this->createOf( $probe );

        $wpdb->suppress_errors( true );
        dbDelta( (string) preg_replace( '/CREATE TABLE\s+' . preg_quote( $table, '/' ) . '\s*\(/', 'CREATE TABLE ' . $probe . ' (', $sql, 1 ) );
        $wpdb->suppress_errors( false );

        $after = $this->createOf( $probe );
        $drift = [];
        foreach ( array_diff( $before, $after ) as $line ) $drift[] = "{$table}: migrations  {$line}";
        foreach ( array_diff( $after, $before ) as $line ) $drift[] = "{$table}: install     {$line}";
        return [ 'drift' => $drift ];
    }

    private function dropProbe(): void {
        global $wpdb;
        $wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS `' . $wpdb->prefix . self::PROBE . '`' );
    }

    /** @return list<string> the table's definition lines, trailing commas and the counter dropped */
    private function createOf( string $table ): array {
        global $wpdb;
        $row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        $sql = is_array( $row ) ? (string) ( $row[1] ?? '' ) : '';
        $sql = (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', $sql );
        return array_values( array_map( static fn( string $l ): string => rtrim( trim( $l ), ',' ), explode( "\n", $sql ) ) );
    }

    private function prospect( string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'          => (int) CurrentClub::id(),
            'first_name'       => $first,
            'last_name'        => 'Herstart',
            'current_club'     => 'VV Voorbeeld',
            'consent_given_at' => '2026-09-01 00:00:00',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a prospect' );
        return $id;
    }
}
