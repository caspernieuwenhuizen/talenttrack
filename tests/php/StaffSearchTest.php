<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4936 — search phase 2, the staff half: evaluations, goals and notes for
 * the staff who read them, the shirt number and position on player rows,
 * the full path on setting rows, and switched-off modules for whoever may
 * switch them on.
 *
 * The academy is SearchFixtures': José (the parent's child), Jesse Boot (the
 * player's own record) and Daan den Besten on team A, Bram Farplayer on
 * team B. Every player gets an evaluation whose staff notes carry the same
 * word, a goal with the same title and a note with the same text, so every
 * refusal is a match the engine found and had to leave out.
 */
final class StaffSearchTest extends WP_UnitTestCase {

    use SearchFixtures;

    private const NOTES_WORD = 'zorgenkind';
    private const GOAL_WORD  = 'kopduelwinnen';
    private const NOTE_WORD  = 'enkelblessure';

    /** @var array<string,int> */
    private array $evaluations = [];

    /** @var array<string,int> */
    private array $goals = [];

    /** @var array<string,int> */
    private array $notes = [];

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
                'notes' => 'Een ' . ucfirst( self::NOTES_WORD ) . ' thuis, let op de focus.',
            ] );
            $this->evaluations[ $name ] = (int) $wpdb->insert_id;
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $club, 'player_id' => $this->players[ $name ], 'title' => 'Kopduelwinnen in de zestien',
                'description' => 'Timing van de sprong', 'created_by' => 1, 'status' => 'pending', 'due_date' => '2020-04-01',
            ] );
            $this->goals[ $name ] = (int) $wpdb->insert_id;
            $wpdb->insert( "{$p}tt_thread_messages", [
                'club_id' => $club, 'thread_type' => 'player', 'thread_id' => $this->players[ $name ], 'author_user_id' => 1,
                'body' => 'Klaagt over een ' . self::NOTE_WORD . ' na de training.', 'visibility' => 'public', 'created_at' => '2020-03-20 10:00:00',
            ] );
            $this->notes[ $name ] = (int) $wpdb->insert_id;
        }

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->resetSearchCaches();
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_a_coach_finds_their_own_players_records_and_no_others(): void {
        $coach  = $this->personas['coach'];
        $team_a = [ $this->players['jose'], $this->players['boot'], $this->players['besten'] ];

        $evals = $this->paged( $coach, 'match evaluation' );
        $this->assertEqualsCanonicalizing( $this->of( $this->evaluations, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $evals, 'staff_evaluation' ) );
        $this->assertSame( 3, $evals['counts']['staff_evaluation'] ?? null, 'the other team\'s evaluation is not counted' );

        $goals = $this->paged( $coach, self::GOAL_WORD );
        $this->assertEqualsCanonicalizing( $this->of( $this->goals, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $goals, 'staff_goal' ) );
        $this->assertSame( 3, $goals['counts']['staff_goal'] ?? null );

        $notes = $this->paged( $coach, self::NOTE_WORD );
        $this->assertEqualsCanonicalizing( $this->of( $this->notes, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $notes, 'note' ) );
        $this->assertSame( 3, $notes['counts']['note'] ?? null );

        foreach ( (array) $notes['items'] as $item ) {
            if ( $item['type'] !== 'note' ) continue;
            $this->assertContains( (int) $item['player_id'], $team_a );
            $this->assertStringContainsString( 'tab=notes', (string) $item['url'] );
            $this->assertStringContainsString( self::NOTE_WORD, (string) ( $item['excerpt'] ?? '' ) );
        }
    }

    public function test_the_head_of_development_finds_them_across_the_academy(): void {
        $hod = $this->personas['hod'];
        $this->assertEqualsCanonicalizing( array_values( $this->evaluations ), $this->idsOf( $this->paged( $hod, 'match evaluation' ), 'staff_evaluation' ) );
        $this->assertEqualsCanonicalizing( array_values( $this->goals ), $this->idsOf( $this->paged( $hod, self::GOAL_WORD ), 'staff_goal' ) );
        $this->assertEqualsCanonicalizing( array_values( $this->evaluations ), $this->idsOf( $this->paged( $hod, self::NOTES_WORD ), 'staff_evaluation' ), 'by a word in the notes' );
    }

    public function test_the_notes_are_matched_and_shown_only_where_the_coach_reads_them(): void {
        $data = $this->paged( $this->personas['coach'], self::NOTES_WORD );
        $this->assertEqualsCanonicalizing( $this->of( $this->evaluations, [ 'jose', 'boot', 'besten' ] ), $this->idsOf( $data, 'staff_evaluation' ) );
        foreach ( (array) $data['items'] as $item ) {
            if ( $item['type'] !== 'staff_evaluation' ) continue;
            $this->assertStringContainsStringIgnoringCase( self::NOTES_WORD, (string) ( $item['excerpt'] ?? '' ) );
        }
    }

    public function test_the_observer_finds_an_evaluation_by_player_but_never_by_its_notes(): void {
        $observer = $this->observer;
        $jose     = $this->players['jose'];
        $this->assertTrue( AuthorizationService::canViewPlayer( $observer, $jose ), 'precondition: the observer reads the player' );
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $observer, $jose, 'evaluations' ), 'precondition: and the evaluations' );

        $by_name = $this->paged( $observer, 'jose' );
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $by_name, 'staff_evaluation' ), 'found by the player\'s name' );
        foreach ( (array) $by_name['items'] as $item ) {
            if ( $item['type'] === 'staff_evaluation' ) $this->assertArrayNotHasKey( 'excerpt', $item, 'no excerpt of the notes' );
        }

        $by_notes = $this->paged( $observer, self::NOTES_WORD );
        $this->assertSame( [], $this->idsOf( $by_notes, 'staff_evaluation' ), 'not by a word only in the notes' );
        $this->assertSame( 0, $by_notes['counts']['staff_evaluation'] ?? 0 );
        $this->assertStringNotContainsStringIgnoringCase( self::NOTES_WORD, (string) wp_json_encode( $by_notes ) );
    }

    public function test_the_observer_gets_no_notes_kind(): void {
        foreach ( [ 'jose', self::NOTE_WORD ] as $q ) {
            $data = $this->paged( $this->observer, $q );
            $this->assertSame( [], $this->idsOf( $data, 'note' ), $q );
            $this->assertSame( 0, $data['counts']['note'] ?? 0, $q );
        }

        $html = $this->page( $this->observer, self::NOTE_WORD );
        $this->assertStringNotContainsString( '>Notes<', $html, 'no Notes group on the results page' );
    }

    public function test_a_scout_finds_their_linked_player_only_and_not_by_the_notes(): void {
        $this->addCandidateSourcePersonas();
        $scout = $this->personas['scout'];
        $this->assertTrue( AuthorizationService::canViewPlayer( $scout, $this->players['jose'] ), 'precondition: the scout reads José' );
        $this->assertFalse( AuthorizationService::canViewPlayer( $scout, $this->players['far'] ), 'precondition: and not Bram' );

        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $this->paged( $scout, 'match evaluation' ), 'staff_evaluation' ) );
        $this->assertSame( [], $this->idsOf( $this->paged( $scout, self::NOTES_WORD ), 'staff_evaluation' ), 'the notes are masked for a scout' );
        $this->assertSame( [ $this->goals['jose'] ], $this->idsOf( $this->paged( $scout, self::GOAL_WORD ), 'staff_goal' ) );

        $notes = $this->paged( $scout, self::NOTE_WORD );
        $this->assertNotContains( $this->notes['far'], $this->idsOf( $notes, 'note' ), 'not another team\'s player\'s note' );
        $this->assertLessThanOrEqual( 1, $notes['counts']['note'] ?? 0 );
    }

    public function test_parents_and_players_get_no_staff_kinds(): void {
        foreach ( [ 'parent', 'player' ] as $persona ) {
            $uid = $this->personas[ $persona ];
            foreach ( [ 'match evaluation', self::GOAL_WORD, self::NOTE_WORD, self::NOTES_WORD ] as $q ) {
                $data = $this->paged( $uid, $q );
                foreach ( [ 'staff_evaluation', 'staff_goal', 'note' ] as $kind ) {
                    $this->assertSame( 0, $data['counts'][ $kind ] ?? 0, "{$persona} / {$q} / {$kind}" );
                    $this->assertSame( [], $this->idsOf( $data, $kind ), "{$persona} / {$q} / {$kind}" );
                }
            }
        }
        // Their own records still come through the family kinds (#4673).
        $this->assertSame( [ $this->evaluations['jose'] ], $this->idsOf( $this->paged( $this->personas['parent'], 'match evaluation' ), 'evaluation' ) );
        $this->assertSame( [ $this->evaluations['boot'] ], $this->idsOf( $this->paged( $this->personas['player'], 'match evaluation' ), 'evaluation' ) );
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
        $this->assertSame( [ 'staff_evaluation', 'staff_goal' ], $groups['evaluations_goals'] ?? null );

        $html = $this->page( $this->personas['coach'], 'kustboys', [ 'type' => 'evaluations_goals' ] );
        $this->assertStringContainsString( 'tt-search-page__result--staff_evaluation', $html );
        $this->assertMatchesRegularExpression( '/class="tt-search-page__kind is-current"/', $html );
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
