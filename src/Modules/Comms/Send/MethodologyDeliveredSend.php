<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Recipients\TeamHeadCoachLookup;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Templates\MethodologyDeliveredTemplate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MethodologyDeliveredSend (#3220, epic #2600) — use case 10's trigger.
 *
 * ## Why this needed a migration first
 *
 * The template has promised coaches that "the activity plan is published"
 * since #0066, and until #3220 there was nothing to fire it from: the
 * Methodology module contains no `do_action()` at all, and no table had a
 * state meaning "ready for the coaches to read". `visibility` on
 * `tt_training_plans` is an access scope, not a lifecycle.
 *
 * So publishing was built rather than approximated. The nearest existing
 * event — a plan being attached to a Tuesday — means "this runs on
 * Tuesday", and firing a message whose copy says "published" from it would
 * have told coaches something the words do not mean.
 *
 * `tt_training_plan_published` is edge-triggered in
 * `TrainingPlansRepository::publish()`, so a plan announces once and not
 * again when somebody corrects it.
 *
 * ## Who is told
 *
 * Head coaches. A plan that names a team tells that team's head coach; a
 * club-wide plan tells every team's. That is the audience the template
 * itself declares ("Sender: HoD. Recipients: coaches"), and it reuses
 * `TeamHeadCoachLookup` rather than growing a second copy of the query
 * that decides who hears about a squad's work.
 *
 * ## The focus token
 *
 * `{focus_summary}` has no dedicated column, and #2605 established what to
 * do about a token with no source: never print an empty label. It resolves
 * to the plan's theme, falling back to the first line of its notes, and
 * finally to a plain sentence pointing at the plan. The message always
 * says something true.
 */
final class MethodologyDeliveredSend {

    public static function init(): void {
        add_action( 'tt_training_plan_published', [ __CLASS__, 'handle' ], 10, 2 );
    }

    /**
     * Action-hook entry point. `do_action()` has nowhere to put a return
     * value; the obligation here is the audit trail.
     */
    public static function handle( int $plan_id, int $club_id = 0 ): void {
        self::send( $plan_id );
    }

    /**
     * @return CommsResult[]
     */
    public static function send( int $plan_id ): array {
        if ( $plan_id <= 0 ) return [];

        $plan = self::loadPlan( $plan_id );
        if ( $plan === null ) return [];

        return CommsDispatcher::dispatchSync(
            MethodologyDeliveredTemplate::KEY,
            self::payload( $plan ),
            self::recipients( (int) ( $plan->team_id ?? 0 ) ),
            [
                'message_type'   => MessageType::METHODOLOGY_DELIVERED,
                'sender_user_id' => get_current_user_id(),
            ]
        );
    }

    /**
     * The four tokens the template declares.
     *
     * @return array<string, scalar|null>
     */
    private static function payload( object $plan ): array {
        $hod = wp_get_current_user();

        return [
            'plan_title'    => (string) ( $plan->title ?? '' ),
            'focus_summary' => self::focus( $plan ),
            'deep_link'     => add_query_arg(
                [ 'tt_view' => 'training-plans', 'id' => (int) ( $plan->id ?? 0 ) ],
                RecordLink::dashboardUrl()
            ),
            'hod_name'      => $hod instanceof \WP_User ? (string) $hod->display_name : '',
        ];
    }

    /**
     * What this plan is for, in one line, never empty.
     *
     * The theme is the closest thing the plan has to a stated focus. Notes
     * are the fallback because a coach who wrote a paragraph about the
     * week has already said what the focus is; only the first line is used,
     * since this lands in the body of an email and not on a page.
     */
    private static function focus( object $plan ): string {
        $theme = trim( (string) ( $plan->theme_key ?? '' ) );
        if ( $theme !== '' ) return $theme;

        $notes = trim( (string) ( $plan->notes ?? '' ) );
        if ( $notes !== '' ) {
            $first = trim( (string) strtok( $notes, "\n" ) );
            if ( $first !== '' ) {
                return mb_strimwidth( $first, 0, 160, '…' );
            }
        }

        return __( 'See the plan for what this training is working on.', 'talenttrack' );
    }

    /**
     * Head coaches to tell.
     *
     * A team-scoped plan reaches that team's head coach; a club-wide one
     * reaches every team's. Deduplicated, because one person can be head
     * coach of two squads and should get one message.
     *
     * @return Recipient[]
     */
    private static function recipients( int $team_id ): array {
        $team_ids = $team_id > 0 ? [ $team_id ] : self::activeTeamIds();
        if ( ! $team_ids ) return [];

        $out = [];
        foreach ( TeamHeadCoachLookup::forTeams( $team_ids ) as $user_id ) {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) continue;
            $out[ $user_id ] = Recipient::coach( $user_id );
        }

        return array_values( $out );
    }

    /** @return list<int> */
    private static function activeTeamIds(): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_teams WHERE club_id = %d AND archived_at IS NULL",
            CurrentClub::id()
        ) );

        return array_map( 'intval', $rows );
    }

    private static function loadPlan( int $plan_id ): ?object {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title, notes, theme_key, team_id, is_template
               FROM {$p}tt_training_plans
              WHERE id = %d AND club_id = %d
              LIMIT 1",
            $plan_id,
            CurrentClub::id()
        ) );

        return $row ?: null;
    }
}
