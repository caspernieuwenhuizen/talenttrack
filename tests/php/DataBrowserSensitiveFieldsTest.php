<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DataBrowser\DataBrowserService;
use TT\Modules\DataBrowser\SemanticRegistry;

/**
 * #4925 — every table that owns a field config/sensitive_fields.php masks
 * on the screens is a sensitive table in the Data Explorer: browsing it raw
 * is badged and written to the audit log.
 *
 * The registry names entities and fields, not tables, so the owning table
 * of each field is written out here. A field registered without an entry
 * fails the test, so a new masked field forces the question.
 */
final class DataBrowserSensitiveFieldsTest extends WP_UnitTestCase {

    /** entity => field => the table the value is stored in. */
    private const OWNER = [
        'players' => [
            'guardian_name'    => 'tt_players',
            'guardian_email'   => 'tt_players',
            'guardian_phone'   => 'tt_players',
            'date_of_birth'    => 'tt_players',
            // The linked guardian's name, through the guardian link.
            'parent_name'      => 'tt_player_parents',
            'parent_link_html' => 'tt_player_parents',
            'custom_fields'    => 'tt_custom_values',
        ],
        'evaluations' => [
            'notes'           => 'tt_evaluations',
            'notes_excerpt'   => 'tt_evaluations',
            'player_feedback' => 'tt_evaluations',
            'category_notes'  => 'tt_eval_category_notes',
        ],
        'attendance' => [
            'notes'  => 'tt_attendance',
            'reason' => 'tt_match_prep_availability',
        ],
        'people' => [
            'email'           => 'tt_people',
            'email_link_html' => 'tt_people',
            'phone'           => 'tt_people',
        ],
        'prospects' => [
            'parent_name'    => 'tt_prospects',
            'parent_email'   => 'tt_prospects',
            'parent_phone'   => 'tt_prospects',
            'scouting_notes' => 'tt_prospects',
            'date_of_birth'  => 'tt_prospects',
            'discovered_by_user_id' => 'tt_prospects',
            // The scout's name, resolved from the row's discovered_by_user_id.
            'discovered_by'  => 'tt_prospects',
            // Which visit found the child: `tt_prospects.scouting_visit_id`.
            'is_discovery'   => 'tt_prospects',
        ],
        // #4694 — the band and the notes live in the potential history; the
        // label, rank and movement are derived from the band there.
        'player_potential' => [
            'band'                => 'tt_player_potential',
            'label'               => 'tt_player_potential',
            'potential_band'      => 'tt_player_potential',
            'band_label'          => 'tt_player_potential',
            'band_rank'           => 'tt_player_potential',
            'direction'           => 'tt_player_potential',
            'steps'               => 'tt_player_potential',
            'previous_band'       => 'tt_player_potential',
            'previous_band_label' => 'tt_player_potential',
            'notes'               => 'tt_player_potential',
        ],
        'scouting_visits' => [
            'notes' => 'tt_scouting_plan_visits',
        ],
        'prospect_visits' => [
            'notes' => 'tt_scouting_plan_visits',
        ],
        'prospect_consent_requests' => [
            'asked_of' => 'tt_prospect_consent_requests',
            'notes'    => 'tt_prospect_consent_requests',
        ],
    ];

    /** @return array<string, mixed> */
    private static function registry(): array {
        $registry = require TT_PLUGIN_DIR . 'config/sensitive_fields.php';
        return is_array( $registry ) ? $registry : [];
    }

    public function test_every_registered_field_names_its_table(): void {
        $registry = self::registry();
        $this->assertNotSame( [], $registry, 'precondition: the registry loads' );
        foreach ( $registry as $entity => $spec ) {
            foreach ( array_keys( (array) ( $spec['fields'] ?? [] ) ) as $field ) {
                $this->assertArrayHasKey( $field, self::OWNER[ $entity ] ?? [], "{$entity}.{$field} has no owning table in this test" );
            }
        }
    }

    public function test_every_owning_table_is_a_sensitive_table(): void {
        foreach ( self::registry() as $entity => $spec ) {
            foreach ( array_keys( (array) ( $spec['fields'] ?? [] ) ) as $field ) {
                $table = self::OWNER[ $entity ][ $field ] ?? '';
                if ( $table === '' ) continue;
                $this->assertTrue( SemanticRegistry::isSensitive( $table ), "{$table} owns {$entity}.{$field} and is not flagged" );
            }
        }
    }

    public function test_the_earlier_tables_stay_flagged(): void {
        foreach ( [ 'tt_player_injuries', 'tt_player_suspensions', 'tt_player_parents', 'tt_player_notes', 'tt_safeguarding', 'tt_eval_category_notes' ] as $key ) {
            $this->assertTrue( SemanticRegistry::isSensitive( $key ), $key );
        }
        $this->assertFalse( SemanticRegistry::isSensitive( 'tt_teams' ), 'a table with no masked field is not flagged' );
    }

    public function test_opening_a_newly_flagged_table_is_badged_and_audited(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        global $wpdb;
        foreach ( [ 'tt_players', 'tt_people' ] as $key ) {
            $count = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'data_browser.view' AND payload LIKE %s",
                '%' . $wpdb->esc_like( '"table":"' . $key . '"' ) . '%'
            ) );
            $before = $count();
            $view   = DataBrowserService::tableView( $key );
            $this->assertTrue( $view['sensitive'], $key . ' is badged' );
            $this->assertSame( 1, $count() - $before, $key . ' writes one audit row' );
        }
        wp_set_current_user( 0 );
    }
}
