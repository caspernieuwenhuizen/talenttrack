<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3696 — the comms log is stamped from the clock the product reasons in.
 *
 * `tt_comms_log.created_at` was left to the column's
 * `DEFAULT CURRENT_TIMESTAMP`, which is the **database server's** local
 * time, while every time decision in Comms uses `wp_timezone()`. On an
 * install where the two differ the log showed a time the decision was not
 * made at.
 *
 * Measured on the pilot install: MariaDB `NOW()` read 23:26 (Europe/Berlin)
 * where WordPress read 21:26 (UTC). A `trial_input_reminder` correctly held
 * for quiet hours at 06:10 site time was logged at 08:10 — outside the
 * 21:00–07:00 window it had just been deferred by, which reads as a broken
 * policy rather than a correct one.
 *
 * The CI database and WordPress may well share a zone, in which case a
 * value-only assertion would pass for the wrong reason. So this asserts the
 * *source* of the value as well: the logger must stamp it, not leave it to
 * the column.
 */
final class CommsLogClockTest extends WP_UnitTestCase {

    private function read( string $rel ): string {
        $path = dirname( __DIR__, 2 ) . '/' . $rel;
        $this->assertFileExists( $path, "Surface moved or was renamed: {$rel}" );
        return (string) file_get_contents( $path );
    }

    public function test_the_logger_stamps_created_at_itself(): void {
        $source = $this->read( 'src/Modules/Comms/CommsAuditLogger.php' );

        $this->assertMatchesRegularExpression(
            "/'created_at'\s*=>\s*current_time\(\s*'mysql',\s*true\s*\)/",
            $source,
            'created_at must be written from PHP in UTC. Left to the column default it '
            . "comes from the database server's clock, which is not the clock quiet hours use."
        );
    }

    /**
     * `current_time( 'mysql', true )` is the UTC form. The non-UTC variant
     * would reintroduce the same class of disagreement from the other side,
     * so pin the `true`.
     */
    public function test_the_stamp_is_the_utc_variant(): void {
        $source = $this->read( 'src/Modules/Comms/CommsAuditLogger.php' );

        $this->assertStringNotContainsString(
            "'created_at'          => current_time( 'mysql' )",
            $source,
            'the site-local variant would disagree with the UTC column the rest of the row assumes'
        );
    }

    /** The column keeps its default for any writer that does not stamp. */
    public function test_the_column_still_has_its_default(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->markTestSkipped( 'tt_comms_log is not installed in this fixture.' );
        }

        $row = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'created_at'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertNotNull( $row, 'created_at must still exist' );
    }

    /** The reader is told which rows may carry the other clock. */
    public function test_the_log_view_explains_older_rows(): void {
        $source = $this->read( 'src/Modules/Comms/Frontend/FrontendMessageLogView.php' );

        $this->assertStringContainsString(
            'database server',
            $source,
            'the log must say that older rows may carry the database clock, so a reader '
            . 'does not conclude the quiet-hours policy is broken'
        );
    }
}
