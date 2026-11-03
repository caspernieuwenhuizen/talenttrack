<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DataBrowser\DataBrowserService;
use TT\Modules\DataBrowser\SemanticRegistry;

/**
 * #4907 — the per-category evaluation notes (#3949) are a coach's free-text
 * judgement on a child, so browsing them in the Data Explorer is badged and
 * written to the audit log, like the player notes.
 */
final class DataBrowserCategoryNotesSensitiveTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_category_notes_are_sensitive_and_the_others_unchanged(): void {
        $this->assertTrue( SemanticRegistry::isSensitive( 'tt_eval_category_notes' ) );
        foreach ( [ 'tt_player_injuries', 'tt_player_suspensions', 'tt_player_parents', 'tt_player_notes', 'tt_safeguarding' ] as $key ) {
            $this->assertTrue( SemanticRegistry::isSensitive( $key ), $key );
        }
        foreach ( [ 'tt_eval_ratings', 'tt_teams' ] as $key ) {
            $this->assertFalse( SemanticRegistry::isSensitive( $key ), $key );
        }
    }

    public function test_opening_the_table_is_badged_and_audited_like_the_player_notes(): void {
        $this->assertSame( 1, $this->auditedViews( 'tt_player_notes' ), 'precondition: the player notes write one audit row' );
        $this->assertSame( 1, $this->auditedViews( 'tt_eval_category_notes' ) );
    }

    /** Open the table once; the number of view rows written for it. */
    private function auditedViews( string $key ): int {
        global $wpdb;
        $count = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'data_browser.view' AND payload LIKE %s",
            '%' . $wpdb->esc_like( '"table":"' . $key . '"' ) . '%'
        ) );
        $before = $count();
        $view   = DataBrowserService::tableView( $key );
        $this->assertTrue( $view['sensitive'], $key . ' is badged' );
        return $count() - $before;
    }
}
