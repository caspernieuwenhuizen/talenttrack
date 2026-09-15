<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;

/**
 * #3382 — a family cannot mute "a training is cancelled".
 *
 * The product already treated this message as urgent in one direction: it
 * has bypassed quiet hours since the module shipped, so it will reach a
 * family at 23:00. It was simultaneously mutable, which meant the same
 * message the product will wake you for was one you could switch off
 * entirely. A muted cancellation means a child dropped at a pitch nobody
 * came to.
 *
 * The suffix convention could not express this, because the constant's
 * *value* is persisted in `tt_comms_log` and `tt_comms_optouts` — renaming
 * it would orphan the history. Hence the explicit policy list.
 */
final class CancellationIsOperationalTest extends WP_UnitTestCase {

    public function test_a_cancellation_is_operational(): void {
        $this->assertTrue( MessageType::isOperational( MessageType::TRAINING_CANCELLED ) );
    }

    public function test_it_is_no_longer_offered_as_something_to_mute(): void {
        $this->assertNotContains( MessageType::TRAINING_CANCELLED, MessageType::optOutable() );
    }

    public function test_it_still_bypasses_quiet_hours(): void {
        // This held before the change and must still hold: the point was to
        // make the two halves agree, not to trade one for the other.
        $this->assertTrue( MessageType::bypassesQuietHours( MessageType::TRAINING_CANCELLED ) );
    }

    public function test_the_suffix_convention_still_works(): void {
        // The explicit list is an addition, not a replacement.
        $this->assertTrue( MessageType::isOperational( MessageType::SAFEGUARDING_BROADCAST ) );
        $this->assertTrue( MessageType::isOperational( MessageType::ACCOUNT_RECOVERY ) );
    }

    public function test_an_ordinary_type_is_still_mutable(): void {
        $this->assertFalse( MessageType::isOperational( MessageType::GOAL_NUDGE ) );
        $this->assertContains( MessageType::GOAL_NUDGE, MessageType::optOutable() );
    }

    public function test_a_recipient_who_muted_everything_still_gets_a_cancellation(): void {
        $user   = (int) self::factory()->user->create();
        $policy = new OptOutPolicy();

        foreach ( MessageType::optOutable() as $type ) {
            $policy->setOptedOut( $user, $type, true );
        }

        $this->assertFalse(
            $policy->isOptedOut( $user, MessageType::TRAINING_CANCELLED ),
            'a cancellation reaches a recipient who has muted everything mutable'
        );
    }

    public function test_a_pre_existing_opt_out_row_is_ignored(): void {
        // Migration 0258 deletes these, but the policy must not depend on the
        // migration having run — a row written before this release, or by an
        // older client, must not suppress the message.
        global $wpdb;

        $user  = (int) self::factory()->user->create();
        $table = $wpdb->prefix . 'tt_comms_optouts';

        $wpdb->insert( $table, array_merge(
            [ 'user_id' => $user, 'message_type' => MessageType::TRAINING_CANCELLED ],
            \TT\Infrastructure\Query\QueryHelpers::clubScopeInsertColumn()
        ) );

        $this->assertFalse(
            ( new OptOutPolicy() )->isOptedOut( $user, MessageType::TRAINING_CANCELLED )
        );
    }

    public function test_the_migration_clears_the_stale_rows(): void {
        global $wpdb;

        $user  = (int) self::factory()->user->create();
        $table = $wpdb->prefix . 'tt_comms_optouts';

        $wpdb->insert( $table, array_merge(
            [ 'user_id' => $user, 'message_type' => MessageType::TRAINING_CANCELLED ],
            \TT\Infrastructure\Query\QueryHelpers::clubScopeInsertColumn()
        ) );
        // A row for a type that stays mutable — the migration must leave it.
        $wpdb->insert( $table, array_merge(
            [ 'user_id' => $user, 'message_type' => MessageType::GOAL_NUDGE ],
            \TT\Infrastructure\Query\QueryHelpers::clubScopeInsertColumn()
        ) );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0258_drop_cancellation_optouts.php';
        $migration->up();

        $remaining = $wpdb->get_col( $wpdb->prepare(
            "SELECT message_type FROM {$table} WHERE user_id = %d",
            $user
        ) );

        $this->assertNotContains( MessageType::TRAINING_CANCELLED, $remaining );
        $this->assertContains( MessageType::GOAL_NUDGE, $remaining );
    }
}
