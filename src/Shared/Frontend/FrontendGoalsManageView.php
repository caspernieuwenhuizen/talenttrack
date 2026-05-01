<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\PlayerPickerComponent;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * FrontendGoalsManageView — full-CRUD frontend for player goals.
 *
 * #0019 Sprint 2 session 2.4 — closes Sprint 2. Mirrors the
 * Sessions view structure (list / create / edit dispatch via
 * query string) but for goals.
 *
 *   ?tt_view=goals             — list view + "New goal" CTA
 *   ?tt_view=goals&action=new  — create form
 *   ?tt_view=goals&id=<int>    — edit form (prefilled)
 *
 * The list view uses an inline-select column for status — Q4 in
 * the Sprint 2 plan locked us to row-inline status edits (no
 * detail panel). The select hits `PATCH /goals/{id}/status`
 * (added in Sprint 1 session 1) via the FrontendListTable's
 * inline_select render type (added alongside this view).
 *
 * Delete is a row action that hits `DELETE /goals/{id}` — the
 * controller treats it as a soft-archive (sets `archived_at`).
 */
class FrontendGoalsManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( $action === 'new' ) {
            self::renderHeader( __( 'New goal', 'talenttrack' ) );
            self::renderForm( $user_id, $is_admin, null );
            return;
        }

        // v3.70.1 hotfix — `?tt_view=goals&id=N` (no action) renders a
        // read-only detail; the edit form requires `&action=edit`.
        // Mirrors the activities pattern so title clicks land on a
        // viewable detail page instead of an edit form.
        if ( $id > 0 && $action === 'edit' ) {
            $goal = self::loadGoal( $id );
            self::renderHeader( $goal ? sprintf( __( 'Edit goal — %s', 'talenttrack' ), (string) $goal->title ) : __( 'Goal not found', 'talenttrack' ) );
            if ( ! $goal ) {
                echo '<p class="tt-notice">' . esc_html__( 'That goal no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $user_id, $is_admin, $goal );
            return;
        }

        if ( $id > 0 ) {
            $goal = self::loadGoal( $id );
            self::renderHeader( $goal ? (string) $goal->title : __( 'Goal not found', 'talenttrack' ) );
            if ( ! $goal ) {
                echo '<p class="tt-notice">' . esc_html__( 'That goal no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderDetail( $goal, $user_id );
            return;
        }

        self::renderHeader( __( 'Goals', 'talenttrack' ) );
        self::renderList( $user_id, $is_admin );
    }

    /**
     * v3.70.1 hotfix — read-only goal detail. Shows the core fields plus
     * the goal's #0028 conversation thread when available.
     *
     * @param object $goal goal row from `loadGoal`
     */
    private static function renderDetail( object $goal, int $user_id ): void {
        $back_url = add_query_arg( [ 'tt_view' => 'goals' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        \TT\Shared\Frontend\FrontendBackButton::render( $back_url );

        $player_id = (int) ( $goal->player_id ?? 0 );
        $player    = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        $status    = (string) ( $goal->status ?? '' );
        $priority  = (string) ( $goal->priority ?? '' );
        $due_date  = (string) ( $goal->due_date ?? '' );
        $desc      = (string) ( $goal->description ?? '' );

        echo '<div class="tt-record-detail" style="display:grid; gap:12px;">';
        echo '<dl class="tt-record-detail-list" style="display:grid; grid-template-columns: minmax(120px, max-content) 1fr; gap:6px 16px; margin:0;">';

        if ( $player ) {
            echo '<dt>' . esc_html__( 'Player', 'talenttrack' ) . '</dt>';
            echo '<dd>'
                . \TT\Shared\Frontend\Components\RecordLink::inline( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    QueryHelpers::player_display_name( $player ),
                    \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'players', $player_id )
                )
                . '</dd>';
        }

        if ( $status !== '' ) {
            echo '<dt>' . esc_html__( 'Status', 'talenttrack' ) . '</dt>';
            echo '<dd>' . \TT\Infrastructure\Query\LookupPill::render( 'goal_status', $status ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        if ( $priority !== '' ) {
            echo '<dt>' . esc_html__( 'Priority', 'talenttrack' ) . '</dt>';
            echo '<dd>' . esc_html( LabelTranslator::goalPriority( $priority ) ) . '</dd>';
        }
        if ( $due_date !== '' ) {
            echo '<dt>' . esc_html__( 'Due', 'talenttrack' ) . '</dt>';
            echo '<dd>' . esc_html( $due_date ) . '</dd>';
        }
        if ( $desc !== '' ) {
            echo '<dt>' . esc_html__( 'Description', 'talenttrack' ) . '</dt>';
            echo '<dd style="white-space:pre-wrap;">' . esc_html( $desc ) . '</dd>';
        }

        echo '</dl>';

        if ( current_user_can( 'tt_edit_goals' ) ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'goals', 'id' => (int) $goal->id, 'action' => 'edit' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            );
            echo '<p><a class="tt-btn tt-btn-secondary" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'talenttrack' ) . '</a></p>';
        }

        if ( class_exists( '\TT\Shared\Frontend\Components\FrontendThreadView' ) ) {
            echo '<section class="tt-pde-section" style="margin-top:16px;">';
            echo '<h3 style="margin:0 0 8px;">' . esc_html__( 'Conversation', 'talenttrack' ) . '</h3>';
            \TT\Shared\Frontend\Components\FrontendThreadView::render( 'goal', (int) $goal->id, $user_id );
            echo '</section>';
        }

        echo '</div>';
    }

    /**
     * List view — FrontendListTable with team/player/status/priority/
     * deadline filters, inline status select, Edit/Delete row actions.
     */
    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $flat_url = add_query_arg( [ 'tt_view' => 'goals', 'action' => 'new' ], $base_url );
        $new_url  = \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-goal', $flat_url );

        echo '<p style="margin:0 0 var(--tt-sp-3, 12px);"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New goal', 'talenttrack' )
            . '</a></p>';

        // Build status / priority option maps from the lookup tables so
        // a club running custom statuses ("Achieved", "Cancelled", …)
        // sees their values without code changes.
        $status_options = [];
        foreach ( QueryHelpers::get_lookup_names( 'goal_status' ) as $st ) {
            $value = strtolower( str_replace( ' ', '_', (string) $st ) );
            $status_options[ $value ] = LabelTranslator::goalStatus( $value );
        }
        $priority_options = [];
        foreach ( QueryHelpers::get_lookup_names( 'goal_priority' ) as $pr ) {
            $value = strtolower( (string) $pr );
            $priority_options[ $value ] = LabelTranslator::goalPriority( $value );
        }

        // Player filter — admin sees all players, coach sees own teams.
        $player_options = [];
        if ( $is_admin ) {
            foreach ( QueryHelpers::get_players() as $pl ) {
                $player_options[ (int) $pl->id ] = QueryHelpers::player_display_name( $pl );
            }
        } else {
            foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
                foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                    $player_options[ (int) $pl->id ] = QueryHelpers::player_display_name( $pl );
                }
            }
        }

        $row_actions = [
            // v3.70.1 hotfix — Edit row action carries `action=edit` so
            // it routes to the form; bare `id=N` (from title clicks) goes
            // to the read-only detail in render() above.
            'edit' => [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'goals', 'id' => '{id}', 'action' => 'edit' ], $base_url ),
            ],
            'delete' => [
                'label'       => __( 'Delete', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => 'goals/{id}',
                'confirm'     => __( 'Delete this goal? It will be archived.', 'talenttrack' ),
                'variant'     => 'danger',
            ],
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'goals',
            'columns' => [
                // #0063 — player + goal title clickable; status as a
                // colour pill (display-only) instead of an inline-select.
                // Inline edit lives on the goal form; the table reads.
                'player_name' => [ 'label' => __( 'Player',   'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'player_link_html' ],
                'title'       => [ 'label' => __( 'Goal',     'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'title_link_html' ],
                'priority'    => [ 'label' => __( 'Priority', 'talenttrack' ), 'sortable' => true ],
                'status'      => [
                    'label'       => __( 'Status', 'talenttrack' ),
                    'sortable'    => true,
                    'render'      => 'html',
                    'value_key'   => 'status_pill_html',
                    // Drop the inline_select / options / patch_path — status
                    // is now display-only on the table per the user's
                    // "should be display only. use colored pills" ask.
                    'options'     => $status_options,
                    'patch_path'  => 'goals/{id}/status',
                    'patch_field' => 'status',
                ],
                'due_date'    => [ 'label' => __( 'Due',      'talenttrack' ), 'sortable' => true, 'render' => 'date' ],
            ],
            'filters' => [
                'team_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Team', 'talenttrack' ),
                    'options' => TeamPickerComponent::filterOptions( $user_id, $is_admin ),
                ],
                'player_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Player', 'talenttrack' ),
                    'options' => $player_options,
                ],
                'status' => [
                    'type'    => 'select',
                    'label'   => __( 'Status', 'talenttrack' ),
                    'options' => $status_options,
                ],
                'priority' => [
                    'type'    => 'select',
                    'label'   => __( 'Priority', 'talenttrack' ),
                    'options' => $priority_options,
                ],
                'due' => [
                    'type'       => 'date_range',
                    'param_from' => 'due_from',
                    'param_to'   => 'due_to',
                    'label_from' => __( 'Due from', 'talenttrack' ),
                    'label_to'   => __( 'Due to',   'talenttrack' ),
                ],
            ],
            'row_actions'  => $row_actions,
            'search'       => [ 'placeholder' => __( 'Search title, description, player…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'due_date', 'order' => 'asc' ],
            'empty_state'  => __( 'No goals match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    /**
     * Create / edit form. PUT vs POST decided by `$goal`.
     */
    private static function renderForm( int $user_id, bool $is_admin, ?object $goal ): void {
        $statuses   = QueryHelpers::get_lookup_names( 'goal_status' );
        $priorities = QueryHelpers::get_lookup_names( 'goal_priority' );

        $is_edit   = $goal !== null;
        $rest_path = $is_edit ? 'goals/' . (int) $goal->id : 'goals';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-goal-form';
        $draft_key = $is_edit ? '' : 'goal-form';

        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>" data-redirect-after-save="list"<?php if ( $draft_key !== '' ) : ?> data-draft-key="<?php echo esc_attr( $draft_key ); ?>"<?php endif; ?>>
            <div class="tt-grid tt-grid-2">
                <?php echo PlayerPickerComponent::render( [
                    'name'     => 'player_id',
                    'label'    => __( 'Player', 'talenttrack' ),
                    'required' => true,
                    'user_id'  => $user_id,
                    'is_admin' => $is_admin,
                    'selected' => (int) ( $goal->player_id ?? 0 ),
                ] ); ?>
                <?php echo DateInputComponent::render( [
                    'name'     => 'due_date',
                    'label'    => __( 'Due date', 'talenttrack' ),
                    'value'    => (string) ( $goal->due_date ?? '' ),
                    // No default so the field stays empty for "no deadline" goals.
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-goal-title"><?php esc_html_e( 'Title', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-goal-title" class="tt-input" name="title" required value="<?php echo esc_attr( (string) ( $goal->title ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-goal-priority"><?php esc_html_e( 'Priority', 'talenttrack' ); ?></label>
                    <select id="tt-goal-priority" class="tt-input" name="priority">
                        <?php foreach ( $priorities as $pr ) :
                            $value = strtolower( (string) $pr );
                            ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( strtolower( (string) ( $goal->priority ?? 'medium' ) ), $value ); ?>>
                                <?php echo esc_html( LabelTranslator::goalPriority( $value ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ( $is_edit ) : // status only editable on the edit form (create defaults to 'pending' via REST) ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-goal-status"><?php esc_html_e( 'Status', 'talenttrack' ); ?></label>
                    <select id="tt-goal-status" class="tt-input" name="status">
                        <?php foreach ( $statuses as $st ) :
                            $value = strtolower( str_replace( ' ', '_', (string) $st ) );
                            ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $goal->status ?? '' ), $value ); ?>>
                                <?php echo esc_html( LabelTranslator::goalStatus( $value ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-goal-description"><?php esc_html_e( 'Description', 'talenttrack' ); ?></label>
                <textarea id="tt-goal-description" class="tt-input" name="description" rows="3"><?php echo esc_textarea( (string) ( $goal->description ?? '' ) ); ?></textarea>
            </div>

            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => $is_edit ? __( 'Update goal', 'talenttrack' ) : __( 'Add goal', 'talenttrack' ) ] ); ?>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="tt-btn tt-btn-secondary">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        // #0028 — chat-style conversation thread for the goal. Only on
        // edit (existing goal) and only when the viewer can read the thread.
        if ( $is_edit && class_exists( '\\TT\\Shared\\Frontend\\Components\\FrontendThreadView' ) ) {
            echo '<section class="tt-goal-conversation" style="margin-top:1.5rem;">';
            echo '<header style="display:flex; align-items:baseline; gap:8px; margin: 0 0 0.5rem;">';
            echo '<h2 style="font-size:1.0625rem; margin:0;">' . esc_html__( 'Conversation', 'talenttrack' ) . '</h2>';
            // #0063 — help button explaining what the goal-conversation
            // is for. Points at the existing conversational-goals doc
            // shipped with #0028. Opens in a new tab so the coach
            // doesn't lose the form they're filling in.
            $help_url = add_query_arg(
                [ 'tt_view' => 'docs', 'topic' => 'conversational-goals' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            );
            echo '<a class="tt-link" href="' . esc_url( $help_url ) . '" target="_blank" rel="noopener" style="font-size:12px; color:#5b6e75;">'
               . esc_html__( 'How does this work?', 'talenttrack' ) . '</a>';
            echo '</header>';
            \TT\Shared\Frontend\Components\FrontendThreadView::render( 'goal', (int) $goal->id, $user_id );
            echo '</section>';
        }
    }

    private static function loadGoal( int $id ): ?object {
        global $wpdb; $p = $wpdb->prefix;
        $scope = QueryHelpers::apply_demo_scope( 'g', 'goal' );
        /** @var object|null $row */
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT g.* FROM {$p}tt_goals g WHERE g.id = %d AND g.archived_at IS NULL {$scope}",
            $id
        ) );
        return $row ?: null;
    }
}
