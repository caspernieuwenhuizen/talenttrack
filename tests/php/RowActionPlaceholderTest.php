<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * #4250 — a row-action template keeps working after `tt_back` is appended.
 *
 * The Prospects overview builds its "Edit contact" href with
 * `add_query_arg( [ … 'id' => '{id}' ] )` and then `BackLink::appendTo()`,
 * which calls `add_query_arg()` again. The second call re-encodes the
 * query string, so `{id}` reaches the list-table JS as `%7Bid%7D`. The JS
 * substituted only the literal form, every link carried the placeholder,
 * and the edit page said "Prospect not found".
 *
 * The JS now matches both forms. This test reads the pattern out of the
 * shipped script, so the PHP side and the browser are asked the same
 * question, and applies it to the href the view builds.
 */
final class RowActionPlaceholderTest extends WP_UnitTestCase {

    private const SCRIPT = 'assets/js/components/frontend-list-table.js';

    public function tear_down(): void {
        unset( $_SERVER['REQUEST_URI'] );
        parent::tear_down();
    }

    /** The pattern the list-table JS ships, as a PHP regex. */
    private function shippedPattern(): string {
        $js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . self::SCRIPT );
        $this->assertSame(
            1,
            preg_match( '~function placeholderPattern\(\)\s*\{\s*return\s*/(.+?)/gi;~', $js, $m ),
            'the list-table script declares one placeholder pattern'
        );
        return '~' . $m[1] . '~i';
    }

    private function substitute( string $href, array $row ): string {
        return (string) preg_replace_callback(
            $this->shippedPattern(),
            static fn ( array $m ): string => isset( $row[ $m[1] ] ) ? (string) $row[ $m[1] ] : '',
            $href
        );
    }

    public function test_the_edit_contact_link_resolves_to_the_row_id_with_tt_back_kept(): void {
        $_SERVER['REQUEST_URI'] = '/academie/?tt_view=prospects-overview';

        $href = BackLink::appendTo(
            add_query_arg( [ 'tt_view' => 'prospect-edit', 'id' => '{id}' ], RecordLink::dashboardUrl() )
        );

        $this->assertStringContainsString( '%7Bid%7D', $href, 'the double add_query_arg encodes the placeholder; this is the case being fixed' );

        $resolved = $this->substitute( $href, [ 'id' => 451 ] );
        $query    = [];
        parse_str( (string) wp_parse_url( $resolved, PHP_URL_QUERY ), $query );

        $this->assertSame( '451', $query['id'] ?? null );
        $this->assertSame( 'prospect-edit', $query['tt_view'] ?? null );
        $this->assertStringContainsString( 'prospects-overview', (string) ( $query['tt_back'] ?? '' ), 'tt_back survives the substitution' );
    }

    public function test_a_literal_placeholder_still_resolves(): void {
        $href = add_query_arg( [ 'tt_view' => 'tournaments', 'id' => '{id}' ], RecordLink::dashboardUrl() );

        $this->assertStringContainsString( '{id}', $href );
        $this->assertStringContainsString( 'id=77', $this->substitute( $href, [ 'id' => 77 ] ) );
    }
}
