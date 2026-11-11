<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Search\SearchRegistry;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\Methodology\ActiveMethodologyResolver;
use TT\Modules\Methodology\MethodologyScope;
use TT\Shared\Frontend\FrontendSearchView;

/**
 * #4942 — search phase 3, the library: exercises, training plans,
 * methodology and courses, for staff only.
 *
 * The academy is SearchFixtures'. Every library record carries the same
 * word, so every refusal is a match the engine found and had to leave out:
 * an exercise that is live, one that is another coach's private drill, one
 * superseded and one archived; a plan for team A, one for team B, a club
 * template and an archived template; a principle in the active methodology
 * and one in a second methodology.
 */
final class LibrarySearchTest extends WP_UnitTestCase {

    use SearchFixtures;

    private const EX_WORD     = 'kaatsrondo';
    private const PLAN_WORD   = 'positiespelweek';
    private const METHOD_WORD = 'zwierbalorde';
    private const COURSE_WORD = 'periodiseren';

    /** @var array<string,int> */
    private array $exercises = [];

    /** @var array<string,int> */
    private array $plans = [];

    private int $observer = 0;
    private int $scout    = 0;

    private int $active_set = 0;
    private int $other_set  = 0;

    private string $config_was = '';

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
        KnowledgeModule::ensureCapabilities();
        CourseRegistry::flushCache();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_exercise_categories", [ 'club_id' => $club, 'slug' => 'lib-search-cat', 'label' => 'Kaatsvormen' ] );
        $category = (int) $wpdb->insert_id;

        $this->exercises['live']       = $this->exercise( 'Kaatsrondo vijf tegen twee', [ 'category_id' => $category, 'description' => 'Snel kaatsen in een rondo.' ] );
        $this->exercises['private']    = $this->exercise( 'Kaatsrondo van een collega', [ 'visibility' => 'private', 'author_user_id' => 1 ] );
        $this->exercises['superseded'] = $this->exercise( 'Kaatsrondo oude versie', [ 'superseded_by_id' => $this->exercises['live'] ] );
        $this->exercises['archived']   = $this->exercise( 'Kaatsrondo gearchiveerd', [ 'archived_at' => '2020-01-01 00:00:00' ] );

        $this->plans['a']        = $this->plan( 'Positiespelweek A', $this->team_a, 0 );
        $this->plans['b']        = $this->plan( 'Positiespelweek B', $this->team_b, 0 );
        $this->plans['template'] = $this->plan( 'Positiespelweek sjabloon', null, 1 );
        $this->plans['archived'] = $this->plan( 'Positiespelweek oud', null, 1, '2020-01-01 00:00:00' );

