<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Search\SearchQuery;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * #4673 — parents and players on the shared search engine.
 *
 * The academy is SearchFixtures': José (the parent's child) and Jesse Boot
 * (the player's own record) on team A, Daan den Besten also on team A, Bram
 * Farplayer on team B. Each of the four players gets an evaluation, a goal
 * carrying the same title, and the parent and another user each get a
 * message with the same subject, so every refusal is a match the engine
 * found and had to leave out.
 */
final class FamilySearchTest extends WP_UnitTestCase {

    use SearchFixtures;

    private int $eval_type = 0;

    /** @var array<string,int> player name => evaluation id */
    private array $evaluations = [];

    /** @var array<string,int> player name => goal id */
    private array $goals = [];

    private int $own_message   = 0;
    private int $other_message = 0;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_lookups", [ 'club_id' => $club, 'lookup_type' => 'eval_type', 'name' => 'Match evaluation', 'sort_order' => 1 ] );
        $this->eval_type = (int) $wpdb->insert_id;
        TranslatableFieldRegistry::register( TranslatableFieldRegistry::ENTITY_LOOKUP, [ 'name', 'description', 'abbreviation' ] );
        ( new TranslationsRepository() )->upsert( TranslatableFieldRegistry::ENTITY_LOOKUP, $this->eval_type, 'name', 'nl_NL', 'Wedstrijdevaluatie', 1 );

        foreach ( [ 'jose', 'boot', 'besten', 'far' ] as $name ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => $club, 'player_id' => $this->players[ $name ], 'coach_id' => 1,
                'eval_type_id' => $this->eval_type, 'eval_date' => '2020-03-14', 'opponent' => 'Kustboys',
            ] );
            $this->evaluations[ $name ] = (int) $wpdb->insert_id;
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $club, 'player_id' => $this->players[ $name ], 'title' => 'Sneller schakelen',
                'created_by' => 1, 'status' => 'pending', 'due_date' => '2020-04-01',
            ] );
            $this->goals[ $name ] = (int) $wpdb->insert_id;
        }

        $this->own_message   = $this->message( $this->personas['parent'], 'Training verplaatst' );
        $this->other_message = $this->message( (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] ), 'Training verplaatst' );
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_a_parent_finds_their_own_childs_records_and_nothing_of_another_family(): void {
        $parent = $this->personas['parent'];
        $before = $this->droppedWarnings();

        $evals = $this->paged( $parent, 'match evaluation' );
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $evals, 'evaluation' ) );

        $goals = $this->paged( $parent, 'sneller' );
        $this->assertSame( [ $this->goals['jose'] ], $this->idsOf( $goals, 'goal' ), 'the child on the same team is not theirs' );

        $messages = $this->paged( $parent, 'verplaatst' );
        $this->assertSame( [ $this->own_message ], $this->idsOf( $messages, 'message' ) );

        $activities = $this->paged( $parent, 'training' );
        $this->assertContains( $this->activities['a'], $this->idsOf( $activities, 'activity' ) );
        $this->assertNotContains( $this->activities['b'], $this->idsOf( $activities, 'activity' ) );

        // Counts follow: nothing outside the family is counted.
        $this->assertSame( 1, (int) ( $goals['counts']['goal'] ?? -1 ) );
        $this->assertSame( 1, (int) ( $messages['counts']['message'] ?? -1 ) );
        $this->assertSame( $before, $this->droppedWarnings(), 'the other families were left out by the query, not by the confirm step' );
    }

    public function test_a_parent_of_two_sees_whose_evaluation_or_goal_it_is(): void {
        $parent = $this->personas['parent'];

        // One child: no name needed.
        $one = $this->paged( $parent, 'sneller' );
        $this->assertStringNotContainsString( 'José', (string) $one['items'][0]['sublabel'] );

        ( new \TT\Infrastructure\Players\ParentAccountService() )->linkToPlayer( $this->players['besten'], $parent );

        $sub = static function ( array $data, string $type ): array {
            $out = [];
            foreach ( (array) $data['items'] as $item ) {
                if ( $item['type'] === $type ) $out[ (int) $item['id'] ] = (string) $item['sublabel'];
            }
            return $out;
        };

        $goals = $sub( $this->paged( $parent, 'sneller' ), 'goal' );
        $this->assertCount( 2, $goals );
        $this->assertStringStartsWith( 'José', $goals[ $this->goals['jose'] ] );
        $this->assertStringStartsWith( 'Daan', $goals[ $this->goals['besten'] ] );

        $evals = $sub( $this->paged( $parent, 'match evaluation' ), 'evaluation' );
        $this->assertStringStartsWith( 'José', $evals[ $this->evaluations['jose'] ] );
        $this->assertStringStartsWith( 'Daan', $evals[ $this->evaluations['besten'] ] );
    }

    /** The teammate card follows the roster, like My Team. */
    public function test_the_teammate_card_follows_the_roster(): void {
        global $wpdb;
        $trialist = $this->player( $this->team_a, 'Tijn', 'Proefspeler', 21 );
        $released = $this->player( $this->team_a, 'Ruben', 'Vertrokken', 22 );
        $inactive = $this->player( $this->team_a, 'Iris', 'Inactief', 23 );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'trial' ], [ 'id' => $trialist ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'released' ], [ 'id' => $released ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'inactive' ], [ 'id' => $inactive ] );

        wp_set_current_user( $this->personas['player'] );
        $viewer = \TT\Infrastructure\Query\QueryHelpers::get_player_for_user( $this->personas['player'] );
        $this->assertNotNull( $viewer );

        $card = static function ( object $viewer, int $id ): string {
            ob_start();
            \TT\Shared\Frontend\FrontendTeammateView::render( $viewer, $id );
            return (string) ob_get_clean();
        };

        $this->assertStringNotContainsString( 'This player is not on your team.', $card( $viewer, $this->players['jose'] ), 'an active teammate opens' );
        foreach ( [ 'trialist' => $trialist, 'released' => $released, 'inactive' => $inactive ] as $what => $id ) {
            $this->assertStringContainsString( 'This player is not on your team.', $card( $viewer, $id ), "a {$what} carrying the team id is refused" );
        }
        $this->assertTrue( \TT\Infrastructure\Teams\TeamRoster::isMember( $this->players['jose'], $this->team_a ) );
        $this->assertFalse( \TT\Infrastructure\Teams\TeamRoster::isMember( $trialist, $this->team_a ) );
    }

    public function test_a_match_in_another_family_reads_like_no_match(): void {
        $parent  = $this->personas['parent'];
        $before  = $this->droppedWarnings();
        $refused = $this->paged( $parent, 'farplayer' );
        $nothing = $this->paged( $parent, 'zxqwvy' );
        $this->assertSame( $nothing['total'], $refused['total'] );
        $this->assertSame( $nothing['items'], $refused['items'] );
        $this->assertSame( $before, $this->droppedWarnings() );
    }

    public function test_a_parent_gets_no_quick_actions_and_no_settings(): void {
        $parent = $this->personas['parent'];
        foreach ( (array) $this->paged( $parent, 'jose' )['items'] as $item ) {
            $this->assertSame( [], $item['actions'], 'no quick action on ' . $item['type'] );
        }
        $this->assertSame( 0, (int) ( $this->paged( $parent, 'logo' )['counts']['setting'] ?? 0 ) );
    }

    public function test_a_player_finds_themselves_their_team_and_teammates_by_name_only(): void {
        $player = $this->personas['player'];

        $self = $this->paged( $player, 'boot' );
        $this->assertSame( [ $this->players['boot'] ], $this->idsOf( $self, 'player' ) );

        $mates = $this->paged( $player, 'jose' );
        $this->assertSame( [ $this->players['jose'] ], $this->idsOf( $mates, 'player' ), 'a teammate by name' );
        $mate = $mates['items'][0];
        $this->assertStringContainsString( 'tt_view=teammate', (string) $mate['url'] );
        $this->assertSame( '', (string) $mate['sublabel'] );
        $this->assertArrayNotHasKey( 'photo_url', $mate );
        $this->assertSame( [], $mate['actions'] );

        $before = $this->droppedWarnings();
        $this->assertSame( [], $this->idsOf( $this->paged( $player, 'farplayer' ), 'player' ), 'not another team' );

        $activities = $this->idsOf( $this->paged( $player, 'training' ), 'activity' );
        $this->assertContains( $this->activities['a'], $activities );
        $this->assertNotContains( $this->activities['b'], $activities );
        $this->assertSame( $before, $this->droppedWarnings() );
    }

    public function test_a_player_reads_only_their_own_evaluations_and_goals_and_no_settings(): void {
        $player = $this->personas['player'];
        $before = $this->droppedWarnings();
        $this->assertSame( [ $this->evaluations['boot'] ], $this->idsOf( $this->paged( $player, 'match evaluation' ), 'evaluation' ) );
        $this->assertSame( [ $this->goals['boot'] ], $this->idsOf( $this->paged( $player, 'sneller' ), 'goal' ) );
        $this->assertSame( [], $this->idsOf( $this->paged( $player, 'verplaatst' ), 'message' ) );
        $this->assertSame( 0, (int) ( $this->paged( $player, 'logo' )['counts']['setting'] ?? 0 ) );
        $this->assertSame( $before, $this->droppedWarnings() );
    }

    /**
     * #4936 — a coach without a child finds the goals of their own team as
     * staff (the staff scope of the same kind), never another team's, and
     * no messages.
     */
    public function test_a_coach_without_a_child_gets_their_teams_goals_as_staff_and_no_messages(): void {
        $coach  = $this->personas['coach'];
        $before = $this->droppedWarnings();
        $this->assertEqualsCanonicalizing(
            [ $this->goals['jose'], $this->goals['boot'], $this->goals['besten'] ],
            $this->idsOf( $this->paged( $coach, 'sneller' ), 'goal' )
        );
        $this->assertSame( [], $this->idsOf( $this->paged( $coach, 'verplaatst' ), 'message' ) );
        $this->assertSame( $before, $this->droppedWarnings() );
    }

    /**
     * A coach of team B whose own child is José on team A: staff for their
     * team, a parent for their child, and nothing of anyone else's child.
     */
    public function test_a_coach_who_is_a_parent_finds_their_own_childs_records_and_no_others(): void {
        $coach = $this->persona( 'tt_coach', 'head_coach', $this->team_b, 'head_coach' );
        // Straight into the guardian pivot: `ParentAccountService::linkToPlayer()`
        // refuses a staff account, but the link exists through other paths
        // (an import, a person row of type parent) and the rules must hold.
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => $this->players['jose'], 'parent_user_id' => $coach,
        ] );
        // Both roles, as such an account has: the guardian's read on the
        // child's development record comes with the parent role.
        ( new \WP_User( $coach ) )->add_role( 'tt_parent' );
        $this->resetSearchCaches();
        $before = $this->droppedWarnings();

        // #4936 — the child as family and the team they coach as staff, each
        // once: the two scopes of the kind do not overlap. Nobody else's.
        $this->assertEqualsCanonicalizing( [ $this->evaluations['jose'], $this->evaluations['far'] ], $this->idsOf( $this->paged( $coach, 'match evaluation' ), 'evaluation' ) );
        $goals = $this->paged( $coach, 'sneller' );
        $this->assertEqualsCanonicalizing( [ $this->goals['jose'], $this->goals['far'] ], $this->idsOf( $goals, 'goal' ) );
        $this->assertSame( 2, $goals['counts']['goal'] ?? null, 'counted once each' );
        $this->assertSame( [], $this->idsOf( $this->paged( $coach, 'verplaatst' ), 'message' ), 'another parent\'s inbox' );
        $this->assertSame( $before, $this->droppedWarnings() );

        // `GET /me/search` asks the family scope alone: their own child.
        $me = ( new \TT\Infrastructure\Search\ParentSearchService() )->search( $coach, 'sneller' );
        $this->assertSame( [ $this->goals['jose'] ], array_column( array_filter( $me['results'], static fn( array $r ): bool => $r['type'] === 'goal' ), 'id' ) );

        // `GET /me/search` stays about their own child: team A's activity,
        // not the team B activity they coach.
        $found  = ( new \TT\Infrastructure\Search\ParentSearchService() )->search( $coach, 'training' );
        $titles = array_column( array_filter( $found['results'], static fn( array $r ): bool => $r['type'] === 'activity' ), 'title' );
        $this->assertSame( [ 'Searchside training' ], array_values( $titles ) );
    }

    public function test_a_teammate_is_a_member_of_the_roster(): void {
        global $wpdb;
        $trialist = $this->player( $this->team_a, 'Tijn', 'Proefspeler', 21 );
        $released = $this->player( $this->team_a, 'Ruben', 'Vertrokken', 22 );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'trial' ], [ 'id' => $trialist ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'status' => 'released' ], [ 'id' => $released ] );
        $player = $this->personas['player'];
        $before = $this->droppedWarnings();

        $this->assertSame( [], $this->idsOf( $this->paged( $player, 'proefspeler' ), 'player' ), 'a trialist carrying the team id' );
        $this->assertSame( [], $this->idsOf( $this->paged( $player, 'vertrokken' ), 'player' ), 'an inactive player carrying the team id' );
        $this->assertSame( $before, $this->droppedWarnings(), 'left out by the query, not by the confirm step' );
    }

    public function test_dutch_evaluation_type_names_match(): void {
        $filter = static fn(): string => 'nl_NL';
        add_filter( 'locale', $filter );
        try {
            $data = $this->paged( $this->personas['parent'], 'wedstrijdevaluatie' );
        } finally {
            remove_filter( 'locale', $filter );
        }
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $data, 'evaluation' ) );
        $this->assertSame( 'Wedstrijdevaluatie', (string) $data['items'][0]['label'] );
    }

    public function test_a_date_finds_the_records_of_that_day(): void {
        $data = $this->paged( $this->personas['parent'], '2020-03-14' );
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $data, 'evaluation' ) );
        $this->assertSame( 'date', (string) $data['understood'][0]['pattern'] );
    }

    public function test_the_shared_query_reads_the_dates_a_parent_types(): void {
        $this->assertSame( [ '2026-11-03' ], ( new SearchQuery( '2026-11-03' ) )->dates );
        $this->assertSame( [ '2026-11-03' ], ( new SearchQuery( '03-11-2026' ) )->dates );
        $this->assertSame( [], ( new SearchQuery( '2026-02-31' ) )->dates, 'not a real day' );
        $this->assertCount( 1, ( new SearchQuery( '3 nov' ) )->dates );
        $this->assertSame( [], ( new SearchQuery( '3 nov' ) )->tokens, 'the day and the month are one pattern' );
        $this->assertSame( ( new SearchQuery( '3 nov' ) )->dates, ( new SearchQuery( '3 november' ) )->dates );
        $this->assertSame( [], ( new SearchQuery( 'training' ) )->dates );
        $this->assertSame( [ '3' ], ( new SearchQuery( '3' ) )->tokens, 'a lone number is text' );
    }

    /**
     * `GET /me/search` keeps its old shape, now from the engine.
     */
    public function test_me_search_keeps_its_old_shape(): void {
        wp_set_current_user( $this->personas['parent'] );
        $this->resetSearchCaches();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/me/search' );
        $request->set_query_params( [ 'q' => 'Sneller' ] );
        $response = rest_do_request( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = (array) ( $response->get_data()['data'] ?? [] );
        $this->assertSame( [ 'query', 'children', 'results', 'total' ], array_keys( $data ) );
        $this->assertSame( [ [ 'id' => $this->players['jose'], 'name' => 'José Pérez' ] ], $data['children'] );
        $this->assertSame( 1, $data['total'] );
        $hit = $data['results'][0];
        $this->assertSame( [ 'type', 'id', 'player_id', 'title', 'subtitle', 'date', 'url' ], array_keys( $hit ) );
        $this->assertSame( 'goal', $hit['type'] );
        $this->assertSame( $this->goals['jose'], $hit['id'] );
        $this->assertSame( $this->players['jose'], $hit['player_id'] );
        $this->assertSame( '2020-04-01', $hit['date'] );
        $this->assertStringContainsString( 'tt_view=my-goals', $hit['url'] );
    }

    public function test_the_results_page_serves_a_parent(): void {
        $parent = $this->personas['parent'];
        wp_set_current_user( $parent );
        $this->resetSearchCaches();
        $_GET['q'] = 'Sneller';
        try {
            ob_start();
            \TT\Shared\Frontend\FrontendSearchView::render( $parent );
            $html = (string) ob_get_clean();
        } finally {
            unset( $_GET['q'] );
        }
        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
        $this->assertSame( 1, substr_count( $html, 'tt-search-page__result ' ) + substr_count( $html, 'tt-search-page__result"' ) );
        $this->assertStringContainsString( 'player_id=' . $this->players['jose'], $html );
        $this->assertStringNotContainsString( 'tt-search-page__actions', $html );
    }

    public function test_a_parent_without_a_linked_child_is_told_so(): void {
        $lonely = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        wp_set_current_user( $lonely );
        $this->resetSearchCaches();
        $_GET['q'] = 'Training';
        try {
            ob_start();
            \TT\Shared\Frontend\FrontendSearchView::render( $lonely );
            $html = (string) ob_get_clean();
        } finally {
            unset( $_GET['q'] );
        }
        $this->assertStringContainsString( 'Ask the academy to link you.', $html );
    }

    public function test_the_retired_help_topic_opens_the_search_topic(): void {
        $this->assertSame( 'search', \TT\Modules\Documentation\HelpTopics::canonicalSlug( 'parent-search' ) );
        $this->assertSame( 'search', \TT\Modules\Documentation\HelpTopics::canonicalSlug( 'search' ) );
    }

    /**
     * How many `search.confirm_dropped` warnings have been logged. A refusal
     * the query made leaves it unchanged; one only the confirm step caught
     * logs one, so a test asserting no change fails if the SQL scope goes.
     */
    private function droppedWarnings(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_error_log WHERE message = %s",
            'search.confirm_dropped'
        ) );
    }

    private function message( int $user_id, string $subject ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_comms_inbox", [
            'club_id'           => (int) CurrentClub::id(),
            'uuid'              => wp_generate_uuid4(),
            'recipient_user_id' => $user_id,
            'template_key'      => 'training_cancelled',
            'message_type'      => 'training_cancelled',
            'subject'           => $subject,
            'body'              => 'Moved.',
        ] );
        return (int) $wpdb->insert_id;
    }
}
