<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\EvalTypeCategoriesRepository;
use TT\Infrastructure\Evaluations\RatingScale;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;

/**
 * #4976 — switching on the PDP conversation forms creates what their trainer
 * scores are saved under, and the ordinary evaluation forms never offer it.
 */
final class PdpFormSeedingTest extends WP_UnitTestCase {

    private const FORMS = [ 'outfield' => 'jg4it_elite_outfield', 'goalkeeper' => 'jg4it_elite_goalkeeper' ];

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server;
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        PdpFormRegistry::reset();
        QueryHelpers::set_config( 'rating_min', '5' );
        QueryHelpers::set_config( 'rating_max', '9' );
        QueryHelpers::set_config( 'rating_step', '1' );
        QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, '' );
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── the scale ────────────────────────────────────────────────────

    public function test_the_scale_maps_one_to_five_onto_five_to_nine_and_back(): void {
        $scale = new RatingScale( 5, 9, 1 );
        $this->assertTrue( $scale->fitsFormSteps() );
        foreach ( [ 1 => 5.0, 2 => 6.0, 3 => 7.0, 4 => 8.0, 5 => 9.0 ] as $step => $stored ) {
            $this->assertSame( $stored, $scale->toStored( $step ) );
            $this->assertSame( $step, $scale->toFormStep( $stored ) );
        }
    }

    public function test_the_scale_refuses_without_five_steps(): void {
        $scale = new RatingScale( 5, 10, 0.5 );
        $this->assertSame( 11, $scale->stepCount() );
        $this->assertFalse( $scale->fitsFormSteps() );
        $this->expectException( \DomainException::class );
        $scale->toStored( 3 );
    }

    public function test_the_scale_refuses_a_value_that_is_not_on_it(): void {
        $this->expectException( \DomainException::class );
        ( new RatingScale( 5, 9, 1 ) )->toFormStep( 6.5 );
    }

    // ── seeding ──────────────────────────────────────────────────────

    public function test_enabling_creates_the_items_and_the_types_once(): void {
        $first = PdpFormCategorySeeder::ensure( self::FORMS );
        $this->assertIsArray( $first );
        $this->assertSame( 34, $first['categories_created'] );
        $this->assertSame( 2, $first['types_created'] );

        $repo  = new EvalCategoriesRepository();
        $mains = [];
        foreach ( PdpFormRegistry::MAINS as $key ) $mains[ $key ] = (int) $repo->getByKey( $key )->id;

        $item = $repo->getByKey( 'jg4it_of_technisch_rechts' );
        $this->assertNotNull( $item );
        $this->assertSame( $mains['technical'], (int) $item->parent_id, 'under the existing main' );
        $this->assertSame( 'Technisch rechts', (string) $item->label );
        $this->assertSame( 'Traptechniek, passing, aanname en dribbelen met het rechterbeen.', (string) $item->description );
        $this->assertSame( 1, (int) $item->is_system );
        $this->assertSame( 'jg4it_elite_outfield', EvalCategoriesRepository::formOwner( $item ) );

        $keeper = $repo->getByKey( 'jg4it_gk_inzet_motivatie' );
        $outfield = $repo->getByKey( 'jg4it_of_inzet_motivatie' );
        $this->assertNotNull( $keeper );
        $this->assertNotNull( $outfield );
        $this->assertNotSame( (int) $keeper->id, (int) $outfield->id, 'the two forms\' "Inzet / Motivatie" are distinct categories' );
        $this->assertNotSame( (string) $keeper->description, (string) $outfield->description );

        foreach ( PdpFormRegistry::GROUPS as $group ) {
            $type_id = PdpFormCategorySeeder::typeIdFor( $group );
            $this->assertGreaterThan( 0, $type_id, "the {$group} type exists" );
            $mapped = ( new EvalTypeCategoriesRepository() )->categoryIdsFor( $type_id );
            $wanted = array_values( $mains );
            sort( $wanted );
            $this->assertSame( $wanted, $mapped, "the {$group} type maps to the four mains" );
        }

        $before = $this->snapshot();
        $second = PdpFormCategorySeeder::ensure( self::FORMS );
        $this->assertSame( [ 'categories_created' => 0, 'categories_updated' => 0, 'types_created' => 0 ], $second );
        $this->assertSame( $before, $this->snapshot(), 'a second run changes nothing' );
    }

    public function test_a_missing_main_refuses_the_enabling(): void {
        global $wpdb;
        $mental = ( new EvalCategoriesRepository() )->getByKey( 'mental' );
        $this->assertNotNull( $mental );
        $wpdb->update( "{$wpdb->prefix}tt_eval_categories", [ 'is_active' => 0 ], [ 'id' => (int) $mental->id ] );

        $refused = PdpFormCategorySeeder::ensure( self::FORMS );
        $this->assertInstanceOf( \WP_Error::class, $refused );
        $this->assertSame( 'missing_main', $refused->get_error_code() );
        $this->assertStringContainsString(
            EvalCategoriesRepository::displayLabel( (string) $mental->label, (int) $mental->id ),
            $refused->get_error_message(),
            'a main that is switched off is named as the admin sees it'
        );
        $this->assertNull( ( new EvalCategoriesRepository() )->getByKey( 'jg4it_of_technisch_rechts' ), 'nothing was written' );
    }

    /**
     * A category insert the database refuses (here: the first form item's
     * INSERT is rewritten into one that fails). The save must then store no
     * choice, rather than switch the forms on with no categories under them.
     * Until #4986 a second club holding the same key did this; keys are now
     * per club, so the failure is forced directly.
     */
    public function test_a_category_that_cannot_be_written_stores_no_choice(): void {
        global $wpdb;
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $definition = PdpFormRegistry::definition( self::FORMS['outfield'] );
        $this->assertNotNull( $definition );
        $first  = PdpFormRegistry::categoryKey( $definition, PdpFormRegistry::items( $definition )[0]['key'] );
        $refuse = static function ( string $sql ) use ( $first ): string {
            return ( stripos( $sql, 'INSERT INTO' ) === 0 && strpos( $sql, 'tt_eval_categories' ) !== false && strpos( $sql, "'{$first}'" ) !== false )
                ? 'INSERT INTO `tt_no_such_table_4986` (x) VALUES (1)'
                : $sql;
        };
        add_filter( 'query', $refuse );

        $wpdb->suppress_errors( true );
        $response = $this->put( 'jg4it_elite' );
        $wpdb->suppress_errors( false );
        remove_filter( 'query', $refuse );

        $this->assertSame( 422, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
        $this->assertSame( 'seed_failed', $response->get_data()['errors'][0]['code'] ?? null );
        $this->assertSame( '', (string) QueryHelpers::get_config( PdpFormRegistry::CONFIG_KEY, '' ), 'the choice is not stored' );
        $this->assertSame( [ 'outfield' => null, 'goalkeeper' => null ], PdpFormRegistry::formsForClub() );
    }

    public function test_a_set_that_is_not_on_offer_is_an_invalid_field(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $response = $this->put( 'not_a_set' );

        $this->assertSame( 400, $response->get_status() );
        $error = $response->get_data()['errors'][0] ?? [];
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertEquals( (object) [ 'field' => 'set', 'allowed' => [ 'jg4it_elite' ] ], $error['details'] ?? null );
        $this->assertSame( '', (string) QueryHelpers::get_config( PdpFormRegistry::CONFIG_KEY, '' ) );
    }

    public function test_a_scale_without_five_steps_refuses_the_enabling(): void {
        QueryHelpers::set_config( 'rating_max', '10' );
        QueryHelpers::set_config( 'rating_step', '0.5' );
        $refused = PdpFormCategorySeeder::ensure( self::FORMS );
        $this->assertInstanceOf( \WP_Error::class, $refused );
        $this->assertSame( 'rating_scale', $refused->get_error_code() );
    }

    public function test_an_academy_without_forms_gets_no_categories_and_no_types(): void {
        $this->assertSame( [ 'categories_created' => 0, 'categories_updated' => 0, 'types_created' => 0 ], PdpFormCategorySeeder::ensure() );
        $this->assertNull( ( new EvalCategoriesRepository() )->getByKey( 'jg4it_of_technisch_rechts' ) );
        $this->assertSame( 0, PdpFormCategorySeeder::typeIdFor( 'outfield' ) );
    }

    // ── the ordinary evaluation forms ───────────────────────────────

    public function test_the_ordinary_rating_inputs_leave_the_form_items_out(): void {
        PdpFormCategorySeeder::ensure( self::FORMS );
        $repo      = new EvalCategoriesRepository();
        $technical = (int) $repo->getByKey( 'technical' )->id;
        $item      = (int) $repo->getByKey( 'jg4it_of_technisch_rechts' )->id;

        $this->assertContains( $item, $this->ids( $repo->getChildren( $technical ) ), 'the reports still read the item' );
        $this->assertNotContains( $item, $this->ids( $repo->getChildrenForRating( $technical ) ), 'an ordinary rating input does not offer it' );
        $this->assertContains( $item, $this->ids( $repo->getChildrenForRating( $technical, 'jg4it_elite_outfield' ) ), 'the form\'s own evaluation does' );
        $this->assertContains( $item, $this->ids( $repo->getChildrenForRating( $technical, '', [ $item ] ) ), 'and an evaluation that already rates it keeps it' );

        global $wpdb;
        $ids = array_map( 'intval', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}tt_eval_categories WHERE parent_id IS NOT NULL AND " . EvalCategoriesRepository::notFormOwnedSql()
        ) );
        $this->assertNotContains( $item, $ids, 'the SQL form of the rule agrees' );
    }

    public function test_the_type_pickers_leave_the_pdp_types_out(): void {
        PdpFormCategorySeeder::ensure( self::FORMS );
        $pdp_type = PdpFormCategorySeeder::typeIdFor( 'outfield' );

        $this->assertContains( $pdp_type, $this->ids( QueryHelpers::get_eval_types() ), 'lists and reports still know the type' );
        $this->assertNotContains( $pdp_type, $this->ids( QueryHelpers::get_pickable_eval_types() ), 'a picker does not offer it' );
        $this->assertContains( $pdp_type, $this->ids( QueryHelpers::get_pickable_eval_types( $pdp_type ) ), 'except to the evaluation that has it' );
    }

    public function test_the_evaluation_form_offers_no_form_item_and_no_pdp_type(): void {
        PdpFormCategorySeeder::ensure( self::FORMS );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        ob_start();
        \TT\Shared\Frontend\CoachForms::renderEvalForm( [], true );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="eval_type_id"', $html, 'precondition: the form rendered' );
        $this->assertStringNotContainsString( 'Technisch rechts', $html );
        $this->assertStringNotContainsString( 'Doelverdediging', $html );
        $this->assertStringNotContainsString( 'PDP conversation', $html );
    }

    public function test_the_new_evaluation_wizard_offers_no_form_item_and_no_pdp_type(): void {
        PdpFormCategorySeeder::ensure( self::FORMS );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        ob_start();
        ( new \TT\Modules\Wizards\Evaluation\HybridDeepRateStep() )->render( [] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="eval_type_id"', $html, 'precondition: the step rendered' );
        $this->assertStringNotContainsString( 'Technisch rechts', $html );
        $this->assertStringNotContainsString( 'PDP conversation', $html );
    }

    // ── the setting ──────────────────────────────────────────────────

    public function test_saving_the_setting_seeds_and_stores_the_set(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $response = $this->put( 'jg4it_elite' );

        $this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
        $this->assertSame( self::FORMS, PdpFormRegistry::formsForClub() );
        $this->assertNotNull( ( new EvalCategoriesRepository() )->getByKey( 'jg4it_gk_doelverdediging' ), 'the save ran the seeder' );

        $off = $this->put( '' );
        $this->assertSame( 200, $off->get_status(), (string) wp_json_encode( $off->get_data() ) );
        $this->assertSame( [ 'outfield' => null, 'goalkeeper' => null ], PdpFormRegistry::formsForClub(), 'and can switch the forms off' );
    }

    public function test_a_body_without_a_set_is_refused_and_switches_nothing_off(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->assertSame( 200, $this->put( 'jg4it_elite' )->get_status() );

        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/pdp-forms' );
        $this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
        $this->assertSame( self::FORMS, PdpFormRegistry::formsForClub() );
    }

    public function test_a_refused_save_stores_nothing(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        QueryHelpers::set_config( 'rating_max', '10' );
        QueryHelpers::set_config( 'rating_step', '0.5' );

        $response = $this->put( 'jg4it_elite' );
        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( '', (string) QueryHelpers::get_config( PdpFormRegistry::CONFIG_KEY, '' ) );
    }

    public function test_the_setting_asks_the_settings_edit_right(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'tt_coach' ] ) );
        $this->assertSame( 403, $this->put( 'jg4it_elite' )->get_status() );
        $this->assertSame( '', (string) QueryHelpers::get_config( PdpFormRegistry::CONFIG_KEY, '' ) );
    }

    public function test_the_settings_screen_carries_the_select(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $_GET['config_sub'] = 'pdp-forms';
        ob_start();
        \TT\Shared\Frontend\FrontendConfigurationView::render( get_current_user_id(), true );
        $html = (string) ob_get_clean();
        unset( $_GET['config_sub'] );

        $this->assertStringContainsString( 'data-rest-path="pdp-forms"', $html );
        $this->assertStringContainsString( 'value="jg4it_elite"', $html );
        $this->assertStringContainsString( 'JG4IT Elite', $html );
    }

    private function put( string $set ): \WP_REST_Response {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/pdp-forms' );
        $request->set_param( 'set', $set );
        return rest_get_server()->dispatch( $request );
    }

    /** @return array<string, string> category key => label|description|parent|meta, plus the type mappings */
    private function snapshot(): array {
        global $wpdb;
        $out = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT category_key, label, description, parent_id, meta, display_order FROM {$wpdb->prefix}tt_eval_categories WHERE club_id = %d ORDER BY category_key",
            CurrentClub::id()
        ) ) as $row ) {
            $out[ 'cat:' . $row->category_key ] = implode( '|', [ $row->label, $row->description, $row->parent_id, $row->meta, $row->display_order ] );
        }
        foreach ( (array) $wpdb->get_results( "SELECT eval_type_id, eval_category_id FROM {$wpdb->prefix}tt_eval_type_categories ORDER BY eval_type_id, eval_category_id" ) as $row ) {
            $out[ 'map:' . $row->eval_type_id . ':' . $row->eval_category_id ] = '1';
        }
        $out['types'] = (string) count( QueryHelpers::get_eval_types() );
        return $out;
    }

    /**
     * @param iterable<object> $rows
     * @return list<int>
     */
    private function ids( iterable $rows ): array {
        $out = [];
        foreach ( $rows as $row ) $out[] = (int) $row->id;
        return $out;
    }
}
