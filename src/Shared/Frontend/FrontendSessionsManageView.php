<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * FrontendSessionsManageView — full-CRUD frontend for training sessions.
 *
 * #0019 Sprint 2 session 2.3. Replaces the v3.0.0 placeholder
 * `FrontendSessionsView` (which only rendered a create form). Three
 * modes selected via query string:
 *
 *   ?tt_view=sessions               — list (FrontendListTable) + Create CTA
 *   ?tt_view=sessions&action=new    — create form
 *   ?tt_view=sessions&id=<int>      — edit form (loads existing row + attendance)
 *
 * Saves go through the REST endpoints introduced in Sprint 1
 * (`POST/PUT /sessions`, attendance handled inline). Delete is wired
 * as a row action on the list view, hitting `DELETE /sessions/{id}`
 * which the controller treats as a soft-archive: `archived_at` gets
 * set rather than the row being removed.
 *
 * Bulk attendance + mobile pagination behaviour lives in
 * `assets/js/components/attendance.js` (loaded by DashboardShortcode).
 */
class FrontendSessionsManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New session', 'talenttrack' ) );
            self::renderForm( $user_id, $is_admin, null, [] );
            return;
        }

        if ( $id > 0 ) {
            $session    = self::loadSession( $id );
            $attendance = $session ? self::loadAttendance( $id ) : [];
            self::renderHeader( $session ? sprintf( __( 'Edit session — %s', 'talenttrack' ), (string) $session->title ) : __( 'Session not found', 'talenttrack' ) );
            if ( ! $session ) {
                echo '<p class="tt-notice">' . esc_html__( 'That session no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $user_id, $is_admin, $session, $attendance );
            return;
        }

        // Default: list view.
        self::renderHeader( __( 'Sessions', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    /**
     * List view — FrontendListTable + "Create" CTA.
     */
    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $new_url  = add_query_arg( [ 'tt_view' => 'sessions', 'action' => 'new' ], $base_url );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New session', 'talenttrack' )
            . '</a></p>';

        $row_actions = [
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'sessions', 'id' => '{id}' ], $base_url ),
            ],
            'delete' => [
                'label'       => __( 'Delete', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'sessions/{id}',
                'confirm'     => __( 'Delete this session? It will be archived.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'sessions',
            'columns' => [
                'session_date' => [ 'label' => __( 'Date',   'talenttrack' ), 'sortable' => true ],
                'team_name'    => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true ],
                'title'        => [ 'label' => __( 'Title',  'talenttrack' ), 'sortable' => true ],
                'attendance'   => [ 'label' => __( 'Att. %', 'talenttrack' ), 'sortable' => true, 'render' => 'percent', 'value_key' => 'attendance_pct' ],
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
            'empty_state'  => __( 'No sessions match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    /**
     * Create / edit form. Uses the existing REST endpoints; PUT vs
     * POST is decided by whether `$session` is set.
     *
     * @param object|null $session
     * @param array<int,object> $attendance keyed by player_id
     */
    private static function renderForm( int $user_id, bool $is_admin, ?object $session, array $attendance ): void {
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

        $statuses = QueryHelpers::get_lookup_names( 'attendance_status' );

        // Edit mode → PUT /sessions/{id}; create → POST /sessions.
        $is_edit   = $session !== null;
        $rest_path = $is_edit ? 'sessions/' . (int) $session->id : 'sessions';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-session-form';
        $draft_key = $is_edit ? '' : 'session-form'; // edit forms don't draft — the row is the source of truth

        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form tt-session-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>"<?php if ( $draft_key !== '' ) : ?> data-draft-key="<?php echo esc_attr( $draft_key ); ?>"<?php endif; ?>>
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-session-title"><?php esc_html_e( 'Title', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-session-title" class="tt-input" name="title" required value="<?php echo esc_attr( (string) ( $session->title ?? '' ) ); ?>" />
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
                    <label class="tt-field-label" for="tt-session-location"><?php esc_html_e( 'Location', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-session-location" class="tt-input" name="location" value="<?php echo esc_attr( (string) ( $session->location ?? '' ) ); ?>" />
                </div>
            </div>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-session-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-session-notes" class="tt-input" name="notes" rows="2"><?php echo esc_textarea( (string) ( $session->notes ?? '' ) ); ?></textarea>
            </div>

            <h3 style="margin:24px 0 12px;"><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h3>

            <?php if ( ! $all_players ) : ?>
                <p><em><?php esc_html_e( 'No players on your teams yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <div class="tt-attendance" data-tt-attendance="1" data-current-team="<?php echo (int) $selected_team; ?>">
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

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update session', 'talenttrack' ) : __( 'Save session', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    private static function loadSession( int $id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 's', 'session' );
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.* FROM {$p}tt_sessions s WHERE s.id = %d AND s.archived_at IS NULL {$scope}",
            $id
        ) );
        return $row ?: null;
    }

    /**
     * @return array<int, object> attendance rows keyed by player_id.
     */
    private static function loadAttendance( int $session_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_attendance WHERE session_id = %d",
            $session_id
        ) );
        $out = [];
        foreach ( $rows ?: [] as $r ) {
            $out[ (int) $r->player_id ] = $r;
        }
        return $out;
    }
}
