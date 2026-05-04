<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\GuestAddModal;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * FrontendActivitiesManageView — full-CRUD frontend for training activities.
 *
 * #0019 Sprint 2 session 2.3. Replaces the v3.0.0 placeholder
 * `FrontendSessionsView` (which only rendered a create form). Three
 * modes selected via query string:
 *
 *   ?tt_view=activities               — list (FrontendListTable) + Create CTA
 *   ?tt_view=activities&action=new    — create form
 *   ?tt_view=activities&id=<int>      — edit form (loads existing row + attendance)
 *
 * Saves go through the REST endpoints introduced in Sprint 1
 * (`POST/PUT /activities`, attendance handled inline). Delete is
 * wired as a row action on the list view, hitting
 * `DELETE /activities/{id}` which the controller treats as a soft-
 * archive: `archived_at` gets set rather than the row being removed.
 *
 * Bulk attendance + mobile pagination behaviour lives in
 * `assets/js/components/attendance.js` (loaded by DashboardShortcode).
 *
 * #0037 — guest section now renders on create AND edit. On create,
 * the "+ Add guest" button auto-saves the activity first, redirects
 * to the edit URL with `&open_guest=1`, and re-opens the modal so the
 * coach can pick a guest in one fluid flow.
 */
class FrontendActivitiesManageView extends FrontendViewBase {

