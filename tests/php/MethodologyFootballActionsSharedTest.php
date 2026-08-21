<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Methodology\MethodologyScope;
use TT\Modules\Methodology\Repositories\FootballActionsRepository;

/**
 * #2620 — the football-action catalogue is shared across methodology sets.
 *
 * Migration 0200 stamped every shipped action into the club's default set;
 * the JO13 seed then never added its own, so the whole catalogue went dark
 * for any install where a non-JO14 set is active. Migration 0219 unstamps
 * them and `create()` no longer stamps new ones.
 *
 * The distinction this suite locks: principles, phases, vision and
 * formations are per-set (see MethodologyScopingTest); football actions
 * are not. A football action is vocabulary of the game, not of one club's
 * play style, and `tt_goals.linked_action_id` points at one row id — so
 * duplicating per set would fragment goal reporting.
 */
final class MethodologyFootballActionsSharedTest extends WP_UnitTestCase {

    private int $secondSetId = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        MethodologyScope::reset();

        $wpdb->insert( $wpdb->prefix . 'tt_methodologies', [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'slug'       => 'shared-actions-test-set',
            'name_json'  => wp_json_encode( [ 'nl' => 'Set B', 'en' => 'Set B' ] ),
            'is_default' => 0,
            'is_shipped' => 0,
        ] );
        $this->secondSetId = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        MethodologyScope::reset();
        parent::tear_down();
    }

    public function test_migration_leaves_no_action_stamped_to_a_set(): void {
        global $wpdb;
        $stamped = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_football_actions
              WHERE club_id = %d AND methodology_id IS NOT NULL",
            1
        ) );
        $this->assertSame( 0, $stamped, 'the shipped catalogue must not be stamped to any one set' );
    }

    public function test_catalogue_lists_under_a_non_default_set(): void {
        $repo = new FootballActionsRepository();

        MethodologyScope::reset();
        $under_default = count( $repo->listAll() );
        $this->assertGreaterThan( 0, $under_default, 'the shipped catalogue must not be empty' );

        MethodologyScope::set( $this->secondSetId );
        $under_second = count( $repo->listAll() );

        $this->assertSame(
            $under_default,
            $under_second,
            'switching the active set must not change which football actions exist'
        );
    }

    public function test_a_new_action_is_visible_under_every_set(): void {
        global $wpdb;
        $repo = new FootballActionsRepository();

        MethodologyScope::set( $this->secondSetId );
        $id = $repo->create( [
            'slug'         => 'zz-shared-test-action',
            'category_key' => FootballActionsRepository::CAT_WITH_BALL,
            'name_json'    => [ 'nl' => 'ZZ testhandeling', 'en' => 'ZZ test action' ],
        ] );
        $this->assertGreaterThan( 0, $id, 'the action must be created' );

        $stamp = $wpdb->get_var( $wpdb->prepare(
            "SELECT methodology_id FROM {$wpdb->prefix}tt_football_actions WHERE id = %d",
            $id
        ) );
        $this->assertNull( $stamp, 'a newly authored action joins the shared catalogue' );

        MethodologyScope::reset();
        $slugs = array_map( static fn( $r ) => (string) $r->slug, $repo->listAll() );
        $this->assertContains(
            'zz-shared-test-action',
            $slugs,
            'an action authored under one set must be visible under another'
        );
    }
}
