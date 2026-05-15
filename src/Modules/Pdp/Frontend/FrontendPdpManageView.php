<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\TeamPickerComponent;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendPdpManageView — coach-facing PDP cycle UI.
 *
 *   ?tt_view=pdp                          — list of players' files in current season
 *   ?tt_view=pdp&id=<file_id>             — file detail (conversations + goals + verdict)
 *   ?tt_view=pdp&id=<file_id>&conv=<cid>  — single conversation form
 *   ?tt_view=pdp&id=<file_id>&action=verdict — verdict form
 *   ?tt_view=pdp&action=new&player_id=<n> — create file (auto-templates conversations)
 *
 * Mutations route through the REST endpoints (#0044 sprint 1) — this
 * view stays read-side + form-render only. Posts use the existing
 * `tt-ajax-form` JS that other manage views use.
 */
class FrontendPdpManageView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $file_id = isset( $_GET['id'] )     ? absint( $_GET['id'] ) : 0;
        $conv_id = isset( $_GET['conv'] )   ? absint( $_GET['conv'] ) : 0;

        // v3.92.1 — breadcrumb chain. Action / id state determines depth.
        $pdp_label = __( 'PDP', 'talenttrack' );
        if ( $action === 'new' ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'New PDP file', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'pdp', $pdp_label ) ]
            );
        } elseif ( $file_id > 0 ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'PDP file detail', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'pdp', $pdp_label ) ]
            );
        } else {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $pdp_label );
        }

        if ( $action === 'new' ) {
            self::renderCreateForm( $user_id, $is_admin );
            return;
        }

        if ( $file_id > 0 ) {
            $file = ( new PdpFilesRepository() )->find( $file_id );
            if ( ! $file ) {
                self::renderHeader( __( 'PDP file not found', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'That PDP file no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            if ( ! self::canSeeFile( $file, $user_id, $is_admin ) ) {
                self::renderHeader( __( 'Access denied', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this PDP file.', 'talenttrack' ) . '</p>';
                return;
            }

            if ( $conv_id > 0 ) {
                self::renderConversationForm( $file, $conv_id, $user_id, $is_admin );
                return;
            }
            if ( $action === 'verdict' ) {
                self::renderVerdictForm( $file, $user_id, $is_admin );
                return;
            }
            self::renderFileDetail( $file, $user_id, $is_admin );
            return;
        }

        self::renderList( $user_id, $is_admin );
    }

    private static function renderList( int $user_id, bool $is_admin ): void {
        $current = ( new SeasonsRepository() )->current();
        if ( ! $current ) {
            self::renderHeader( __( 'Player Development Plans', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'No current season is set. Configure a season under Configuration → Seasons before creating PDP files.', 'talenttrack' ) . '</p>';
            return;
        }

        // v3.110.110 — page-header CTA + FrontendListTable parity with
        // the goals + evaluations pages (per pilot ask: "the table list
        // POP is not using the same formatting as the standard used in
        // goals list page"). Filter bar: Team / Player / Status. Search
        // matches player name. Sortable columns. Pagination + per-page
        // selector. Parent/player ack columns surface grey/green
        // checkmarks rolled up from the file's conversations.
        $base_url = remove_query_arg( [ 'action', 'id', 'conv', 'player_id' ] );
        $new_url  = add_query_arg( [ 'tt_view' => 'pdp', 'action' => 'new' ], $base_url );
        $page_actions = [];
        if ( current_user_can( 'tt_edit_pdp' ) ) {
            $page_actions[] = [
                'label'   => __( 'Open new PDP file', 'talenttrack' ),
                'href'    => $new_url,
                'primary' => true,
                'icon'    => '+',
            ];
        }
        self::renderHeader( __( 'Player Development Plans', 'talenttrack' ), self::pageActionsHtml( $page_actions ) );

        echo '<p style="color:#5b6e75; margin-bottom:12px;">' . esc_html( sprintf(
            /* translators: %s = season name */
            __( 'Showing PDP files for the current season (%s).', 'talenttrack' ),
            (string) $current->name
        ) ) . '</p>';

        // Player + team filter options scoped the same way the REST
        // endpoint scopes (admins see everything; coaches see their
        // own teams' rosters).
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
        $status_options = [
            'open'      => __( 'Open',      'talenttrack' ),
            'completed' => __( 'Completed', 'talenttrack' ),
            'archived'  => __( 'Archived',  'talenttrack' ),
        ];

        echo FrontendListTable::render( [
            'rest_path' => 'pdp-files',
            'columns' => [
                'player_name' => [ 'label' => __( 'Player', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'player_link_html' ],
                'team_name'   => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'team_link_html' ],
                'status'      => [ 'label' => __( 'Status', 'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'status_pill_html' ],
                'cycle_size'  => [ 'label' => __( 'Cycle',  'talenttrack' ), 'sortable' => true ],
                'parent_ack'  => [ 'label' => __( 'Parent confirmation', 'talenttrack' ), 'render' => 'html', 'value_key' => 'parent_ack_html' ],
                'player_ack'  => [ 'label' => __( 'Player confirmation', 'talenttrack' ), 'render' => 'html', 'value_key' => 'player_ack_html' ],
                'updated_at'  => [ 'label' => __( 'Updated', 'talenttrack' ), 'sortable' => true, 'render' => 'date' ],
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
            ],
            'search'       => [ 'placeholder' => __( 'Search player…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'updated_at', 'order' => 'desc' ],
            'empty_state'  => __( 'No PDP files match your filters.', 'talenttrack' ),
        ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
    }

    private static function renderCreateForm( int $user_id, bool $is_admin ): void {
        self::renderHeader( __( 'Open new PDP file', 'talenttrack' ) );

        $current = ( new SeasonsRepository() )->current();
        if ( ! $current ) {
            echo '<p class="tt-notice">' . esc_html__( 'No current season is set. Configure one before opening a PDP file.', 'talenttrack' ) . '</p>';
            return;
        }

        // Resolve the player roster, scoped to the coach's teams
        // (admins see every player). Used to populate the player
        // dropdown that filters client-side on team change.
        $players = self::eligiblePlayerObjects( $user_id, $is_admin );
        if ( empty( $players ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players available. Coaches can only open PDP files for players on their own teams.', 'talenttrack' ) . '</p>';
            return;
        }

        // Pre-fill team filter when the user landed here from a team
        // page (?team_id=N). Pre-fill player when entered from a
        // player profile (?player_id=N).
        $preset_team   = isset( $_GET['team_id'] )   ? absint( $_GET['team_id'] )   : 0;
        $preset_player = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;

        // v3.110.110 — pilot ask: replace the search-as-you-type
        // picker with a classic two-dropdown cascade. Team dropdown
        // narrows the player dropdown to its roster; an "All teams"
        // selection shows every player the coach has access to. The
        // search picker (PlayerSearchPickerComponent) is still the
        // right choice for surfaces with hundreds of options (admin
        // scout pages, comparison view); on the PDP create form the
        // coach's eligible-player roster is typically small (a single
        // team's worth) and the dropdown is faster than typing.
        //
        // If `?player_id=N` was passed (entered from a player file),
        // the team dropdown is preselected to that player's team and
        // the player is preselected; the operator can still change
        // either. Coach-scope enforcement happens server-side in the
        // REST controller — the dropdown is convenience, not a
        // security boundary.
        $teams_for_filter = [];
        foreach ( $players as $pl ) {
            $tid = (int) ( $pl->team_id ?? 0 );
            if ( $tid <= 0 || isset( $teams_for_filter[ $tid ] ) ) continue;
            $t = QueryHelpers::get_team( $tid );
            if ( $t ) $teams_for_filter[ $tid ] = (string) $t->name;
        }
        asort( $teams_for_filter, SORT_NATURAL | SORT_FLAG_CASE );

        // Resolve the preselected team — explicit `?team_id=N`, else
        // derived from `?player_id=N`'s team membership.
        $selected_team = $preset_team;
        if ( $selected_team === 0 && $preset_player > 0 && isset( $players[ $preset_player ] ) ) {
            $selected_team = (int) ( $players[ $preset_player ]->team_id ?? 0 );
        }
        ?>
        <form class="tt-ajax-form" data-rest-path="pdp-files" data-rest-method="POST" data-redirect-after-save="list">
            <div class="tt-grid tt-grid-2">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-pdp-team-filter"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                    <select id="tt-pdp-team-filter" class="tt-input" data-tt-pdp-team-filter>
                        <option value="0"><?php esc_html_e( 'All teams', 'talenttrack' ); ?></option>
                        <?php foreach ( $teams_for_filter as $tid => $tname ) : ?>
                            <option value="<?php echo (int) $tid; ?>" <?php selected( $selected_team, (int) $tid ); ?>><?php echo esc_html( $tname ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-pdp-player-picker"><?php esc_html_e( 'Player', 'talenttrack' ); ?></label>
                    <select id="tt-pdp-player-picker" name="player_id" class="tt-input" required data-tt-pdp-player-picker>
                        <option value=""><?php esc_html_e( '— Select a player —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) :
                            $pid     = (int) $pl->id;
                            $pteam   = (int) ( $pl->team_id ?? 0 );
                            $pname   = QueryHelpers::player_display_name( $pl );
                            ?>
                            <option value="<?php echo $pid; ?>"
                                    data-team-id="<?php echo $pteam; ?>"
                                    <?php selected( $preset_player, $pid ); ?>>
                                <?php echo esc_html( $pname ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <script>
                (function () {
                    // v3.110.110 — team-dropdown filters player-dropdown
                    // options. Player options carry data-team-id so the
                    // filter is fully client-side; "All teams" (value 0)
                    // unhides everything. The select keeps its current
                    // value when valid; otherwise resets to placeholder
                    // so the form's `required` re-applies.
                    var team = document.getElementById('tt-pdp-team-filter');
                    var player = document.getElementById('tt-pdp-player-picker');
                    if (!team || !player) return;
                    function apply() {
                        var tid = parseInt(team.value, 10) || 0;
                        var currentValid = false;
                        Array.prototype.forEach.call(player.options, function (opt) {
                            if (!opt.value) { opt.hidden = false; return; }
                            var show = tid === 0 || parseInt(opt.dataset.teamId, 10) === tid;
                            opt.hidden = !show;
                            if (show && opt.value === player.value) currentValid = true;
                        });
                        if (!currentValid) player.value = '';
                    }
                    team.addEventListener('change', apply);
                    apply();
                })();
            </script>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-pdp-cycle"><?php esc_html_e( 'Conversations this season', 'talenttrack' ); ?></label>
                <select id="tt-pdp-cycle" name="cycle_size" class="tt-input">
                    <option value=""><?php esc_html_e( 'Use club / team default', 'talenttrack' ); ?></option>
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                </select>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-pdp-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-pdp-notes" name="notes" class="tt-input" rows="3"></textarea>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Open file', 'talenttrack' ); ?></button>
                <a href="<?php echo esc_url( remove_query_arg( [ 'action' ] ) ); ?>" class="tt-btn tt-btn-secondary"><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></a>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    /**
     * Same coach-scope rules as `eligiblePlayers()` but returns the raw
     * player objects (so PlayerSearchPickerComponent can derive labels
     * + team metadata for its team-filter dropdown).
     *
     * @return array<int, object>
     */
    private static function eligiblePlayerObjects( int $user_id, bool $is_admin ): array {
        $out = [];
        if ( $is_admin ) {
            foreach ( QueryHelpers::get_players() as $pl ) {
                $out[ (int) $pl->id ] = $pl;
            }
            return $out;
        }
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                $out[ (int) $pl->id ] = $pl;
            }
        }
        return $out;
    }

    private static function renderFileDetail( object $file, int $user_id, bool $is_admin ): void {
        $player = QueryHelpers::get_player( (int) $file->player_id );
        $title  = $player
            ? sprintf( /* translators: %s = player name */ __( 'PDP file — %s', 'talenttrack' ), QueryHelpers::player_display_name( $player ) )
            : __( 'PDP file', 'talenttrack' );
        self::renderHeader( $title );

        $base_url = remove_query_arg( [ 'action', 'id', 'conv', 'player_id' ] );
        $print_url = add_query_arg(
            [ 'tt_pdp_print' => 1, 'file_id' => (int) $file->id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );

        $convs   = ( new PdpConversationsRepository() )->listForFile( (int) $file->id );
        $verdict = ( new PdpVerdictsRepository() )->findForFile( (int) $file->id );
        $can_verdict = current_user_can( 'tt_edit_pdp_verdict' );

        echo '<div style="display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap;">';
        echo '<a class="tt-btn tt-btn-secondary" target="_blank" rel="noopener" href="' . esc_url( $print_url ) . '">'
            . esc_html__( 'Print / PDF', 'talenttrack' ) . '</a>';
        if ( $can_verdict && $verdict === null ) {
            $vurl = add_query_arg( [ 'tt_view' => 'pdp', 'id' => (int) $file->id, 'action' => 'verdict' ], $base_url );
            echo '<a class="tt-btn tt-btn-primary" href="' . esc_url( $vurl ) . '">' . esc_html__( 'Record verdict', 'talenttrack' ) . '</a>';
        }
        echo '</div>';

        // #0077 M9 — derived per-conversation status (no schema change
        // needed; the dates already carry the truth):
        //   coach_signoff_at  set → 'signed_off'
        //   conducted_at      set → 'held'
        //   otherwise              → 'scheduled'
        // Plus a "X / N done" indicator on the cycle-size row so the
        // operator sees progress at a glance instead of counting rows.
        $cycle_size = (int) ( $file->cycle_size ?? 0 );
        $signed_count = 0;
        foreach ( $convs as $c ) {
            if ( ! empty( $c->coach_signoff_at ) ) $signed_count++;
        }

        // Summary card
        echo '<div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px; margin-bottom:16px;">';
        echo '<p style="margin:0 0 6px; display:flex; align-items:center; gap:8px;">';
        // v3.110.110 — converge onto LookupPill so the status uses the
        // same rounded-pill chrome as the list view + every other
        // status in the app (pilot ask: "the status pill does not have
        // rounded edges like all other pills").
        echo '<strong>' . esc_html__( 'Status:', 'talenttrack' ) . '</strong> '
            . \TT\Infrastructure\Query\LookupPill::render( 'pdp_status', (string) $file->status, self::statusLabel( (string) $file->status ) );
        // #0077 F1 — context-sensitive help drawer entry.
        \TT\Shared\Frontend\Components\HelpDrawer::button( 'pdp' );
        echo '</p>';
        echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Cycle size:', 'talenttrack' ) . '</strong> '
            . (int) $cycle_size;
        if ( $cycle_size > 0 ) {
            echo ' <span style="color:#5b6e75; font-size:13px; margin-left:8px;">'
                . sprintf(
                    /* translators: 1: signed-off count, 2: cycle size */
                    esc_html__( '(%1$d of %2$d signed off)', 'talenttrack' ),
                    (int) $signed_count,
                    (int) $cycle_size
                )
                . '</span>';
        }
        echo '</p>';
        if ( ! empty( $file->notes ) ) {
            echo '<p style="margin:0;"><strong>' . esc_html__( 'Notes:', 'talenttrack' ) . '</strong> '
                . esc_html( (string) $file->notes ) . '</p>';
        }
        echo '</div>';

        // Conversations list — single Status column (was three: scheduled,
        // conducted, signed_off) + parent/player ack pills so coaches see
        // who still needs to acknowledge.
        echo '<h2 style="font-size:16px; margin:18px 0 8px;">' . esc_html__( 'Conversations', 'talenttrack' ) . '</h2>';
        echo '<table class="tt-list-table-table">';
        echo '<thead><tr>';
        echo '<th>#</th>';
        echo '<th>' . esc_html__( 'Template', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Scheduled', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Acks', 'talenttrack' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead><tbody>';
        foreach ( $convs as $c ) {
            $url = add_query_arg( [ 'tt_view' => 'pdp', 'id' => (int) $file->id, 'conv' => (int) $c->id ], $base_url );
            $derived = self::derivedConvStatus( $c );
            $badge_class = [
                'scheduled'  => 'tt-status-scheduled',
                'held'       => 'tt-status-in-progress',
                'signed_off' => 'tt-status-completed',
            ][ $derived ];
            $badge_label = [
                'scheduled'  => __( 'Scheduled', 'talenttrack' ),
                'held'       => __( 'Held', 'talenttrack' ),
                'signed_off' => __( 'Signed off', 'talenttrack' ),
            ][ $derived ];
            echo '<tr>';
            echo '<td>' . (int) $c->sequence . '</td>';
            echo '<td>' . esc_html( self::templateLabel( (string) $c->template_key ) ) . '</td>';
            echo '<td>' . esc_html( self::shortDate( $c->scheduled_at ) ) . '</td>';
            echo '<td><span class="tt-status-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $badge_label ) . '</span></td>';
            echo '<td>';
            $parent_ok = ! empty( $c->parent_ack_at );
            $player_ok = ! empty( $c->player_ack_at );
            echo '<span title="' . esc_attr__( 'Parent ack', 'talenttrack' ) . '" style="margin-right:6px;">'
                . ( $parent_ok ? '👤✓' : '👤·' ) . '</span>';
            echo '<span title="' . esc_attr__( 'Player ack', 'talenttrack' ) . '">'
                . ( $player_ok ? '⚽✓' : '⚽·' ) . '</span>';
            echo '</td>';
            echo '<td><a class="tt-btn tt-btn-secondary tt-btn-sm" href="' . esc_url( $url ) . '">'
                . esc_html__( 'Open', 'talenttrack' ) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Goals linked to this player (current season)
        self::renderGoalsBlock( (int) $file->player_id );

        // Verdict block
        if ( $verdict !== null ) {
            echo '<h2 style="font-size:16px; margin:24px 0 8px;">' . esc_html__( 'End-of-season verdict', 'talenttrack' ) . '</h2>';
            echo '<div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">';
            echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Decision:', 'talenttrack' ) . '</strong> '
                . esc_html( self::decisionLabel( (string) $verdict->decision ) ) . '</p>';
            if ( ! empty( $verdict->summary ) ) {
                echo '<div style="margin:8px 0; padding:8px; background:#fafbfc; border-radius:4px;">'
                    . wp_kses_post( (string) $verdict->summary ) . '</div>';
            }
            if ( ! empty( $verdict->signed_off_at ) ) {
                echo '<p style="margin:0; color:#5b6e75;"><em>'
                    . esc_html( sprintf(
                        /* translators: %s = signed-off timestamp */
                        __( 'Signed off on %s', 'talenttrack' ),
                        (string) $verdict->signed_off_at
                    ) ) . '</em></p>';
            }
            echo '</div>';
        }
    }

    private static function renderConversationForm( object $file, int $conv_id, int $user_id, bool $is_admin ): void {
        $convs_repo = new PdpConversationsRepository();
        $conv = $convs_repo->find( $conv_id );
        if ( ! $conv || (int) $conv->pdp_file_id !== (int) $file->id ) {
            self::renderHeader( __( 'Conversation not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That conversation no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        $title = sprintf(
            /* translators: %1$d sequence, %2$s template */
            __( 'Conversation %1$d (%2$s)', 'talenttrack' ),
            (int) $conv->sequence,
            self::templateLabel( (string) $conv->template_key )
        );
        self::renderHeader( $title );

        $base_url = remove_query_arg( [ 'action', 'conv', 'player_id' ] );

        $is_signed = ! empty( $conv->coach_signoff_at );
        $rest_path = 'pdp-conversations/' . (int) $conv->id;
        // v3.92.5 — after save / sign, land back on the parent PDP file
        // so the user sees their work in context. Public.js honours
        // `data-redirect-after-save-url` and waits for the success
        // toast before navigating.
        $back_to_file_url = add_query_arg( [ 'tt_view' => 'pdp', 'id' => (int) $file->id ], $base_url );

        // #0063 — switch from a cramped 1fr / 280px sidebar layout to a
        // simple tab strip: Conversation | Evidence. CSS-only toggle
        // via the :target pseudo-class fallback works without JS,
        // and a small handler upgrades to ARIA-correct tabs when JS is
        // present. Closes the "evidence is a format mess next to the
        // conversation" complaint.
        ?>
        <div class="tt-pdp-conv-tabs" data-tt-pdp-conv-tabs>
            <div role="tablist" aria-label="<?php esc_attr_e( 'Conversation sections', 'talenttrack' ); ?>" style="display:flex; gap:4px; border-bottom:1px solid #e5e7ea; margin-bottom:12px;">
                <button type="button" role="tab" aria-selected="true" data-tt-pdp-tab="conversation"
                        style="padding:8px 14px; border:0; background:transparent; border-bottom:2px solid transparent; cursor:pointer;"
                        class="is-active">
                    <?php esc_html_e( 'Conversation', 'talenttrack' ); ?>
                </button>
                <button type="button" role="tab" aria-selected="false" data-tt-pdp-tab="evidence"
                        style="padding:8px 14px; border:0; background:transparent; border-bottom:2px solid transparent; cursor:pointer;">
                    <?php esc_html_e( 'Evidence', 'talenttrack' ); ?>
                </button>
            </div>
        </div>
        <style>
            .tt-pdp-conv-tabs button.is-active { border-bottom-color: var(--tt-primary, #0b3d2e) !important; font-weight: 600; }
            .tt-pdp-conv-pane[hidden] { display: none; }
        </style>
        <script>
        (function(){
            if (window.__ttPdpConvTabsBound) return;
            window.__ttPdpConvTabsBound = true;
            document.addEventListener('click', function(e){
                var btn = e.target && e.target.closest ? e.target.closest('[data-tt-pdp-tab]') : null;
                if (!btn) return;
                var key = btn.getAttribute('data-tt-pdp-tab');
                document.querySelectorAll('[data-tt-pdp-tab]').forEach(function(b){
                    var on = b.getAttribute('data-tt-pdp-tab') === key;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                document.querySelectorAll('.tt-pdp-conv-pane').forEach(function(p){
                    p.hidden = ( p.getAttribute('data-tt-pdp-pane') !== key );
                });
            });
        })();
        </script>
        <?php

        // Main form column (Conversation pane).
        echo '<div class="tt-pdp-conv-pane" data-tt-pdp-pane="conversation" role="tabpanel">';
        ?>
        <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PATCH" data-redirect-after-save-url="<?php echo esc_attr( $back_to_file_url ); ?>">
            <div class="tt-field">
                <label class="tt-field-label" for="tt-conv-scheduled"><?php esc_html_e( 'Scheduled at', 'talenttrack' ); ?></label>
                <input type="datetime-local" id="tt-conv-scheduled" name="scheduled_at" class="tt-input"
                    value="<?php echo esc_attr( self::toDatetimeLocal( $conv->scheduled_at ) ); ?>" />
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-conv-conducted"><?php esc_html_e( 'Conducted at', 'talenttrack' ); ?></label>
                <input type="datetime-local" id="tt-conv-conducted" name="conducted_at" class="tt-input"
                    value="<?php echo esc_attr( self::toDatetimeLocal( $conv->conducted_at ) ); ?>" />
                <small style="color:#5b6e75;"><?php esc_html_e( 'Fill in once the conversation has happened.', 'talenttrack' ); ?></small>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-conv-agenda"><?php esc_html_e( 'Agenda (pre-meeting)', 'talenttrack' ); ?></label>
                <textarea id="tt-conv-agenda" name="agenda" class="tt-input" rows="3"><?php echo esc_textarea( (string) ( $conv->agenda ?? '' ) ); ?></textarea>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-conv-notes"><?php esc_html_e( 'Notes (post-meeting)', 'talenttrack' ); ?></label>
                <textarea id="tt-conv-notes" name="notes" class="tt-input" rows="5"><?php echo esc_textarea( (string) ( $conv->notes ?? '' ) ); ?></textarea>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-conv-actions"><?php esc_html_e( 'Agreed actions', 'talenttrack' ); ?></label>
                <textarea id="tt-conv-actions" name="agreed_actions" class="tt-input" rows="3"><?php echo esc_textarea( (string) ( $conv->agreed_actions ?? '' ) ); ?></textarea>
            </div>

            <?php if ( ! empty( $conv->player_reflection ) ) : ?>
                <div class="tt-card" style="background:#fafbfc; border:1px solid #e5e7ea; border-radius:6px; padding:12px; margin:12px 0;">
                    <p style="margin:0 0 4px; font-weight:600;"><?php esc_html_e( 'Player self-reflection', 'talenttrack' ); ?></p>
                    <div><?php echo wp_kses_post( (string) $conv->player_reflection ); ?></div>
                </div>
            <?php endif; ?>

            <?php if ( ! $is_signed ) : ?>
                <div class="tt-field">
                    <label class="tt-checkbox">
                        <input type="checkbox" name="coach_signoff_at" value="<?php echo esc_attr( current_time( 'mysql', true ) ); ?>" />
                        <?php esc_html_e( 'Sign off this conversation now', 'talenttrack' ); ?>
                    </label>
                </div>
            <?php else : ?>
                <p class="tt-pdp-signed-off"><strong><?php esc_html_e( 'Signed off', 'talenttrack' ); ?></strong> — <?php echo esc_html( (string) $conv->coach_signoff_at ); ?></p>
            <?php endif; ?>

            <div class="tt-form-actions" style="margin-top:16px;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save conversation', 'talenttrack' ); ?></button>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        echo '</div>'; // /conversation pane

        // Evidence pane — same content as the old sidebar, hidden by
        // default; the tab toggle reveals it.
        echo '<div class="tt-pdp-conv-pane" data-tt-pdp-pane="evidence" role="tabpanel" hidden>';
        self::renderEvidenceSidebar( (int) $file->player_id, $conv );
        echo '</div>';
    }

    private static function renderVerdictForm( object $file, int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_pdp_verdict' ) ) {
            self::renderHeader( __( 'Verdict', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Only head of academy or head coach roles can record a verdict.', 'talenttrack' ) . '</p>';
            return;
        }
        $existing = ( new PdpVerdictsRepository() )->findForFile( (int) $file->id );
        $title    = $existing ? __( 'Edit end-of-season verdict', 'talenttrack' ) : __( 'Record end-of-season verdict', 'talenttrack' );
        self::renderHeader( $title );

        $base_url  = remove_query_arg( [ 'action', 'conv', 'player_id' ] );
        $rest_path = 'pdp-files/' . (int) $file->id . '/verdict';
        $decisions = [
            'promote'  => __( 'Promote to next age group', 'talenttrack' ),
            'retain'   => __( 'Retain in current group', 'talenttrack' ),
            'release'  => __( 'Release from academy', 'talenttrack' ),
            'transfer' => __( 'Transfer to another team / club', 'talenttrack' ),
        ];
        ?>
        <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PUT">
            <div class="tt-field">
                <label class="tt-field-label tt-field-required" for="tt-verdict-decision"><?php esc_html_e( 'Decision', 'talenttrack' ); ?></label>
                <select id="tt-verdict-decision" name="decision" class="tt-input" required>
                    <option value=""><?php esc_html_e( '— Select decision —', 'talenttrack' ); ?></option>
                    <?php foreach ( $decisions as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $existing ? (string) $existing->decision : '', $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-verdict-summary"><?php esc_html_e( 'Summary / rationale', 'talenttrack' ); ?></label>
                <textarea id="tt-verdict-summary" name="summary" class="tt-input" rows="6"><?php echo esc_textarea( (string) ( $existing->summary ?? '' ) ); ?></textarea>
            </div>
            <div class="tt-field">
                <label class="tt-checkbox">
                    <input type="checkbox" name="signed_off" value="1" <?php checked( $existing && ! empty( $existing->signed_off_at ) ); ?> />
                    <?php esc_html_e( 'Sign off this verdict now', 'talenttrack' ); ?>
                </label>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save verdict', 'talenttrack' ); ?></button>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    /**
     * Evidence sidebar: every evaluation, activity, and goal change for
     * the player since the previous conversation. Read-only.
     */
    private static function renderEvidenceSidebar( int $player_id, object $conv ): void {
        $since = self::previousConversationDate( $conv );

        global $wpdb; $p = $wpdb->prefix;

        echo '<aside class="tt-card" style="background:#fafbfc; border:1px solid #e5e7ea; border-radius:6px; padding:12px; align-self:start;">';
        echo '<h3 style="margin:0 0 8px; font-size:14px;">' . esc_html__( 'Evidence', 'talenttrack' ) . '</h3>';
        if ( $since !== null ) {
            echo '<p style="margin:0 0 8px; color:#5b6e75; font-size:12px;">' . esc_html(
                sprintf(
                    /* translators: %s = date */
                    __( 'Since %s', 'talenttrack' ),
                    $since
                )
            ) . '</p>';
        }

        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date FROM {$p}tt_evaluations
              WHERE player_id = %d AND archived_at IS NULL" . ( $since ? " AND eval_date >= %s" : '' ) .
              " ORDER BY eval_date DESC LIMIT 10",
            ...( $since ? [ $player_id, $since ] : [ $player_id ] )
        ) );
        echo '<p style="margin:8px 0 4px; font-weight:600; font-size:13px;">' . esc_html__( 'Evaluations', 'talenttrack' ) . '</p>';
        if ( $evals ) {
            echo '<ul style="margin:0 0 8px; padding-left:18px; font-size:12px;">';
            foreach ( $evals as $e ) {
                echo '<li>' . esc_html( (string) $e->eval_date ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="margin:0 0 8px; font-size:12px; color:#5b6e75;"><em>' . esc_html__( 'No evaluations in this window.', 'talenttrack' ) . '</em></p>';
        }

        $acts = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id, a.session_date, a.title, att.status
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d" . ( $since ? " AND a.session_date >= %s" : '' ) .
              " ORDER BY a.session_date DESC LIMIT 10",
            ...( $since ? [ $player_id, $since ] : [ $player_id ] )
        ) );
        echo '<p style="margin:8px 0 4px; font-weight:600; font-size:13px;">' . esc_html__( 'Activities', 'talenttrack' ) . '</p>';
        if ( $acts ) {
            echo '<ul style="margin:0 0 8px; padding-left:18px; font-size:12px;">';
            foreach ( $acts as $a ) {
                echo '<li>' . esc_html( (string) $a->session_date ) . ' — '
                    . esc_html( (string) $a->title ) . ' ('
                    . esc_html( (string) $a->status ) . ')</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="margin:0 0 8px; font-size:12px; color:#5b6e75;"><em>' . esc_html__( 'No activities in this window.', 'talenttrack' ) . '</em></p>';
        }

        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, created_at
               FROM {$p}tt_goals
              WHERE player_id = %d" . ( $since ? " AND created_at >= %s" : '' ) . "
                AND archived_at IS NULL
              ORDER BY created_at DESC LIMIT 10",
            ...( $since ? [ $player_id, $since ] : [ $player_id ] )
        ) );
        echo '<p style="margin:8px 0 4px; font-weight:600; font-size:13px;">' . esc_html__( 'Goal changes', 'talenttrack' ) . '</p>';
        if ( $goals ) {
            echo '<ul style="margin:0; padding-left:18px; font-size:12px;">';
            foreach ( $goals as $g ) {
                echo '<li>' . esc_html( (string) $g->title ) . ' — '
                    . esc_html( (string) $g->status ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p style="margin:0; font-size:12px; color:#5b6e75;"><em>' . esc_html__( 'No goal changes in this window.', 'talenttrack' ) . '</em></p>';
        }

        echo '</aside>';
    }

    /**
     * Linked goals block on the PDP detail. Shows each goal with the
     * polymorphic links it carries (principles / football actions /
     * positions / values), plus the conversation footprint — the most
     * recent agreed-action note from any conversation that mentions
     * the goal title (case-insensitive substring match) anchors the
     * "what was said about it last" question without requiring the
     * coach to explicitly tag conversations to goals.
     *
     * Filter UI: a "Show" dropdown (active / all / completed) on top.
     * State is in the URL (?goals=active|all|completed) so a coach can
     * deep-link to a "completed goals" view from a parent message.
     */
    private static function renderGoalsBlock( int $player_id ): void {
        global $wpdb; $p = $wpdb->prefix;

        $filter = isset( $_GET['goals'] ) ? sanitize_key( (string) $_GET['goals'] ) : 'active';
        if ( ! in_array( $filter, [ 'active', 'all', 'completed' ], true ) ) $filter = 'active';

        $where_status = match ( $filter ) {
            'completed' => "AND g.status = 'completed'",
            'all'       => '',
            default     => "AND g.status NOT IN ('completed','archived')",
        };

        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.id, g.title, g.description, g.status, g.priority, g.due_date, g.created_at
               FROM {$p}tt_goals g
              WHERE g.player_id = %d AND g.archived_at IS NULL
                {$where_status}
              ORDER BY g.due_date ASC, g.created_at DESC",
            $player_id
        ) );

        echo '<h2 style="font-size:16px; margin:24px 0 8px;">' . esc_html__( 'Linked goals', 'talenttrack' ) . '</h2>';

        // Filter dropdown — submit on change to keep the URL the source
        // of truth. Mobile-friendly: native select.
        $base = remove_query_arg( [ 'goals' ] );
        echo '<form method="get" action="' . esc_url( $base ) . '" style="margin-bottom:8px;">';
        // Preserve current view + id query args.
        foreach ( [ 'tt_view', 'id' ] as $k ) {
            if ( isset( $_GET[ $k ] ) ) {
                printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( (string) $_GET[ $k ] ) );
            }
        }
        echo '<label style="font-size:12px; color:#5b6e75;">' . esc_html__( 'Show:', 'talenttrack' ) . ' ';
        echo '<select name="goals" class="tt-input" onchange="this.form.submit();" style="display:inline-block; width:auto;">';
        printf( '<option value="active" %s>%s</option>',     selected( $filter, 'active', false ),    esc_html__( 'Active', 'talenttrack' ) );
        printf( '<option value="completed" %s>%s</option>',  selected( $filter, 'completed', false ), esc_html__( 'Completed', 'talenttrack' ) );
        printf( '<option value="all" %s>%s</option>',        selected( $filter, 'all', false ),       esc_html__( 'All', 'talenttrack' ) );
        echo '</select></label>';
        echo '</form>';

        if ( empty( $goals ) ) {
            echo '<p><em>' . esc_html__( 'No goals match this filter.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // Pre-fetch conversation snippets per file so we look up once,
        // not per-goal. Most-recent-first, capped at 20 conversations.
        $convs = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.notes, c.agreed_actions, COALESCE(c.conducted_at, c.scheduled_at) AS when_at
               FROM {$p}tt_pdp_conversations c
               JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id
              WHERE f.player_id = %d
                AND ( c.notes IS NOT NULL OR c.agreed_actions IS NOT NULL )
              ORDER BY when_at DESC LIMIT 20",
            $player_id
        ) );

        // Goal links — fetch all in one query keyed by goal_id.
        $goal_ids = array_map( static fn( $g ) => (int) $g->id, $goals );
        $links_by_goal = self::loadGoalLinks( $goal_ids );

        echo '<div class="tt-pdp-goals-list" style="display:flex; flex-direction:column; gap:10px;">';
        foreach ( $goals as $g ) {
            $title = (string) $g->title;
            $status = (string) $g->status;
            $priority = (string) ( $g->priority ?? '' );
            $due = self::shortDate( $g->due_date );
            $links = $links_by_goal[ (int) $g->id ] ?? [];

            $latest_mention = self::findLatestMention( (string) $title, $convs );

            echo '<div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:12px;">';
            echo '<div style="display:flex; justify-content:space-between; gap:8px; align-items:flex-start;">';
            // #0070 — connected goal title links to goal detail page.
            echo '<div style="flex:1; min-width:0;"><strong>'
                . \TT\Shared\Frontend\Components\RecordLink::inline(
                    $title,
                    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'goals', (int) $g->id )
                )
                . '</strong>';
            if ( ! empty( $g->description ) ) {
                echo '<div style="font-size:12px; color:#5b6e75; margin-top:2px;">' . esc_html( (string) $g->description ) . '</div>';
            }
            echo '</div>';
            echo '<div style="text-align:right; font-size:12px; color:#5b6e75; white-space:nowrap;">';
            // #0063 — connected goals use goal_status pill same as everywhere else.
            echo \TT\Infrastructure\Query\LookupPill::render( 'goal_status', $status );
            if ( $priority !== '' ) echo ' · ' . esc_html( $priority );
            if ( ! empty( $g->due_date ) ) {
                echo '<br>' . esc_html( sprintf( /* translators: %s = date */ __( 'Due %s', 'talenttrack' ), $due ) );
            }
            echo '</div>';
            echo '</div>';

            if ( ! empty( $links ) ) {
                echo '<div style="margin-top:8px; display:flex; flex-wrap:wrap; gap:4px;">';
                foreach ( $links as $l ) {
                    echo '<span style="display:inline-block; padding:2px 8px; background:#f0f7f6; color:#1d7874; border-radius:10px; font-size:11px;">'
                        . esc_html( self::linkLabel( (string) $l['type'] ) ) . ': ' . esc_html( (string) $l['name'] )
                        . '</span>';
                }
                echo '</div>';
            }

            if ( $latest_mention !== null ) {
                echo '<div style="margin-top:8px; padding:8px; background:#fafbfc; border-left:3px solid #1d7874; font-size:12px; color:#3a4047;">';
                echo '<div style="font-weight:600; margin-bottom:2px;">' . esc_html( sprintf(
                    /* translators: %s = date */
                    __( 'Last mentioned in conversation on %s', 'talenttrack' ),
                    self::shortDate( $latest_mention['when_at'] )
                ) ) . '</div>';
                echo '<div>' . esc_html( $latest_mention['snippet'] ) . '</div>';
                echo '</div>';
            }

            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * @param list<int> $goal_ids
     * @return array<int, list<array{type:string, name:string}>>
     */
    private static function loadGoalLinks( array $goal_ids ): array {
        if ( empty( $goal_ids ) ) return [];
        global $wpdb; $p = $wpdb->prefix;
        $placeholders = implode( ',', array_fill( 0, count( $goal_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT goal_id, link_type, link_id FROM {$p}tt_goal_links
              WHERE goal_id IN ($placeholders)",
            ...$goal_ids
        ) );
        // Resolve a name per (link_type, link_id). Cheap loop — typical
        // PDP file has <20 links across all goals.
        $names = [];
        foreach ( (array) $rows as $r ) {
            $key = (string) $r->link_type . ':' . (int) $r->link_id;
            if ( ! isset( $names[ $key ] ) ) {
                $names[ $key ] = self::resolveLinkName( (string) $r->link_type, (int) $r->link_id );
            }
        }
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->goal_id ][] = [
                'type' => (string) $r->link_type,
                'name' => $names[ (string) $r->link_type . ':' . (int) $r->link_id ] ?? '',
            ];
        }
        return $out;
    }

    private static function resolveLinkName( string $type, int $id ): string {
        global $wpdb; $p = $wpdb->prefix;
        switch ( $type ) {
            case 'principle':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_principles WHERE id = %d", $id
                ) );
            case 'football_action':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_football_actions WHERE id = %d", $id
                ) );
            case 'position':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_lookups WHERE id = %d", $id
                ) );
            case 'value':
                return (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_lookups WHERE id = %d", $id
                ) );
        }
        return '';
    }

    private static function linkLabel( string $type ): string {
        switch ( $type ) {
            case 'principle':       return __( 'Principle', 'talenttrack' );
            case 'football_action': return __( 'Football action', 'talenttrack' );
            case 'position':        return __( 'Position', 'talenttrack' );
            case 'value':           return __( 'Value', 'talenttrack' );
        }
        return $type;
    }

    /**
     * Walk the player's conversation history (most recent first) and
     * return a snippet of the first conversation where the goal title
     * appears in either the notes or agreed_actions field. Returns
     * null when nothing matches.
     *
     * @param array<int, object> $convs
     * @return array{when_at:string, snippet:string}|null
     */
    private static function findLatestMention( string $title, array $convs ): ?array {
        $title = trim( $title );
        if ( $title === '' || empty( $convs ) ) return null;
        $needle = mb_strtolower( $title );
        foreach ( $convs as $c ) {
            $haystack = mb_strtolower( (string) ( $c->notes ?? '' ) . ' ' . (string) ( $c->agreed_actions ?? '' ) );
            $pos = mb_strpos( $haystack, $needle );
            if ( $pos === false ) continue;
            $source = (string) ( $c->agreed_actions ?? $c->notes ?? '' );
            $start = max( 0, $pos - 40 );
            $snippet = mb_substr( $source, $start, 160 );
            if ( $start > 0 ) $snippet = '…' . $snippet;
            if ( mb_strlen( $source ) > $start + 160 ) $snippet .= '…';
            return [
                'when_at' => (string) $c->when_at,
                'snippet' => $snippet,
            ];
        }
        return null;
    }

    /**
     * Players the user is allowed to open files for. Admin → all active.
     * Coach → players on their teams.
     *
     * @return array<int, string> player_id => display name
     */
    private static function eligiblePlayers( int $user_id, bool $is_admin ): array {
        $out = [];
        if ( $is_admin ) {
            foreach ( QueryHelpers::get_players() as $pl ) {
                $out[ (int) $pl->id ] = QueryHelpers::player_display_name( $pl );
            }
        } else {
            foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
                foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                    $out[ (int) $pl->id ] = QueryHelpers::player_display_name( $pl );
                }
            }
        }
        asort( $out );
        return $out;
    }

    private static function canSeeFile( object $file, int $user_id, bool $is_admin ): bool {
        if ( $is_admin ) return true;
        return QueryHelpers::coach_owns_player( $user_id, (int) $file->player_id );
    }

    private static function previousConversationDate( object $conv ): ?string {
        if ( (int) $conv->sequence <= 1 ) return null;
        global $wpdb; $p = $wpdb->prefix;
        $prev = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(conducted_at, scheduled_at) FROM {$p}tt_pdp_conversations
              WHERE pdp_file_id = %d AND sequence = %d",
            (int) $conv->pdp_file_id, (int) $conv->sequence - 1
        ) );
        return $prev ? substr( (string) $prev, 0, 10 ) : null;
    }

    private static function statusLabel( string $status ): string {
        // _x() — the noun "Open" (status), distinct from the verb "Open"
        // (action) that already lives in the .po as "Openen".
        switch ( $status ) {
            case 'open':      return _x( 'Open', 'PDP file status', 'talenttrack' );
            case 'completed': return __( 'Completed', 'talenttrack' );
            case 'archived':  return __( 'Archived', 'talenttrack' );
        }
        return $status;
    }

    private static function templateLabel( string $key ): string {
        switch ( $key ) {
            case 'start': return __( 'Start of season', 'talenttrack' );
            case 'mid':   return __( 'Mid season', 'talenttrack' );
            case 'mid_a': return __( 'Mid-season A', 'talenttrack' );
            case 'mid_b': return __( 'Mid-season B', 'talenttrack' );
            case 'end':   return __( 'End of season', 'talenttrack' );
        }
        return $key;
    }

    private static function decisionLabel( string $decision ): string {
        switch ( $decision ) {
            case 'promote':  return __( 'Promote', 'talenttrack' );
            case 'retain':   return __( 'Retain', 'talenttrack' );
            case 'release':  return __( 'Release', 'talenttrack' );
            case 'transfer': return __( 'Transfer', 'talenttrack' );
        }
        return $decision;
    }

    private static function shortDate( $value ): string {
        if ( empty( $value ) ) return '—';
        return substr( (string) $value, 0, 10 );
    }

    /**
     * #0077 M9 — derive a per-conversation status enum from the dates
     * already on the row. No schema change: coach_signoff_at set →
     * 'signed_off'; conducted_at set → 'held'; otherwise 'scheduled'.
     */
    private static function derivedConvStatus( object $conv ): string {
        if ( ! empty( $conv->coach_signoff_at ) ) return 'signed_off';
        if ( ! empty( $conv->conducted_at ) )     return 'held';
        return 'scheduled';
    }

    private static function toDatetimeLocal( $value ): string {
        if ( empty( $value ) ) return '';
        $v = (string) $value;
        if ( strlen( $v ) >= 16 ) return substr( str_replace( ' ', 'T', $v ), 0, 16 );
        return '';
    }
}
