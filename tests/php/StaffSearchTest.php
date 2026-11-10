<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Search\Providers\CompositeSearchProvider;
use TT\Infrastructure\Search\Providers\EvaluationSearchProvider;
use TT\Infrastructure\Search\Providers\StaffEvaluationSearchProvider;
use TT\Infrastructure\Search\SearchRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Threads\Adapters\PlayerThreadAdapter;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\ThreadTypeRegistry;

/**
 * #4936 — search phase 2, the staff half: evaluations and goals for the
 * staff who read them (a second scope of the `evaluation` and `goal` kinds),
 * player notes (`player_note`), the shirt number and position on player
 * rows, the full path on setting rows, and switched-off modules for whoever
 * may switch them on.
 *
 * The academy is SearchFixtures': José (the parent's child), Jesse Boot (the
 * player's own record) and Daan den Besten on team A, Bram Farplayer on
 * team B. Every player gets an evaluation whose staff notes carry the same
 * word, a goal with the same title and a note with the same text, and José a
 * staff-only note, so every refusal is a match the engine found and had to
 * leave out.
 */
final class StaffSearchTest extends WP_UnitTestCase {

    use SearchFixtures;

    private const NOTES_WORD      = 'zorgenkind';
    private const GOAL_WORD       = 'kopduelwinnen';
    private const NOTE_WORD       = 'enkelblessure';
    private const STAFF_ONLY_WORD = 'medicijngebruik';

    /** @var array<string,int> */
    private array $evaluations = [];

    /** @var array<string,int> */
    private array $goals = [];

    /** @var array<string,int> */
    private array $notes = [];

    private int $staff_only_note = 0;

    private int $observer = 0;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_lookups", [ 'club_id' => $club, 'lookup_type' => 'eval_type', 'name' => 'Match evaluation', 'sort_order' => 1 ] );
        $type = (int) $wpdb->insert_id;

