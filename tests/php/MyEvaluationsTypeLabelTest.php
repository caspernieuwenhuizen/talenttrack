<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\PlayerEvaluationsReader;
use TT\Infrastructure\REST\PlayerEvaluationsRestController;
use TT\Modules\I18n\TranslationsRepository;

/**
 * #3681 — "My evaluations" printed the raw `tt_lookups.name`, so a Dutch
 * install showed a card labelled "Match" under a page that says *Wedstrijd*
 * everywhere else, and `GET /players/{id}/evaluations` handed the same
 * untranslated word to any other front end.
 *
 * The coach branch of the very same view has translated its type since #806,
 * by pre-localising at the repository boundary. This pins the player and
 * guardian branch onto that pattern: the reader hydrates
 * `type_name_localised`, the REST route exposes it as `type_localised`, and
 * the canonical `type` is untouched for consumers that group on it.
 */
final class MyEvaluationsTypeLabelTest extends WP_UnitTestCase {

    private int $player_id = 0;
    private int $match_type = 0;
    private int $with_type = 0;
    private int $without_type = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_seasons", [
            'club_id'    => 1,
            'name'       => '2090/2091',
            'start_date' => '2090-08-01',
            'end_date'   => '2091-06-30',
            'is_current' => 1,
        ] );

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => 1, 'first_name' => 'Eval', 'last_name' => 'Label', 'status' => 'active', 'wp_user_id' => null,
        ] );
        $this->player_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_lookups", [
            'club_id'     => 1,
            'lookup_type' => 'eval_type',
            'name'        => 'Match',
        ] );
        $this->match_type = (int) $wpdb->insert_id;

        // The operator-maintained label, in whatever locale the suite runs in.
        ( new TranslationsRepository() )->upsert(
            'lookup',
            $this->match_type,
            'name',
            $this->locale(),
            'Wedstrijd',
            1
        );

        $this->with_type    = $this->evaluation( $this->match_type );
        $this->without_type = $this->evaluation( 0 );
    }

    private function locale(): string {
        return function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en_US';
    }

    private function evaluation( int $type_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'      => 1,
            'player_id'    => $this->player_id,
            'coach_id'     => 1,
            'eval_date'    => '2090-09-10',
            'eval_type_id' => $type_id,
            'opponent'     => 'FC Groningen',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return array<int, object> rows keyed by evaluation id */
    private function rows(): array {
        $out = [];
        foreach ( ( new PlayerEvaluationsReader() )->listForPlayer( $this->player_id ) as $row ) {
            $out[ (int) $row->id ] = $row;
        }
        return $out;
    }

    public function test_the_reader_hydrates_the_localised_type(): void {
        $row = $this->rows()[ $this->with_type ] ?? null;

        $this->assertNotNull( $row );
        $this->assertSame( 'Wedstrijd', (string) $row->type_name_localised );
        $this->assertSame( 'Match', (string) $row->type_name, 'the canonical lookup value stays available' );
    }

    /** A row with no type lookup has nothing to localise, and says so. */
    public function test_a_row_without_a_type_localises_to_nothing(): void {
        $row = $this->rows()[ $this->without_type ] ?? null;

        $this->assertNotNull( $row );
        $this->assertSame( '', (string) $row->type_name_localised );
    }

    public function test_the_rest_route_returns_the_localised_type(): void {
        $items = $this->items();

        $this->assertSame( 'Wedstrijd', $items[ $this->with_type ]['type_localised'] );
        $this->assertSame( 'Match', $items[ $this->with_type ]['type'], '`type` is the v1 contract and must not move' );
    }

    /** With no lookup behind it, the localised field falls back to the raw one. */
    public function test_the_rest_route_falls_back_to_the_raw_type(): void {
        $items = $this->items();

        $this->assertSame(
            $items[ $this->without_type ]['type'],
            $items[ $this->without_type ]['type_localised'],
            'a front end printing type_localised must never print an empty cell where type had a value'
        );
    }

    /** @return array<int, array<string, mixed>> response items keyed by evaluation id */
    private function items(): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player_id . '/evaluations' );
        $request->set_url_params( [ 'id' => $this->player_id ] );
        $request->set_param( 'scope', PlayerEvaluationsReader::SCOPE_CURRENT );

        $data = PlayerEvaluationsRestController::list_for_player( $request )->get_data();
        $this->assertIsArray( $data );

        $out = [];
        foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
            $out[ (int) $item['id'] ] = $item;
        }
        return $out;
    }
}
