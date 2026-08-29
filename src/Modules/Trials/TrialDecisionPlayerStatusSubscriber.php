<?php
namespace TT\Modules\Trials;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerStatus;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrialDecisionPlayerStatusSubscriber (#3116) — makes the player record
 * agree with the decision the academy made.
 *
 * Recording a decision used to update the trial-case row and nothing
 * else. `tt_trial_decision_recorded` had one listener,
 * `JourneyEventSubscriber`, which writes the timeline and deliberately
 * does not touch the player. So an admitted player stayed on `trial`
 * status indefinitely: the academy said yes to a child and the record
 * did not show it.
 *
 * ## Why a class of its own
 *
 * `JourneyEventSubscriber`'s docblock claims to be "the only place where
 * journey-specific reactions to those hooks live". Mutating a player's
 * status is not a journey reaction — the journey entry is the *record* of
 * the transition, this is the transition. Folding it in there would make
 * that claim false and put a write with cascade consequences inside a
 * class people read as append-only.
 *
 * ## Why a subscriber rather than the REST controller
 *
 * `tt_trial_decision_recorded` already fires from the single decision
 * path and carries `( $case_id, $player_id, $decision, $decided_at )` —
 * everything needed. Hanging the behaviour there means the UI and the API
 * cannot diverge, which is the failure mode #3115 exists to fix on the
 * neighbouring surface.
 *
 * ## The mapping
 *
 * | decision                    | player status | also |
 * | --------------------------- | ------------- | ---- |
 * | `admit`                     | `active`      | — |
 * | `deny_final`                | `released`    | archived through `ArchiveRepository` |
 * | `deny_encouragement`        | `inactive`    | **not** archived |
 * | `declined_offered_position` | `inactive`    | the family said no; the club did not release them |
 * | `offered_team_position`     | unchanged     | nothing is decided — the family has not answered |
 * | `continue_in_trial_group`   | unchanged     | the trial is explicitly still running |
 *
 * The distinction that matters is the third row. *Decline with
 * encouragement* means "not now, come back"; archiving that family's
 * record says the opposite of what the club just told them. `inactive`
 * keeps the player on the books and findable next season. Only
 * `deny_final` ends the relationship, and only `deny_final` archives.
 *
 * `PlayerStatus` has no value meaning "declined but welcome back", and
 * this does not add one: the question "who did we turn away but want to
 * see again?" is answered from `tt_trial_cases.decision`, where the
 * decision actually lives, rather than from a player column that would
 * duplicate it and go stale the moment the player trials again.
 *
 * ## Two traps, both deliberate
 *
 * **1. The write must not fire `tt_player_save_diff`.**
 * `JourneyEventSubscriber::on_trial_decision_recorded()` already emits
 * `SIGNED` for `admit` and `RELEASED` for `deny_final`, and its
 * `emitStatusTransition()` emits those same two events off a status diff.
 * `tt_player_save_diff` has exactly one emitter — the players REST
 * controller — so a direct club-scoped `$wpdb->update` here does not fire
 * it and the events stay single. That is the reason this does not route
 * through the players REST create/update path, and it is not an
 * oversight: routing it there would double every SIGNED and RELEASED
 * event on the timeline.
 *
 * **2. Only a player currently on `trial` moves.** A decision recorded
 * twice, or recorded against a player another path already promoted,
 * must not walk an `active` player back to `inactive`. The guard makes
 * the second run a no-op.
 *
 * Precedent for the write shape:
 * `Modules\Workflow\Forms\AwaitTeamOfferDecisionForm`, whose own comment
 * records that this same class of gap has bitten here once before.
 */
final class TrialDecisionPlayerStatusSubscriber {

    /**
     * Decision => the player status it settles on. A decision absent
     * from this map leaves the player where they are, which is the right
     * answer for the two that mean "still running".
     *
     * @var array<string, string>
     */
    private const STATUS_BY_DECISION = [
        TrialCaseDecision::ADMIT                     => PlayerStatus::ACTIVE,
        TrialCaseDecision::DENY_FINAL                => PlayerStatus::RELEASED,
        TrialCaseDecision::DENY_ENCOURAGEMENT        => PlayerStatus::INACTIVE,
        TrialCaseDecision::DECLINED_OFFERED_POSITION => PlayerStatus::INACTIVE,
    ];

    public static function init(): void {
        add_action( 'tt_trial_decision_recorded', [ __CLASS__, 'onDecisionRecorded' ], 10, 4 );
    }

    /**
     * @param int    $case_id
     * @param int    $player_id
     * @param string $decision
     * @param string $decided_at Unused — the trial case row is the record
     *                           of when; the player row carries state, not
     *                           history.
     */
    public static function onDecisionRecorded( int $case_id, int $player_id, string $decision, string $decided_at = '' ): void {
        if ( $player_id <= 0 ) return;

        $status = self::STATUS_BY_DECISION[ $decision ] ?? '';
        if ( $status === '' ) return;

        global $wpdb;

        // Trap 2 — only a player still on trial moves. Recording the same
        // decision twice, or deciding on a player another path already
        // promoted, must not walk them backwards.
        $updated = (int) $wpdb->update(
            $wpdb->prefix . 'tt_players',
            [ 'status' => $status ],
            [
                'id'      => $player_id,
                'club_id' => CurrentClub::id(),
                'status'  => PlayerStatus::TRIAL,
            ]
        );

        if ( $updated <= 0 ) return;

        // Only the final decline ends the relationship, so only the final
        // decline archives. Through `ArchiveRepository` rather than a raw
        // `archived_at` write, so the row joins the archive → trash → purge
        // lifecycle (#2018) with its audit entry and stays restorable.
        if ( $decision === TrialCaseDecision::DENY_FINAL ) {
            ( new ArchiveRepository() )->archive( 'player', [ $player_id ], get_current_user_id() );
        }
    }
}
