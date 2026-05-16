<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;

/**
 * FrontendTournamentsManageView (#0093 chunk 3) — list + detail +
 * flat-form CRUD for tournaments. Admin-only in v1; the dispatcher
 * gates on `tt_view_tournaments` before reaching this class.
 *
 * Modes via query string:
 *
 *   ?tt_view=tournaments                 — list view (FrontendListTable)
 *   ?tt_view=tournaments&action=new      — flat-form create
 *   ?tt_view=tournaments&id=<int>        — detail (matches + squad +
 *                                          planner placeholder)
 *   ?tt_view=tournaments&id=<int>&action=edit
 *                                        — flat-form edit (basics only;
 *                                          matches + squad edited from
 *                                          detail page in later chunks)
 *
 * The flat-form path is the power-user / fallback creator; chunk 4
 * ships the `new-tournament` wizard and routes `+ New tournament`
 * through `WizardEntryPoint::urlFor()` instead. Both paths POST to
 * `/wp-json/talenttrack/v1/tournaments`.
 *
 * The per-match planner grid + drag-drop + sticky minutes ticker
 * (the headline UI from the spec) land in chunks 5-6; the detail
 * view here renders the matches and squad as plain stacked cards
 * so the operator can verify CRUD before the planner UI lands.
 */
class FrontendTournamentsManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        if ( ! current_user_can( 'tt_view_tournaments' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to tournaments.', 'talenttrack' ) . '</p>';
            return;
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $list_label   = __( 'Tournaments', 'talenttrack' );
        $parent_crumb = [ FrontendBreadcrumbs::viewCrumb( 'tournaments', $list_label ) ];

        if ( $action === 'new' ) {
            if ( ! current_user_can( 'tt_edit_tournaments' ) ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'Your role cannot create tournaments.', 'talenttrack' ) . '</p>';
                return;
            }
            FrontendBreadcrumbs::fromDashboard( __( 'New tournament', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'New tournament', 'talenttrack' ) );
            self::renderForm( $user_id, $is_admin, null );
            return;
        }

        if ( $id > 0 && $action === 'edit' ) {
            if ( ! current_user_can( 'tt_edit_tournaments' ) ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'Your role cannot edit tournaments.', 'talenttrack' ) . '</p>';
                return;
            }
            $tournament = self::loadTournament( $id );
            $title      = $tournament ? sprintf( __( 'Edit tournament — %s', 'talenttrack' ), (string) $tournament->name ) : __( 'Tournament not found', 'talenttrack' );
            FrontendBreadcrumbs::fromDashboard( $title, $parent_crumb );
            self::renderHeader( $title );
            if ( ! $tournament ) {
                echo '<p class="tt-notice">' . esc_html__( 'That tournament no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderForm( $user_id, $is_admin, $tournament );
            return;
        }

        if ( $id > 0 ) {
            $tournament = self::loadTournament( $id );
            $title      = $tournament ? (string) $tournament->name : __( 'Tournament not found', 'talenttrack' );
            FrontendBreadcrumbs::fromDashboard( $title, $parent_crumb );
            if ( ! $tournament ) {
                self::renderHeader( $title );
                echo '<p class="tt-notice">' . esc_html__( 'That tournament no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderDetail( $tournament, $user_id, $is_admin );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( $list_label );

        // Page-header action: + New tournament. Chunk 4 routes this
        // through WizardEntryPoint::urlFor() once the wizard is
        // registered; until then it falls through to the flat-form
        // path.
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $page_actions = [];
        if ( current_user_can( 'tt_edit_tournaments' ) ) {
            $flat_url = add_query_arg( [ 'tt_view' => 'tournaments', 'action' => 'new' ], $base_url );
            $page_actions[] = [
                'label'   => __( 'New tournament', 'talenttrack' ),
                'href'    => class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' )
                    ? \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-tournament', $flat_url )
                    : $flat_url,
                'primary' => true,
                'icon'    => '+',
            ];
        }
        self::renderHeader( $list_label, self::pageActionsHtml( $page_actions ) );
        self::renderList( $user_id, $is_admin );
    }

    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );

        // Build the team filter from the user's accessible teams.
        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $team_options = [];
        foreach ( $teams as $t ) {
            $team_options[ (int) $t->id ] = (string) $t->name;
        }

        $row_actions = [
            'view' => [
                'label' => __( 'Open', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'tournaments', 'id' => '{id}' ], $base_url ),
            ],
        ];
        if ( current_user_can( 'tt_edit_tournaments' ) ) {
            $row_actions['edit'] = [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'tournaments', 'id' => '{id}', 'action' => 'edit' ], $base_url ),
            ];
        }

        echo FrontendListTable::render( [
            'rest_path' => 'tournaments',
            'columns' => [
                'name'       => [ 'label' => __( 'Name', 'talenttrack' ),       'sortable' => true ],
                'team_name'  => [ 'label' => __( 'Team', 'talenttrack' ) ],
                'start_date' => [ 'label' => __( 'Start', 'talenttrack' ),      'sortable' => true, 'render' => 'date' ],
                'end_date'   => [ 'label' => __( 'End', 'talenttrack' ),        'render' => 'date' ],
                'default_formation' => [ 'label' => __( 'Formation', 'talenttrack' ) ],
            ],
            'filters' => [
                'team_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Team', 'talenttrack' ),
                    'options' => $team_options,
                ],
                'status' => [
                    'type'    => 'select',
                    'label'   => __( 'Status', 'talenttrack' ),
                    'options' => [
                        'active'   => __( 'Active',   'talenttrack' ),
                        'archived' => __( 'Archived', 'talenttrack' ),
                    ],
                ],
            ],
            'row_actions'  => $row_actions,
            'search'       => [ 'placeholder' => __( 'Search by name…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'start_date', 'order' => 'desc' ],
            'empty_state'  => __( 'No tournaments yet.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    /**
     * Detail / planner view. Chunks 5-6 replace the matches + squad
     * stacks below with the proper planner grid + sticky minutes
     * ticker. v3.110.131-foundation just renders matches + squad as
     * read-only stacks so CRUD can be exercised end-to-end.
     */
    private static function renderDetail( object $tournament, int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );
        $edit_url = add_query_arg( [ 'tt_view' => 'tournaments', 'id' => (int) $tournament->id, 'action' => 'edit' ], $base_url );

        $page_actions = [];
        if ( current_user_can( 'tt_edit_tournaments' ) ) {
            $page_actions[] = [
                'label' => __( 'Edit', 'talenttrack' ),
                'href'  => $edit_url,
                'icon'  => '✎',
            ];
        }
        self::renderHeader( (string) $tournament->name, self::pageActionsHtml( $page_actions ) );

        global $wpdb; $p = $wpdb->prefix;

        $team_name = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
            (int) $tournament->team_id, CurrentClub::id()
        ) );

        $matches = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_tournament_matches
              WHERE tournament_id = %d AND club_id = %d
           ORDER BY sequence ASC",
            (int) $tournament->id, CurrentClub::id()
        ) ) ?: [];

        $squad = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.*, pl.first_name, pl.last_name
               FROM {$p}tt_tournament_squad s
               JOIN {$p}tt_players pl ON pl.id = s.player_id AND pl.club_id = s.club_id
              WHERE s.tournament_id = %d AND s.club_id = %d
           ORDER BY pl.last_name ASC, pl.first_name ASC",
            (int) $tournament->id, CurrentClub::id()
        ) ) ?: [];

        // Tournament facts strip.
        ?>
        <div class="tt-tournament-facts" style="display:flex;flex-wrap:wrap;gap:var(--tt-sp-3, 12px);margin-bottom:var(--tt-sp-4, 16px);">
            <div class="tt-fact">
                <strong><?php esc_html_e( 'Team', 'talenttrack' ); ?>:</strong>
                <?php echo esc_html( $team_name !== '' ? $team_name : __( '—', 'talenttrack' ) ); ?>
            </div>
            <div class="tt-fact">
                <strong><?php esc_html_e( 'Dates', 'talenttrack' ); ?>:</strong>
                <?php
                $start = (string) $tournament->start_date;
                $end   = (string) ( $tournament->end_date ?? '' );
                echo esc_html( $end !== '' ? $start . ' → ' . $end : $start );
                ?>
            </div>
            <?php if ( $tournament->default_formation ) : ?>
                <div class="tt-fact">
                    <strong><?php esc_html_e( 'Formation', 'talenttrack' ); ?>:</strong>
                    <?php echo esc_html( (string) $tournament->default_formation ); ?>
                </div>
            <?php endif; ?>
            <div class="tt-fact">
                <strong><?php esc_html_e( 'Squad', 'talenttrack' ); ?>:</strong>
                <?php echo (int) count( $squad ); ?>
            </div>
            <div class="tt-fact">
                <strong><?php esc_html_e( 'Matches', 'talenttrack' ); ?>:</strong>
                <?php echo (int) count( $matches ); ?>
            </div>
        </div>

        <p class="tt-notice tt-notice-info">
            <?php esc_html_e( 'The interactive planner grid + minutes ticker land in a follow-up. For now, the matches and squad are listed below.', 'talenttrack' ); ?>
        </p>

        <h3><?php esc_html_e( 'Matches', 'talenttrack' ); ?></h3>
        <?php if ( ! $matches ) : ?>
            <p class="tt-muted"><?php esc_html_e( 'No matches yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <ol class="tt-tournament-matches" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:var(--tt-sp-3, 12px);">
                <?php foreach ( $matches as $m ) : ?>
                    <li class="tt-card" style="padding:var(--tt-sp-3, 12px);border:1px solid var(--tt-line, #e2e8f0);border-radius:var(--tt-r-md, 8px);">
                        <strong>
                            <?php
                            $label = (string) ( $m->label ?? '' );
                            $opp   = (string) ( $m->opponent_name ?? '' );
                            $headline = $label !== '' ? $label : ( $opp !== '' ? sprintf( __( 'vs %s', 'talenttrack' ), $opp ) : sprintf( __( 'Match %d', 'talenttrack' ), (int) $m->sequence ) );
                            echo esc_html( $headline );
                            ?>
                        </strong>
                        <?php if ( $m->opponent_level ) : ?>
                            <span class="tt-pill" style="margin-left:8px;font-size:11px;"><?php echo esc_html( (string) $m->opponent_level ); ?></span>
                        <?php endif; ?>
                        <div class="tt-muted" style="font-size:12px;margin-top:4px;">
                            <?php
                            $windows = json_decode( (string) $m->substitution_windows, true ) ?: [];
                            $win_label = $windows
                                ? sprintf( __( 'subs at %s', 'talenttrack' ), implode( ', ', array_map( static function ( $w ) { return $w . "'"; }, $windows ) ) )
                                : __( 'no subs', 'talenttrack' );
                            $bits = [
                                sprintf( __( '%d min', 'talenttrack' ), (int) $m->duration_min ),
                                $win_label,
                            ];
                            if ( $m->formation ) $bits[] = (string) $m->formation;
                            if ( $m->completed_at ) $bits[] = __( 'completed', 'talenttrack' );
                            elseif ( $m->kicked_off_at ) $bits[] = __( 'in progress', 'talenttrack' );
                            echo esc_html( implode( ' · ', $bits ) );
                            ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <h3 style="margin-top:var(--tt-sp-5, 24px);"><?php esc_html_e( 'Squad', 'talenttrack' ); ?></h3>
        <?php if ( ! $squad ) : ?>
            <p class="tt-muted"><?php esc_html_e( 'No players in the squad yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <ul class="tt-tournament-squad" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:6px;">
                <?php foreach ( $squad as $sq ) : ?>
                    <li style="padding:6px 10px;border:1px solid var(--tt-line, #e2e8f0);border-radius:var(--tt-r-sm, 4px);display:flex;justify-content:space-between;gap:var(--tt-sp-3, 12px);">
                        <span><?php echo esc_html( trim( ( (string) $sq->first_name ) . ' ' . ( (string) $sq->last_name ) ) ); ?></span>
                        <span class="tt-muted" style="font-size:12px;">
                            <?php
                            $pos = json_decode( (string) $sq->eligible_positions, true ) ?: [];
                            echo esc_html( $pos ? implode( ' · ', $pos ) : __( '(no positions set)', 'talenttrack' ) );
                            if ( $sq->target_minutes !== null ) {
                                echo ' · ' . esc_html( sprintf( __( '%d min target', 'talenttrack' ), (int) $sq->target_minutes ) );
                            }
                            ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    private static function renderForm( int $user_id, bool $is_admin, ?object $tournament ): void {
        $is_edit   = $tournament !== null;
        $rest_path = $is_edit ? 'tournaments/' . (int) $tournament->id : 'tournaments';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-tournament-form';

        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );
        $formations = QueryHelpers::get_lookup_names( 'tournament_formation' );

        $cancel_url = $is_edit
            ? esc_url( add_query_arg( [ 'tt_view' => 'tournaments', 'id' => (int) $tournament->id ], remove_query_arg( [ 'action' ] ) ) )
            : esc_url( add_query_arg( [ 'tt_view' => 'tournaments' ], remove_query_arg( [ 'action', 'id' ] ) ) );
        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>" data-redirect-after-save="list">
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-tour-name"><?php esc_html_e( 'Tournament name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-tour-name" class="tt-input" name="name" required value="<?php echo esc_attr( (string) ( $tournament->name ?? '' ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-tour-team"><?php esc_html_e( 'Anchor team', 'talenttrack' ); ?></label>
                    <select id="tt-tour-team" class="tt-input" name="team_id" required>
                        <option value=""><?php esc_html_e( '— choose —', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams as $t ) : ?>
                            <option value="<?php echo (int) $t->id; ?>" <?php selected( (int) ( $tournament->team_id ?? 0 ), (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php echo DateInputComponent::render( [
                    'name'     => 'start_date',
                    'label'    => __( 'Start date', 'talenttrack' ),
                    'value'    => (string) ( $tournament->start_date ?? '' ),
                    'required' => true,
                ] ); ?>
                <?php echo DateInputComponent::render( [
                    'name'  => 'end_date',
                    'label' => __( 'End date', 'talenttrack' ),
                    'value' => (string) ( $tournament->end_date ?? '' ),
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-tour-formation"><?php esc_html_e( 'Default formation', 'talenttrack' ); ?></label>
                    <select id="tt-tour-formation" class="tt-input" name="default_formation">
                        <option value=""><?php esc_html_e( '(none)', 'talenttrack' ); ?></option>
                        <?php foreach ( $formations as $f ) : ?>
                            <option value="<?php echo esc_attr( (string) $f ); ?>" <?php selected( (string) ( $tournament->default_formation ?? '' ), (string) $f ); ?>><?php echo esc_html( (string) $f ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field tt-field-full">
                    <label class="tt-field-label" for="tt-tour-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                    <textarea id="tt-tour-notes" class="tt-input" name="notes" rows="3"><?php echo esc_textarea( (string) ( $tournament->notes ?? '' ) ); ?></textarea>
                </div>
            </div>

            <?php echo FormSaveButton::render( [
                'cancel_url' => $cancel_url,
                'label'      => $is_edit ? __( 'Save changes', 'talenttrack' ) : __( 'Create tournament', 'talenttrack' ),
            ] ); ?>
        </form>
        <?php
    }

    private static function loadTournament( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_tournaments WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }
}
