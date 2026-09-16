<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Query\LabelTranslator;

/**
 * #3471 — one column, two stored casings, one of them untranslatable.
 *
 * `tt_goals.status` holds the snake_case code on some write paths and the
 * TitleCase `goal_status` lookup label on others (the lookup is seeded
 * TitleCase by `Activator` and `LookupCanonicalSeeds`; `GoalPastTargetDateAlert`
 * documents display-cased values from older imports as well).
 *
 * `goalStatus()` switched on the raw value, so a goal stored as `In Progress`
 * missed every arm and `humanise()` handed the English word straight back —
 * on the player's own "My goals", on the parent's view of their child's, and
 * on the coach surfaces, all of which read the same `status_localised`.
 *
 * `FrontendMyGoalsView::bucketFor()` already normalised, which is why the goal
 * landed in the right column wearing an untranslated chip.
 *
 * Asserting on identity between the two casings rather than on Dutch strings:
 * the test suite runs in the default locale, so the point to pin is that both
 * shapes resolve to the *same* label and neither falls through to the raw
 * value.
 */
final class LabelTranslatorStatusCaseTest extends WP_UnitTestCase {

    /** @return array<string, array{0:string, 1:string}> code => [code, stored label] */
    public static function goalStatusPairs(): array {
        return [
            'pending'          => [ GoalStatus::PENDING,          'Pending' ],
            'pending approval' => [ GoalStatus::PENDING_APPROVAL, 'Pending Approval' ],
            'in progress'      => [ GoalStatus::IN_PROGRESS,      'In Progress' ],
            'completed'        => [ GoalStatus::COMPLETED,        'Completed' ],
            'on hold'          => [ GoalStatus::ON_HOLD,          'On Hold' ],
            'cancelled'        => [ GoalStatus::CANCELLED,        'Cancelled' ],
        ];
    }

    /**
     * @dataProvider goalStatusPairs
     */
    public function test_goal_status_resolves_the_same_for_code_and_lookup_label( string $code, string $stored_label ): void {
        $from_code  = LabelTranslator::goalStatus( $code );
        $from_label = LabelTranslator::goalStatus( $stored_label );

        $this->assertSame(
            $from_code,
            $from_label,
            "Stored label '{$stored_label}' must resolve to the same status label as code '{$code}'."
        );
    }

    /**
     * The regression itself: the stored TitleCase value must not come back
     * unchanged, which is what `humanise()` did for every unmatched arm.
     */
    public function test_a_titlecase_status_is_not_echoed_back_verbatim(): void {
        $this->assertNotSame(
            'In Progress',
            LabelTranslator::goalStatus( 'In Progress' ),
            'A TitleCase status fell through to humanise() and printed the raw English.'
        );
    }

    /** Every constant on the enum has an arm — no status humanises. */
    public function test_every_goal_status_constant_has_an_arm(): void {
        foreach ( GoalStatus::ALL as $code ) {
            $this->assertNotSame(
                LabelTranslator::goalStatus( $code . '_no_such_status' ),
                LabelTranslator::goalStatus( $code ),
                "GoalStatus '{$code}' has no arm in goalStatus() and is falling through to humanise()."
            );
        }
    }

    /** Leading/trailing whitespace from an import must not defeat the match. */
    public function test_surrounding_whitespace_is_tolerated(): void {
        $this->assertSame(
            LabelTranslator::goalStatus( GoalStatus::ON_HOLD ),
            LabelTranslator::goalStatus( '  On Hold  ' )
        );
    }

    /** Same fold applied to player status, seeded TitleCase for the same reason. */
    public function test_player_status_resolves_the_same_for_code_and_lookup_label(): void {
        $this->assertSame(
            LabelTranslator::playerStatus( 'active' ),
            LabelTranslator::playerStatus( 'Active' )
        );
    }
}
