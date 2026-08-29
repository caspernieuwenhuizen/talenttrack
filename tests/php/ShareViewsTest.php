<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Sharing\ShareViewQuery;
use TT\Shared\Sharing\ShareViewRecorder;
use TT\Shared\Sharing\ShareViewRetentionCron;

/**
 * #3096 — counting the people who opened a share link.
 *
 * The promises worth proving are the ones a coach would notice being
 * broken:
 *
 *   - a second visit from the same browser does not invent a second reader;
 *   - a different browser does;
 *   - a link unfurler is not a reader, and neither is a HEAD request;
 *   - the 90-day purge takes the visitor handle and leaves the count, so
 *     the number on the surface never walks backwards;
 *   - nothing recognisable as an IP or a user-agent is in the table.
 */
final class ShareViewsTest extends WP_UnitTestCase {

    private const SUBJECT = ShareViewRecorder::SUBJECT_MATCH_ANALYSIS;

    public function set_up(): void {
        parent::set_up();

        $_SERVER['REQUEST_METHOD']  = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Safari/605';
        $_SERVER['REMOTE_ADDR']     = '198.51.100.7';
        $_COOKIE                    = [];
    }

    public function tear_down(): void {
        $_COOKIE = [];
        unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR'] );
        parent::tear_down();
    }

    /** Same browser, three opens: one reader, three opens. */
    public function test_repeat_visits_from_one_browser_count_once(): void {
        $recorder = new ShareViewRecorder();

        $this->withCookie( 'uuid-a', function () use ( $recorder ) {
            $recorder->record( self::SUBJECT, 11, 1, 'uuid-a' );
            $recorder->record( self::SUBJECT, 11, 1, 'uuid-a' );
            $recorder->record( self::SUBJECT, 11, 1, 'uuid-a' );
        } );

        $summary = ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 11 );

