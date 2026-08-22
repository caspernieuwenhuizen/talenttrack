<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;

/**
 * #2638 — opt-outs live in a club-scoped table, not `wp_usermeta`.
 *
 * The behaviour must be identical to the usermeta version, so most of this
 * asserts the semantics did not shift: absence means opted in, operational
 * types can never be muted, and a repeat opt-out is idempotent.
 *
 * The one genuinely new assertion is the tenancy one. It is also the reason
 * the issue existed: with usermeta there was no seam at all between clubs,
 * so a parent with children at two academies would have muted a message type
 * at both or neither.
 */
final class CommsOptOutStorageTest extends WP_UnitTestCase {

    /** @var OptOutPolicy */
    private $policy;

    /** @var int */
    private $user;

    public function set_up(): void {
        parent::set_up();
        OptOutPolicy::flushTableCache();
        $this->policy = new OptOutPolicy();
        $this->user   = self::factory()->user->create();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_comms_optouts" );
    }

    public function tear_down(): void {
        OptOutPolicy::flushTableCache();
        parent::tear_down();
    }

    // ── semantics preserved from the usermeta implementation ───────────

    public function test_absence_of_a_row_means_opted_in(): void {
        $this->assertFalse( $this->policy->isOptedOut( $this->user, MessageType::GOAL_NUDGE ) );
    }

    public function test_opting_out_then_back_in_round_trips(): void {
        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );
        $this->assertTrue( $this->policy->isOptedOut( $this->user, MessageType::GOAL_NUDGE ) );

        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, false );
        $this->assertFalse( $this->policy->isOptedOut( $this->user, MessageType::GOAL_NUDGE ) );
    }

    public function test_opting_out_of_one_type_does_not_mute_another(): void {
        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );

        $this->assertTrue( $this->policy->isOptedOut( $this->user, MessageType::GOAL_NUDGE ) );
        $this->assertFalse( $this->policy->isOptedOut( $this->user, MessageType::ATTENDANCE_FLAG ) );
    }

    public function test_operational_types_can_never_be_muted(): void {
        $this->policy->setOptedOut( $this->user, MessageType::SAFEGUARDING_BROADCAST, true );

        $this->assertFalse(
            $this->policy->isOptedOut( $this->user, MessageType::SAFEGUARDING_BROADCAST ),
            'safeguarding broadcasts must reach every recipient regardless of preference'
        );
    }

    public function test_anonymous_user_is_never_opted_out(): void {
        $this->assertFalse( $this->policy->isOptedOut( 0, MessageType::GOAL_NUDGE ) );
    }

    // ── the new storage's own guarantees ───────────────────────────────

    /**
     * A second opt-out must not move `opted_out_at`. When someone muted a
     * message type is worth being able to answer, and a repeat toggle from a
     * settings screen should not rewrite that history.
     */
    public function test_opting_out_twice_is_idempotent_and_keeps_the_original_timestamp(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_optouts';

        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );
        $first = $wpdb->get_var( $wpdb->prepare(
            "SELECT opted_out_at FROM {$table} WHERE user_id = %d", $this->user
        ) );

        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );

        $this->assertSame(
            1,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $this->user
            ) ),
            'the unique key must prevent a duplicate row'
        );
        $this->assertSame( $first, $wpdb->get_var( $wpdb->prepare(
            "SELECT opted_out_at FROM {$table} WHERE user_id = %d", $this->user
        ) ) );
    }

    /**
     * The reason this issue existed. With usermeta there was no seam between
     * clubs at all, so a parent with children at two academies muted a
     * message type at both or neither.
     */
    public function test_an_opt_out_at_one_club_does_not_apply_at_another(): void {
        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );
        $this->assertTrue( $this->policy->isOptedOut( $this->user, MessageType::GOAL_NUDGE ) );

        $other_club = static function (): int { return 2; };
        add_filter( 'tt_current_club_id', $other_club, 9999 );
        try {
            $this->assertFalse(
                ( new OptOutPolicy() )->isOptedOut( $this->user, MessageType::GOAL_NUDGE ),
                'club 2 must not inherit club 1\'s opt-out'
            );
        } finally {
            remove_filter( 'tt_current_club_id', $other_club, 9999 );
        }
    }

    public function test_opting_back_in_only_clears_this_clubs_row(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_optouts';

        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );

        $other_club = static function (): int { return 2; };
        add_filter( 'tt_current_club_id', $other_club, 9999 );
        try {
            ( new OptOutPolicy() )->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );
            ( new OptOutPolicy() )->setOptedOut( $this->user, MessageType::GOAL_NUDGE, false );
        } finally {
            remove_filter( 'tt_current_club_id', $other_club, 9999 );
        }

        $this->assertTrue(
            $this->policy->isOptedOut( $this->user, MessageType::GOAL_NUDGE ),
            'clearing club 2 must leave club 1 alone'
        );
        $this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $this->user
        ) ) );
    }

    public function test_opted_out_types_lists_only_this_users_muted_types(): void {
        $other = self::factory()->user->create();
        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );
        $this->policy->setOptedOut( $this->user, MessageType::ATTENDANCE_FLAG, true );
        $this->policy->setOptedOut( $other, MessageType::PDP_READY, true );

        $this->assertSame(
            [ MessageType::ATTENDANCE_FLAG, MessageType::GOAL_NUDGE ],
            $this->policy->optedOutTypesFor( $this->user )
        );
        $this->assertSame( [ MessageType::PDP_READY ], $this->policy->optedOutTypesFor( $other ) );
    }

    /**
     * No `get_user_meta` path survives. The point of #2638 is that the
     * usermeta storage is gone, not merely bypassed — a stale reader left
     * behind would answer differently from the writer.
     */
    public function test_no_optout_usermeta_is_written(): void {
        $this->policy->setOptedOut( $this->user, MessageType::GOAL_NUDGE, true );

        global $wpdb;
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
              WHERE user_id = %d AND meta_key LIKE 'tt_comms_optout%%'",
            $this->user
        ) ) );
    }
}
