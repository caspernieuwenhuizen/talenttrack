<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\FrontendMyTeamView;

/**
 * #4396 — the trend chip on My team told a parent about "your progress" and
 * "your first evaluation". A reader who is not the player is told about the
 * child by first name; the player's own view keeps the second person.
 */
final class MyTeamTrendChipVoiceTest extends WP_UnitTestCase {

    /** @var int */
    private $team;

    /** @var object */
    private $child;

    /** @var int */
    private $parent;

    /** @var int */
    private $childUser;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'JO9-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->childUser = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $this->team,
            'first_name' => 'Koen',
            'last_name'  => 'De Wit',
            'status'     => 'active',
            'wp_user_id' => $this->childUser,
        ] );
        $this->child = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d",
            (int) $wpdb->insert_id
        ) );

        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => (int) $this->child->id,
            'parent_user_id' => $this->parent,
            'is_primary'     => 1,
        ] );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_parent_without_evaluations_reads_about_the_child(): void {
        wp_set_current_user( $this->parent );

        $chip = $this->chip();

        $this->assertStringContainsString( 'Progress of Koen', $chip );
        $this->assertStringContainsString( "Koen's rating trend will appear after the first evaluation.", $chip );
        $this->assertStringNotContainsStringIgnoringCase( 'your', $chip );
    }

    public function test_a_parent_after_one_evaluation_reads_about_the_child(): void {
        $this->seedEvaluation();
        wp_set_current_user( $this->parent );

        $chip = $this->chip();

        $this->assertStringContainsString( 'average so far', $chip, 'the fixture must reach the one-evaluation branch' );
        $this->assertStringContainsString( "Koen's trend appears after the next evaluation.", $chip );
        $this->assertStringNotContainsStringIgnoringCase( 'your', $chip );
    }

    public function test_the_player_keeps_the_own_wording(): void {
        wp_set_current_user( $this->childUser );

        $chip = $this->chip();

        $this->assertStringContainsString( 'Your progress', $chip );
        $this->assertStringContainsString( 'Your rating trend will appear after your first evaluation.', $chip );
        $this->assertStringNotContainsString( 'Progress of Koen', $chip );
    }

    public function test_the_player_after_one_evaluation_keeps_the_own_wording(): void {
        $this->seedEvaluation();
        wp_set_current_user( $this->childUser );

        $this->assertStringContainsString( 'Your trend appears after your next evaluation.', $this->chip() );
    }

    private function chip(): string {
        ob_start();
        FrontendMyTeamView::render( $this->child );
        $html = (string) ob_get_clean();
        $this->assertSame( 1, preg_match( '/<div class="tt-mt-trend-chip">(.*?)<\/div>/s', $html, $m ), 'the trend chip must render' );
        return html_entity_decode( $m[1], ENT_QUOTES );
    }

    private function seedEvaluation(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_evaluations', [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => (int) $this->child->id,
            'coach_id'  => 1,
            'eval_date' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
        ] );
        $wpdb->insert( $wpdb->prefix . 'tt_eval_ratings', [
            'club_id'       => (int) CurrentClub::id(),
            'evaluation_id' => (int) $wpdb->insert_id,
            'category_id'   => 1,
            'rating'        => 7,
        ] );
    }
}