        $this->assertSame( 1, $summary['unique'] );
        $this->assertSame( 3, $summary['opens'] );
        $this->assertNotNull( $summary['last_seen_at'] );
    }

    /** A second browser is a second person. */
    public function test_a_second_browser_counts_as_a_second_person(): void {
        $recorder = new ShareViewRecorder();

        $this->withCookie( 'uuid-b', fn() => $recorder->record( self::SUBJECT, 12, 1, 'uuid-b' ) );
        $this->withCookie( 'uuid-b', fn() => $recorder->record( self::SUBJECT, 12, 1, 'uuid-b' ), 'second' );

        $this->assertSame( 2, ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 12 )['unique'] );
    }

    /** Two analyses are counted apart even for one browser. */
    public function test_subjects_are_counted_separately(): void {
        $recorder = new ShareViewRecorder();

        $this->withCookie( 'uuid-c', fn() => $recorder->record( self::SUBJECT, 13, 1, 'uuid-c' ) );
        $this->withCookie( 'uuid-d', fn() => $recorder->record( self::SUBJECT, 14, 1, 'uuid-d' ) );

        $q = new ShareViewQuery();
        $this->assertSame( 1, $q->summaryFor( self::SUBJECT, 13 )['unique'] );
        $this->assertSame( 1, $q->summaryFor( self::SUBJECT, 14 )['unique'] );
    }

    /**
     * A link pasted into a chat is fetched by the chat, not read by a
     * person. Counting it would report an audience that never existed.
     */
    public function test_unfurlers_and_head_requests_are_not_readers(): void {
        $recorder = new ShareViewRecorder();

        $_SERVER['HTTP_USER_AGENT'] = 'WhatsApp/2.23';
        $recorder->record( self::SUBJECT, 15, 1, 'uuid-e' );

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Safari/605';
        $_SERVER['REQUEST_METHOD']  = 'HEAD';
        $recorder->record( self::SUBJECT, 15, 1, 'uuid-e' );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_USER_AGENT'] = '';
        $recorder->record( self::SUBJECT, 15, 1, 'uuid-e' );

        $this->assertSame( 0, ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 15 )['unique'] );
    }

    /** An analysis nobody opened answers zero rather than failing. */
    public function test_unopened_subject_reports_nothing(): void {
        $summary = ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 999 );

        $this->assertSame( 0, $summary['unique'] );
        $this->assertNull( $summary['last_seen_at'] );
    }

    /**
     * The purge takes the handle and keeps the number. A count that dropped
     * on the ninety-first day would read as people un-reading the document.
     */
    public function test_purge_folds_the_count_and_drops_the_handle(): void {
        global $wpdb;
        $recorder = new ShareViewRecorder();

        $this->withCookie( 'uuid-f', fn() => $recorder->record( self::SUBJECT, 16, 1, 'uuid-f' ) );
        $this->withCookie( 'uuid-f', fn() => $recorder->record( self::SUBJECT, 16, 1, 'uuid-f' ), 'other' );

        $before = ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 16 );
        $this->assertSame( 2, $before['unique'] );

        // Age both rows past the window.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_share_views SET last_seen_at = %s WHERE subject_id = 16",
            gmdate( 'Y-m-d H:i:s', strtotime( '-200 days' ) )
        ) );

        $deleted = ( new ShareViewQuery() )->purgeOlderThan(
            ShareViewRetentionCron::RETENTION_DAYS,
            current_time( 'mysql' )
        );

        $this->assertSame( 2, $deleted );

        $rows = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_share_views WHERE subject_id = 16"
        );
        $this->assertSame( 0, $rows, 'the visitor handles are gone' );

        $after = ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 16 );
        $this->assertSame( 2, $after['unique'], 'the count survives the purge' );
        $this->assertNotNull( $after['last_seen_at'] );
    }

    /** A purge and a later visit add up rather than replacing each other. */
    public function test_archived_and_live_counts_are_added(): void {
        global $wpdb;
        $recorder = new ShareViewRecorder();

        $this->withCookie( 'uuid-g', fn() => $recorder->record( self::SUBJECT, 17, 1, 'uuid-g' ) );

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}tt_share_views SET last_seen_at = %s WHERE subject_id = 17",
            gmdate( 'Y-m-d H:i:s', strtotime( '-200 days' ) )
        ) );
        ( new ShareViewQuery() )->purgeOlderThan( 90, current_time( 'mysql' ) );

        $this->withCookie( 'uuid-g', fn() => $recorder->record( self::SUBJECT, 17, 1, 'uuid-g' ), 'returning' );

        $this->assertSame( 2, ( new ShareViewQuery() )->summaryFor( self::SUBJECT, 17 )['unique'] );
    }

    /** No raw IP, no raw user-agent, anywhere in the table. */
    public function test_no_identifying_data_is_stored(): void {
        global $wpdb;
        $recorder = new ShareViewRecorder();

        $_SERVER['REMOTE_ADDR']     = '203.0.113.44';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Firefox/128';

        $recorder->record( self::SUBJECT, 18, 1, 'uuid-h' );

        $dump = (string) wp_json_encode( $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}tt_share_views WHERE subject_id = 18",
            ARRAY_A
        ) );

        $this->assertStringNotContainsString( '203.0.113.44', $dump );
        $this->assertStringNotContainsString( 'Firefox', $dump );
        $this->assertStringNotContainsString( 'Macintosh', $dump );
    }

    /**
     * Stands in for the browser: `primeCookie()` cannot set a real one under
     * PHPUnit (headers are already sent), so the cookie it would have
     * written is placed on `$_COOKIE` directly. `$seed` distinguishes one
     * browser from another.
     */
    private function withCookie( string $scope_key, callable $fn, string $seed = 'first' ): void {
        $name = 'tt_sv_' . substr( hash( 'sha256', $scope_key ), 0, 16 );
        $keep = $_COOKIE;

        $_COOKIE[ $name ] = hash( 'sha256', $scope_key . '|' . $seed );
        try {
            $fn();
        } finally {
            $_COOKIE = $keep;
        }
    }
}
