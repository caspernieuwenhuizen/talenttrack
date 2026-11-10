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
 * `Activator::ensureSchema()` runs on activation and on "Retry migrations
 * now", after the migrations have shaped the tables, and its CREATEs go
 * through dbDelta. A CREATE that disagrees with a migration puts the old
 * shape back: `tt_players.wp_user_id` went back to `DEFAULT 0` after
 * migration 0170 made it nullable and unique per club.
 *
 * The suite's schema is the migrations' (the plugin is activated, then
 * migrated), so a re-activation here is the operator's re-activation on a
 * live install. When the CREATEs match, it changes nothing. When one does
 * not, dbDelta rewrites that table for the rest of the run, so a failure
 * here can show up in later tests too: fix this one first.
 */
final class ActivatorMatchesMigrationsTest extends WP_UnitTestCase {

    public function test_a_reactivation_changes_no_tt_table(): void {
        $before = $this->createStatements();
        $this->assertNotEmpty( $before, 'Precondition: the suite has tt_ tables.' );

        $this->reactivate();

        $after = $this->createStatements();
        $drift = [];
        foreach ( $before as $table => $sql ) {
            if ( ! isset( $after[ $table ] ) ) {
                $drift[] = "{$table}: gone after ensureSchema()";
                continue;
            }
            if ( $after[ $table ] === $sql ) continue;
            $was = explode( "\n", $sql );
            $now = explode( "\n", $after[ $table ] );
            foreach ( array_diff( $was, $now ) as $line ) $drift[] = "{$table}: migrations  " . trim( $line );
            foreach ( array_diff( $now, $was ) as $line ) $drift[] = "{$table}: install     " . trim( $line );
        }
        foreach ( array_diff_key( $after, $before ) as $table => $sql ) {
            $drift[] = "{$table}: created by ensureSchema() only";
        }

        $this->assertSame( [], $drift, "ensureSchema() changed what the migrations built:\n" . implode( "\n", $drift ) );
    }

    public function test_a_reactivation_keeps_one_account_per_player(): void {
        $this->reactivate();

        global $wpdb;
        $table  = $wpdb->prefix . 'tt_players';
        $column = $wpdb->get_row( $wpdb->prepare(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table, 'wp_user_id'
        ) );
        $this->assertNotNull( $column );
        $this->assertSame( 'YES', $column->IS_NULLABLE );
        // MySQL reports a NULL default as NULL, MariaDB as the string 'NULL'.
        $this->assertContains( $column->COLUMN_DEFAULT, [ null, 'NULL' ], 'no account is NULL, not 0' );

        $key = $wpdb->get_col( $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s AND NON_UNIQUE = 0
              ORDER BY SEQ_IN_INDEX',
            $table, 'uniq_club_user'
        ) );
        $this->assertSame( [ 'club_id', 'wp_user_id' ], $key );
    }

    public function test_two_promotions_in_one_club_both_succeed_after_a_reactivation(): void {
        $this->reactivate();

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

    /** The install routine's schema pass, as activation runs it. */
    private function reactivate(): void {
        global $wpdb;
        $schema = new \ReflectionMethod( Activator::class, 'ensureSchema' );
        $schema->setAccessible( true );
        $wpdb->suppress_errors( true );
        $schema->invoke( null );
        $wpdb->suppress_errors( false );
    }

    /** @return array<string,string> table => SHOW CREATE TABLE, without the counter */
    private function createStatements(): array {
        global $wpdb;
        $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'tt_' ) . '%' ) );
        sort( $tables );

        $out = [];
        foreach ( $tables as $table ) {
            $row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
            if ( ! is_array( $row ) || ! isset( $row[1] ) ) continue;
            $out[ (string) $table ] = (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', (string) $row[1] );
        }
        return $out;
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
