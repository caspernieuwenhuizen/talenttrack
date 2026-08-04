<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #2317 (epic #2316) — methodology-sets foundation.
 *
 * The bootstrap runs every plugin migration once, so migration 0200 has
 * already created `tt_methodologies`, added `methodology_id` to each
 * entity table, and backfilled the shipped content into a default set.
 * This suite locks that contract: a regression would strand the whole
 * selectable-methodology epic on a schema that never grouped anything.
 */
final class MethodologySetsFoundationTest extends WP_UnitTestCase {

    /** @return list<string> */
    private function entityTables(): array {
        return [
            'tt_principles',
            'tt_methodology_visions',
            'tt_formations',
            'tt_methodology_phases',
            'tt_methodology_learning_goals',
            'tt_methodology_influence_factors',
            'tt_set_pieces',
            'tt_football_actions',
            'tt_methodology_framework_primers',
        ];
    }

    public function test_methodologies_table_exists(): void {
        global $wpdb;
        $t = $wpdb->prefix . 'tt_methodologies';
        $this->assertSame( $t, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) );
    }

    public function test_default_shipped_set_seeded_for_club_one(): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_methodologies
              WHERE club_id = %d AND is_default = 1 LIMIT 1",
            1
        ) );
        $this->assertNotNull( $row, 'a default methodology must exist for club 1' );
        $this->assertSame( '1', (string) $row->is_shipped, 'the seeded default is shipped' );
        $this->assertNotEmpty( $row->uuid, 'root entities carry a uuid' );
        $name = json_decode( (string) $row->name_json, true );
        $this->assertSame( 'JO14-1 Hedel', $name['nl'] ?? null );
    }

    public function test_every_entity_table_has_methodology_id(): void {
        global $wpdb;
        foreach ( $this->entityTables() as $table ) {
            $full = $wpdb->prefix . $table;
            $col  = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$full}` LIKE %s", 'methodology_id' ) );
            $this->assertNotNull( $col, "{$table} must carry methodology_id" );
        }
    }

    public function test_shipped_content_is_backfilled_into_the_default_set(): void {
        global $wpdb;
        $default_id = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE club_id = 1 AND is_default = 1 LIMIT 1"
        );
        $this->assertGreaterThan( 0, $default_id );

        // Migration 0018 seeds shipped principles for club 1; none may be
        // left unassigned after the foundation backfill.
        $orphans = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_principles
              WHERE club_id = %d AND methodology_id IS NULL",
            1
        ) );
        $this->assertSame( 0, $orphans, 'no club-1 principle may be left without a methodology set' );

        $in_default = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_principles
              WHERE club_id = %d AND methodology_id = %d",
            1, $default_id
        ) );
        $this->assertGreaterThan( 0, $in_default, 'shipped principles must land in the default set' );
    }
}
