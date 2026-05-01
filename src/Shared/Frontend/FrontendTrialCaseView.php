<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Shared\Frontend\Components\StaffPickerComponent;
use TT\Modules\Trials\Letters\DefaultLetterTemplates;
use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialExtensionsRepository;
use TT\Modules\Trials\Repositories\TrialStaffInputsRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;

/**
 * FrontendTrialCaseView — six-tab case detail surface.
 *
 *   ?tt_view=trial-case&id=N&tab=overview        (default)
 *   ?tt_view=trial-case&id=N&tab=execution
 *   ?tt_view=trial-case&id=N&tab=inputs
 *   ?tt_view=trial-case&id=N&tab=decision
 *   ?tt_view=trial-case&id=N&tab=letter
 *   ?tt_view=trial-case&id=N&tab=meeting
 *
 * Per-tab visibility is enforced inside `dispatchTab()` via
 * `TrialCaseAccessPolicy`. The Decision and Letter tabs are
 * manager-only; Inputs is visible to assigned staff (own input only)
 * and managers (everyone's, with release control).
 */
class FrontendTrialCaseView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $case_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $case_id <= 0 ) {
            self::renderHeader( __( 'Trial case not found', 'talenttrack' ) );
            return;
        }

        if ( ! TrialCaseAccessPolicy::canViewSynthesis( $user_id, $case_id ) ) {
            self::renderHeader( __( 'Trial case', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You are not assigned to this case.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( $user_id, $case_id );

        $cases  = new TrialCasesRepository();
        $case   = $cases->find( $case_id );
        if ( ! $case ) {
            self::renderHeader( __( 'Trial case not found', 'talenttrack' ) );
            return;
        }

        $player = QueryHelpers::get_player( (int) $case->player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '#' . (int) $case->player_id;
        self::renderHeader( sprintf( __( 'Trial: %s', 'talenttrack' ), $name ) );

        self::renderHeaderStrip( $case, $name );

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'overview';
        self::renderTabBar( $case_id, $tab, $user_id, $case );

        self::dispatchTab( $tab, $case, $user_id );
    }

    private static function renderHeaderStrip( object $case, string $name ): void {
        $tracks = new TrialTracksRepository();
        $track  = $tracks->find( (int) $case->track_id );
        echo '<div class="tt-trial-strip">';
        echo '<div><strong>' . esc_html__( 'Track:', 'talenttrack' ) . '</strong> ' . esc_html( $track ? (string) $track->name : '—' ) . '</div>';
        echo '<div><strong>' . esc_html__( 'Window:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $case->start_date . ' → ' . (string) $case->end_date ) . '</div>';
        echo '<div><strong>' . esc_html__( 'Status:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $case->status ) . '</div>';
        if ( $case->decision ) {
            echo '<div><strong>' . esc_html__( 'Decision:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $case->decision ) . '</div>';
        }
        echo '</div>';
    }

    private static function renderTabBar( int $case_id, string $current, int $user_id, object $case ): void {
        $base = remove_query_arg( [ 'tab' ] );
        $tabs = [
            'overview'  => __( 'Overview', 'talenttrack' ),
            'execution' => __( 'Execution', 'talenttrack' ),
            'inputs'    => __( 'Staff inputs', 'talenttrack' ),
        ];
        if ( TrialCaseAccessPolicy::isManager( $user_id ) ) {
            $tabs['decision'] = __( 'Decision', 'talenttrack' );
            $tabs['letter']   = __( 'Letter', 'talenttrack' );
            if ( $case->status === TrialCasesRepository::STATUS_DECIDED ) {
                $tabs['meeting'] = __( 'Parent meeting', 'talenttrack' );
            }
        }

        echo '<nav class="tt-tabbar" role="tablist">';
        foreach ( $tabs as $slug => $label ) {
            $url = add_query_arg( [ 'tab' => $slug ], $base );
            $cls = $slug === $current ? 'tt-tab tt-tab-active' : 'tt-tab';
            echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    private static function dispatchTab( string $tab, object $case, int $user_id ): void {
        switch ( $tab ) {
            case 'execution': self::renderExecutionTab( $case ); return;
            case 'inputs':    self::renderInputsTab( $case, $user_id ); return;
            case 'decision':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) { echo '<p class="tt-notice">' . esc_html__( 'Manager only.', 'talenttrack' ) . '</p>'; return; }
                self::renderDecisionTab( $case ); return;
            case 'letter':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) { echo '<p class="tt-notice">' . esc_html__( 'Manager only.', 'talenttrack' ) . '</p>'; return; }
                self::renderLetterTab( $case ); return;
            case 'meeting':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) { echo '<p class="tt-notice">' . esc_html__( 'Manager only.', 'talenttrack' ) . '</p>'; return; }
                self::renderMeetingTab( $case ); return;
            case 'overview':
            default:
                self::renderOverviewTab( $case, $user_id ); return;
        }
    }

    /* ===== Overview tab (Sprint 1) ===== */

    private static function renderOverviewTab( object $case, int $user_id ): void {
        $staff_repo = new TrialCaseStaffRepository();
        $ext_repo   = new TrialExtensionsRepository();
        $staff      = $staff_repo->listForCase( (int) $case->id );
        $extensions = $ext_repo->listForCase( (int) $case->id );

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Summary', 'talenttrack' ) . '</h2>';
        if ( $case->notes ) echo '<p>' . esc_html( (string) $case->notes ) . '</p>';
        echo '</section>';

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Assigned staff', 'talenttrack' ) . '</h2>';
        if ( ! $staff ) {
            echo '<p>' . esc_html__( 'No staff assigned yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-trial-staff-list">';
            foreach ( $staff as $s ) {
                $u = get_userdata( (int) $s->user_id );
                $label = $u ? (string) $u->display_name : '#' . (int) $s->user_id;
                $role  = $s->role_label ? ' (' . esc_html( (string) $s->role_label ) . ')' : '';
                echo '<li>' . esc_html( $label ) . $role . '</li>';
            }
            echo '</ul>';
        }
        if ( TrialCaseAccessPolicy::isManager( $user_id ) ) {
            self::renderAssignStaffForm( (int) $case->id );
        }
        echo '</section>';

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Extension history', 'talenttrack' ) . '</h2>';
        if ( ! $extensions ) {
            echo '<p>' . esc_html__( 'No extensions yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Extended at', 'talenttrack' ) . '</th><th>' . esc_html__( 'Previous end', 'talenttrack' ) . '</th><th>' . esc_html__( 'New end', 'talenttrack' ) . '</th><th>' . esc_html__( 'Justification', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $extensions as $e ) {
                echo '<tr><td>' . esc_html( (string) $e->extended_at ) . '</td><td>' . esc_html( (string) $e->previous_end_date ) . '</td><td>' . esc_html( (string) $e->new_end_date ) . '</td><td>' . esc_html( (string) $e->justification ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if ( TrialCaseAccessPolicy::isManager( $user_id ) && in_array( $case->status, [ 'open', 'extended' ], true ) ) {
            self::renderExtensionForm( (int) $case->id, (string) $case->end_date );
        }
        echo '</section>';

        if ( TrialCaseAccessPolicy::isManager( $user_id ) && $case->archived_at === null ) {
            $base = remove_query_arg( [ 'tab' ] );
            echo '<form method="post" class="tt-trial-archive">';
            wp_nonce_field( 'tt_trial_archive_' . (int) $case->id, 'tt_trial_archive_nonce' );
            echo '<input type="hidden" name="tt_trial_action" value="archive">';
            echo '<button type="submit" class="tt-button tt-button-danger" onclick="return confirm(\'' . esc_js( __( 'Archive this case?', 'talenttrack' ) ) . '\');">' . esc_html__( 'Archive case', 'talenttrack' ) . '</button>';
            echo '</form>';
        }
    }

    private static function renderAssignStaffForm( int $case_id ): void {
        echo '<form method="post" class="tt-trial-assign-form">';
        wp_nonce_field( 'tt_trial_assign_' . $case_id, 'tt_trial_assign_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="assign_staff">';
        echo StaffPickerComponent::render( [
            'name'        => 'staff_user_id',
            'label'       => __( 'Staff member', 'talenttrack' ),
            'required'    => true,
            'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
        ] );
        echo ' <input type="text" name="role_label" class="tt-input" placeholder="' . esc_attr__( 'Role label (optional)', 'talenttrack' ) . '">';
        echo ' <button type="submit" class="tt-button">' . esc_html__( 'Assign', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    private static function renderExtensionForm( int $case_id, string $current_end ): void {
        $next = gmdate( 'Y-m-d', strtotime( $current_end . ' +14 days' ) ?: time() + 14 * 86400 );
        echo '<form method="post" class="tt-trial-extend-form">';
        wp_nonce_field( 'tt_trial_extend_' . $case_id, 'tt_trial_extend_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="extend">';
        echo '<label>' . esc_html__( 'New end date', 'talenttrack' ) . ' <input type="date" name="new_end_date" value="' . esc_attr( $next ) . '" required></label>';
        echo '<label>' . esc_html__( 'Justification (required)', 'talenttrack' ) . ' <textarea name="justification" rows="2" required></textarea></label>';
        echo '<button type="submit" class="tt-button">' . esc_html__( 'Extend trial', 'talenttrack' ) . '</button>';
        echo '</form>';
    }

    /* ===== Execution tab (Sprint 2) ===== */

    private static function renderExecutionTab( object $case ): void {
        global $wpdb;
        $pid    = (int) $case->player_id;
        $start  = (string) $case->start_date;
        $end    = (string) $case->end_date;

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Synthesis', 'talenttrack' ) . '</h2>';
        $svc      = new PlayerStatsService();
        $headline = $svc->getHeadlineNumbers( $pid, [ 'date_from' => $start, 'date_to' => $end ], 5 );
        if ( $headline['eval_count'] === 0 ) {
            echo '<p>' . esc_html__( 'No evaluations during the trial window yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-trial-headline">';
            echo '<li>' . esc_html__( 'Rolling rating', 'talenttrack' ) . ': <strong>' . esc_html( (string) $headline['rolling'] ) . '</strong></li>';
            echo '<li>' . esc_html__( 'Evaluations in window', 'talenttrack' ) . ': <strong>' . (int) $headline['eval_count'] . '</strong></li>';
            echo '</ul>';
        }
        echo '</section>';

        // Sessions / activities — schema names: tt_activities + tt_attendance.
        $activities = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.activity_date, a.activity_type_key, a.notes, att.status AS attendance
               FROM {$wpdb->prefix}tt_activities a
          LEFT JOIN {$wpdb->prefix}tt_attendance att
                 ON att.activity_id = a.id AND att.player_id = %d AND att.club_id = a.club_id
              WHERE a.activity_date BETWEEN %s AND %s
                AND a.club_id = %d
              ORDER BY a.activity_date DESC", $pid, $start, $end, CurrentClub::id()
        ) );
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Sessions', 'talenttrack' ) . '</h2>';
        if ( ! $activities ) {
            echo '<p>' . esc_html__( 'No sessions yet during this trial period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Type', 'talenttrack' ) . '</th><th>' . esc_html__( 'Attendance', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $activities as $a ) {
                echo '<tr><td>' . esc_html( (string) $a->activity_date ) . '</td><td>' . esc_html( (string) $a->activity_type_key ) . '</td><td>' . esc_html( (string) ( $a->attendance ?? '—' ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';

        // Evaluations.
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date, evaluator_user_id
               FROM {$wpdb->prefix}tt_evaluations
              WHERE player_id = %d AND eval_date BETWEEN %s AND %s AND club_id = %d
              ORDER BY eval_date DESC", $pid, $start, $end, CurrentClub::id()
        ) );
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Evaluations', 'talenttrack' ) . '</h2>';
        if ( ! $evals ) {
            echo '<p>' . esc_html__( 'No evaluations yet during this trial period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Date', 'talenttrack' ) . '</th><th>' . esc_html__( 'Evaluator', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $evals as $e ) {
                $u = get_userdata( (int) $e->evaluator_user_id );
                echo '<tr><td>' . esc_html( (string) $e->eval_date ) . '</td><td>' . esc_html( $u ? (string) $u->display_name : '#' . (int) $e->evaluator_user_id ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';

        // Goals.
        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, priority, target_date, updated_at
               FROM {$wpdb->prefix}tt_goals
              WHERE player_id = %d
                AND ( ( created_at >= %s AND created_at <= %s )
                   OR ( updated_at >= %s AND updated_at <= %s ) )
                AND archived_at IS NULL
                AND club_id = %d
              ORDER BY updated_at DESC",
            $pid, $start . ' 00:00:00', $end . ' 23:59:59', $start . ' 00:00:00', $end . ' 23:59:59', CurrentClub::id()
        ) );
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Goals', 'talenttrack' ) . '</h2>';
        if ( ! $goals ) {
            echo '<p>' . esc_html__( 'No goals yet during this trial period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Title', 'talenttrack' ) . '</th><th>' . esc_html__( 'Status', 'talenttrack' ) . '</th><th>' . esc_html__( 'Priority', 'talenttrack' ) . '</th><th>' . esc_html__( 'Updated', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $goals as $g ) {
                echo '<tr><td>' . esc_html( (string) $g->title ) . '</td><td>' . esc_html( (string) $g->status ) . '</td><td>' . esc_html( (string) $g->priority ) . '</td><td>' . esc_html( (string) $g->updated_at ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    /* ===== Inputs tab (Sprint 3) ===== */

    private static function renderInputsTab( object $case, int $user_id ): void {
        $is_manager = TrialCaseAccessPolicy::isManager( $user_id );
        $inputs_repo = new TrialStaffInputsRepository();
        $staff_repo  = new TrialCaseStaffRepository();
        $assigned    = $staff_repo->isAssigned( (int) $case->id, $user_id );

        // Own input form (if assigned and case still open).
        if ( $assigned && in_array( $case->status, [ 'open', 'extended' ], true ) ) {
            $own = $inputs_repo->findForCaseUser( (int) $case->id, $user_id );
            self::renderOwnInputForm( (int) $case->id, $own );
        }

        // Aggregation for manager + assigned staff who can see released inputs.
        $visible = $inputs_repo->listVisibleForUser( (int) $case->id, $user_id, $is_manager );
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Submitted inputs', 'talenttrack' ) . '</h2>';

        if ( $is_manager ) {
            $assigned_count  = count( $staff_repo->listForCase( (int) $case->id ) );
            $submitted_count = count( $inputs_repo->listForCase( (int) $case->id, true ) );
            echo '<p class="tt-trial-input-count">' . sprintf( esc_html__( '%1$d of %2$d assigned staff have submitted.', 'talenttrack' ), $submitted_count, $assigned_count ) . '</p>';

            if ( $submitted_count > 0 && empty( $case->inputs_released_at ) ) {
                echo '<form method="post"><input type="hidden" name="tt_trial_action" value="release_inputs">';
                wp_nonce_field( 'tt_trial_release_' . (int) $case->id, 'tt_trial_release_nonce' );
                echo '<button type="submit" class="tt-button">' . esc_html__( 'Release submitted inputs to assigned staff', 'talenttrack' ) . '</button></form>';
            } elseif ( ! empty( $case->inputs_released_at ) ) {
                echo '<p>' . esc_html__( 'Inputs released on:', 'talenttrack' ) . ' ' . esc_html( (string) $case->inputs_released_at ) . '</p>';
            }
        }

        if ( ! $visible ) {
            echo '<p>' . esc_html__( 'No submitted inputs visible to you yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<div class="tt-trial-input-grid">';
            foreach ( $visible as $row ) {
                if ( ! $row->submitted_at ) continue; // drafts are own-only and shown above
                $u = get_userdata( (int) $row->user_id );
                echo '<article class="tt-trial-input-card">';
                echo '<header><strong>' . esc_html( $u ? (string) $u->display_name : '#' . (int) $row->user_id ) . '</strong>';
                echo '<div class="tt-meta">' . esc_html( (string) $row->submitted_at ) . '</div></header>';
                if ( $row->overall_rating !== null ) {
                    echo '<div class="tt-trial-input-rating">' . esc_html__( 'Overall', 'talenttrack' ) . ': <strong>' . esc_html( (string) $row->overall_rating ) . '</strong></div>';
                }
                if ( $row->free_text_notes ) {
                    echo '<details><summary>' . esc_html__( 'Notes', 'talenttrack' ) . '</summary><p>' . esc_html( (string) $row->free_text_notes ) . '</p></details>';
                }
                echo '</article>';
            }
            echo '</div>';
        }

        echo '</section>';
    }

    private static function renderOwnInputForm( int $case_id, ?object $existing ): void {
        $is_submitted = $existing && $existing->submitted_at;
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Your input', 'talenttrack' ) . '</h2>';
        if ( $is_submitted ) {
            echo '<p>' . esc_html__( 'You submitted on:', 'talenttrack' ) . ' ' . esc_html( (string) $existing->submitted_at ) . '</p>';
            echo '<p><em>' . esc_html__( 'To edit after submit, ask the head of development.', 'talenttrack' ) . '</em></p>';
            return;
        }

        echo '<form method="post" class="tt-trial-input-form">';
        wp_nonce_field( 'tt_trial_input_' . $case_id, 'tt_trial_input_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="save_input">';

        echo '<label>' . esc_html__( 'Overall rating (1–5)', 'talenttrack' ) . ' <input type="number" step="0.1" min="1" max="5" name="overall_rating" value="' . esc_attr( $existing && $existing->overall_rating !== null ? (string) $existing->overall_rating : '' ) . '"></label>';
        echo '<label>' . esc_html__( 'Notes', 'talenttrack' ) . ' <textarea name="free_text_notes" rows="4">' . esc_textarea( $existing ? (string) $existing->free_text_notes : '' ) . '</textarea></label>';
        echo '<div class="tt-form-actions">';
        echo '<button type="submit" name="submit_action" value="draft" class="tt-button">' . esc_html__( 'Save draft', 'talenttrack' ) . '</button> ';
        echo '<button type="submit" name="submit_action" value="submit" class="tt-button tt-button-primary">' . esc_html__( 'Submit input', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form></section>';
    }

    /* ===== Decision tab (Sprint 4) ===== */

    private static function renderDecisionTab( object $case ): void {
        if ( $case->status === TrialCasesRepository::STATUS_DECIDED ) {
            self::renderPostDecision( $case );
            return;
        }

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Record decision', 'talenttrack' ) . '</h2>';
        echo '<form method="post" class="tt-trial-decision-form">';
        wp_nonce_field( 'tt_trial_decide_' . (int) $case->id, 'tt_trial_decide_nonce' );
        echo '<input type="hidden" name="tt_trial_action" value="decide">';

        $opts = [
            TrialCasesRepository::DECISION_ADMIT          => __( 'Admit (offer a place)', 'talenttrack' ),
            TrialCasesRepository::DECISION_DENY_FINAL     => __( 'Decline (final)', 'talenttrack' ),
            TrialCasesRepository::DECISION_DENY_ENCOURAGE => __( 'Decline (with encouragement to re-apply)', 'talenttrack' ),
        ];
        echo '<fieldset class="tt-decision-radios"><legend>' . esc_html__( 'Outcome', 'talenttrack' ) . '</legend>';
        foreach ( $opts as $val => $label ) {
            echo '<label><input type="radio" name="decision" value="' . esc_attr( $val ) . '" required> ' . esc_html( $label ) . '</label>';
        }
        echo '</fieldset>';

        echo '<label>' . esc_html__( 'Justification (internal record, ≥ 30 characters)', 'talenttrack' ) . ' <textarea name="decision_notes" rows="3" minlength="30" required></textarea></label>';
        echo '<label>' . esc_html__( 'Strengths (used in the encouragement letter)', 'talenttrack' ) . ' <textarea name="strengths_summary" rows="2"></textarea></label>';
        echo '<label>' . esc_html__( 'Growth areas (used in the encouragement letter)', 'talenttrack' ) . ' <textarea name="growth_areas" rows="2"></textarea></label>';

        echo '<div class="tt-form-actions"><button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Record decision and generate letter', 'talenttrack' ) . '</button></div>';
        echo '</form></section>';
    }

    private static function renderPostDecision( object $case ): void {
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Decision recorded', 'talenttrack' ) . '</h2>';
        echo '<dl class="tt-trial-decision-summary">';
        echo '<dt>' . esc_html__( 'Outcome', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $case->decision ) . '</dd>';
        echo '<dt>' . esc_html__( 'Recorded at', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $case->decision_made_at ) . '</dd>';
        if ( $case->decision_notes ) {
            echo '<dt>' . esc_html__( 'Justification', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $case->decision_notes ) . '</dd>';
        }
        echo '</dl>';

        if ( $case->decision === TrialCasesRepository::DECISION_ADMIT && LetterTemplateEngine::acceptanceSlipEnabled() ) {
            if ( $case->acceptance_slip_returned_at ) {
                echo '<p>' . esc_html__( 'Acceptance slip received on:', 'talenttrack' ) . ' ' . esc_html( (string) $case->acceptance_slip_returned_at ) . '</p>';
            } else {
                echo '<form method="post"><input type="hidden" name="tt_trial_action" value="accept_received">';
                wp_nonce_field( 'tt_trial_accept_' . (int) $case->id, 'tt_trial_accept_nonce' );
                echo '<button type="submit" class="tt-button">' . esc_html__( 'Mark acceptance slip as received', 'talenttrack' ) . '</button></form>';
            }
        }

        echo '<form method="post" style="margin-top:1rem;"><input type="hidden" name="tt_trial_action" value="regenerate_letter">';
        wp_nonce_field( 'tt_trial_regenerate_' . (int) $case->id, 'tt_trial_regenerate_nonce' );
        echo '<button type="submit" class="tt-button">' . esc_html__( 'Regenerate letter', 'talenttrack' ) . '</button></form>';

        echo '</section>';
    }

    /* ===== Letter tab (Sprint 4) ===== */

    private static function renderLetterTab( object $case ): void {
        $svc = new TrialLetterService();
        $letter = $svc->findActiveForCase( (int) $case->id );

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Letter', 'talenttrack' ) . '</h2>';

        if ( ! $letter ) {
            echo '<p>' . esc_html__( 'No letter generated yet. Record a decision on the Decision tab to produce one.', 'talenttrack' ) . '</p>';
        } else {
            $print_url = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $case->id, 'tab' => 'letter', 'print' => 1 ], home_url( '/' ) );
            echo '<p><a class="tt-button" target="_blank" rel="noopener" href="' . esc_url( $print_url ) . '">' . esc_html__( 'Open print-ready view', 'talenttrack' ) . '</a></p>';
            echo '<div class="tt-trial-letter-preview">' . wp_kses_post( (string) $letter->rendered_html ) . '</div>';
        }

        echo '</section>';

        // History
        $history = $svc->listForCase( (int) $case->id );
        if ( $history ) {
            echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Letter history', 'talenttrack' ) . '</h2>';
            echo '<table class="tt-table"><thead><tr><th>' . esc_html__( 'Generated at', 'talenttrack' ) . '</th><th>' . esc_html__( 'Audience', 'talenttrack' ) . '</th><th>' . esc_html__( 'Status', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $history as $row ) {
                $status = $row->revoked_at ? __( 'Revoked', 'talenttrack' ) : __( 'Active', 'talenttrack' );
                echo '<tr><td>' . esc_html( (string) $row->created_at ) . '</td><td>' . esc_html( (string) $row->audience ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
            }
            echo '</tbody></table></section>';
        }
    }

    /* ===== Meeting tab (Sprint 5) — preview link to fullscreen ===== */

    private static function renderMeetingTab( object $case ): void {
        $url = add_query_arg( [ 'tt_view' => 'trial-parent-meeting', 'id' => (int) $case->id ], home_url( '/' ) );
        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Parent meeting mode', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'A sanitized fullscreen view for the conversation with the parents. No internal data is shown — only the decision, the player photo and basics, and the letter.', 'talenttrack' ) . '</p>';
        echo '<p><a class="tt-button tt-button-primary" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'Open meeting view', 'talenttrack' ) . '</a></p>';
        echo '</section>';
    }

    /* ===== POST handlers ===== */

    private static function handlePost( int $user_id, int $case_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        $action = isset( $_POST['tt_trial_action'] ) ? sanitize_key( (string) $_POST['tt_trial_action'] ) : '';
        if ( $action === '' ) return;

        $cases = new TrialCasesRepository();

        switch ( $action ) {
            case 'assign_staff':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_assign_' . $case_id, 'tt_trial_assign_nonce' ) ) return;
                $u = isset( $_POST['staff_user_id'] ) ? absint( $_POST['staff_user_id'] ) : 0;
                $label = isset( $_POST['role_label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['role_label'] ) ) : null;
                if ( $u > 0 ) ( new TrialCaseStaffRepository() )->assign( $case_id, $u, $label ?: null );
                return;

            case 'extend':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_extend_' . $case_id, 'tt_trial_extend_nonce' ) ) return;
                $new_end = isset( $_POST['new_end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_end_date'] ) ) : '';
                $just    = isset( $_POST['justification'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['justification'] ) ) : '';
                $case    = $cases->find( $case_id );
                if ( ! $case || $new_end === '' || trim( $just ) === '' ) return;
                if ( $new_end <= $case->end_date ) return;
                ( new TrialExtensionsRepository() )->record( $case_id, (string) $case->end_date, $new_end, $just, $user_id );
                $cases->update( $case_id, [
                    'end_date'        => $new_end,
                    'extension_count' => (int) $case->extension_count + 1,
                    'status'          => TrialCasesRepository::STATUS_EXTENDED,
                ] );
                return;

            case 'archive':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_archive_' . $case_id, 'tt_trial_archive_nonce' ) ) return;
                $cases->archive( $case_id, $user_id );
                return;

            case 'save_input':
                if ( ! TrialCaseAccessPolicy::canSubmitInput( $user_id, $case_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_input_' . $case_id, 'tt_trial_input_nonce' ) ) return;
                $inputs = new TrialStaffInputsRepository();
                $overall = isset( $_POST['overall_rating'] ) && $_POST['overall_rating'] !== '' ? (float) $_POST['overall_rating'] : null;
                $notes   = isset( $_POST['free_text_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['free_text_notes'] ) ) : '';
                $inputs->upsertDraft( $case_id, $user_id, [ 'overall_rating' => $overall, 'free_text_notes' => $notes ] );
                if ( ( $_POST['submit_action'] ?? '' ) === 'submit' ) {
                    $inputs->submit( $case_id, $user_id );
                }
                return;

            case 'release_inputs':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_release_' . $case_id, 'tt_trial_release_nonce' ) ) return;
                ( new TrialStaffInputsRepository() )->release( $case_id, $user_id );
                $cases->releaseInputs( $case_id, $user_id );
                return;

            case 'decide':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_decide_' . $case_id, 'tt_trial_decide_nonce' ) ) return;
                $decision = isset( $_POST['decision'] ) ? sanitize_key( (string) $_POST['decision'] ) : '';
                $notes    = isset( $_POST['decision_notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['decision_notes'] ) ) : '';
                $strengths = isset( $_POST['strengths_summary'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['strengths_summary'] ) ) : '';
                $growth    = isset( $_POST['growth_areas'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['growth_areas'] ) ) : '';
                if ( strlen( $notes ) < 30 ) return;
                $ok = $cases->recordDecision( $case_id, $decision, $user_id, $notes, $strengths, $growth );
                if ( $ok ) {
                    $case = $cases->find( $case_id );
                    if ( $case ) {
                        // Player status follows decision.
                        global $wpdb;
                        $new_player_status = $decision === TrialCasesRepository::DECISION_ADMIT ? 'active' : 'archived';
                        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => $new_player_status ], [ 'id' => (int) $case->player_id, 'club_id' => CurrentClub::id() ] );
                        // Letter
                        $audience = self::audienceForDecision( $decision );
                        $svc      = new TrialLetterService();
                        $letter_id = $svc->generate( $case, $audience, $user_id, $strengths ?: null, $growth ?: null );
                        $svc->revokePriorLetters( $case_id, $letter_id );
                    }
                }
                return;

            case 'regenerate_letter':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_regenerate_' . $case_id, 'tt_trial_regenerate_nonce' ) ) return;
                $case = $cases->find( $case_id );
                if ( ! $case || ! $case->decision ) return;
                $audience = self::audienceForDecision( (string) $case->decision );
                $svc      = new TrialLetterService();
                $new_id   = $svc->generate( $case, $audience, $user_id, $case->strengths_summary, $case->growth_areas );
                $svc->revokePriorLetters( $case_id, $new_id );
                return;

            case 'accept_received':
                if ( ! TrialCaseAccessPolicy::isManager( $user_id ) ) return;
                if ( ! self::nonceOk( 'tt_trial_accept_' . $case_id, 'tt_trial_accept_nonce' ) ) return;
                $cases->markAcceptanceReceived( $case_id, $user_id );
                return;
        }
    }

    private static function nonceOk( string $action, string $field ): bool {
        return isset( $_POST[ $field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ), $action );
    }

    private static function audienceForDecision( string $decision ): string {
        switch ( $decision ) {
            case TrialCasesRepository::DECISION_ADMIT:           return AudienceType::TRIAL_ADMITTANCE;
            case TrialCasesRepository::DECISION_DENY_FINAL:      return AudienceType::TRIAL_DENIAL_FINAL;
            case TrialCasesRepository::DECISION_DENY_ENCOURAGE:  return AudienceType::TRIAL_DENIAL_ENCOURAGE;
        }
        return AudienceType::TRIAL_DENIAL_FINAL;
    }
}