        foreach ( [ 'jose', 'boot', 'besten', 'far' ] as $name ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => $club, 'player_id' => $this->players[ $name ], 'coach_id' => 1,
                'eval_type_id' => $type, 'eval_date' => '2020-03-14', 'opponent' => 'Kustboys',
                'notes' => 'Een ' . ucfirst( self::NOTES_WORD ) . ' thuis, let op de focus. Woord' . $name . '.',
            ] );
            $this->evaluations[ $name ] = (int) $wpdb->insert_id;
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $club, 'player_id' => $this->players[ $name ], 'title' => 'Kopduelwinnen in de zestien',
                'description' => 'Timing van de sprong', 'created_by' => 1, 'status' => 'pending', 'due_date' => '2020-04-01',
            ] );
            $this->goals[ $name ] = (int) $wpdb->insert_id;
            $this->notes[ $name ] = $this->note( $this->players[ $name ], 'Klaagt over een ' . self::NOTE_WORD . ' na de training.', 'public' );
        }
        $this->staff_only_note = $this->note( $this->players['jose'], 'Thuis gesprek over ' . self::STAFF_ONLY_WORD . '.', 'private_to_coach' );

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->resetSearchCaches();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_a_kind_served_by_two_scopes_is_one_composite(): void {
        $providers = SearchRegistry::providersOf( 'evaluation' );
        $this->assertInstanceOf( CompositeSearchProvider::class, SearchRegistry::get( 'evaluation' ) );
        $this->assertCount( 2, $providers );
        $this->assertInstanceOf( EvaluationSearchProvider::class, $providers[0], 'the family scope first' );
        $this->assertInstanceOf( StaffEvaluationSearchProvider::class, $providers[1] );
        foreach ( [ 'staff_evaluation', 'staff_goal', 'note' ] as $gone ) {
            $this->assertNull( SearchRegistry::get( $gone ), "{$gone} is not a kind" );
        }
    }

    public function test_a_coach_finds_their_own_players_records_and_no_others(): void {
        $coach  = $this->personas['coach'];
        $team_a = [ $this->players['jose'], $this->players['boot'], $this->players['besten'] ];

        $evals = $this->paged( $coach, 'match evaluation' );
        $this->assertEqualsCanonicalizing( $this->of( $this->evaluations, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $evals, 'evaluation' ) );
        $this->assertSame( 3, $evals['counts']['evaluation'] ?? null, 'the other team\'s evaluation is not counted' );

        $goals = $this->paged( $coach, self::GOAL_WORD );
        $this->assertEqualsCanonicalizing( $this->of( $this->goals, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $goals, 'goal' ) );
        $this->assertSame( 3, $goals['counts']['goal'] ?? null );

        $notes = $this->paged( $coach, self::NOTE_WORD );
        $this->assertEqualsCanonicalizing( $this->of( $this->notes, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $notes, 'player_note' ) );
        $this->assertSame( 3, $notes['counts']['player_note'] ?? null );

        foreach ( (array) $notes['items'] as $item ) {
            if ( $item['type'] !== 'player_note' ) continue;
            $this->assertContains( (int) $item['player_id'], $team_a );
            $this->assertStringContainsString( 'tab=notes', (string) $item['url'] );
            $this->assertStringContainsString( self::NOTE_WORD, (string) ( $item['excerpt'] ?? '' ) );
        }
    }

    public function test_the_head_of_development_finds_them_across_the_academy(): void {
        $hod = $this->personas['hod'];
        $this->assertEqualsCanonicalizing( array_values( $this->evaluations ), $this->idsOf( $this->paged( $hod, 'match evaluation' ), 'evaluation' ) );
        $this->assertEqualsCanonicalizing( array_values( $this->goals ), $this->idsOf( $this->paged( $hod, self::GOAL_WORD ), 'goal' ) );
        $this->assertEqualsCanonicalizing( array_values( $this->evaluations ), $this->idsOf( $this->paged( $hod, self::NOTES_WORD ), 'evaluation' ), 'by a word in the notes' );
    }

    public function test_the_notes_are_matched_and_shown_only_where_the_coach_reads_them(): void {
        $data = $this->paged( $this->personas['coach'], self::NOTES_WORD );
        $this->assertEqualsCanonicalizing( $this->of( $this->evaluations, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $data, 'evaluation' ) );
        foreach ( (array) $data['items'] as $item ) {
            if ( $item['type'] !== 'evaluation' ) continue;
            $this->assertStringContainsStringIgnoringCase( self::NOTES_WORD, (string) ( $item['excerpt'] ?? '' ) );
        }
    }

    /**
     * A coach who is also the guardian of a player on their own team, whose
     * evaluations that child has switched off. About that child the family
     * rules alone decide: nothing of the child's evaluations is listed or
     * counted, at any query, not even a word only in its notes.
     */
    public function test_a_coach_who_is_guardian_of_a_child_on_their_team_counts_nothing_the_child_switched_off(): void {
        global $wpdb;
        $coach  = $this->personas['coach'];
        $besten = $this->players['besten'];
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id' => (int) CurrentClub::id(), 'player_id' => $besten, 'parent_user_id' => $coach,
        ] );
        ( new \WP_User( $coach ) )->add_role( 'tt_parent' );
        ( new PlayerParentVisibilityRepository() )->setVisibility( $besten, 'evaluations', false );
        $this->resetSearchCaches();
        $this->assertFalse( AuthorizationService::mayReadPlayerSection( $coach, $besten, 'evaluations' ), 'precondition: the coach may not open the child\'s evaluations' );

        $all = $this->paged( $coach, 'match evaluation' );
        $this->assertNotContains( $this->evaluations['besten'], $this->idsOf( $all, 'evaluation' ) );
        $this->assertSame( 2, $all['counts']['evaluation'] ?? null, 'only José\'s and Jesse\'s are counted' );

        $own_word = $this->paged( $coach, 'woordbesten' );
        $this->assertSame( [], $this->idsOf( $own_word, 'evaluation' ) );
        $this->assertSame( 0, $own_word['counts']['evaluation'] ?? 0, 'a word only in the child\'s notes counts nothing' );

        $by_name = $this->paged( $coach, 'besten' );
        $this->assertSame( 0, $by_name['counts']['evaluation'] ?? 0, 'nor the child\'s name' );
    }

    public function test_the_observer_finds_an_evaluation_by_player_but_never_by_its_notes(): void {
        $observer = $this->observer;
        $jose     = $this->players['jose'];
        $this->assertTrue( AuthorizationService::canViewPlayer( $observer, $jose ), 'precondition: the observer reads the player' );
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $observer, $jose, 'evaluations' ), 'precondition: and the evaluations' );

        $by_name = $this->paged( $observer, 'jose' );
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $by_name, 'evaluation' ), 'found by the player\'s name' );
        foreach ( (array) $by_name['items'] as $item ) {
            if ( $item['type'] === 'evaluation' ) $this->assertArrayNotHasKey( 'excerpt', $item, 'no excerpt of the notes' );
        }

        $by_notes = $this->paged( $observer, self::NOTES_WORD );
        $this->assertSame( [], $this->idsOf( $by_notes, 'evaluation' ), 'not by a word only in the notes' );
        $this->assertSame( 0, $by_notes['counts']['evaluation'] ?? 0 );
        $this->assertStringNotContainsStringIgnoringCase( self::NOTES_WORD, (string) wp_json_encode( $by_notes ) );
    }

    public function test_the_observer_gets_no_notes_at_all(): void {
        foreach ( [ 'jose', self::NOTE_WORD, self::STAFF_ONLY_WORD ] as $q ) {
            $data = $this->paged( $this->observer, $q );
            $this->assertSame( [], $this->idsOf( $data, 'player_note' ), $q );
            $this->assertSame( 0, $data['counts']['player_note'] ?? 0, $q );
        }

        $html = $this->page( $this->observer, self::NOTE_WORD );
        $this->assertStringNotContainsString( '>Notes<', $html, 'no Notes group on the results page' );
    }

    public function test_a_scout_finds_their_linked_player_only_and_not_by_the_notes(): void {
        $this->addCandidateSourcePersonas();
        $scout = $this->personas['scout'];
        $this->assertTrue( AuthorizationService::canViewPlayer( $scout, $this->players['jose'] ), 'precondition: the scout reads José' );
        $this->assertFalse( AuthorizationService::canViewPlayer( $scout, $this->players['far'] ), 'precondition: and not Bram' );

        $evals = $this->paged( $scout, 'match evaluation' );
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $evals, 'evaluation' ) );
        $this->assertSame( 1, $evals['counts']['evaluation'] ?? null );
        $by_notes = $this->paged( $scout, self::NOTES_WORD );
        $this->assertSame( [], $this->idsOf( $by_notes, 'evaluation' ), 'the notes are masked for a scout' );
        $this->assertSame( 0, $by_notes['counts']['evaluation'] ?? 0 );
        $this->assertSame( [ $this->goals['jose'] ], $this->idsOf( $this->paged( $scout, self::GOAL_WORD ), 'goal' ) );

        // The scout reads player notes academy-wide and thread messages
        // academy-wide (`ThreadAccess::hasGlobalAccess()`), so on the one
        // player they may view they read both José's notes.
        $this->assertTrue( ThreadAccess::canSeePrivate( 'player', $this->players['jose'], $scout ), 'precondition: the rule lets the scout see José\'s staff-only note' );
        $public = $this->paged( $scout, self::NOTE_WORD );
        $this->assertSame( [ $this->notes['jose'] ], $this->idsOf( $public, 'player_note' ) );
        $this->assertSame( 1, $public['counts']['player_note'] ?? null );
        $private = $this->paged( $scout, self::STAFF_ONLY_WORD );
        $this->assertSame( [ $this->staff_only_note ], $this->idsOf( $private, 'player_note' ) );
        $this->assertSame( 1, $private['counts']['player_note'] ?? null );
    }

    public function test_a_staff_only_note_is_found_only_by_who_may_see_it(): void {
        global $wpdb;
        $matrix = "{$wpdb->prefix}tt_authorization_matrix";
        $jose   = $this->players['jose'];

        // The assistant keeps the notes read on their team, and holds neither
        // the staff-only right nor `tt_edit_evaluations`.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$matrix} WHERE persona = %s AND entity IN ( %s, %s )", 'assistant_coach', ThreadAccess::STAFF_ONLY_ENTITY, 'evaluations' ) );
        // The head coach keeps the staff-only right on their team, and no
        // longer holds `tt_edit_evaluations`.
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$matrix} WHERE persona = %s AND entity = %s", 'head_coach', 'evaluations' ) );
        $this->resetSearchCaches();

        $assistant = $this->personas['assistant'];
        $coach     = $this->personas['coach'];
        $adapter   = ThreadTypeRegistry::get( 'player' );
        $this->assertInstanceOf( PlayerThreadAdapter::class, $adapter );
        $this->assertTrue( $adapter->canRead( $assistant, $jose ), 'precondition: the assistant reads José\'s notes' );
        $this->assertFalse( user_can( $assistant, 'tt_edit_evaluations' ), 'precondition: the assistant does not edit evaluations' );
        $this->assertFalse( ThreadAccess::canSeePrivate( 'player', $jose, $assistant ), 'precondition: nor see staff-only notes' );
        $this->assertFalse( user_can( $coach, 'tt_edit_evaluations' ), 'precondition: the head coach does not edit evaluations' );
        $this->assertTrue( ThreadAccess::canSeePrivate( 'player', $jose, $coach ), 'precondition: the head coach sees them through the staff-only right' );

        $this->assertSame( 3, $this->paged( $assistant, self::NOTE_WORD )['counts']['player_note'] ?? null, 'precondition: the assistant finds the public notes' );
        $refused = $this->paged( $assistant, self::STAFF_ONLY_WORD );
        $this->assertSame( [], $this->idsOf( $refused, 'player_note' ) );
        $this->assertSame( 0, $refused['counts']['player_note'] ?? 0, 'neither found nor counted' );

        $kept = $this->paged( $coach, self::STAFF_ONLY_WORD );
        $this->assertSame( [ $this->staff_only_note ], $this->idsOf( $kept, 'player_note' ) );
        $this->assertSame( 1, $kept['counts']['player_note'] ?? null );

        $hod = $this->paged( $this->personas['hod'], self::STAFF_ONLY_WORD );
        $this->assertSame( [ $this->staff_only_note ], $this->idsOf( $hod, 'player_note' ) );
        $this->assertSame( 1, $hod['counts']['player_note'] ?? null );
    }

    /**
     * One rule: the batched checks the note kind asks give the single
     * checks' answers, for every persona on every player.
     */
    public function test_the_batched_note_checks_agree_with_the_single_ones(): void {
        global $wpdb;
        $this->addCandidateSourcePersonas();
        $this->personas['observer'] = $this->observer;
        $adapter = ThreadTypeRegistry::get( 'player' );
        $this->assertInstanceOf( PlayerThreadAdapter::class, $adapter );

        $team_by_player = [];
        foreach ( $this->players as $pid ) {
            $team_by_player[ $pid ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d", $pid ) );
        }

        $checked = 0;
        foreach ( $this->personas as $persona => $uid ) {
            $this->resetSearchCaches();
            $many  = $adapter->canReadMany( $uid, $team_by_player );
            $reach = ThreadAccess::canSeePrivateOnTeams( $uid, array_values( array_unique( $team_by_player ) ) );
            foreach ( $team_by_player as $pid => $team ) {
                $read = $adapter->canRead( $uid, $pid );
                $this->assertSame( $read, $many[ $pid ], "{$persona} / player {$pid}: canReadMany() is canRead()" );
                $this->assertSame(
                    $read && ThreadAccess::canSeePrivate( 'player', $pid, $uid ),
                    $many[ $pid ] && ( $reach['all'] || ! empty( $reach['teams'][ $team ] ) ),
                    "{$persona} / player {$pid}: canSeePrivateOnTeams() with the read is canSeePrivate() with the read"
                );
                $checked++;
            }
        }
        $this->assertGreaterThan( 30, $checked );
    }

    public function test_parents_and_players_get_exactly_their_family_results(): void {
        $parent = $this->personas['parent'];
        $player = $this->personas['player'];
        $family = [
            'parent' => [ 'evaluation' => $this->evaluations['jose'], 'goal' => $this->goals['jose'] ],
            'player' => [ 'evaluation' => $this->evaluations['boot'], 'goal' => $this->goals['boot'] ],
        ];
        foreach ( [ 'parent' => $parent, 'player' => $player ] as $persona => $uid ) {
            $evals = $this->paged( $uid, 'match evaluation' );
            $this->assertSame( [ $family[ $persona ]['evaluation'] ], $this->idsOf( $evals, 'evaluation' ), $persona );
            $this->assertSame( 1, $evals['counts']['evaluation'] ?? null, $persona );

            $goals = $this->paged( $uid, self::GOAL_WORD );
            $this->assertSame( [ $family[ $persona ]['goal'] ], $this->idsOf( $goals, 'goal' ), $persona );
            $this->assertSame( 1, $goals['counts']['goal'] ?? null, $persona );

            $by_notes = $this->paged( $uid, self::NOTES_WORD );
            $this->assertSame( 0, $by_notes['counts']['evaluation'] ?? 0, "{$persona}: the staff notes are never searched for a family" );

            foreach ( [ self::NOTE_WORD, self::STAFF_ONLY_WORD, 'jose' ] as $q ) {
                $this->assertSame( 0, $this->paged( $uid, $q )['counts']['player_note'] ?? 0, "{$persona} / {$q}" );
            }
            foreach ( $evals['items'] as $item ) {
                if ( $item['type'] === 'evaluation' ) $this->assertStringContainsString( 'tt_view=my-evaluations', (string) $item['url'], "{$persona}: the family page" );
            }
        }
    }

    public function test_a_player_row_carries_the_shirt_number_and_the_primary_position(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'preferred_positions' => '["CM","RW"]' ], [ 'id' => $this->players['jose'] ] );
        $item = $this->item( $this->paged( $this->personas['coach'], 'jose', [ 'types' => 'player' ] ), 'player', $this->players['jose'] );

        $this->assertNotNull( $item );
        $this->assertSame( 7, $item['shirt_number'] ?? null );
        $this->assertSame( 'CM', $item['position'] ?? null );

        $boot = $this->item( $this->paged( $this->personas['coach'], 'boot', [ 'types' => 'player' ] ), 'player', $this->players['boot'] );
        $this->assertArrayNotHasKey( 'position', (array) $boot, 'no position when none is set' );

        $html = $this->page( $this->personas['coach'], 'jose' );
        $this->assertStringContainsString( '#7 CM', $html );
    }

    public function test_a_teammate_row_carries_no_shirt_number_or_position(): void {
        $item = $this->item( $this->paged( $this->personas['player'], 'besten', [ 'types' => 'player' ] ), 'player', $this->players['besten'] );
        $this->assertNotNull( $item, 'precondition: the player finds a teammate' );
        $this->assertArrayNotHasKey( 'shirt_number', $item );
        $this->assertArrayNotHasKey( 'position', $item );
    }

    public function test_a_setting_row_carries_its_full_path(): void {
        $items = array_values( array_filter(
            (array) $this->paged( $this->personas['admin'], 'appearance', [ 'types' => 'setting' ] )['items'],
            static fn( array $i ): bool => $i['label'] === 'Appearance'
        ) );
        $this->assertNotEmpty( $items, 'precondition: the admin finds the appearance settings' );
        $this->assertSame( 'Configuration › Academy › Appearance', $items[0]['path'] ?? null );
        $this->assertSame( $items[0]['path'], $items[0]['sublabel'], 'shown instead of the section' );
        $this->assertFalse( $items[0]['disabled'] ?? null );
    }

    public function test_an_admin_sees_a_switched_off_module_and_a_coach_does_not(): void {
        $module = 'TT\\Modules\\Push\\PushModule';
        $admin  = $this->personas['admin'];
        $this->assertTrue( user_can( $admin, 'tt_manage_modules' ), 'precondition: the admin may switch modules' );
        ModuleRegistry::setEnabled( $module, false );
        try {
            $this->resetSearchCaches();
            $off = array_values( array_filter(
                (array) $this->paged( $admin, 'push notifications', [ 'types' => 'setting' ] )['items'],
                static fn( array $i ): bool => ! empty( $i['disabled'] ) && $i['label'] === 'Push notifications'
            ) );
            $this->assertCount( 1, $off, 'the switched-off module is one result' );
            $this->assertStringEndsWith( '› Push notifications', (string) ( $off[0]['path'] ?? '' ) );
            $this->assertSame( [ 'turn_on' ], array_column( $off[0]['actions'], 'key' ) );
            $this->assertStringContainsString( 'tt_view=modules', (string) $off[0]['actions'][0]['url'] );
            $this->assertStringContainsString( '#tt-module-pushmodule', (string) $off[0]['actions'][0]['url'], 'opens the module\'s toggle' );

            $html = $this->page( $admin, 'push notifications' );
            $this->assertStringContainsString( 'tt-search-page__state', $html );

            foreach ( (array) $this->paged( $this->personas['coach'], 'push notifications', [ 'types' => 'setting' ] )['items'] as $item ) {
                $this->assertEmpty( $item['disabled'] ?? false, 'a coach never sees a switched-off module' );
            }
        } finally {
            ModuleRegistry::setEnabled( $module, true );
        }
    }

    public function test_the_evaluations_and_goals_group_holds_both_kinds(): void {
        $groups = [];
        foreach ( \TT\Shared\Frontend\FrontendSearchView::groups() as $group ) $groups[ $group['kind'] ] = $group['kinds'];
        $this->assertSame( [ 'evaluation', 'goal' ], $groups['evaluations_goals'] ?? null );
        $this->assertSame( [ 'player_note' ], $groups['player_note'] ?? null );

        $html = $this->page( $this->personas['coach'], 'kustboys', [ 'type' => 'evaluations_goals' ] );
        $this->assertStringContainsString( 'tt-search-page__result--evaluation', $html );
        $this->assertMatchesRegularExpression( '/class="tt-search-page__kind is-current"/', $html );
    }

    private function note( int $player_id, string $body, string $visibility ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_thread_messages", [
            'club_id' => (int) CurrentClub::id(), 'thread_type' => 'player', 'thread_id' => $player_id, 'author_user_id' => 1,
            'body' => $body, 'visibility' => $visibility, 'created_at' => '2020-03-20 10:00:00',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,int> $ids
     * @param list<string>      $names
     * @return list<int>
     */
    private function of( array $ids, array $names ): array {
        return array_map( static fn( string $n ): int => $ids[ $n ], $names );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function item( array $data, string $type, int $id ): ?array {
        foreach ( (array) ( $data['items'] ?? [] ) as $item ) {
            if ( ( $item['type'] ?? '' ) === $type && (int) $item['id'] === $id ) return $item;
        }
        return null;
    }

    /** @param array<string,string> $get */
    private function page( int $user_id, string $q, array $get = [] ): string {
        foreach ( [ 'q', 'type', 'team_id', 'season_id', 'paged' ] as $key ) unset( $_GET[ $key ] );
        $_GET['q'] = $q;
        foreach ( $get as $key => $value ) $_GET[ $key ] = $value;
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        ob_start();
        \TT\Shared\Frontend\FrontendSearchView::render( $user_id );
        $html = (string) ob_get_clean();
        foreach ( [ 'q', 'type' ] as $key ) unset( $_GET[ $key ] );
        return $html;
    }
}
