<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Admin\FunctionalRolesPage;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\I18n\TranslatableFieldRegistry;
use TT\Modules\I18n\TranslationsRepository;

/**
 * #4557 — a club that renames a seeded functional role sees its own name.
 * `LabelTranslator::functionalRoleLabel()` shows the stored label when it
 * differs from the seed's default for that key, and the translated stock
 * label otherwise. A custom role keeps its own label, as before.
 *
 * Each case runs in English and in Dutch. The Dutch stands in for the
 * catalogue for the one msgid involved, as MinutesMatchTypeLabelTest does.
 */
final class FunctionalRoleRenameLabelTest extends WP_UnitTestCase {

    private int $club = 0;

    /** @var callable|null */
    private $dutch = null;

    /** @var callable|null */
    private $dutchPlain = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        $this->club = (int) CurrentClub::id();
        LabelTranslator::flushFunctionalRoleLabels();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $this->english();
        LabelTranslator::flushFunctionalRoleLabels();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array<string, array{0: bool}> */
    public function locales(): array {
        return [ 'en' => [ false ], 'nl' => [ true ] ];
    }

    /** @dataProvider locales */
    public function test_a_renamed_seeded_role_shows_the_clubs_name( bool $dutch ): void {
        $id = $this->role( 'head_coach', 'Trainer A-selectie' );
        $dutch ? $this->dutch() : $this->english();

        $this->assertSame( 'Trainer A-selectie', LabelTranslator::functionalRoleLabel( 'head_coach' ) );
        $this->assertSame( 'Trainer A-selectie', LabelTranslator::functionalRoleLabel( 'head_coach', $id ) );
        $this->assertSame( 'Trainer A-selectie', FunctionalRolesPage::roleLabel( 'head_coach', $id ), 'the admin page follows' );
        $this->assertSame( 'Trainer A-selectie', $this->assignmentRole( $id ), 'and the assignments list' );
    }

    /** @dataProvider locales */
    public function test_an_unrenamed_seeded_role_shows_the_translated_stock_label( bool $dutch ): void {
        // The Title Case original an install seeded before #4523 still holds.
        $id = $this->role( 'head_coach', 'Head Coach' );
        $dutch ? $this->dutch() : $this->english();

        $expected = $dutch ? 'Hoofdtrainer' : 'Head coach';
        $this->assertSame( $expected, LabelTranslator::functionalRoleLabel( 'head_coach', $id ) );
        $this->assertSame( $expected, $this->assignmentRole( $id ) );

        // And the sentence-case label a newer install seeds.
        $this->role( 'head_coach', 'Head coach' );
        $this->assertSame( $expected, LabelTranslator::functionalRoleLabel( 'head_coach', $id ) );
    }

    /** @dataProvider locales */
    public function test_a_custom_role_keeps_its_own_label( bool $dutch ): void {
        $id = $this->role( 'video_analist', 'Video-analist' );
        $dutch ? $this->dutch() : $this->english();

        $this->assertNull( LabelTranslator::functionalRoleLabel( 'video_analist', $id ), 'no stock label: callers show the stored one' );
        $this->assertSame( 'Video-analist', $this->assignmentRole( $id ) );
    }

    public function test_a_rename_over_rest_shows_in_the_same_request(): void {
        $id = $this->role( 'physio', 'Physio' );
        $this->assertSame( 'Physio', LabelTranslator::functionalRoleLabel( 'physio', $id ) );

        $req = new WP_REST_Request( 'PUT', '/talenttrack/v1/functional-roles/' . $id );
        $req->set_header( 'Content-Type', 'application/json' );
        $req->set_body( (string) wp_json_encode( [ 'label' => 'Fysiotherapeut' ] ) );
        $this->assertSame( 200, rest_do_request( $req )->get_status() );

        $this->assertSame( 'Fysiotherapeut', LabelTranslator::functionalRoleLabel( 'physio', $id ) );
    }

    // Dutch installs that ran migration 0085 hold the Dutch of the original
    // seed ("Hoofdtrainer") in tt_translations for every seeded row.