        $this->config_was = (string) QueryHelpers::get_config( ActiveMethodologyResolver::CONFIG_KEY, '0' );
        $this->active_set = $this->methodology( 'lib-search-active' );
        $this->other_set  = $this->methodology( 'lib-search-other' );
        ActiveMethodologyResolver::setInstallDefault( $this->active_set );
        MethodologyScope::reset();
        $this->principle( 'LS-01', 'Zwierbalorde in de opbouw', $this->active_set );
        $this->principle( 'LS-02', 'Zwierbalorde van een andere methodiek', $this->other_set );

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->scout    = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->resetSearchCaches();
    }

    public function tear_down(): void {
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        FeatureRegistry::setEnabled( 'knowledge_courses', true );
        QueryHelpers::set_config( ActiveMethodologyResolver::CONFIG_KEY, $this->config_was );
        MethodologyScope::reset();
        CourseRegistry::flushCache();
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_the_library_is_one_group_between_settings_and_sections(): void {
        $groups = array_column( FrontendSearchView::groups(), 'kind' );
        $at     = array_search( 'library', $groups, true );
        $this->assertNotFalse( $at );
        $this->assertSame( 'setting', $groups[ $at - 1 ] );
        $this->assertSame( 'view', $groups[ $at + 1 ] );
        $this->assertSame( [ 'exercise', 'training_plan', 'methodology', 'course' ], FrontendSearchView::kindsOf( 'library' ) );
        foreach ( [ 'exercise', 'training_plan', 'methodology', 'course' ] as $kind ) {
            $this->assertNotNull( SearchRegistry::get( $kind ), "{$kind} is a kind" );
        }
    }

    public function test_staff_find_the_current_live_exercises_and_nothing_superseded_or_archived(): void {
        $expected = [ $this->exercises['live'], $this->exercises['private'] ];
        foreach ( [ 'coach', 'hod' ] as $persona ) {
            $data = $this->paged( $this->personas[ $persona ], self::EX_WORD );
            $this->assertEqualsCanonicalizing( $expected, $this->idsOf( $data, 'exercise' ), $persona );
            $this->assertSame( 2, $data['counts']['exercise'] ?? null, "{$persona}: the superseded and archived rows are not counted" );
        }
        $observer = $this->paged( $this->observer, self::EX_WORD );
        $this->assertEqualsCanonicalizing( $expected, $this->idsOf( $observer, 'exercise' ), 'the observer reads the library' );
    }

    public function test_an_exercise_is_found_by_its_category_and_says_what_it_is(): void {
        $data = $this->paged( $this->personas['coach'], 'kaatsvormen', [ 'types' => 'exercise' ] );
        $this->assertSame( [ $this->exercises['live'] ], $this->idsOf( $data, 'exercise' ) );
        $item = $this->firstOf( $data, 'exercise' );
        $this->assertStringStartsWith( 'Exercise', (string) $item['sublabel'] );
        $this->assertStringContainsString( 'tt_view=exercises', (string) $item['url'] );
        $this->assertStringContainsString( 'kaatsen', (string) ( $item['excerpt'] ?? '' ) );
    }

    public function test_a_coach_finds_their_own_teams_plans_and_the_templates_and_no_other_teams(): void {
        $data = $this->paged( $this->personas['coach'], self::PLAN_WORD );
        $this->assertEqualsCanonicalizing( [ $this->plans['a'], $this->plans['template'] ], $this->idsOf( $data, 'training_plan' ) );
        $this->assertSame( 2, $data['counts']['training_plan'] ?? null, 'the other team\'s plan and the archived template are not counted' );
        $item = $this->firstOf( $data, 'training_plan' );
        $this->assertStringStartsWith( 'Training plan', (string) $item['sublabel'] );
        $this->assertStringContainsString( 'tt_view=training-plan', (string) $item['url'] );
    }

    public function test_the_head_of_development_finds_every_live_plan(): void {
        $data = $this->paged( $this->personas['hod'], self::PLAN_WORD );
        $this->assertEqualsCanonicalizing( [ $this->plans['a'], $this->plans['b'], $this->plans['template'] ], $this->idsOf( $data, 'training_plan' ) );
        $this->assertSame( 3, $data['counts']['training_plan'] ?? null );
    }

    public function test_the_team_filter_narrows_plans_to_that_team(): void {
        $data = $this->paged( $this->personas['hod'], self::PLAN_WORD, [ 'team_id' => (string) $this->team_b ] );
        $this->assertSame( [ $this->plans['b'] ], $this->idsOf( $data, 'training_plan' ) );
    }

    public function test_methodology_comes_only_from_the_selected_methodology(): void {
        $data  = $this->paged( $this->personas['coach'], self::METHOD_WORD );
        $items = $this->itemsOf( $data, 'methodology' );
        $this->assertCount( 1, $items );
        $this->assertSame( 1, $data['counts']['methodology'] ?? null, 'the other methodology is not counted' );
        $this->assertStringContainsString( 'LS-01', (string) $items[0]['label'] );
        $this->assertStringContainsString( 'mtab=principles', (string) $items[0]['url'] );
        $this->assertStringStartsWith( 'Methodology', (string) $items[0]['sublabel'] );
    }

    public function test_a_listable_course_is_found_and_not_locked(): void {
        $items = $this->itemsOf( $this->paged( $this->personas['hod'], self::COURSE_WORD ), 'course' );
        $this->assertCount( 1, $items );
        $this->assertStringContainsString( 'slug=voetbalperiodisering', (string) $items[0]['url'] );
        $this->assertArrayNotHasKey( 'locked', $items[0] );
    }

    public function test_a_locked_course_is_listed_and_marked_locked(): void {
        // A commercial install on the free plan: courses are locked, not hidden.
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_FREE );
        $items = $this->itemsOf( $this->paged( $this->personas['hod'], self::COURSE_WORD ), 'course' );
        $this->assertCount( 1, $items );
        $this->assertTrue( $items[0]['locked'] ?? false );
    }

    public function test_an_unavailable_course_never_appears(): void {
        FeatureRegistry::setEnabled( 'knowledge_courses', false );
        $data = $this->paged( $this->personas['hod'], self::COURSE_WORD );
        $this->assertSame( [], $this->itemsOf( $data, 'course' ) );
        $this->assertSame( 0, $data['counts']['course'] ?? null );
    }

    public function test_a_switched_off_module_returns_nothing_of_its_kind(): void {
        $cases = [
            'exercise'      => [ \TT\Modules\Exercises\ExercisesModule::class, self::EX_WORD ],
            'training_plan' => [ \TT\Modules\Training\TrainingModule::class, self::PLAN_WORD ],
            'methodology'   => [ \TT\Modules\Methodology\MethodologyModule::class, self::METHOD_WORD ],
            'course'        => [ KnowledgeModule::class, self::COURSE_WORD ],
        ];
        foreach ( $cases as $kind => [ $module, $word ] ) {
            if ( ModuleRegistry::isAlwaysOn( $module ) ) continue;
            ModuleRegistry::setEnabled( $module, false );
            try {
                $data = $this->paged( $this->personas['hod'], $word );
                $this->assertSame( [], $this->itemsOf( $data, $kind ), "{$kind} with its module off" );
                $this->assertSame( 0, $data['counts'][ $kind ] ?? null, "{$kind} with its module off is not counted" );
            } finally {
                ModuleRegistry::setEnabled( $module, true );
            }
        }
    }

    public function test_a_scout_finds_methodology_but_no_exercises_or_plans(): void {
        $this->assertSame( [], $this->idsOf( $this->paged( $this->scout, self::EX_WORD ), 'exercise' ) );
        $this->assertSame( 0, $this->paged( $this->scout, self::EX_WORD )['counts']['exercise'] ?? null );
        $this->assertSame( 0, $this->paged( $this->scout, self::PLAN_WORD )['counts']['training_plan'] ?? null );
        $this->assertCount( 1, $this->itemsOf( $this->paged( $this->scout, self::METHOD_WORD ), 'methodology' ) );
    }

    public function test_the_observer_finds_no_plans(): void {
        $data = $this->paged( $this->observer, self::PLAN_WORD );
        $this->assertSame( [], $this->idsOf( $data, 'training_plan' ) );
        $this->assertSame( 0, $data['counts']['training_plan'] ?? null );
    }

    public function test_a_parent_and_a_player_get_nothing_from_the_library(): void {
        foreach ( [ 'parent', 'player' ] as $persona ) {
            foreach ( [ self::EX_WORD, self::PLAN_WORD, self::METHOD_WORD, self::COURSE_WORD ] as $word ) {
                $data = $this->paged( $this->personas[ $persona ], $word );
                foreach ( [ 'exercise', 'training_plan', 'methodology', 'course' ] as $kind ) {
                    $this->assertSame( [], $this->itemsOf( $data, $kind ), "{$persona} / {$kind}" );
                    $this->assertSame( 0, (int) ( $data['counts'][ $kind ] ?? 0 ), "{$persona} / {$kind} is not counted" );
                }
            }
        }
    }

    /** @param array<string,mixed> $extra */
    private function exercise( string $name, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_exercises", array_merge( [
            'club_id' => (int) CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'name' => $name,
            'visibility' => 'club', 'duration_minutes' => 12,
        ], $extra ) );
        return (int) $wpdb->insert_id;
    }

    private function plan( string $title, ?int $team_id, int $is_template, ?string $archived_at = null ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_training_plans", [
            'club_id' => (int) CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'title' => $title,
            'notes' => 'Veel herhaling in kleine vakken.', 'team_id' => $team_id, 'is_template' => $is_template,
            'archived_at' => $archived_at,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function methodology( string $slug ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_methodologies", [
            'club_id' => (int) CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'slug' => $slug,
            'name_json' => wp_json_encode( [ 'nl' => $slug, 'en' => $slug ] ), 'is_default' => 0, 'is_shipped' => 0,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function principle( string $code, string $title, int $methodology_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_principles", [
            'club_id' => (int) CurrentClub::id(), 'code' => $code, 'methodology_id' => $methodology_id,
            'title_json' => wp_json_encode( [ 'nl' => $title, 'en' => $title ] ),
        ] );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,mixed>>
     */
    private function itemsOf( array $data, string $type ): array {
        return array_values( array_filter( (array) ( $data['items'] ?? [] ), static fn( $item ): bool => ( $item['type'] ?? '' ) === $type ) );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function firstOf( array $data, string $type ): array {
        $items = $this->itemsOf( $data, $type );
        $this->assertNotEmpty( $items, "a {$type} hit" );
        return (array) $items[0];
    }
}
