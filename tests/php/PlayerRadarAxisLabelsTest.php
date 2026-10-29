<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerRadarQuery;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * #4300 — the player radar names its axes the way the evaluation form names
 * the categories: the academy's own translation when there is one, the
 * catalogue's for a seeded name, the stored name otherwise. All three modes
 * and the REST dataset share the labels.
 */
final class PlayerRadarAxisLabelsTest extends WP_UnitTestCase {

    private int $player = 0;
    private int $team = 0;

    /** @var array<int, object> */
    private array $categories = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Radar O12-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Ravi', 'last_name' => 'Radar', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->categories = QueryHelpers::get_categories();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_axes_use_the_academys_translation_in_every_mode(): void {
        $this->assertNotEmpty( $this->categories, 'precondition: main categories are seeded' );
        $first = (array) $this->categories[0];
        $id    = (int) ( $first['id'] ?? 0 );

        $saved = ( new TranslationsRepository() )->upsert(
            TranslatableFieldRegistry::ENTITY_EVAL_CATEGORY,
            $id,
            'label',
            (string) determine_locale(),
            'Balvaardigheid',
            get_current_user_id()
        );
        $this->assertTrue( $saved, 'precondition: the translation is stored' );

        foreach ( $this->allModes() as $mode => $labels ) {
            $this->assertSame( 'Balvaardigheid', $labels[0] ?? null, $mode );
        }
    }

    public function test_the_axes_use_the_catalogue_for_a_seeded_name(): void {
        $filter = static function ( $translation, $text, $domain ) {
            return $domain === 'talenttrack' ? 'NL ' . $text : $translation;
        };
        add_filter( 'gettext', $filter, 10, 3 );
        try {
            $expected = array_map(
                static fn( $c ): string => 'NL ' . (string) ( ( (array) $c )['name'] ?? '' ),
                $this->categories
            );
            foreach ( $this->allModes() as $mode => $labels ) {
                $this->assertSame( $expected, $labels, $mode );
            }
        } finally {
            remove_filter( 'gettext', $filter, 10 );
        }
    }

    public function test_without_a_translation_the_stored_names_are_unchanged(): void {
        $expected = array_map(
            static fn( $c ): string => (string) ( ( (array) $c )['name'] ?? '' ),
            $this->categories
        );
        foreach ( $this->allModes() as $mode => $labels ) {
            $this->assertSame( $expected, $labels, $mode );
        }
    }

    /**
     * The axis labels from each radar mode and from the REST dataset.
     *
     * @return array<string, list<string>>
     */
    private function allModes(): array {
        $query = new PlayerRadarQuery();
        $out   = [
            'progress'   => array_values( $query->progressForPlayer( $this->player )['labels'] ),
            'comparison' => array_values( $query->comparison( [ $this->player ] )['labels'] ),
            'team'       => array_values( $query->teamAverages( [ $this->team ] )['labels'] ),
        ];

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/player-radar' );
        $request->set_param( 'mode', 'comparison' );
        $request->set_param( 'player_ids', (string) $this->player );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        $body = (array) ( $data['data'] ?? $data );
        $out['rest'] = array_values( array_map( 'strval', (array) ( $body['labels'] ?? [] ) ) );

        return $out;
    }
}
