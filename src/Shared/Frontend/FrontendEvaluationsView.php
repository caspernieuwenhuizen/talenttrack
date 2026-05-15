<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendEvaluationsView — coach-tier evaluations surface.
 *
 * List mode (default): recent evaluations across the coach's teams,
 * with filters and a "New evaluation" CTA.
 *
 * Create mode (?action=new): shows the evaluation form via
 * CoachForms::renderEvalForm. After save the form's existing redirect
 * sends the user back to the list.
 *
 * Edit mode (?action=edit&id=N): shows the same form in PUT mode,
 * prefilled from the existing eval row. Added in v3.110.55 alongside
 * the page-header Edit + Archive affordances on the detail view.
 */
class FrontendEvaluationsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $teams  = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        // v3.110.64 — every code path on this routable view now emits
        // a `Dashboard / Evaluations / …` breadcrumb chain, per the
        // two-affordance contract in `docs/back-navigation.md`. The
        // list / new / edit / not-found paths previously called
        // `renderHeader()` without a breadcrumb, so the user had no
        // top-level link back to the dashboard. Detail path already
        // sets its own crumb in `renderDetail()`; nothing changes
        // there.
        $eval_crumb = \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb(
            'evaluations',
            __( 'Evaluations', 'talenttrack' )
        );

        if ( $action === 'new' ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'New evaluation', 'talenttrack' ),
                [ $eval_crumb ]
            );
            self::renderHeader( __( 'New evaluation', 'talenttrack' ) );
            // v3.110.3 — when launched from a player profile's empty
            // Evaluations tab, `?player_id=N` is in the URL; pre-fill
            // the form so the picker step is skipped.
            $preset_player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
            CoachForms::renderEvalForm( $teams, $is_admin, $preset_player_id );
            return;
        }

        // v3.110.55 — edit mode. Same form as create, switched to PUT
        // and prefilled. Cap-gated; falls through to the read-only
        // detail when the user can't edit.
        if ( $action === 'edit' && $id > 0 && current_user_can( 'tt_edit_evaluations' ) ) {
            $existing = self::loadEvaluation( $id );
            if ( ! $existing ) {
                \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                    __( 'Evaluation not found', 'talenttrack' ),
                    [ $eval_crumb ]
                );
                self::renderHeader( __( 'Evaluation not found', 'talenttrack' ) );
                echo '<p><em>' . esc_html__( 'That evaluation no longer exists, or you do not have access.', 'talenttrack' ) . '</em></p>';
                return;
            }
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Edit evaluation', 'talenttrack' ),
                [ $eval_crumb ]
            );
            self::renderHeader( __( 'Edit evaluation', 'talenttrack' ) );
            CoachForms::renderEvalForm( $teams, $is_admin, 0, $existing );
            return;
        }

        // v3.110.4 — `?tt_view=evaluations&id=N` renders a read-only
        // detail page. Previously the URL was unhandled and the user
        // bounced back to the list — meaning every list-row link went
        // to the player / team / coach instead of the eval itself.
        if ( $id > 0 ) {
            self::renderDetail( $id, $user_id, $is_admin );
            return;
        }

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'Evaluations', 'talenttrack' )
        );
        self::renderHeader( __( 'Evaluations', 'talenttrack' ) );

        $base_url = remove_query_arg( [ 'action', 'id', 'f_team_id', 'f_player_id', 'f_date_from', 'f_date_to' ] );
        $flat_url = add_query_arg( [ 'tt_view' => 'evaluations', 'action' => 'new' ], $base_url );
        $new_url  = \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-evaluation', $flat_url );

        // v3.110.102 — pass `tt_back` pointing back at the
        // evaluations list. The wizard view now honours tt_back as
        // the Cancel target (alongside the existing `return_to`
        // mechanism) AND `FrontendBreadcrumbs::fromDashboard` emits
        // the `← Back to evaluations` pill at the top of the wizard
        // surface. Two affordances, both routed back to where the
        // coach came from. Pilot symptom: entering the wizard from
        // the evaluations tile and clicking Cancel dropped them on
        // the dashboard, not back on the list.
        $eval_list_url = add_query_arg( [ 'tt_view' => 'evaluations' ], $base_url );
        $new_url       = add_query_arg( [ 'tt_back' => rawurlencode( $eval_list_url ) ], $new_url );

        echo '<p style="margin:0 0 12px;"><a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">'
            . esc_html__( 'New evaluation', 'talenttrack' )
            . '</a></p>';

        $filters = self::filtersFromQuery();
        self::renderFilters( $filters, $teams );
        self::renderTable( $user_id, $is_admin, $filters );
    }

    /** @return array<string, mixed> */
    private static function filtersFromQuery(): array {
        $f = [];
        if ( ! empty( $_GET['f_team_id'] ) )   $f['team_id']   = absint( $_GET['f_team_id'] );
        if ( ! empty( $_GET['f_player_id'] ) ) $f['player_id'] = absint( $_GET['f_player_id'] );
        if ( ! empty( $_GET['f_date_from'] ) ) $f['date_from'] = sanitize_text_field( wp_unslash( (string) $_GET['f_date_from'] ) );
        if ( ! empty( $_GET['f_date_to'] ) )   $f['date_to']   = sanitize_text_field( wp_unslash( (string) $_GET['f_date_to'] ) );
        return $f;
    }

    /**
     * @param array<string,mixed> $filters
     * @param object[]            $teams
     */
    private static function renderFilters( array $filters, array $teams ): void {
        $sel_team   = (int) ( $filters['team_id']   ?? 0 );
        $sel_player = (int) ( $filters['player_id'] ?? 0 );
        $sel_from   = (string) ( $filters['date_from'] ?? '' );
        $sel_to     = (string) ( $filters['date_to']   ?? '' );
        ?>
        <form method="get" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:12px;">
            <input type="hidden" name="tt_view" value="evaluations" />

            <div class="tt-field" style="flex:1 1 200px;">
                <label class="tt-field-label" for="tt-eval-f-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                <select id="tt-eval-f-team" name="f_team_id" class="tt-input">
                    <option value="0"><?php esc_html_e( 'All teams', 'talenttrack' ); ?></option>
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo (int) $t->id; ?>" <?php selected( $sel_team, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-eval-f-from"><?php esc_html_e( 'From', 'talenttrack' ); ?></label>
                <input id="tt-eval-f-from" type="date" name="f_date_from" value="<?php echo esc_attr( $sel_from ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 140px;">
                <label class="tt-field-label" for="tt-eval-f-to"><?php esc_html_e( 'To', 'talenttrack' ); ?></label>
                <input id="tt-eval-f-to" type="date" name="f_date_to" value="<?php echo esc_attr( $sel_to ); ?>" class="tt-input" />
            </div>
            <div class="tt-field" style="flex:0 0 auto; align-self:flex-end;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Filter', 'talenttrack' ); ?></button>
                <a href="<?php echo esc_url( remove_query_arg( [ 'f_team_id', 'f_player_id', 'f_date_from', 'f_date_to' ] ) ); ?>" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Clear', 'talenttrack' ); ?></a>
            </div>
        </form>
        <?php
    }

    /**
     * @param array<string,mixed> $filters
     */
    private static function renderTable( int $user_id, bool $is_admin, array $filters ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Scope to the coach's teams when not admin. Joins to player +
        // team for display columns and so the team filter actually
        // filters by the player's team rather than a denormalised one.
        $where  = '1=1';
        $params = [];

        if ( ! $is_admin ) {
            $teams = QueryHelpers::get_teams_for_coach( $user_id );
            $team_ids = array_map( static fn( $t ) => (int) $t->id, $teams );
            if ( empty( $team_ids ) ) {
                echo '<p><em>' . esc_html__( 'You are not assigned to any teams yet.', 'talenttrack' ) . '</em></p>';
                return;
            }
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where  .= " AND pl.team_id IN ($placeholders)";
            $params = array_merge( $params, $team_ids );
        }

        if ( ! empty( $filters['team_id'] ) ) {
            $where   .= ' AND pl.team_id = %d';
            $params[] = (int) $filters['team_id'];
        }
        if ( ! empty( $filters['player_id'] ) ) {
            $where   .= ' AND e.player_id = %d';
            $params[] = (int) $filters['player_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $df = self::parseDate( (string) $filters['date_from'] );
            if ( $df !== '' ) {
                $where   .= ' AND e.eval_date >= %s';
                $params[] = $df;
            }
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $dt = self::parseDate( (string) $filters['date_to'] );
            if ( $dt !== '' ) {
                $where   .= ' AND e.eval_date <= %s';
                $params[] = $dt;
            }
        }

        // #0070 — also resolve the coach's tt_people.id so the trainer
        // cell can link to the person detail. Same pattern as the
        // admin EvaluationsPage uses for its trainer column.
        //
        // v3.110.4 — average rating per eval as a correlated subquery
        // over `tt_eval_ratings`. Read by `renderTable()` to render an
        // Average column whose value links to the eval detail page,
        // giving operators a way to open the eval itself (the other
        // cell links go to the player / team / coach).
        $sql = "SELECT e.id, e.eval_date, e.notes, e.player_id, e.coach_id,
                       pl.first_name, pl.last_name, pl.team_id,
                       t.name AS team_name,
                       u.display_name AS coach_name,
                       coach_p.id AS coach_person_id,
                       (SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r WHERE r.evaluation_id = e.id AND r.club_id = e.club_id) AS avg_rating
                  FROM {$p}tt_evaluations e
                  LEFT JOIN {$p}tt_players pl ON pl.id = e.player_id
                  LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
                  LEFT JOIN {$wpdb->users} u  ON u.ID  = e.coach_id
                  LEFT JOIN {$p}tt_people coach_p ON coach_p.wp_user_id = e.coach_id AND coach_p.club_id = e.club_id
                 WHERE $where AND e.archived_at IS NULL
                 ORDER BY e.eval_date DESC, e.id DESC
                 LIMIT 100";

        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_results( $sql );

        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No evaluations match these filters yet.', 'talenttrack' ) . '</p>';
            return;
        }

        // v3.110.55 — list rows are view-only. The inline Delete (×) and
        // the per-row Open button were removed: deletion now happens
        // from the detail page's Archive action, and the row cells are
        // already click-through (Date links to eval, Player/Team/Coach
        // link to their respective detail pages, Average opens eval).
        $base_url = add_query_arg( [ 'tt_view' => 'evaluations' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        ?>
        <div class="tt-table-wrap" style="overflow-x:auto;">
            <table class="tt-table tt-table-sortable" style="width:100%;">
                <thead><tr>
                    <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th>
                    <th style="text-align:right;"><?php esc_html_e( 'Average', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $r ) :
                    $name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
                    if ( $name === '' ) $name = '#' . (int) $r->player_id;
                    $team_id    = (int) ( $r->team_id ?? 0 );
                    $team_name  = (string) ( $r->team_name ?? '' );
                    $coach_name = (string) ( $r->coach_name ?? '' );
                    $coach_pid  = (int) ( $r->coach_person_id ?? 0 );
                    $eval_id    = (int) $r->id;
                    $eval_url   = \TT\Shared\Frontend\Components\BackLink::appendTo(
                        add_query_arg( [ 'tt_view' => 'evaluations', 'id' => $eval_id ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() )
                    );
                    $avg        = $r->avg_rating !== null ? round( (float) $r->avg_rating, 1 ) : null;
                    $avg_text   = $avg === null ? '—' : number_format_i18n( $avg, 1 );
                    ?>
                    <tr data-tt-row>
                        <td style="white-space:nowrap;">
                            <a class="tt-record-link" href="<?php echo esc_url( $eval_url ); ?>"><?php echo esc_html( (string) $r->eval_date ); ?></a>
                        </td>
                        <td><?php
                            // #0070 — player name links to player detail.
                            echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                $name,
                                \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $r->player_id )
                            );
                        ?></td>
                        <td><?php
                            if ( $team_id > 0 && $team_name !== '' ) {
                                echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                    $team_name,
                                    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', $team_id )
                                );
                            } else {
                                echo esc_html( $team_name !== '' ? $team_name : '—' );
                            }
                        ?></td>
                        <td><?php
                            if ( $coach_name !== '' && $coach_pid > 0 ) {
                                echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                    $coach_name,
                                    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $coach_pid )
                                );
                            } else {
                                echo esc_html( $coach_name !== '' ? $coach_name : '—' );
                            }
                        ?></td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            <?php if ( $avg !== null ) : ?>
                                <a class="tt-record-link" href="<?php echo esc_url( $eval_url ); ?>"><strong><?php echo esc_html( $avg_text ); ?></strong></a>
                            <?php else : ?>
                                <span class="tt-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:300px; overflow-wrap:anywhere;"><?php echo esc_html( wp_trim_words( (string) ( $r->notes ?? '' ), 14 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * v3.110.4 — read-only detail page for a single evaluation.
     * Reachable via `?tt_view=evaluations&id=N`. Shows the eval
     * header (date / player / team / coach / activity context) plus
     * every rating grouped by main category with sub-ratings indented
     * underneath, then notes.
     */
    private static function renderDetail( int $eval_id, int $user_id, bool $is_admin ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // v3.110.104 — also fetch `eval_type_id` + a left join on
        // `tt_lookups` for the localised label. Pre-fix the detail
        // page didn't render the evaluation type at all, even though
        // it's stored on every row written via the wizard (v3.110.67
        // wired the eval_type_id persistence) and the edit form
        // expects to pre-fill from it (v3.110.105 will).
        $eval = $wpdb->get_row( $wpdb->prepare(
            "SELECT e.id, e.eval_date, e.notes, e.player_id, e.coach_id, e.opponent, e.competition, e.game_result, e.home_away, e.minutes_played,
                    e.eval_type_id,
                    pl.first_name, pl.last_name, pl.team_id,
                    t.name AS team_name,
                    u.display_name AS coach_name,
                    coach_p.id AS coach_person_id,
                    et.name AS eval_type_key, et.label AS eval_type_label, et.meta AS eval_type_meta
               FROM {$p}tt_evaluations e
               LEFT JOIN {$p}tt_players pl ON pl.id = e.player_id
               LEFT JOIN {$p}tt_teams   t  ON t.id  = pl.team_id
               LEFT JOIN {$wpdb->users} u  ON u.ID  = e.coach_id
               LEFT JOIN {$p}tt_people coach_p ON coach_p.wp_user_id = e.coach_id AND coach_p.club_id = e.club_id
               LEFT JOIN {$p}tt_lookups et ON et.id = e.eval_type_id AND et.lookup_type = 'eval_type'
              WHERE e.id = %d AND e.club_id = %d AND e.archived_at IS NULL
              LIMIT 1",
            $eval_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );

        if ( ! $eval ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Evaluation not found', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'evaluations', __( 'Evaluations', 'talenttrack' ) ) ]
            );
            self::renderHeader( __( 'Evaluation not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That evaluation no longer exists, or you do not have access.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $player_name = trim( ( $eval->first_name ?? '' ) . ' ' . ( $eval->last_name ?? '' ) );
        if ( $player_name === '' ) $player_name = '#' . (int) $eval->player_id;

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            sprintf( /* translators: %s = player name */ __( 'Evaluation — %s', 'talenttrack' ), $player_name ),
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'evaluations', __( 'Evaluations', 'talenttrack' ) ) ]
        );

        /* translators: %s = player display name */
        $page_title = sprintf( __( 'Evaluation of %s', 'talenttrack' ), $player_name );

        // v3.110.55 — Edit + Archive in the page-header actions slot.
        // Edit becomes a FAB bottom-right on mobile; Archive is a
        // danger-styled secondary button (hidden on mobile by the slot
        // CSS). Archive wires through the generic
        // tt-frontend-archive-button.js handler — DELETE evaluations/{id}
        // soft-archives the row and redirects back to the list.
        $list_url    = add_query_arg( [ 'tt_view' => 'evaluations' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
        $actions     = [];
        if ( current_user_can( 'tt_edit_evaluations' ) ) {
            $edit_url = add_query_arg(
                [ 'tt_view' => 'evaluations', 'id' => $eval_id, 'action' => 'edit' ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            );
            $actions[] = [
                'label'   => __( 'Edit', 'talenttrack' ),
                'href'    => $edit_url,
                'primary' => true,
                'icon'    => '✎',
            ];
            $actions[] = [
                'label'      => __( 'Archive', 'talenttrack' ),
                'variant'    => 'danger',
                'data_attrs' => [
                    'tt-archive-rest-path' => 'evaluations/' . $eval_id,
                    'tt-archive-confirm'   => __( 'Archive this evaluation? It will be hidden but the data is preserved.', 'talenttrack' ),
                    'tt-archive-redirect'  => $list_url,
                ],
            ];
        }
        self::renderHeader( $page_title, $actions ? self::pageActionsHtml( $actions ) : '' );

        $ratings = ( new \TT\Infrastructure\Evaluations\EvalRatingsRepository() )->getForEvaluation( $eval_id );

        // Group ratings: mains first, then subs nested under their parent.
        $by_parent = [];
        $mains     = [];
        foreach ( $ratings as $row ) {
            $parent_id = $row->parent_id !== null ? (int) $row->parent_id : 0;
            if ( $parent_id === 0 ) {
                $mains[ (int) $row->category_id ] = $row;
            } else {
                $by_parent[ $parent_id ][] = $row;
            }
        }

        $team_id    = (int) ( $eval->team_id ?? 0 );
        $team_name  = (string) ( $eval->team_name ?? '' );
        $coach_name = (string) ( $eval->coach_name ?? '' );
        $coach_pid  = (int) ( $eval->coach_person_id ?? 0 );

        ?>
        <section class="tt-record-detail">
            <div class="tt-record-detail-meta">
                <dl class="tt-profile-dl" style="display:grid; grid-template-columns:auto 1fr; gap:6px 18px; margin:0 0 16px;">
                    <dt><?php esc_html_e( 'Date', 'talenttrack' ); ?></dt>
                    <dd><?php echo esc_html( (string) $eval->eval_date ); ?></dd>
                    <?php
                    // v3.110.104 — Type row. Resolves via LookupTranslator
                    // when the join returned a row (full lookup, with the
                    // `label` JSON honouring the current locale).
                    $eval_type_label = '';
                    if ( ! empty( $eval->eval_type_id ) ) {
                        // Synthesise a lookup-shaped object so the
                        // existing translator works without a second
                        // query. LookupTranslator::name reads `label`
                        // first, falls back to `name`.
                        $lookup_row = (object) [
                            'name'  => (string) ( $eval->eval_type_key ?? '' ),
                            'label' => (string) ( $eval->eval_type_label ?? '' ),
                            'meta'  => (string) ( $eval->eval_type_meta ?? '' ),
                        ];
                        $eval_type_label = (string) \TT\Infrastructure\Query\LookupTranslator::name( $lookup_row );
                    }
                    if ( $eval_type_label !== '' ) :
                    ?>
                        <dt><?php esc_html_e( 'Type', 'talenttrack' ); ?></dt>
                        <dd><?php echo esc_html( $eval_type_label ); ?></dd>
                    <?php endif; ?>
                    <dt><?php esc_html_e( 'Player', 'talenttrack' ); ?></dt>
                    <dd><?php echo \TT\Shared\Frontend\Components\RecordLink::inline(
                        $player_name,
                        \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'players', (int) $eval->player_id )
                    ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — link helper escapes label and URL ?></dd>
                    <?php if ( $team_id > 0 && $team_name !== '' ) : ?>
                        <dt><?php esc_html_e( 'Team', 'talenttrack' ); ?></dt>
                        <dd><?php echo \TT\Shared\Frontend\Components\RecordLink::inline(
                            $team_name,
                            \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', $team_id )
                        ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></dd>
                    <?php endif; ?>
                    <?php if ( $coach_name !== '' ) : ?>
                        <dt><?php esc_html_e( 'Coach', 'talenttrack' ); ?></dt>
                        <dd><?php
                            if ( $coach_pid > 0 ) {
                                echo \TT\Shared\Frontend\Components\RecordLink::inline(
                                    $coach_name,
                                    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'people', $coach_pid )
                                );
                            } else {
                                echo esc_html( $coach_name );
                            }
                        ?></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $eval->opponent ) ) : ?>
                        <dt><?php esc_html_e( 'Opponent', 'talenttrack' ); ?></dt>
                        <dd><?php echo esc_html( (string) $eval->opponent ); ?></dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $eval->game_result ) ) : ?>
                        <dt><?php esc_html_e( 'Result', 'talenttrack' ); ?></dt>
                        <dd><?php echo esc_html( (string) $eval->game_result ); ?>
                            <?php if ( ! empty( $eval->home_away ) ) : ?>
                                <span class="tt-muted"> &middot; <?php echo esc_html( (string) $eval->home_away ); ?></span>
                            <?php endif; ?>
                        </dd>
                    <?php endif; ?>
                    <?php if ( ! empty( $eval->minutes_played ) ) : ?>
                        <dt><?php esc_html_e( 'Minutes Played', 'talenttrack' ); ?></dt>
                        <dd><?php echo (int) $eval->minutes_played; ?></dd>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="tt-record-detail-body">
                <h3><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h3>
                <?php if ( empty( $mains ) && empty( $by_parent ) ) : ?>
                    <p class="tt-muted"><?php esc_html_e( 'No ratings recorded for this evaluation.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <table class="tt-table" style="width:100%; max-width:520px;">
                        <thead><tr>
                            <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Rating', 'talenttrack' ); ?></th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ( $mains as $cat_id => $main ) :
                                $label = (string) ( $main->category_label ?? $main->category_key ?? '—' );
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( $label ) ); ?></strong></td>
                                    <td style="text-align:right; font-variant-numeric:tabular-nums;"><?php echo esc_html( number_format_i18n( (float) $main->rating, 1 ) ); ?></td>
                                </tr>
                                <?php if ( ! empty( $by_parent[ $cat_id ] ) ) : ?>
                                    <?php foreach ( $by_parent[ $cat_id ] as $sub ) :
                                        $sub_label = (string) ( $sub->category_label ?? $sub->category_key ?? '—' );
                                        ?>
                                        <tr>
                                            <td style="padding-left:20px; color:var(--tt-muted, #5b6e75);">↳ <?php echo esc_html( \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( $sub_label ) ); ?></td>
                                            <td style="text-align:right; font-variant-numeric:tabular-nums; color:var(--tt-muted, #5b6e75);"><?php echo esc_html( number_format_i18n( (float) $sub->rating, 1 ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php // Subs whose parent main wasn't directly rated. ?>
                            <?php foreach ( $by_parent as $parent_id => $subs ) :
                                if ( isset( $mains[ $parent_id ] ) ) continue;
                                foreach ( $subs as $sub ) :
                                    $sub_label = (string) ( $sub->category_label ?? $sub->category_key ?? '—' );
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( $sub_label ) ); ?></td>
                                        <td style="text-align:right; font-variant-numeric:tabular-nums;"><?php echo esc_html( number_format_i18n( (float) $sub->rating, 1 ) ); ?></td>
                                    </tr>
                                <?php endforeach;
                            endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ( ! empty( $eval->notes ) ) : ?>
                    <h3 style="margin-top:18px;"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></h3>
                    <p style="white-space:pre-wrap;"><?php echo esc_html( (string) $eval->notes ); ?></p>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private static function parseDate( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) return '';
        $d = \DateTime::createFromFormat( 'Y-m-d', $raw );
        return ( $d && $d->format( 'Y-m-d' ) === $raw ) ? $raw : '';
    }

    /**
     * v3.110.55 — load a non-archived eval row for the edit form.
     * Tenancy-scoped, so cross-club edits return null.
     */
    private static function loadEvaluation( int $eval_id ): ?object {
        global $wpdb;
        $p   = $wpdb->prefix;
        // v3.110.105 — also fetch `activity_id` so the edit form's
        // Type pre-fill can back-fill from the activity's type when
        // the eval row itself doesn't carry an `eval_type_id` (legacy
        // mark-attendance wizard rows written before v3.110.105's
        // EvaluationInserter started persisting the type).
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, player_id, eval_type_id, eval_date, notes, opponent, competition, game_result, home_away, minutes_played, activity_id
               FROM {$p}tt_evaluations
              WHERE id = %d AND club_id = %d AND archived_at IS NULL
              LIMIT 1",
            $eval_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        return $row ?: null;
    }
}
