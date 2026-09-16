<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\SubjectVoice;

/**
 * #3477 — three readers, three voices.
 *
 * A player-subject surface is read by the player, by their guardian and by
 * staff. Views used to decide this with `$is_self`, which folds the guardian
 * into whichever of the other two the author was not thinking of — so a
 * parent read "Jouw focus" about their child, and on the PDP page a coach was
 * treated as the parent (`$is_parent = ! $is_self`) and offered the parent
 * acknowledgement control.
 *
 * This pins the resolution, so a fourth instance of that class fails here
 * rather than shipping.
 */
final class SubjectVoiceTest extends WP_UnitTestCase {

    private int $player_uid = 0;
    private int $parent_uid = 0;
    private object $player;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->player_uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Bas',
            'last_name'  => 'Willems',
            'status'     => 'active',
            'wp_user_id' => $this->player_uid,
        ] );
        $player_id    = (int) $wpdb->insert_id;
        $this->player = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d", $player_id
        ) );

        $this->parent_uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => 1,
            'player_id'      => $player_id,
            'parent_user_id' => $this->parent_uid,
            'is_primary'     => 1,
        ] );
    }

    public function test_the_player_reads_their_own_record_as_self(): void {
        $voice = SubjectVoice::forPlayer( $this->player, $this->player_uid );

        $this->assertTrue( $voice->isSelf() );
        $this->assertSame( 'mine', $voice->pick( 'mine', 'theirs', 'clinical' ) );
        $this->assertNull( $voice->linkPlayerId(), 'The player\'s own Me-views resolve the subject without player_id.' );
    }

    public function test_a_linked_guardian_reads_as_parent(): void {
        $voice = SubjectVoice::forPlayer( $this->player, $this->parent_uid );

        $this->assertTrue( $voice->isParent() );
        $this->assertFalse( $voice->isSelf() );
        $this->assertSame( 'theirs', $voice->pick( 'mine', 'theirs', 'clinical' ) );
        $this->assertSame( (int) $this->player->id, $voice->linkPlayerId() );
    }

    /** The PDP bug: anyone who was not the player used to count as the parent. */
    public function test_staff_is_not_mistaken_for_a_parent(): void {
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $voice = SubjectVoice::forPlayer( $this->player, $coach );

        $this->assertTrue( $voice->isStaff() );
        $this->assertFalse( $voice->isParent(), 'A coach must not be offered the parent\'s controls.' );
        $this->assertSame( 'clinical', $voice->pick( 'mine', 'theirs', 'clinical' ) );
    }

    /** Without a staff-specific wording, staff read the third-person form. */
    public function test_staff_fall_back_to_the_third_person_form(): void {
        $coach = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $this->assertSame( 'theirs', SubjectVoice::forPlayer( $this->player, $coach )->pick( 'mine', 'theirs' ) );
    }

    public function test_names_resolve_and_never_come_back_empty(): void {
        $voice = SubjectVoice::forPlayer( $this->player, $this->parent_uid );

        $this->assertSame( 'Bas', $voice->firstName() );
        $this->assertStringContainsString( 'Bas', $voice->name() );

        $nameless = (object) [ 'id' => 0, 'first_name' => '', 'last_name' => '', 'wp_user_id' => 0 ];
        $blank    = SubjectVoice::forPlayer( $nameless, $this->parent_uid );
        $this->assertNotSame( '', $blank->name() );
        $this->assertNotSame( '', $blank->firstName() );
    }
}
