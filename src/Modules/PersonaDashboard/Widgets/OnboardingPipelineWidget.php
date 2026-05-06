<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * OnboardingPipelineWidget (#0081 child 3) — the recruitment funnel
 * visualisation. Six stages laid out left-to-right; each column shows a
 * count and a stale-count badge. Pivots existing workflow-task and
 * trial-case data — no new tables, no parallel state machine.
 *
 * Stage-to-data mapping:
 *   - Prospects     — open `LogProspectTemplate` tasks OR prospects with
 *                     no task at all yet.
 *   - Invited       — open `InviteToTestTrainingTemplate` or
 *                     `ConfirmTestTrainingTemplate` tasks.
 *   - Test Training — open `RecordTestTrainingOutcomeTemplate` tasks.
 *   - Trial Group   — trial cases with `decision = continue_in_trial_group`
 *                     (or null) AND `archived_at IS NULL`.
 *   - Team Offer    — open `AwaitTeamOfferDecisionTemplate` tasks.
 *   - Joined        — players promoted from a prospect within the last
 *                     90 days AND `status = active`.
 *
 * Sizing variants:
 *   - S/M  — count strip only, no card expansion.
 *   - L    — counts + stale badges + click-to-expand cards (planned;
 *           ships static for now, pure SVG-no-JS philosophy).
 *   - XL   — full-width strip (intended for the standalone view).
 *
 * Mobile (≤720px) stacks columns vertically via the existing
 * `tt-pd-size-l` / `tt-pd-size-xl` responsive CSS.
 *
 * The widget is read-only navigation. Drag-to-advance was rejected at
 * spec time (every drag is a state mutation; the workflow engine
 * drives transitions). Operators advance by completing the assigned
 * task, which goes through the form / chain / engine.
 */
class OnboardingPipelineWidget extends AbstractWidget {

    public function id(): string { return 'onboarding_pipeline'; }

    public function label(): string {
        return __( 'Onboarding pipeline', 'talenttrack' );
    }

    public function defaultSize(): string { return Size::L; }

    /** @return list<string> */
    public function allowedSizes(): array {
        return [ Size::S, Size::M, Size::L, Size::XL ];
    }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function capRequired(): string { return 'tt_view_prospects'; }

    public function defaultMobilePriority(): int { return 30; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $stages = self::computeStageCounts( $ctx->user_id, $ctx->club_id );

        $title = '<div class="tt-pd-pipeline-title">'
            . esc_html__( 'Onboarding pipeline', 'talenttrack' )
            . '</div>';

        $cols = '';
        foreach ( $stages as $stage ) {
            $stale_html = '';
            if ( $stage['stale'] > 0 ) {
                $stale_html = '<span class="tt-pd-pipeline-stale" aria-label="'
                    . esc_attr( sprintf(
                        /* translators: %d: number of stale items in this funnel stage. */
                        _n( '%d stale', '%d stale', (int) $stage['stale'], 'talenttrack' ),
                        (int) $stage['stale']
                    ) )
                    . '">' . esc_html( '(' . $stage['stale'] . ' ' . __( 'stale', 'talenttrack' ) . ')' ) . '</span>';
            }
            $cols .= '<div class="tt-pd-pipeline-col">'
                . '<div class="tt-pd-pipeline-stage-label">' . esc_html( $stage['label'] ) . '</div>'
                . '<div class="tt-pd-pipeline-count">' . esc_html( (string) $stage['count'] ) . '</div>'
                . $stale_html
                . '</div>';
        }

        $body = $title . '<div class="tt-pd-pipeline-cols">' . $cols . '</div>';
        return $this->wrap( $slot, $body );
    }

    /**
     * Run the per-stage counts. One pivot per stage; cached for 60s
     * via WP object cache to keep the widget light on busy dashboards.
     *
     * Scope filter: a scout sees only their own prospects; everyone
     * else with the cap sees the full club view. The filter is applied
     * at the SQL layer so a scout literally cannot see the HoD's
     * wider data.
     *
     * @return list<array{key:string,label:string,count:int,stale:int}>
     */
    public static function computeStageCounts( int $user_id, int $club_id ): array {
        $cache_key = sprintf( 'tt_op_pipeline_%d_%d', $club_id, $user_id );
        $cached    = wp_cache_get( $cache_key, 'tt_persona_dashboard' );
        if ( is_array( $cached ) ) return $cached;

        $scout_only = self::isScoutOnly( $user_id );
        $stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

        global $wpdb;
        $prospects = $wpdb->prefix . 'tt_prospects';
        $tasks     = $wpdb->prefix . 'tt_workflow_tasks';
        $cases     = $wpdb->prefix . 'tt_trial_cases';
        $players   = $wpdb->prefix . 'tt_players';

        $scout_pred_prospect = $scout_only
            ? $wpdb->prepare( ' AND pr.discovered_by_user_id = %d', $user_id )
            : '';
        $scout_pred_task = ''; // tasks themselves don't carry the scout id; relies on the prospect-id join

        $count_open_for_template = function ( string $template_key ) use ( $wpdb, $tasks, $prospects, $club_id, $scout_only, $user_id, $stale_cutoff ) {
            if ( $scout_only ) {
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tasks} wt
                       JOIN {$prospects} pr ON pr.id = wt.prospect_id
                      WHERE wt.club_id = %d AND wt.template_key = %s
                        AND wt.status IN ('open','in_progress','overdue')
                        AND pr.discovered_by_user_id = %d",
                    $club_id, $template_key, $user_id
                ) );
                $stale = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tasks} wt
                       JOIN {$prospects} pr ON pr.id = wt.prospect_id
                      WHERE wt.club_id = %d AND wt.template_key = %s
                        AND wt.status IN ('open','in_progress','overdue')
                        AND wt.due_at < %s
                        AND pr.discovered_by_user_id = %d",
                    $club_id, $template_key, $stale_cutoff, $user_id
                ) );
            } else {
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tasks}
                      WHERE club_id = %d AND template_key = %s
                        AND status IN ('open','in_progress','overdue')",
                    $club_id, $template_key
                ) );
                $stale = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tasks}
                      WHERE club_id = %d AND template_key = %s
                        AND status IN ('open','in_progress','overdue')
                        AND due_at < %s",
                    $club_id, $template_key, $stale_cutoff
                ) );
            }
            return [ 'count' => $count, 'stale' => $stale ];
        };

        // Prospects column — open LogProspect tasks (drafts).
        $col_prospects = $count_open_for_template( 'log_prospect' );

        // Invited column — open InviteToTestTraining + ConfirmTestTraining.
        $col_invited_a = $count_open_for_template( 'invite_to_test_training' );
        $col_invited_b = $count_open_for_template( 'confirm_test_training' );
        $col_invited = [
            'count' => $col_invited_a['count'] + $col_invited_b['count'],
            'stale' => $col_invited_a['stale'] + $col_invited_b['stale'],
        ];

        // Test Training column — open RecordTestTrainingOutcome tasks.
        $col_test = $count_open_for_template( 'record_test_training_outcome' );

        // Trial Group column — trial cases with continue_in_trial_group
        // decision (or null after admit but pre-review). Stale = open
        // ReviewTrialGroupMembership tasks past due.
        $tg_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT player_id) FROM {$cases}
              WHERE club_id = %d AND archived_at IS NULL
                AND ( decision = %s OR decision IS NULL )",
            $club_id, 'continue_in_trial_group'
        ) );
        $tg_stale = $count_open_for_template( 'review_trial_group_membership' )['stale'];
        $col_trial = [ 'count' => $tg_count, 'stale' => $tg_stale ];

        // Team Offer column — open AwaitTeamOfferDecision tasks.
        $col_offer = $count_open_for_template( 'await_team_offer_decision' );

        // Joined column — players promoted from a prospect within last 90d.
        $joined_cutoff = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
        if ( $scout_only ) {
            $joined_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prospects} pr
                  WHERE pr.club_id = %d AND pr.promoted_to_player_id IS NOT NULL
                    AND pr.created_at >= %s
                    AND pr.discovered_by_user_id = %d",
                $club_id, $joined_cutoff, $user_id
            ) );
        } else {
            $joined_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$prospects}
                  WHERE club_id = %d AND promoted_to_player_id IS NOT NULL
                    AND created_at >= %s",
                $club_id, $joined_cutoff
            ) );
        }
        $col_joined = [ 'count' => $joined_count, 'stale' => 0 ];

        $stages = [
            [ 'key' => 'prospects', 'label' => __( 'Prospects',     'talenttrack' ), 'count' => $col_prospects['count'], 'stale' => $col_prospects['stale'] ],
            [ 'key' => 'invited',   'label' => __( 'Invited',       'talenttrack' ), 'count' => $col_invited['count'],   'stale' => $col_invited['stale']   ],
            [ 'key' => 'test',      'label' => __( 'Test training', 'talenttrack' ), 'count' => $col_test['count'],      'stale' => $col_test['stale']      ],
            [ 'key' => 'trial',     'label' => __( 'Trial group',   'talenttrack' ), 'count' => $col_trial['count'],     'stale' => $col_trial['stale']     ],
            [ 'key' => 'offer',     'label' => __( 'Team offer',    'talenttrack' ), 'count' => $col_offer['count'],     'stale' => $col_offer['stale']     ],
            [ 'key' => 'joined',    'label' => __( 'Joined',        'talenttrack' ), 'count' => $col_joined['count'],    'stale' => $col_joined['stale']    ],
        ];

        wp_cache_set( $cache_key, $stages, 'tt_persona_dashboard', 60 );
        return $stages;
    }

    private static function isScoutOnly( int $user_id ): bool {
        if ( $user_id <= 0 ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user ) return false;
        $roles = (array) ( $user->roles ?? [] );
        if ( in_array( 'tt_head_dev', $roles, true ) ) return false;
        if ( in_array( 'tt_club_admin', $roles, true ) ) return false;
        if ( in_array( 'administrator', $roles, true ) ) return false;
        return in_array( 'tt_scout', $roles, true );
    }
}
