<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Spond\SpondAccount;
use TT\Modules\Spond\SpondClient;

/**
 * #4279 — the event fetch's paging loop is bounded by a range instead of a counter check (its first check
 * was always true). These pin what it does: a short page ends it after one
 * request, and full pages stop at the 20-page safety cap.
 */
final class SpondFetchPagingTest extends WP_UnitTestCase {

    private int $requests = 0;

    private int $page_rows = 0;

    public function set_up(): void {
        parent::set_up();
        $this->requests = 0;
        add_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10, 3 );
    }

    public function tear_down(): void {
        remove_filter( 'pre_http_request', [ $this, 'fakeSpond' ], 10 );
        parent::tear_down();
    }

    /**
     * @param false|array<string,mixed>|\WP_Error $pre
     * @param array<string,mixed>                $args
     * @return array<string,mixed>
     */
    public function fakeSpond( $pre, $args, string $url ): array {
        $page = $this->requests++;
        $rows = [];
        for ( $i = 0; $i < $this->page_rows; $i++ ) {
            $n      = $page * 1000 + $i;
            $rows[] = [
                'id'             => 'evt-' . $n,
                'startTimestamp' => gmdate( 'Y-m-d\TH:i:s.000\Z', 1790000000 + $n * 60 ),
            ];
        }
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode( $rows ),
            'response' => [ 'code' => 200, 'message' => 'OK' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function account(): SpondAccount {
        return new class implements SpondAccount {
            public function hasCredentials(): bool { return true; }
            public function getEmail(): string { return 'coach@example.test'; }
            public function getPassword(): string { return 'secret'; }
            public function getCachedToken(): string { return 'test-token'; }
            public function cacheToken( string $token, int $ttl_seconds ): void {}
            public function clearToken(): void {}
            public function isTeamOverride(): bool { return false; }
        };
    }

    public function test_a_short_page_ends_the_fetch_after_one_request(): void {
        $this->page_rows = 3;

        $result = SpondClient::fetchEvents( 'group-1', $this->account() );

        $this->assertTrue( $result['ok'] );
        $this->assertCount( 3, $result['events'] );
        $this->assertSame( 1, $this->requests );
    }

    public function test_full_pages_stop_at_the_safety_cap(): void {
        $this->page_rows = 250;

        $result = SpondClient::fetchEvents( 'group-1', $this->account() );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 20, $this->requests );
        $this->assertCount( 20 * 250, $result['events'] );
    }
}