    public function test_a_renamed_role_is_not_hidden_by_the_backfilled_translation(): void {
        $id = $this->role( 'head_coach', 'Trainer A-selectie' );
        $this->translation( $id, 'Hoofdtrainer' );
        $this->dutch();

        $this->assertSame( 'Trainer A-selectie', LabelTranslator::functionalRoleLabel( 'head_coach', $id ) );
        $this->assertSame( 'Trainer A-selectie', $this->assignmentRole( $id ) );
    }

    public function test_an_unrenamed_role_with_the_backfilled_translation_shows_the_dutch(): void {
        $id = $this->role( 'head_coach', 'Head Coach' );
        $this->translation( $id, 'Hoofdtrainer' );
        $this->dutch();

        $this->assertSame( 'Hoofdtrainer', LabelTranslator::functionalRoleLabel( 'head_coach', $id ) );
        $this->assertSame( 'Hoofdtrainer', $this->assignmentRole( $id ) );
    }

    public function test_a_translation_entered_for_the_new_name_still_wins(): void {
        $id = $this->role( 'head_coach', 'Trainer A-selectie' );
        $this->translation( $id, 'Trainer A' );
        $this->dutch();

        $this->assertSame( 'Trainer A', LabelTranslator::functionalRoleLabel( 'head_coach', $id ) );
    }

    // Helpers

    /** A `nl_NL` label translation on this functional role row. */
    private function translation( int $role_id, string $value ): void {
        $this->assertTrue( ( new TranslationsRepository() )->upsert(
            TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE, $role_id, 'label', 'nl_NL', $value, get_current_user_id()
        ) );
    }

    /** The club's row for this key with `$label` stored, created when absent. */
    private function role( string $key, string $label ): int {
        global $wpdb;
        $t  = "{$wpdb->prefix}tt_functional_roles";
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE club_id = %d AND role_key = %s", $this->club, $key ) );
        if ( $id > 0 ) {
            $wpdb->update( $t, [ 'label' => $label ], [ 'id' => $id ] );
        } else {
            $wpdb->insert( $t, [ 'club_id' => $this->club, 'role_key' => $key, 'label' => $label ] );
            $id = (int) $wpdb->insert_id;
        }
        LabelTranslator::flushFunctionalRoleLabels();
        return $id;
    }

    /** The `role` of an assignment to this role, as `GET functional-roles/assignments` returns it. */
    private function assignmentRole( int $role_id ): string {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Rol JO12-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [ 'club_id' => $this->club, 'first_name' => 'Rol', 'last_name' => 'Houder' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $team, 'person_id' => (int) $wpdb->insert_id, 'functional_role_id' => $role_id,
        ] );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/functional-roles/assignments' );
        $req->set_param( 'team_id', $team );
        $rows = (array) ( ( (array) ( ( (array) rest_do_request( $req )->get_data() )['data'] ?? [] ) )['rows'] ?? [] );
        $this->assertCount( 1, $rows );
        return (string) $rows[0]['role'];
    }

    /** The Dutch catalogue for the msgids involved, and an nl_NL locale for tt_translations. */
    private function dutch(): void {
        $this->english();
        $this->dutch = static function ( $translation, $text, $context, $domain ) {
            if ( $domain === 'talenttrack' && $context === 'role label' && $text === 'Head coach' ) return 'Hoofdtrainer';
            return $translation;
        };
        $this->dutchPlain = static function ( $translation, $text, $domain ) {
            if ( $domain === 'talenttrack' && $text === 'Head Coach' ) return 'Hoofdtrainer';
            return $translation;
        };
        add_filter( 'gettext_with_context', $this->dutch, 10, 4 );
        add_filter( 'gettext', $this->dutchPlain, 10, 3 );
        add_filter( 'pre_determine_locale', [ $this, 'nlLocale' ] );
    }

    public function nlLocale(): string {
        return 'nl_NL';
    }

    private function english(): void {
        if ( $this->dutch !== null ) remove_filter( 'gettext_with_context', $this->dutch, 10 );
        if ( $this->dutchPlain !== null ) remove_filter( 'gettext', $this->dutchPlain, 10 );
        remove_filter( 'pre_determine_locale', [ $this, 'nlLocale' ] );
        $this->dutch      = null;
        $this->dutchPlain = null;
    }
}
