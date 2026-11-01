<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Frontend\FrontendMinutesTeamReportView;
use TT\Shared\Frontend\Components\MinutesBreakdown;

/**
 * #4536 — the minutes reports showed the match type in English on a Dutch
 * install: the per-match rows printed the stored key ("Friendly",
 * "unknown"), and any type beyond the fixed three became a raw column
 * header ("UNKNOWN"). Every label now comes from the `game_subtype` lookup,
 * and a match with no type reads "No type".
 *
 * The lookup rows are inserted in `set_up()`, not in a test body:
 * `LookupTranslator` memoises each lookup type for the whole process (see
 * `LookupTranslatorCaseFallbackTest`), so every test has to see the same
 * set whichever runs first.
 */
final class MinutesMatchTypeLabelTest extends WP_UnitTestCase {

    /** @var callable */
    private $gettext;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        foreach ( [ 'League' => 1, 'Cup' => 2, 'Friendly' => 3, 'Zaalcompetitie' => 4 ] as $name => $order ) {
            $wpdb->insert( $wpdb->prefix . 'tt_lookups', [
                'club_id'     => (int) CurrentClub::id(),
                'lookup_type' => 'game_subtype',
                'name'        => $name,
                'sort_order'  => $order,
            ] );
        }

        // Stand in for the Dutch catalogue for the one new msgid.
        $this->gettext = static function ( $translation, $text, $context, $domain ) {
            if ( $domain === 'talenttrack' && $context === 'match type' && $text === 'No type' ) return 'Geen type';
            return $translation;
        };
        add_filter( 'gettext_with_context', $this->gettext, 10, 4 );
    }

    public function tear_down(): void {
        remove_filter( 'gettext_with_context', $this->gettext, 10 );
        parent::tear_down();
    }

    public function test_a_match_with_no_type_reads_no_type_not_the_bucket_key(): void {
        $this->assertSame( 'Geen type', MinutesBreakdown::typeLabel( 'unknown' ) );
        $this->assertSame( 'Geen type', MinutesBreakdown::typeLabel( '' ) );
    }

    public function test_a_type_is_labelled_through_the_game_subtype_lookup(): void {
        foreach ( [ 'League', 'Cup', 'Friendly', 'Zaalcompetitie' ] as $key ) {
            $this->assertSame( LookupTranslator::byTypeAndName( 'game_subtype', $key ), MinutesBreakdown::typeLabel( $key ) );
        }
    }

    public function test_the_per_match_rows_print_the_label_not_the_stored_key(): void {
        $html = MinutesBreakdown::html( [
            [ 'activity_id' => 1, 'session_date' => '2026-08-22', 'title' => 'Toernooi', 'type_key' => 'unknown', 'minutes' => 30, 'record_type' => 'actual' ],
            [ 'activity_id' => 2, 'session_date' => '2026-08-29', 'title' => 'Competitie', 'type_key' => 'League', 'minutes' => 70, 'record_type' => 'actual' ],
        ] );

        $this->assertStringContainsString( '<td>Geen type</td>', $html );
        $this->assertStringNotContainsString( '<td>unknown</td>', $html );
        $this->assertStringContainsString( '<td>' . esc_html( LookupTranslator::byTypeAndName( 'game_subtype', 'League' ) ) . '</td>', $html );
    }

    public function test_the_team_report_columns_label_every_key_and_keep_it(): void {
        $labels = $this->typeLabels( [
            [ 'by_type' => [ 'League' => 70, 'unknown' => 30, 'Zaalcompetitie' => 40 ] ],
        ] );

        $this->assertSame( [ 'League', 'Cup', 'Friendly', 'unknown', 'Zaalcompetitie' ], array_keys( $labels ), 'the stored keys stay the keys: the filter URL and the cells read them' );
        $this->assertSame( 'Geen type', $labels['unknown'], 'no raw "UNKNOWN" column' );
        $this->assertSame( LookupTranslator::byTypeAndName( 'game_subtype', 'Zaalcompetitie' ), $labels['Zaalcompetitie'], 'a club-added type through the lookup, not its raw key' );
        $this->assertSame( LookupTranslator::byTypeAndName( 'game_subtype', 'Friendly' ), $labels['Friendly'] );
    }

    public function test_the_filter_offers_the_three_types_by_their_stored_key(): void {
        $this->assertSame( [ 'League', 'Cup', 'Friendly' ], array_keys( $this->typeLabels( [] ) ) );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    private function typeLabels( array $rows ): array {
        $m = new \ReflectionMethod( FrontendMinutesTeamReportView::class, 'typeLabels' );
        $m->setAccessible( true );
        return (array) $m->invoke( null, $rows );
    }
}
