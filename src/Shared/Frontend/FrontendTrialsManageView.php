<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialCaseStaffRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Frontend\Components\StaffPickerComponent;

/**
 * FrontendTrialsManageView — list + create surface for trial cases.
 *
 *   ?tt_view=trials               — list of cases with filters
 *   ?tt_view=trials&action=new    — create-case form (writes via REST,
 *                                   then redirects to ?tt_view=trial-case&id=N)
 *
 * The detail / edit surface lives in `FrontendTrialCaseView` because it
 * carries six tabs and needs its own dispatch.
 */
class FrontendTrialsManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_manage_trials' ) ) {
            self::renderHeader( __( 'Trials', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage trial cases.', 'talenttrack' ) . '</p>';
            return;
        }

        // v3.85.5 — hard cut: Trials is a Pro-tier feature per
        // FeatureMap. Free + Standard installs that previously had
        // access (because the gate was missing) now see an upgrade
        // nudge in place of the trials surface. Existing data stays
        // in the database — no loss — just inaccessible until upgrade.
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'trial_module' )
        ) {
            self::renderHeader( __( 'Trials', 'talenttrack' ) );
            echo \TT\Modules\License\Admin\UpgradeNudge::inline( __( 'Trial cases', 'talenttrack' ), 'pro' );
            return;
        }

        self::enqueueAssets();
        self::handlePost( $user_id );

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        if ( $action === 'new' ) {
            self::renderHeader( __( 'New trial case', 'talenttrack' ) );
            self::renderCreateForm();
            return;
        }

        self::renderHeader( __( 'Trial cases', 'talenttrack' ) );
        self::renderList();
    }

    private static function handlePost( int $user_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_trials_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_trials_nonce'] ) ), 'tt_trials_create' ) ) return;

        $player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
        $track_id  = isset( $_POST['track_id'] )  ? absint( $_POST['track_id'] )  : 0;
        $start     = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start_date'] ) ) : '';
        $end       = isset( $_POST['end_date'] )   ? sanitize_text_field( wp_unslash( (string) $_POST['end_date'] ) )   : '';
        $notes     = isset( $_POST['notes'] )      ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) )  : '';

        // Inline-create path: when no existing player is picked but the
        // inline first/last/DOB fields are filled, create the trial-status
        // player first and use that id for the case.
        if ( $player_id <= 0 ) {
            $new_first = isset( $_POST['new_player_first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_player_first_name'] ) ) : '';
            $new_last  = isset( $_POST['new_player_last_name'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['new_player_last_name'] ) )  : '';
            $new_dob   = isset( $_POST['new_player_dob'] )        ? sanitize_text_field( wp_unslash( (string) $_POST['new_player_dob'] ) )        : '';
            if ( $new_first !== '' && $new_last !== '' && $new_dob !== '' ) {
                global $wpdb;
                $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', [
                    'club_id'       => CurrentClub::id(),
                    'first_name'    => $new_first,
                    'last_name'     => $new_last,
                    'date_of_birth' => $new_dob,
                    'status'        => 'trial',
                    'created_at'    => current_time( 'mysql' ),
                    'updated_at'    => current_time( 'mysql' ),
                ] );
                if ( $ok ) {
                    $player_id = (int) $wpdb->insert_id;
                    // Auto-tag demo-on rows — mirror of PlayersPage v3.76.2.
                    if ( class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
                        \TT\Modules\DemoData\DemoMode::tagIfActive( 'player', $player_id );
                    }
                }
            }
        }

        if ( $player_id <= 0 || $track_id <= 0 || $start === '' || $end === '' ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html__( 'Please pick a player (or fill in first name, last name and date of birth to create one), a track, and start/end dates.', 'talenttrack' ) . '</div>';
            return;
        }

        $cases = new TrialCasesRepository();
        $case_id = $cases->create( [
            'player_id'  => $player_id,
            'track_id'   => $track_id,
            'start_date' => $start,
            'end_date'   => $end,
            'notes'      => $notes,
            'created_by' => $user_id,
        ] );

        if ( $case_id <= 0 ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html__( 'Could not create the case. Please try again.', 'talenttrack' ) . '</div>';
            return;
        }

        // Mark player as trial.
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'tt_players', [ 'status' => 'trial' ], [ 'id' => $player_id, 'club_id' => CurrentClub::id() ] );

        // Initial staff assignments (parallel arrays).
        $staff_ids   = isset( $_POST['staff_user_id'] )    ? (array) $_POST['staff_user_id']    : [];
        $staff_roles = isset( $_POST['staff_role_label'] ) ? (array) $_POST['staff_role_label'] : [];
        $staff_repo  = new TrialCaseStaffRepository();
        foreach ( $staff_ids as $i => $u ) {
            $u = absint( $u );
            if ( $u <= 0 ) continue;
            $label = isset( $staff_roles[ $i ] ) ? sanitize_text_field( wp_unslash( (string) $staff_roles[ $i ] ) ) : null;
            $staff_repo->assign( $case_id, $u, $label ?: null );
        }

        $detail_url = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => $case_id ], home_url( '/' ) );
        wp_safe_redirect( $detail_url );
        exit;
    }

    private static function renderList(): void {
        $cases_repo = new TrialCasesRepository();
        $tracks_repo = new TrialTracksRepository();

        $filters = [
            'status'   => isset( $_GET['status'] )   ? sanitize_key( (string) $_GET['status'] )   : '',
            'track_id' => isset( $_GET['track_id'] ) ? absint( $_GET['track_id'] )                : 0,
            'decision' => isset( $_GET['decision'] ) ? sanitize_key( (string) $_GET['decision'] ) : '',
            'include_archived' => ! empty( $_GET['include_archived'] ),
        ];
        $rows   = $cases_repo->search( $filters );
        $tracks = $tracks_repo->listAll( true );

        $base_url = remove_query_arg( [ 'action', 'id', 'status', 'track_id', 'decision', 'include_archived' ] );
        $new_url  = add_query_arg( [ 'tt_view' => 'trials', 'action' => 'new' ], $base_url );

        echo '<div class="tt-toolbar"><a class="tt-button tt-button-primary" href="' . esc_url( $new_url ) . '">' . esc_html__( 'New trial case', 'talenttrack' ) . '</a></div>';

        echo '<form method="get" class="tt-filter-row">';
        echo '<input type="hidden" name="tt_view" value="trials"/>';

        echo '<label>' . esc_html__( 'Status', 'talenttrack' ) . ' <select name="status">';
        echo '<option value="">' . esc_html__( 'All', 'talenttrack' ) . '</option>';
        foreach ( [ 'open', 'extended', 'decided', 'archived' ] as $s ) {
            $sel = selected( $filters['status'], $s, false );
            echo '<option value="' . esc_attr( $s ) . '" ' . $sel . '>' . esc_html( ucfirst( $s ) ) . '</option>';
        }
        echo '</select></label>';

        echo '<label>' . esc_html__( 'Track', 'talenttrack' ) . ' <select name="track_id">';
        echo '<option value="0">' . esc_html__( 'All', 'talenttrack' ) . '</option>';
        foreach ( $tracks as $t ) {
            $sel = selected( $filters['track_id'], (int) $t->id, false );
            echo '<option value="' . esc_attr( (string) $t->id ) . '" ' . $sel . '>' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label>' . esc_html__( 'Decision', 'talenttrack' ) . ' <select name="decision">';
        echo '<option value="">' . esc_html__( 'Any', 'talenttrack' ) . '</option>';
        foreach ( [ 'admit', 'deny_final', 'deny_encouragement' ] as $d ) {
            $sel = selected( $filters['decision'], $d, false );
            echo '<option value="' . esc_attr( $d ) . '" ' . $sel . '>' . esc_html( $d ) . '</option>';
        }
        echo '</select></label>';

        echo '<label><input type="checkbox" name="include_archived" value="1" ' . checked( $filters['include_archived'], true, false ) . '> ' . esc_html__( 'Include archived', 'talenttrack' ) . '</label>';
        echo '<button type="submit" class="tt-button">' . esc_html__( 'Filter', 'talenttrack' ) . '</button>';
        echo '</form>';

        if ( ! $rows ) {
            echo '<p class="tt-notice">' . esc_html__( 'No trial cases match those filters yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $tracks_by_id = [];
        foreach ( $tracks as $t ) { $tracks_by_id[ (int) $t->id ] = $t; }
        $staff_repo = new TrialCaseStaffRepository();

        echo '<table class="tt-table tt-table-sortable"><thead><tr>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Track', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Window', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Decision', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Staff', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $player = QueryHelpers::get_player( (int) $r->player_id );
            $name   = $player ? QueryHelpers::player_display_name( $player ) : '#' . (int) $r->player_id;
            $track  = $tracks_by_id[ (int) $r->track_id ] ?? null;
            $detail = add_query_arg( [ 'tt_view' => 'trial-case', 'id' => (int) $r->id ], $base_url );
            $staff_count = count( $staff_repo->listForCase( (int) $r->id ) );

            echo '<tr>';
            echo '<td><a href="' . esc_url( $detail ) . '">' . esc_html( $name ) . '</a></td>';
            echo '<td>' . esc_html( $track ? \TT\Infrastructure\Query\LabelTranslator::trialTrackName( (string) $track->name ) : '—' ) . '</td>';
            echo '<td>' . esc_html( (string) $r->start_date . ' → ' . (string) $r->end_date ) . '</td>';
            echo '<td>' . esc_html( (string) $r->status ) . '</td>';
            echo '<td>' . esc_html( (string) ( $r->decision ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( (string) $staff_count ) . '</td>';
            echo '<td><a class="tt-button tt-button-small" href="' . esc_url( $detail ) . '">' . esc_html__( 'Open', 'talenttrack' ) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderCreateForm(): void {
        $tracks_repo = new TrialTracksRepository();
        $tracks      = $tracks_repo->listAll( false );

        $today = gmdate( 'Y-m-d' );
        $default_days  = $tracks ? (int) $tracks[0]->default_duration_days : 28;
        $default_end   = gmdate( 'Y-m-d', time() + $default_days * 86400 );

        echo '<form method="post" class="tt-form tt-trial-create-form">';
        wp_nonce_field( 'tt_trials_create', 'tt_trials_nonce' );

        echo '<fieldset class="tt-trial-create-player"><legend>' . esc_html__( 'Player', 'talenttrack' ) . '</legend>';
        echo PlayerSearchPickerComponent::render( [
            'name'     => 'player_id',
            'label'    => __( 'Existing player', 'talenttrack' ),
            'required' => false,
            'user_id'  => get_current_user_id(),
            'is_admin' => current_user_can( 'tt_edit_settings' ),
        ] );
        echo '<details class="tt-trial-inline-create" style="margin-top:8px;"><summary>' . esc_html__( 'Or create a new player here', 'talenttrack' ) . '</summary>';
        echo '<p class="tt-field-hint">' . esc_html__( 'Fill in at least first name, last name and date of birth. The new player record will be created with status "trial" before the case is opened.', 'talenttrack' ) . '</p>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-trial-new-first">' . esc_html__( 'First name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-trial-new-first" name="new_player_first_name" class="tt-input" autocomplete="given-name"></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-trial-new-last">' . esc_html__( 'Last name', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-trial-new-last" name="new_player_last_name" class="tt-input" autocomplete="family-name"></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-trial-new-dob">' . esc_html__( 'Date of birth', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-trial-new-dob" name="new_player_dob" class="tt-input"></div>';
        echo '</details>';
        echo '</fieldset>';

        echo '<label>' . esc_html__( 'Track', 'talenttrack' );
        echo ' <select name="track_id" required>';
        foreach ( $tracks as $t ) {
            echo '<option value="' . esc_attr( (string) $t->id ) . '" data-days="' . esc_attr( (string) $t->default_duration_days ) . '">' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></label>';

        echo '<label>' . esc_html__( 'Start date', 'talenttrack' ) . ' <input type="date" name="start_date" value="' . esc_attr( $today ) . '" required></label>';
        echo '<label>' . esc_html__( 'End date', 'talenttrack' ) . ' <input type="date" name="end_date" value="' . esc_attr( $default_end ) . '" required></label>';

        echo '<fieldset class="tt-trial-staff-rows"><legend>' . esc_html__( 'Initial staff (optional)', 'talenttrack' ) . '</legend>';
        for ( $i = 0; $i < 3; $i++ ) {
            echo '<div class="tt-trial-staff-row">';
            echo StaffPickerComponent::render( [
                'name'        => 'staff_user_id[]',
                'label'       => sprintf( __( 'Staff slot %d', 'talenttrack' ), $i + 1 ),
                'required'    => false,
                'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
            ] );
            echo ' <input type="text" name="staff_role_label[]" class="tt-input" placeholder="' . esc_attr__( 'Role label (optional)', 'talenttrack' ) . '">';
            echo '</div>';
        }
        echo '</fieldset>';

        echo '<label>' . esc_html__( 'Notes', 'talenttrack' ) . ' <textarea name="notes" rows="3"></textarea></label>';

        echo '<div class="tt-form-actions"><button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Create case', 'talenttrack' ) . '</button></div>';
        echo '</form>';
    }
}
