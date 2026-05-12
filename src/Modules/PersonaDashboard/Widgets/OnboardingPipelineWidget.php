<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\Prospects\Domain\ProspectStageClassifier;

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
     * Run the per-stage counts.
     *
     * v3.110.48 rewrote this to be `COUNT(DISTINCT prospect_id)`-driven,
     * classifying each prospect into exactly one stage. v3.110.81
     * delegates the actual classification to
     * `\TT\Modules\Prospects\Domain\ProspectStageClassifier` so the
     * kanban view and this widget can't drift on rule changes; see
     * that class for the full stage transitions.
     *
     * Key v3.110.81 rule change: a prospect with an OPEN
     * `invite_to_test_training` task counts as Prospects (we haven't
     * sent the email yet). It used to count as Invited based purely
     * on task existence, which contradicted the operator's mental
     * model and inflated the Invited column.
     *
     * Trial group counts prospects with `promoted_to_trial_case_id`
     * set rather than every `tt_trial_cases` row — the original
     * "every trial case" count conflated the two lifecycles (some
     * trial cases never came from a prospect) and confused operators
     * looking at the funnel as a recruitment view.
     *
     * Scope filter: a scout sees only their own prospects; everyone
     * else with the cap sees the full club view.
     *
     * Cached for 60s via WP object cache.
     *
     * @return list<array{key:string,label:string,count:int,stale:int}>
     */
    public static function computeStageCounts( int $user_id, int $club_id ): array {
        // v3.110.84 — cache key bumped again (`v3` suffix) because the
        // classifier's Joined-vs-Trial-group rule changed. Trial-group
        // prospects (admit_to_trial path sets both promoted_to_player_id
        // AND promoted_to_trial_case_id) were mis-classified as Joined
        // in v3.110.81-83 because the rule preferred player_id over
        // trial_case_id. Joined now requires `player.status != 'trial'`,
        // and the SQL needs the new player_status column to evaluate it.
        $cache_key = sprintf( 'tt_op_pipeline_v3_%d_%d', $club_id, $user_id );
        $cached    = wp_cache_get( $cache_key, 'tt_persona_dashboard' );
        if ( is_array( $cached ) ) return $cached;

        $scout_only = self::isScoutOnly( $user_id );
        $stale_cutoff = time() - 30 * DAY_IN_SECONDS;
        $joined_cutoff = time() - 90 * DAY_IN_SECONDS;

        global $wpdb;
        $prospects = $wpdb->prefix . 'tt_prospects';
        $tasks     = $wpdb->prefix . 'tt_workflow_tasks';
        $players   = $wpdb->prefix . 'tt_players';

        $where_scout = $scout_only
            ? $wpdb->prepare( ' AND p.discovered_by_user_id = %d', $user_id )
            : '';

        // v3.110.84 — added `player_status` (LEFT JOIN tt_players on
        // promoted_to_player_id) so the classifier can distinguish
        // Trial group (player still at status='trial') from Joined
        // (player graduated). MAX over player_status is safe because
        // the join is 1:1 — one player record per promoted_to_player_id.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.id,
                p.promoted_to_player_id,
                p.promoted_to_trial_case_id,
                p.created_at,
                MAX(pl.status) AS player_status,
                MAX(CASE WHEN wt.template_key = 'log_prospect'                 AND wt.status IN ('open','in_progress','overdue') THEN 1 ELSE 0 END) AS open_log,
                MAX(CASE WHEN wt.template_key = 'invite_to_test_training'      AND wt.status IN ('open','in_progress','overdue') THEN 1 ELSE 0 END) AS open_invite,
                MAX(CASE WHEN wt.template_key = 'confirm_test_training'        AND wt.status IN ('open','in_progress','overdue') THEN 1 ELSE 0 END) AS open_confirm,
                MAX(CASE WHEN wt.template_key = 'record_test_training_outcome' AND wt.status IN ('open','in_progress','overdue') THEN 1 ELSE 0 END) AS open_outcome,
                MAX(CASE WHEN wt.template_key = 'await_team_offer_decision'    AND wt.status IN ('open','in_progress','overdue') THEN 1 ELSE 0 END) AS open_offer,
                MAX(CASE WHEN wt.template_key = 'invite_to_test_training'      AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_invite,
                MAX(CASE WHEN wt.template_key = 'confirm_test_training'        AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_confirm,
                MAX(CASE WHEN wt.template_key = 'record_test_training_outcome' AND wt.status = 'completed' THEN 1 ELSE 0 END) AS done_outcome,
                MIN(CASE WHEN wt.status IN ('open','in_progress','overdue') THEN UNIX_TIMESTAMP(wt.due_at) ELSE NULL END) AS soonest_due_ts
              FROM {$prospects} p
              LEFT JOIN {$tasks}   wt ON wt.prospect_id = p.id AND wt.club_id = %d
              LEFT JOIN {$players} pl ON pl.id = p.promoted_to_player_id AND pl.club_id = %d
             WHERE p.club_id = %d
               AND p.archived_at IS NULL
               {$where_scout}
             GROUP BY p.id",
            $club_id, $club_id, $club_id
        ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $tally = [
            'prospects' => [ 'count' => 0, 'stale' => 0 ],
            'invited'   => [ 'count' => 0, 'stale' => 0 ],
            'test'      => [ 'count' => 0, 'stale' => 0 ],
            'trial'     => [ 'count' => 0, 'stale' => 0 ],
            'offer'     => [ 'count' => 0, 'stale' => 0 ],
            'joined'    => [ 'count' => 0, 'stale' => 0 ],
        ];

        foreach ( $rows as $row ) {
            $stage = ProspectStageClassifier::classify( $row, $joined_cutoff );
            if ( $stage === null || ! isset( $tally[ $stage ] ) ) continue;
            $tally[ $stage ]['count']++;
            // Stale = the prospect's open task (the one that defines
            // the stage's "waiting for…") has been overdue 30+ days.
            // Only applies where there IS a current open task — Trial
            // group and Joined are derived from prospect-table columns
            // and have no waiting-task semantics.
            if ( ! empty( $row->soonest_due_ts ) && (int) $row->soonest_due_ts < $stale_cutoff
                 && in_array( $stage, [ 'prospects', 'invited', 'test', 'offer' ], true ) ) {
                $tally[ $stage ]['stale']++;
            }
        }

        $stages = [
            [ 'key' => 'prospects', 'label' => __( 'Prospects',     'talenttrack' ), 'count' => $tally['prospects']['count'], 'stale' => $tally['prospects']['stale'] ],
            [ 'key' => 'invited',   'label' => __( 'Invited',       'talenttrack' ), 'count' => $tally['invited']['count'],   'stale' => $tally['invited']['stale']   ],
            [ 'key' => 'test',      'label' => __( 'Test training', 'talenttrack' ), 'count' => $tally['test']['count'],      'stale' => $tally['test']['stale']      ],
            [ 'key' => 'trial',     'label' => __( 'Trial group',   'talenttrack' ), 'count' => $tally['trial']['count'],     'stale' => $tally['trial']['stale']     ],
            [ 'key' => 'offer',     'label' => __( 'Team offer',    'talenttrack' ), 'count' => $tally['offer']['count'],     'stale' => $tally['offer']['stale']     ],
            [ 'key' => 'joined',    'label' => __( 'Joined',        'talenttrack' ), 'count' => $tally['joined']['count'],    'stale' => $tally['joined']['stale']    ],
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