    private static bool $activities_css_enqueued = false;

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        if ( self::$activities_css_enqueued ) return;
        // #0056 Sprint D — pilot mobile-first stylesheet for the
        // activities surface. Owns the responsive treatment of the
        // attendance table (was a max-width block in frontend-admin.css).
        wp_enqueue_style(
            'tt-frontend-activities-manage',
            TT_PLUGIN_URL . 'assets/css/frontend-activities-manage.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        self::$activities_css_enqueued = true;
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        // v3.92.1 — breadcrumb chain. The view renders four shapes
        // depending on `$action` / `$id` (list / new / detail / edit);
        // the chain reflects whichever the user is on.
        $current = __( 'Activities', 'talenttrack' );
        $intermediate = null;
        if ( $action === 'new' ) {
            $current = __( 'New activity', 'talenttrack' );
            $intermediate = [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ];
        } elseif ( $id > 0 && $action === 'edit' ) {
            $current = __( 'Edit activity', 'talenttrack' );
            $intermediate = [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ];
        } elseif ( $id > 0 ) {
            $current = __( 'Activity detail', 'talenttrack' );
            $intermediate = [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ];
        }
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $current, $intermediate );

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New activity', 'talenttrack' ) );
            self::renderForm( $user_id, $is_admin, null, [], [] );
            return;
        }

        // v3.70.1 hotfix — `?tt_view=activities&id=N` (no action) now
        // renders a read-only detail view, matching how players / teams /
        // people detail-dispatch in DashboardShortcode. The edit form
        // requires `&action=edit` (so links from the list's row actions
        // open the form, but title clicks open the detail). This keeps
        // the link target consistent with other master-data lists and
        // unblocks academy admins / HoD who don't qualify for the
        // player-only `my-activities` gate.
        if ( $id > 0 && $action === 'edit' ) {
            $session    = self::loadSession( $id );
            $attendance = $session ? self::loadAttendance( $id ) : [];
            $guests     = $session ? self::loadGuests( $id )     : [];
            self::renderHeader( $session ? sprintf( __( 'Edit activity — %s', 'talenttrack' ), (string) $session->title ) : __( 'Activity not found', 'talenttrack' ) );
            if ( ! $session ) {
                echo '<p class="tt-notice">' . esc_html__( 'That activity no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $user_id, $is_admin, $session, $attendance, $guests );
            return;
        }

        if ( $id > 0 ) {
            $session = self::loadSession( $id );
            self::renderHeader( $session ? (string) $session->title : __( 'Activity not found', 'talenttrack' ) );
            if ( ! $session ) {
                echo '<p class="tt-notice">' . esc_html__( 'That activity no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderDetail( $session, $is_admin );
            return;
        }

        // Default: list view.
        self::renderHeader( __( 'Activities', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    /**
     * v3.70.1 hotfix — read-only activity detail. Keeps the surface
     * thin: the existing edit form remains the source of truth for
     * mutation; this just gives a clickable destination from list
     * cells without forcing the user into the form.
     *
     * @param object $session activity row from `loadSession`
     */
    private static function renderDetail( object $session, bool $is_admin ): void {
        $back_url = add_query_arg( [ 'tt_view' => 'activities' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        \TT\Shared\Frontend\FrontendBackButton::render( $back_url );

        $team_id   = (int) ( $session->team_id ?? 0 );
        $team_name = (string) ( $session->team_name ?? '' );
        $type_key  = (string) ( $session->activity_type_key ?? 'training' );
        $status_key = (string) ( $session->activity_status_key ?? 'planned' );

        echo '<div class="tt-record-detail" style="display:grid; gap:12px;">';

        echo '<dl class="tt-record-detail-list" style="display:grid; grid-template-columns: minmax(120px, max-content) 1fr; gap:6px 16px; margin:0;">';

        echo '<dt>' . esc_html__( 'Date', 'talenttrack' ) . '</dt>';
        echo '<dd>' . esc_html( (string) $session->session_date ) . '</dd>';

        echo '<dt>' . esc_html__( 'Type', 'talenttrack' ) . '</dt>';
        echo '<dd>' . \TT\Infrastructure\Query\LookupPill::render( 'activity_type', $type_key ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '<dt>' . esc_html__( 'Status', 'talenttrack' ) . '</dt>';
        echo '<dd>' . \TT\Infrastructure\Query\LookupPill::render( 'activity_status', $status_key ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        if ( $team_name !== '' ) {
            echo '<dt>' . esc_html__( 'Team', 'talenttrack' ) . '</dt>';
            echo '<dd>';
            if ( $team_id > 0 ) {
                echo \TT\Shared\Frontend\Components\RecordLink::inline( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    $team_name,
                    \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', $team_id )
                );
            } else {
                echo esc_html( $team_name );
            }
            echo '</dd>';
        }

        $location = (string) ( $session->location ?? '' );
        if ( $location !== '' ) {
            echo '<dt>' . esc_html__( 'Location', 'talenttrack' ) . '</dt>';
            echo '<dd>' . esc_html( $location ) . '</dd>';
        }

        $notes = (string) ( $session->notes ?? '' );
        if ( $notes !== '' ) {
            echo '<dt>' . esc_html__( 'Notes', 'talenttrack' ) . '</dt>';
            echo '<dd style="white-space:pre-wrap;">' . esc_html( $notes ) . '</dd>';
        }

        echo '</dl>';

        if ( current_user_can( 'tt_edit_activities' ) ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'activities', 'id' => (int) $session->id, 'action' => 'edit' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            );
            echo '<p><a class="tt-btn tt-btn-secondary" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'talenttrack' ) . '</a></p>';
        }

        echo '</div>';
    }

    /**
     * List view — FrontendListTable + "Create" CTA.
     */
    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $flat_url = add_query_arg( [ 'tt_view' => 'activities', 'action' => 'new' ], $base_url );
        $new_url  = \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-activity', $flat_url );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New activity', 'talenttrack' )
            . '</a></p>';

        $row_actions = [
            // v3.70.1 hotfix — Edit row action carries `action=edit` so
            // it routes to the form; bare `id=N` (from title clicks) goes
            // to the read-only detail in render() above.
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'activities', 'id' => '{id}', 'action' => 'edit' ], $base_url ),
            ],
            'delete' => [
                'label'       => __( 'Delete', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'activities/{id}',
                'confirm'     => __( 'Delete this activity? It will be archived.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'activities',
            'columns' => [
                // #0063 — Title moves to second column + RecordLink-wrapped.
                // Status pill colour now driven by lookup `meta.color`
                // (planned re-coloured to yellow via migration 0049).
                'session_date'        => [ 'label' => __( 'Date',   'talenttrack' ), 'sortable' => true ],
                'title'               => [ 'label' => __( 'Title',  'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'title_link_html' ],
                'activity_type_key'   => [ 'label' => __( 'Type',   'talenttrack' ), 'sortable' => false, 'render' => 'html', 'value_key' => 'activity_type_pill_html' ],
                'activity_status_key' => [ 'label' => __( 'Status', 'talenttrack' ), 'sortable' => false, 'render' => 'html', 'value_key' => 'activity_status_pill_html' ],
                // v3.71.0 — Source column (manual / spond / generated)
                // surfaces the lookup that was seeded but never displayed.
                'activity_source_key' => [ 'label' => __( 'Source', 'talenttrack' ), 'sortable' => false, 'render' => 'html', 'value_key' => 'activity_source_pill_html' ],
                'team_name'           => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'team_link_html' ],
                'attendance'          => [ 'label' => __( 'Att. %', 'talenttrack' ), 'sortable' => true, 'render' => 'percent', 'value_key' => 'attendance_pct' ],
            ],
            'filters' => [
                'team_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Team', 'talenttrack' ),
                    'options' => TeamPickerComponent::filterOptions( $user_id, $is_admin ),
                ],
                'date' => [
                    'type'       => 'date_range',
                    'param_from' => 'date_from',
                    'param_to'   => 'date_to',
                    'label_from' => __( 'From', 'talenttrack' ),
                    'label_to'   => __( 'To', 'talenttrack' ),
                ],
                'attendance' => [
                    'type'    => 'select',
                    'label'   => __( 'Attendance', 'talenttrack' ),
                    'options' => [
                        'complete' => __( 'Complete', 'talenttrack' ),
                        'partial'  => __( 'Partial',  'talenttrack' ),
                        'none'     => __( 'None',     'talenttrack' ),
                    ],
                ],
            ],
            'row_actions'  => $row_actions,
            'search'       => [ 'placeholder' => __( 'Search title, location, team…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
            'empty_state'  => __( 'No activities match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    /**
     * Create / edit form. Uses the existing REST endpoints; PUT vs
     * POST is decided by whether `$session` is set.
     *
     * @param object|null $session
     * @param array<int,object> $attendance roster rows keyed by player_id
     * @param array<int,object> $guests     guest attendance rows (#0026)
     */
    private static function renderForm( int $user_id, bool $is_admin, ?object $session, array $attendance, array $guests ): void {
        $teams         = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $selected_team = (int) ( $session->team_id ?? ( $teams ? $teams[0]->id : 0 ) );

        // Roster spans every team the coach has access to so changing
        // the team dropdown later doesn't lose attendance state. The
        // attendance.js helper hides rows for non-current teams.
        $players_by_team = [];
        $all_players     = [];
        foreach ( $teams as $t ) {
            $tp = QueryHelpers::get_players( (int) $t->id );
            foreach ( $tp as $pl ) {
                $all_players[ (int) $pl->id ] = $pl;
                $players_by_team[ (int) $t->id ][] = (int) $pl->id;
            }
        }

        $statuses      = QueryHelpers::get_lookup_names( 'attendance_status' );
        $game_subtypes = QueryHelpers::get_lookup_names( 'game_subtype' );
        // #0050 — Type lookup-driven; admins can rename or add types
        // via Configuration → Activity Types. Conditional Subtype /
        // Other-label rows stay anchored to the seeded keys.
        $activity_type_rows   = QueryHelpers::get_lookups( 'activity_type' );
        $activity_status_rows = QueryHelpers::get_lookups( 'activity_status' );

        $current_type    = (string) ( $session->activity_type_key ?? 'training' );
        $current_status  = (string) ( $session->activity_status_key ?? 'planned' );
        $current_subtype = (string) ( $session->game_subtype_key ?? '' );
        $current_other   = (string) ( $session->other_label ?? '' );

        // Edit mode → PUT /activities/{id}; create → POST /activities.
        $is_edit   = $session !== null;
        $rest_path = $is_edit ? 'activities/' . (int) $session->id : 'activities';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-activity-form';
        $draft_key = $is_edit ? '' : 'activity-form'; // edit forms don't draft — the row is the source of truth

        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form tt-activity-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>" data-redirect-after-save="list"<?php if ( $draft_key !== '' ) : ?> data-draft-key="<?php echo esc_attr( $draft_key ); ?>"<?php endif; ?>>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-activity-type"><?php esc_html_e( 'Type', 'talenttrack' ); ?></label>
                    <select id="tt-activity-type" class="tt-input" name="activity_type_key" required>
                        <?php foreach ( $activity_type_rows as $type_row ) : ?>
                            <option value="<?php echo esc_attr( (string) $type_row->name ); ?>" <?php selected( $current_type, (string) $type_row->name ); ?>><?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::name( $type_row ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-activity-status"><?php esc_html_e( 'Status', 'talenttrack' ); ?></label>
                    <select id="tt-activity-status" class="tt-input" name="activity_status_key">
                        <?php foreach ( $activity_status_rows as $status_row ) :
                            $row_name = (string) $status_row->name;
                            // #0061 — skip statuses flagged hidden_from_form (e.g. `draft`).
                            $meta   = is_string( $status_row->meta ?? null ) ? json_decode( (string) $status_row->meta, true ) : null;
                            $hidden = is_array( $meta ) && ! empty( $meta['hidden_from_form'] );
                            if ( $hidden && $current_status !== $row_name ) continue;
                            ?>
                            <option value="<?php echo esc_attr( $row_name ); ?>" <?php selected( $current_status, $row_name ); ?>><?php echo esc_html( \TT\Infrastructure\Query\LookupTranslator::name( $status_row ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-activity-title"><?php esc_html_e( 'Title', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-activity-title" class="tt-input" name="title" required value="<?php echo esc_attr( (string) ( $session->title ?? '' ) ); ?>" />
                </div>
                <div class="tt-field" id="tt-activity-subtype-row" style="<?php echo $current_type === 'game' ? '' : 'display:none;'; ?>">
                    <label class="tt-field-label" for="tt-activity-subtype"><?php esc_html_e( 'Game subtype', 'talenttrack' ); ?></label>
                    <select id="tt-activity-subtype" class="tt-input" name="game_subtype_key">
                        <option value=""><?php esc_html_e( '— Choose —', 'talenttrack' ); ?></option>
                        <?php foreach ( $game_subtypes as $sub ) : ?>
                            <option value="<?php echo esc_attr( (string) $sub ); ?>" <?php selected( $current_subtype, (string) $sub ); ?>><?php echo esc_html( (string) $sub ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field" id="tt-activity-other-row" style="<?php echo $current_type === 'other' ? '' : 'display:none;'; ?>">
                    <label class="tt-field-label tt-field-required" for="tt-activity-other-label"><?php esc_html_e( 'Other label', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-activity-other-label" class="tt-input" name="other_label" maxlength="120" value="<?php echo esc_attr( $current_other ); ?>" placeholder="<?php esc_attr_e( 'e.g. Team-building day', 'talenttrack' ); ?>" />
                </div>
                <?php echo DateInputComponent::render( [
                    'name'     => 'session_date',
                    'label'    => __( 'Date', 'talenttrack' ),
                    'required' => true,
                    'value'    => (string) ( $session->session_date ?? current_time( 'Y-m-d' ) ),
                ] ); ?>
                <?php echo TeamPickerComponent::render( [
                    'name'     => 'team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'required' => true,
                    'teams'    => $teams,
                    'selected' => $selected_team,
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-activity-location"><?php esc_html_e( 'Location', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-activity-location" class="tt-input" name="location" value="<?php echo esc_attr( (string) ( $session->location ?? '' ) ); ?>" />
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-activity-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-activity-notes" class="tt-input" name="notes" rows="2"><?php echo esc_textarea( (string) ( $session->notes ?? '' ) ); ?></textarea>
            </div>

            <?php
            // #0077 M2 — Principles practiced multiselect. Mirrors the
            // wp-admin ActivitiesPage form (lines ~331-352) so the
            // frontend has parity. Saved via the REST controller's
            // persistPrincipleLinks helper.
            if ( class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' )
                 && class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrincipleLinksRepository' ) ) {
                $all_principles = ( new \TT\Modules\Methodology\Repositories\PrinciplesRepository() )->listFiltered();
                $linked_ids = ( $is_edit && $session && (int) $session->id > 0 )
                    ? ( new \TT\Modules\Methodology\Repositories\PrincipleLinksRepository() )->principlesForActivity( (int) $session->id )
                    : [];
                if ( ! empty( $all_principles ) ) : ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-activity-principles"><?php esc_html_e( 'Principles practiced', 'talenttrack' ); ?></label>
                    <select id="tt-activity-principles" class="tt-input" name="activity_principle_ids[]" multiple size="6">
                        <?php foreach ( $all_principles as $pr ) :
                            $title = \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                            ?>
                            <option value="<?php echo (int) $pr->id; ?>" <?php selected( in_array( (int) $pr->id, $linked_ids, true ) ); ?>>
                                <?php echo esc_html( $pr->code . ' · ' . ( $title ?: '—' ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="tt-field-hint"><?php esc_html_e( 'Optional. Hold Ctrl/Cmd to select multiple.', 'talenttrack' ); ?></p>
                </div>
                <?php endif;
            }
            ?>

            <script>
            (function(){
                var sel = document.getElementById('tt-activity-type');
                if ( ! sel ) return;
                var subRow   = document.getElementById('tt-activity-subtype-row');
                var otherRow = document.getElementById('tt-activity-other-row');
                sel.addEventListener('change', function(){
                    if ( subRow )   subRow.style.display   = ( sel.value === 'game' )  ? '' : 'none';
                    if ( otherRow ) otherRow.style.display = ( sel.value === 'other' ) ? '' : 'none';
                });
            })();
            </script>

            <?php
            // #0061 — Hide the attendance section unless the activity has actually
            // happened (status = completed). Planned + cancelled don't get
            // attendance rows. The wrapper carries data-tt-attendance-section
            // so the status `<select>` JS below can toggle it without a reload.
            $attendance_visible = ( $current_status === 'completed' );
            ?>
            <div data-tt-attendance-section data-tt-attendance-allowed-status="completed"<?php echo $attendance_visible ? '' : ' hidden'; ?>>
            <h3 style="margin:24px 0 12px;"><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h3>

            <?php if ( ! $all_players ) : ?>
                <p><em><?php esc_html_e( 'No players on your teams yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <?php
                // Tell the JS which option value counts as "Present" for
                // the live summary count + Mark-all-present default. Defaults
                // to the literal English seed name; if the admin renamed the
                // first attendance_status row (e.g. to 'Aanwezig'), we use
                // that — the first row in sort_order is the canonical
                // "present" by convention (#0019 Sprint 2 lookup contract).
                $present_value = ! empty( $statuses ) ? (string) $statuses[0] : 'Present';
                ?>
                <div class="tt-attendance" data-tt-attendance="1" data-current-team="<?php echo (int) $selected_team; ?>" data-tt-attendance-present-value="<?php echo esc_attr( $present_value ); ?>">
                    <div class="tt-attendance-toolbar">
                        <button type="button" class="tt-btn tt-btn-secondary tt-attendance-mark-all" data-tt-attendance-mark-all="1">
                            <?php esc_html_e( 'Mark all present', 'talenttrack' ); ?>
                        </button>
                        <span class="tt-attendance-summary" data-tt-attendance-summary="1"></span>
                    </div>

                    <table class="tt-table tt-attendance-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $all_players as $pid => $pl ) :
                            $row_team_id = (int) $pl->team_id;
                            $row_status  = (string) ( $attendance[ $pid ]->status ?? 'Present' );
                            $row_notes   = (string) ( $attendance[ $pid ]->notes  ?? '' );
                            ?>
                            <tr class="tt-attendance-row" data-team-id="<?php echo $row_team_id; ?>">
                                <td data-label="<?php esc_attr_e( 'Player', 'talenttrack' ); ?>">
                                    <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Status', 'talenttrack' ); ?>">
                                    <select class="tt-input tt-attendance-status" name="att[<?php echo (int) $pid; ?>][status]" data-tt-attendance-status="1">
                                        <?php foreach ( $statuses as $s ) : ?>
                                            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $row_status, $s ); ?>><?php echo esc_html( LabelTranslator::attendanceStatus( $s ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td data-label="<?php esc_attr_e( 'Notes', 'talenttrack' ); ?>">
                                    <input type="text" class="tt-input" name="att[<?php echo (int) $pid; ?>][notes]" value="<?php echo esc_attr( $row_notes ); ?>" />
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="tt-attendance-show-all" data-tt-attendance-show-all="1" hidden>
                        <button type="button" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Show all', 'talenttrack' ); ?></button>
                    </p>
                </div>
            <?php endif; ?>
            </div>
            <p data-tt-attendance-hidden-hint<?php echo $attendance_visible ? ' hidden' : ''; ?> style="color:#5b6e75;font-style:italic;margin:16px 0;">
                <?php esc_html_e( 'Attendance is recorded once the activity is marked Completed.', 'talenttrack' ); ?>
            </p>
            <script>
            (function(){
                var statusSel = document.getElementById('tt-activity-status');
                if ( ! statusSel ) return;
                var section = document.querySelector('[data-tt-attendance-section]');
                var hint    = document.querySelector('[data-tt-attendance-hidden-hint]');
                function sync(){
                    var ok = statusSel.value === 'completed';
                    if ( section ) section.toggleAttribute('hidden', ! ok);
                    if ( hint )    hint.toggleAttribute('hidden', ok);
                }
                statusSel.addEventListener('change', sync);
                sync();
            })();
            </script>

            <?php
            // #0037 — guest section renders in both modes. On create it
            // shows the table + button just like edit; the "+ Add guest"
            // click auto-saves the activity first (see guest-add.js),
            // redirects to the edit URL with `&open_guest=1`, and the
            // modal re-opens so the coach picks a guest in one motion.
            self::renderGuestSection( $is_edit ? (int) $session->id : 0, $guests );
            ?>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update activity', 'talenttrack' ) : __( 'Save activity', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php

        // Guest add modal — outside the form so its inputs don't get
        // serialized into the activity PUT payload. The JS handler
        // POSTs to /activities/{id}/guests on submit; on create it
        // first auto-saves the activity then opens the modal.
        echo GuestAddModal::render( [
            'user_id'         => $user_id,
            'is_admin'        => $is_admin,
            'exclude_team_id' => $selected_team,
        ] );
        self::enqueueGuestAddAssets();
    }

    /**
     * Guest attendance section — linked + anonymous rows under the
     * roster, plus the "+ Add guest" button.
     *
     * @param array<int, object> $guests
     */
    private static function renderGuestSection( int $activity_id, array $guests ): void {
        ?>
        <h3 style="margin:24px 0 12px;"><?php esc_html_e( 'Guests', 'talenttrack' ); ?></h3>
        <p class="tt-help-text" style="margin:-6px 0 12px; font-size:12px; color:#5b6470;">
            <?php esc_html_e( 'Players from outside the squad. Guests do not count toward team stats.', 'talenttrack' ); ?>
        </p>
        <div class="tt-attendance" data-tt-guest-session-id="<?php echo (int) $activity_id; ?>">
            <table class="tt-table tt-attendance-table" data-tt-guest-table>
                <thead><tr>
                    <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php if ( empty( $guests ) ) : ?>
                    <tr class="tt-attendance-row tt-attendance-row--empty" data-tt-guest-empty>
                        <td colspan="3" style="text-align:center; color:#5b6470; font-style:italic; padding:18px;">
                            <?php esc_html_e( 'No guests added yet.', 'talenttrack' ); ?>
                        </td>
                    </tr>
                <?php else : foreach ( $guests as $g ) :
                    $is_linked = ! empty( $g->guest_player_id );
                    if ( $is_linked ) {
                        $label = trim( (string) ( $g->_player_name ?? '' ) );
                        if ( $label === '' ) $label = __( 'Guest', 'talenttrack' );
                        $sub = (string) ( $g->_home_team ?? '' );
                    } else {
                        $label = (string) ( $g->guest_name ?? __( 'Guest', 'talenttrack' ) );
                        $sub   = __( '(unaffiliated)', 'talenttrack' );
                    }
                    ?>
                    <tr class="tt-attendance-row tt-attendance-row--guest" data-tt-attendance-id="<?php echo (int) $g->id; ?>" data-is-guest="1">
                        <td data-label="<?php esc_attr_e( 'Player', 'talenttrack' ); ?>">
                            <em><?php echo esc_html( $label ); ?></em>
                            <span class="tt-guest-badge"><?php esc_html_e( 'Guest', 'talenttrack' ); ?></span>
                            <?php if ( $sub !== '' ) : ?>
                                <div class="tt-guest-subline"><?php echo esc_html( $sub ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?php esc_attr_e( 'Status', 'talenttrack' ); ?>">
                            <?php echo esc_html( LabelTranslator::attendanceStatus( (string) ( $g->status ?? 'Present' ) ) ); ?>
                        </td>
                        <td data-label="<?php esc_attr_e( 'Notes', 'talenttrack' ); ?>">
                            <?php if ( $is_linked ) :
                                $eval_url = add_query_arg(
                                    [ 'tt_view' => 'evaluation_form', 'player_id' => (int) $g->guest_player_id ],
                                    remove_query_arg( [ 'action', 'id' ] )
                                );
                                ?>
                                <a href="<?php echo esc_url( $eval_url ); ?>"><?php esc_html_e( 'Evaluate', 'talenttrack' ); ?></a>
                                <button type="button" class="tt-btn-link" data-tt-guest-remove="<?php echo (int) $g->id; ?>" style="margin-left:8px; color:#b32d2e;">
                                    <?php esc_html_e( 'Remove', 'talenttrack' ); ?>
                                </button>
                            <?php else :
                                $promote_url = add_query_arg( [
                                    'page'               => 'tt-players',
                                    'action'             => 'new',
                                    'from_attendance_id' => (int) $g->id,
                                ], admin_url( 'admin.php' ) );
                                ?>
                                <input type="text" class="tt-input tt-guest-notes-input"
                                       data-tt-guest-notes-id="<?php echo (int) $g->id; ?>"
                                       data-initial="<?php echo esc_attr( (string) ( $g->guest_notes ?? '' ) ); ?>"
                                       value="<?php echo esc_attr( (string) ( $g->guest_notes ?? '' ) ); ?>"
                                       placeholder="<?php esc_attr_e( 'Notes…', 'talenttrack' ); ?>" />
                                <div class="tt-guest-row-actions" style="margin-top:6px; font-size:12px;">
                                    <a href="<?php echo esc_url( $promote_url ); ?>"><?php esc_html_e( 'Add as player', 'talenttrack' ); ?></a> ·
                                    <button type="button" class="tt-btn-link" data-tt-guest-remove="<?php echo (int) $g->id; ?>" style="color:#b32d2e;">
                                        <?php esc_html_e( 'Remove', 'talenttrack' ); ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <p style="margin-top:8px;">
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-guest-modal-open>
                    + <?php esc_html_e( 'Add guest', 'talenttrack' ); ?>
                </button>
            </p>
        </div>
        <?php
    }

    private static function enqueueGuestAddAssets(): void {
        wp_enqueue_script(
            'tt-guest-add',
            plugins_url( 'assets/js/components/guest-add.js', TT_PLUGIN_FILE ),
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-guest-add', 'TT_GuestAdd', [
            'restNs'  => rest_url( 'talenttrack/v1' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'strings' => [
                'guestBadge'      => __( 'Guest',          'talenttrack' ),
                'unaffiliated'    => __( '(unaffiliated)', 'talenttrack' ),
                'player'          => __( 'Player',         'talenttrack' ),
                'status'          => __( 'Status',         'talenttrack' ),
                'notes'           => __( 'Notes',          'talenttrack' ),
                'evaluate'        => __( 'Evaluate',       'talenttrack' ),
                'promote'         => __( 'Add as player',  'talenttrack' ),
                'remove'          => __( 'Remove',         'talenttrack' ),
                'confirmRemove'   => __( 'Remove this guest?',          'talenttrack' ),
                'linkedRequired'  => __( 'Pick a player.',              'talenttrack' ),
                'nameRequired'    => __( 'Name is required.',           'talenttrack' ),
                'saveFailed'      => __( 'Could not add guest.',        'talenttrack' ),
                'saveFirst'       => __( 'Saving activity first…', 'talenttrack' ),
                'networkError'    => __( 'Network error.',              'talenttrack' ),
                'notesPlaceholder'=> __( 'Notes…',                      'talenttrack' ),
                'linkedFallback'  => __( 'Guest',                       'talenttrack' ),
                'anonFallback'    => __( 'Guest',                       'talenttrack' ),
            ],
        ] );
    }

    private static function loadSession( int $id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 's', 'activity' );
        // v3.70.1 hotfix — also fetch team_name so renderDetail can show
        // a clickable team cell without a second query.
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*, t.name AS team_name FROM {$p}tt_activities s
             LEFT JOIN {$p}tt_teams t ON t.id = s.team_id AND t.club_id = s.club_id
             WHERE s.id = %d AND s.archived_at IS NULL {$scope}",
            $id
        ) );
        return $row ?: null;
    }

    /**
     * @return array<int, object> roster attendance rows keyed by player_id (excludes guests).
     */
    private static function loadAttendance( int $activity_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_attendance WHERE activity_id = %d AND is_guest = 0",
            $activity_id
        ) );
        $out = [];
        foreach ( $rows ?: [] as $r ) {
            if ( $r->player_id !== null ) $out[ (int) $r->player_id ] = $r;
        }
        return $out;
    }

    /**
     * #0026 — Guest attendance rows for a session, decorated with the
     * linked player's display name + home team for render.
     *
     * @return array<int, object>
     */
    private static function loadGuests( int $activity_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, pl.first_name, pl.last_name, t.name AS home_team_name
             FROM {$p}tt_attendance a
             LEFT JOIN {$p}tt_players pl ON pl.id = a.guest_player_id
             LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
             WHERE a.activity_id = %d AND a.is_guest = 1
             ORDER BY a.id ASC",
            $activity_id
        ) );
        $out = [];
        foreach ( $rows ?: [] as $r ) {
            if ( ! empty( $r->guest_player_id ) ) {
                $r->_player_name = trim( (string) $r->first_name . ' ' . (string) $r->last_name );
                $r->_home_team   = (string) ( $r->home_team_name ?? '' );
            }
            $out[] = $r;
        }
        return $out;
    }
}
