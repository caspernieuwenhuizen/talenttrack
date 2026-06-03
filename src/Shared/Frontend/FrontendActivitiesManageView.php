<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityStatusKey;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\GuestAddModal;
use TT\Shared\Frontend\Components\RecordLink;
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
            // v3.110.53 — Edit + Archive page-header actions on the
            // activity detail page.
            // v3.110.97 — Continue rating action added. v3.110.96
            // filtered already-rated activities out of the wizard's
            // ActivityPicker (so the picker is for fresh runs only).
            // Coaches who want to add ratings to an already-rated
            // activity now use this CTA: deep-links into the
            // mark-attendance wizard with `activity_id` pre-seeded,
            // bypassing the picker and going straight through the
            // attendance step (force-rendered, pre-filled with the
            // saved roster) → confirm → rate-actors → review. The
            // rate step filters out players who already have an
            // eval row (see RateActorsStep::ratablePlayersForActivity)
            // so re-entry shows only the unrated set; no duplicate
            // eval rows.
            $detail_actions = [];
            if ( $session && current_user_can( 'tt_edit_activities' ) ) {
                $activities_list_url = add_query_arg( [ 'tt_view' => 'activities' ], \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );
                $edit_url = add_query_arg(
                    [ 'tt_view' => 'activities', 'id' => (int) $session->id, 'action' => 'edit' ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                );
                $detail_actions[] = [
                    'label'   => __( 'Edit', 'talenttrack' ),
                    'href'    => $edit_url,
                    'primary' => true,
                    'icon'    => '✎',
                ];
                // v3.110.214 (#838) — match preparation surface.
                // Only on match-type activities; jumps to the wizard if
                // no prep row exists, or directly to the form when it
                // does (FrontendMatchPrepView handles the redirect).
                $type_key = strtolower( (string) ( $session->activity_type_key ?? '' ) );
                if ( in_array( $type_key, [ 'match', ActivityTypeKey::GAME ], true ) && current_user_can( 'tt_edit_activities' ) ) {
                    $prep_url = add_query_arg(
                        [
                            'tt_view'     => 'match-prep',
                            'activity_id' => (int) $session->id,
                        ],
                        \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                    );
                    $detail_actions[] = [
                        'label' => __( 'Plan match prep', 'talenttrack' ),
                        'href'  => $prep_url,
                    ];
                    // v3.110.216 (#847) — assistant coach live-match
                    // surface. Same gate as match prep (type=match/game
                    // + tt_edit_activities); the view re-checks that a
                    // match prep exists, refusing to launch otherwise.
                    $exec_url = add_query_arg(
                        [
                            'tt_view'     => 'match-execution',
                            'activity_id' => (int) $session->id,
                        ],
                        \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                    );
                    $detail_actions[] = [
                        'label' => __( 'Start match', 'talenttrack' ),
                        'href'  => $exec_url,
                    ];
                }
                // v3.110.97 — Continue rating. Only on completed
                // activities (attendance + rating only make sense
                // after the session happened). Cap-gated on the
                // mark-attendance wizard's `tt_edit_evaluations`.
                $is_completed = ( (string) ( $session->activity_status_key ?? '' ) === ActivityStatusKey::COMPLETED );
                if ( $is_completed && current_user_can( 'tt_edit_evaluations' ) ) {
                    $rate_url = \TT\Shared\Wizards\WizardEntryPoint::buildUrl(
                        'mark-attendance',
                        [
                            'activity_id' => (int) $session->id,
                            'restart'     => 1,
                        ]
                    );
                    $detail_actions[] = [
                        'label' => __( 'Continue rating', 'talenttrack' ),
                        'href'  => $rate_url,
                    ];
                }
                $detail_actions[] = [
                    'label'   => __( 'Archive', 'talenttrack' ),
                    'variant' => 'danger',
                    'data_attrs' => [
                        'tt-archive-rest-path' => 'activities/' . (int) $session->id,
                        'tt-archive-confirm'   => __( 'Archive this activity? It will be hidden but the data is preserved.', 'talenttrack' ),
                        'tt-archive-redirect'  => $activities_list_url,
                    ],
                ];
            }
            self::renderHeader(
                $session ? (string) $session->title : __( 'Activity not found', 'talenttrack' ),
                self::pageActionsHtml( $detail_actions )
            );
            if ( ! $session ) {
                echo '<p class="tt-notice">' . esc_html__( 'That activity no longer exists.', 'talenttrack' ) . '</p>';
                return;
            }
            self::renderDetail( $session, $is_admin );
            return;
        }

        // Default: list view.
        // v3.110.53 — header-actions slot for + New activity.
        $list_base_url = remove_query_arg( [ 'action', 'id' ] );
        $page_actions = [];
        if ( current_user_can( 'tt_view_activities' ) ) {
            // #1047 — entry point to the dedicated match-executions
            // listing surface. Sits left of the primary "+ New
            // activity" CTA so the retrospective surface is reachable
            // without diving into individual activity-detail pages.
            $page_actions[] = [
                'label' => __( 'Match executions', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'match-executions' ], $list_base_url ),
            ];
        }
        if ( current_user_can( 'tt_edit_activities' ) ) {
            $flat_url = add_query_arg( [ 'tt_view' => 'activities', 'action' => 'new' ], $list_base_url );
            $page_actions[] = [
                'label'   => __( 'New activity', 'talenttrack' ),
                'href'    => \TT\Shared\Wizards\WizardEntryPoint::urlFor( 'new-activity', $flat_url ),
                'primary' => true,
                'icon'    => '+',
            ];
        }
        self::renderHeader( __( 'Activities', 'talenttrack' ), self::pageActionsHtml( $page_actions ) );
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
        $team_id   = (int) ( $session->team_id ?? 0 );
        $team_name = (string) ( $session->team_name ?? '' );
        $type_key  = (string) ( $session->activity_type_key ?? ActivityTypeKey::TRAINING );
        $status_key = (string) ( $session->activity_status_key ?? ActivityStatusKey::PLANNED );

        echo '<div class="tt-record-detail" style="display:grid; gap:12px;">';

        echo '<dl class="tt-record-detail-list" style="display:grid; grid-template-columns: minmax(120px, max-content) 1fr; gap:6px 16px; margin:0;">';

        echo '<dt>' . esc_html__( 'Date', 'talenttrack' ) . '</dt>';
        echo '<dd>' . esc_html( (string) $session->session_date ) . '</dd>';

        // #1126 — surface optional time window when set. Renders
        // nothing when both fields are empty (no placeholder).
        $st = (string) ( $session->start_time ?? '' );
        $et = (string) ( $session->end_time   ?? '' );
        if ( $st !== '' ) {
            $window = substr( $st, 0, 5 ) . ( $et !== '' ? ' – ' . substr( $et, 0, 5 ) : '' );
            echo '<dt>' . esc_html__( 'Time', 'talenttrack' ) . '</dt>';
            echo '<dd>' . esc_html( $window ) . '</dd>';
        }

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
                    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'teams', $team_id )
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

        // #1123 — Gekoppelde spelprincipes — dedicated section after
        // the detail dl with linked pills (was an inline `<dt>/<dd>`
        // that pilot reported was easy to miss + didn't link to the
        // methodology browser). Skipped when the Methodology module
        // isn't loaded.
        if ( class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrincipleLinksRepository' )
             && class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' )
        ) {
            $linked_ids = ( new \TT\Modules\Methodology\Repositories\PrincipleLinksRepository() )
                ->principlesForActivity( (int) $session->id );
            if ( ! empty( $linked_ids ) ) {
                $repo = new \TT\Modules\Methodology\Repositories\PrinciplesRepository();
                $base = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
                echo '<section class="tt-activity-principles" style="margin:8px 0 12px;">';
                echo '<h3 style="margin:0 0 6px; font-size:13px; font-weight:700; color:#1a1d21;">'
                    . esc_html__( 'Gekoppelde spelprincipes', 'talenttrack' )
                    . '</h3>';
                echo '<div style="display:flex; flex-wrap:wrap; gap:6px;">';
                foreach ( $linked_ids as $pid ) {
                    $pr = $repo->find( (int) $pid );
                    if ( ! $pr ) continue;
                    $code = (string) ( $pr->code ?? '' );
                    $title = '';
                    if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                        $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                    }
                    $url = add_query_arg(
                        [ 'tt_view' => 'methodology', 'mtab' => 'principles', 'pid' => (int) $pid ],
                        $base
                    );
                    // Bucket colour derived from code prefix (A* /
                    // V* / O*) — same scheme as the planner card
                    // chips for visual consistency.
                    $first = $code !== '' ? strtoupper( $code[0] ) : '';
                    $bg = '#eef4fb'; $bd = '#c5d8ee'; $fg = '#1f4f8a';
                    if ( $first === 'A' ) { $bg = '#fde9d6'; $bd = '#f3c79b'; $fg = '#8a3b00'; }
                    if ( $first === 'V' ) { $bg = '#dfeede'; $bd = '#a8d2a4'; $fg = '#1f5a1a'; }
                    if ( $first === 'O' ) { $bg = '#fff3c4'; $bd = '#e3c75e'; $fg = '#7a5a08'; }
                    $label = $code . ( $title !== '' ? ' · ' . $title : '' );
                    echo '<a href="' . esc_url( $url ) . '"'
                        . ' style="display:inline-block; padding:4px 10px; background:' . esc_attr( $bg ) . '; border:1px solid ' . esc_attr( $bd ) . '; border-radius:999px; font-size:12px; color:' . esc_attr( $fg ) . '; text-decoration:none; font-weight:600;"'
                        . ' title="' . esc_attr( $title ) . '">'
                        . esc_html( $label )
                        . '</a>';
                }
                echo '</div></section>';
            }
        }

        // #1099 + #1100 — Activity Explorer presets. Two affordances
        // side-by-side: Evaluation coverage + Attendance vs squad.
        $activity_id_attr = (string) (int) ( $session->id ?? 0 );
        $cov_url = \TT\Modules\Analytics\Domain\ExplorerUrl::build(
            'evaluation_coverage',
            [ 'activity_id' => $activity_id_attr, 'date_after' => '-12 months' ]
        );
        $att_url = \TT\Modules\Analytics\Domain\ExplorerUrl::build(
            'attendance_vs_squad',
            [ 'team_id' => (string) (int) ( $session->team_id ?? 0 ), 'date_after' => '-12 months' ],
            'player_id'
        );
        echo '<div class="tt-activity-explorer-row" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 12px;">';
        echo '<a href="' . esc_url( $cov_url ) . '" style="background:transparent;border:1px solid #d6dadd;color:#5b6e75;text-decoration:none;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;">'
            . esc_html__( 'Explorer · Evaluation coverage →', 'talenttrack' )
            . '</a>';
        echo '<a href="' . esc_url( $att_url ) . '" style="background:transparent;border:1px solid #d6dadd;color:#5b6e75;text-decoration:none;padding:6px 10px;border-radius:6px;font-size:12px;font-weight:600;">'
            . esc_html__( 'Explorer · Attendance vs squad →', 'talenttrack' )
            . '</a>';
        echo '</div>';

        // v3.110.138 — when the coach marked attendance and chose
        // "Skip rating — no rating needed", this activity carries
        // `evaluation_skipped=1` and is filtered out of the eval-
        // wizard's picker. Surface the state + an explicit "Re-open
        // for rating" button so the coach can put it back on the
        // rating queue if they change their mind. Gated on
        // `tt_edit_activities`; defensive when the column doesn't
        // exist on this install (pre-migration-0100 fallback).
        $eval_skipped = (int) ( $session->evaluation_skipped ?? 0 );
        if ( $eval_skipped === 1 ) {
            echo '<div class="tt-notice tt-notice-info" style="margin:0 0 8px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">';
            echo '<span>' . esc_html__( 'Rating skipped — this activity won\'t appear in the rating picker.', 'talenttrack' ) . '</span>';
            if ( current_user_can( 'tt_edit_activities' ) ) {
                $aid = (int) $session->id;
                echo '<button type="button" class="tt-button tt-button-secondary" data-tt-reopen-rating="' . esc_attr( (string) $aid ) . '" data-tt-rest-path="activities/' . (int) $aid . '/evaluation-skipped">';
                echo esc_html__( 'Re-open for rating', 'talenttrack' );
                echo '</button>';
            }
            echo '</div>';
            ?>
            <script>
            (function () {
                var btn = document.querySelector('[data-tt-reopen-rating]');
                if ( ! btn ) return;
                btn.addEventListener('click', function () {
                    var path = btn.getAttribute('data-tt-rest-path');
                    btn.disabled = true;
                    var tt = window.TT || {};
                    var url = ( tt.rest_url || '/wp-json/talenttrack/v1/' ).replace(/\/+$/, '/') + path;
                    fetch(url, {
                        method: 'PATCH',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': tt.rest_nonce || '' },
                        body: JSON.stringify({ skipped: 0 })
                    }).then(function (r) {
                        if ( r.ok ) window.location.reload();
                        else { btn.disabled = false; btn.textContent = 'Retry'; }
                    }).catch(function () { btn.disabled = false; });
                });
            })();
            </script>
            <?php
        }

        // v3.110.95 — Attendance summary block. Surfaces the same
        // figures the activity-list "Att. %" column shows (now that
        // both queries share the "attendance row for player on
        // current active roster" predicate). The percentage is a
        // clickable link to the edit form, which IS the per-player
        // attendance list with marks — exactly what the user asked
        // for. Visible only on completed activities, since planned
        // / cancelled rows have no meaningful attendance.
        $status_key_for_att = (string) ( $session->activity_status_key ?? ActivityStatusKey::PLANNED );
        if ( $status_key_for_att === ActivityStatusKey::COMPLETED ) {
            self::renderAttendanceSummary( $session );
        }

        // v3.110.53 — Edit + Archive moved to the page-header actions
        // slot rendered by render() before this method runs.
        //
        // v3.110.98 — the activity-scoped Analytics section (#0083
        // Child 4) is no longer rendered here. Operator decision: the
        // detail page is a "what happened in this session" surface,
        // not a stats deep-dive. Analytics moves to the central
        // Analytics tile on the dashboard where coaches can slice
        // across activities. The renderer + the registered
        // activity-scoped KPIs stay on disk so the central tile
        // continues to consume them.

        echo '</div>';
    }

    /**
     * v3.110.95 — Attendance summary on the read-only activity detail
     * page. Renders a "X / Y (Z%) Present" line plus per-status counts.
     * The percentage cell links to the edit form (`action=edit`), which
     * is the per-player attendance list with status marks — what the
     * user asked for.
     *
     * Uses the same predicate as the activities list-view SQL
     * (`ActivitiesRestController::list_sessions`) so the % shown here
     * matches the % in the list. Counts are grouped server-side in one
     * query; only attendance rows whose player is still on this team's
     * current active roster are included, mirroring v3.110.95's list-
     * view fix.
     */
    private static function renderAttendanceSummary( object $session ): void {
        global $wpdb;
        $p          = $wpdb->prefix;
        $activity_id = (int) ( $session->id ?? 0 );
        $team_id     = (int) ( $session->team_id ?? 0 );
        if ( $activity_id <= 0 || $team_id <= 0 ) return;

        $club_id = \TT\Infrastructure\Tenancy\CurrentClub::id();
        $roster_size = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND status = 'active'",
            $team_id, $club_id
        ) );
        if ( $roster_size === 0 ) return;

        // v3.110.98 — `LOWER(a.status)` normalises the group key so
        // legacy capitalised rows ('Present') and current lowercase
        // rows ('present') aggregate into the same bucket. Pre-fix
        // this counted by raw stored case, the PHP lookups asked for
        // 'Present', and rosters whose rows had been written lowercase
        // (the AttendanceStep::validate path via `sanitize_key()`)
        // produced the headline "0 / N (0% present)" even when every
        // player had a present row. The breakdown then fell through to
        // the "custom status" branch and rendered the raw lowercase
        // keys, which looked like a separate bug. Same case-handling
        // story as v3.110.78's RateConfirmStep + ratablePlayersForActivity
        // fixes.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT LOWER(a.status) AS status, COUNT(*) AS cnt
               FROM {$p}tt_attendance a
               INNER JOIN {$p}tt_players pl ON pl.id = a.player_id AND pl.club_id = a.club_id
              WHERE a.activity_id = %d AND a.is_guest = 0 AND a.club_id = %d
                AND pl.team_id = %d AND pl.status = 'active'
              GROUP BY LOWER(a.status)",
            $activity_id, $club_id, $team_id
        ) );

        $by_status = [];
        $total = 0;
        foreach ( (array) $rows as $r ) {
            $key = strtolower( (string) ( $r->status ?? '' ) );
            $cnt = (int) ( $r->cnt ?? 0 );
            $by_status[ $key ] = $cnt;
            $total += $cnt;
        }
        $present = (int) ( $by_status['present'] ?? 0 );
        $pct = (int) round( ( $present / $roster_size ) * 100 );
        if ( $pct > 100 ) $pct = 100;

        $can_edit = current_user_can( 'tt_edit_activities' );
        $edit_url = '';
        if ( $can_edit ) {
            $edit_url = \TT\Shared\Frontend\Components\BackLink::appendTo(
                add_query_arg(
                    [ 'tt_view' => 'activities', 'id' => $activity_id, 'action' => 'edit' ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                )
            );
        }

        echo '<section class="tt-activity-attendance-summary" style="margin-top:20px; padding:16px 18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">';
        echo '<h3 style="margin:0 0 12px; font-size:16px;">' . esc_html__( 'Attendance', 'talenttrack' ) . '</h3>';

        echo '<p style="margin:0 0 8px; font-size:14px;">';
        $headline = sprintf(
            /* translators: 1: present count, 2: roster size, 3: percentage 0-100 */
            __( '%1$d / %2$d players (%3$d%% present)', 'talenttrack' ),
            $present, $roster_size, $pct
        );
        if ( $edit_url !== '' ) {
            echo '<a href="' . esc_url( $edit_url ) . '" class="tt-record-link" style="font-weight:600; font-size:16px;">'
                . esc_html( $headline )
                . '</a>';
        } else {
            echo '<strong>' . esc_html( $headline ) . '</strong>';
        }
        echo '</p>';

        // Per-status breakdown — explicit, so the operator sees the
        // composition without having to re-count manually. Hidden when
        // no rows recorded yet (total = 0). v3.110.98 — lowercase
        // status keys so the lookup against `$by_status` (now keyed by
        // `LOWER(a.status)`) matches. LabelTranslator handles the
        // localised label via ucfirst.
        if ( $total > 0 ) {
            $status_keys = [ 'present', 'absent', 'late', 'excused', 'injured' ];
            $parts = [];
            foreach ( $status_keys as $sk ) {
                $cnt = (int) ( $by_status[ $sk ] ?? 0 );
                if ( $cnt === 0 ) continue;
                $label = \TT\Infrastructure\Query\LabelTranslator::attendanceStatus( ucfirst( $sk ) );
                $parts[] = '<span style="display:inline-block; margin-right:14px;">'
                    . esc_html( $label ) . ': <strong>' . (int) $cnt . '</strong></span>';
            }
            // Any custom status admins added beyond the seeded set.
            foreach ( $by_status as $sk => $cnt ) {
                if ( in_array( $sk, $status_keys, true ) ) continue;
                if ( $cnt === 0 || $sk === '' ) continue;
                $parts[] = '<span style="display:inline-block; margin-right:14px;">'
                    . esc_html( ucfirst( $sk ) ) . ': <strong>' . (int) $cnt . '</strong></span>';
            }
            if ( $parts !== [] ) {
                echo '<p style="margin:0; font-size:13px; color:#475569;">'
                    . implode( '', $parts )
                    . '</p>';
            }

            // If recorded rows < current roster, surface the gap.
            if ( $total < $roster_size ) {
                $unrecorded = $roster_size - $total;
                echo '<p style="margin:8px 0 0; font-size:12px; color:#92400e; font-style:italic;">'
                    . esc_html( sprintf(
                        /* translators: %d: number of players without an attendance row */
                        _n(
                            '%d player on the current roster has no attendance row yet.',
                            '%d players on the current roster have no attendance row yet.',
                            $unrecorded,
                            'talenttrack'
                        ),
                        $unrecorded
                    ) )
                    . '</p>';
            }
        } else {
            echo '<p style="margin:0; font-size:13px; color:#475569; font-style:italic;">'
                . esc_html__( 'No attendance recorded yet.', 'talenttrack' )
                . '</p>';
        }

        echo '</section>';
    }

    /**
     * List view — date-bucketed card list (v4.7.0, #973).
     *
     * Port of `.local-mockups/activity-list/index.html` to PHP-rendered
     * output. Replaces the v4.6.x `FrontendListTable` render (Date /
     * Title / Type / Status / Source / Team / Att.% table) with a
     * Today / This week / Next week / Later-this-month / Later card
     * stack plus a pinned-top Past toggle and a "Needs attention"
     * pseudo-bucket for past-planned activities the coach forgot to
     * close.
     *
     * Backend untouched: this method reads `tt_activities` directly via
     * a query that mirrors `ActivitiesRestController::list_sessions`'s
     * WHERE / scope rules (club_id, demo scope, head-coach team scope
     * for non-global-readers, archived filter, team_id filter). The
     * REST endpoint stays the canonical contract for non-WordPress
     * consumers per CLAUDE.md §4.
     *
     * URL state:
     *   ?team_id=N            — filter to one team (carries forward
     *                            existing dashboard-widget / team-detail
     *                            link targets).
     *   ?activity_type_key=X  — filter to one lookup-backed type.
     *   ?include_past=1       — expand the past block.
     */
    private static function renderList( int $user_id, bool $is_admin ): void {
        // URL-state -> sanitized filters.
        $team_filter = isset( $_GET['team_id'] ) ? absint( (string) $_GET['team_id'] ) : 0;
        $type_filter = isset( $_GET['activity_type_key'] ) ? sanitize_key( (string) $_GET['activity_type_key'] ) : '';
        $include_past = ! empty( $_GET['include_past'] );

        // Lookup-backed type options for the Type filter.
        $type_rows = QueryHelpers::get_lookups( 'activity_type' );

        // Team options — same source as the legacy filter, so dashboard
        // / team-detail links continue to land on the same scoped view.
        $team_options = TeamPickerComponent::filterOptions( $user_id, $is_admin );

        // Pull the row set for THIS list — server-side query mirroring
        // the REST WHERE/scope so other surfaces (dashboard widgets,
        // team detail) reading the same rows stay consistent.
        $rows = self::loadActivitiesForList( $team_filter, $type_filter );

        // Today (site timezone, GMT-stored value converted via
        // wp_timezone() per `current_time('Y-m-d', true)`).
        $today_str = current_time( 'Y-m-d', true );

        // Bucket the rows.
        $buckets = self::bucketize( $rows, $today_str );

        $past_total = count( $buckets['past'] );

        // ---- HEADER + FILTERS ---------------------------------------
        echo '<div class="tt-act-surface" data-tt-act-surface>';

        // Filter row — Team + Type. GET form so query state survives in URL.
        echo '<form method="get" class="tt-act-filters" data-tt-act-filters>';
        // Preserve `tt_view=activities` + any back-target on submit.
        echo '<input type="hidden" name="tt_view" value="activities" />';
        if ( ! empty( $_GET['tt_back'] ) ) {
            echo '<input type="hidden" name="tt_back" value="' . esc_attr( (string) $_GET['tt_back'] ) . '" />';
        }
        if ( $include_past ) {
            echo '<input type="hidden" name="include_past" value="1" />';
        }

        echo '<label class="tt-act-filters__field">';
        echo '<span class="tt-act-filters__label">' . esc_html__( 'Team', 'talenttrack' ) . '</span>';
        echo '<select class="tt-act-filters__select" name="team_id" onchange="this.form.submit()">';
        echo '<option value="">' . esc_html__( '— all teams —', 'talenttrack' ) . '</option>';
        foreach ( $team_options as $tid => $tname ) {
            echo '<option value="' . esc_attr( (string) (int) $tid ) . '"' . selected( $team_filter, (int) $tid, false ) . '>' . esc_html( (string) $tname ) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label class="tt-act-filters__field">';
        echo '<span class="tt-act-filters__label">' . esc_html__( 'Type', 'talenttrack' ) . '</span>';
        echo '<select class="tt-act-filters__select" name="activity_type_key" onchange="this.form.submit()">';
        echo '<option value="">' . esc_html__( '— all types —', 'talenttrack' ) . '</option>';
        foreach ( $type_rows as $tr ) {
            $name  = (string) ( $tr->name ?? '' );
            $label = LookupTranslator::name( $tr );
            echo '<option value="' . esc_attr( $name ) . '"' . selected( $type_filter, $name, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</label>';
        echo '<noscript><button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button></noscript>';
        echo '</form>';

        // ---- EMPTY STATE --------------------------------------------
        $forward_total = $buckets['attention_count']
            + count( $buckets['today'] )
            + count( $buckets['this_week'] )
            + count( $buckets['next_week'] )
            + count( $buckets['later_this_month'] )
            + count( $buckets['later'] );

        if ( $forward_total === 0 && $past_total === 0 ) {
            echo '<div class="tt-act-empty">';
            echo '<h3>' . esc_html__( 'No activities to show', 'talenttrack' ) . '</h3>';
            echo '<p>' . esc_html__( 'Try changing the team or type filter, or create a new activity to get started.', 'talenttrack' ) . '</p>';
            echo '</div>';
            echo '</div>'; // .tt-act-surface
            return;
        }

        // ---- PAST TOGGLE --------------------------------------------
        if ( $past_total > 0 ) {
            self::renderPastToggle( $past_total, $include_past );
        }

        // ---- PAST BLOCK (when expanded) -----------------------------
        if ( $include_past && $past_total > 0 ) {
            echo '<ul class="tt-act-list tt-act-list--past" aria-label="' . esc_attr__( 'Past activities', 'talenttrack' ) . '">';
            echo '<li><div class="tt-act-bucket-head"><span>' . esc_html__( 'Past', 'talenttrack' ) . '</span>';
            echo '<span class="tt-act-bucket-head__count">' . esc_html( sprintf(
                /* translators: %d: count of past activities */
                _n( '%d activity', '%d activities', $past_total, 'talenttrack' ),
                $past_total
            ) ) . '</span></div></li>';
            foreach ( $buckets['past'] as $row ) {
                echo self::renderActivityCard( $row, 'past' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper.
            }
            echo '</ul>';
        }

        // ---- FORWARD BUCKETS ----------------------------------------
        echo '<ul class="tt-act-list">';

        if ( $buckets['attention_count'] > 0 ) {
            self::renderBucket(
                'attention',
                __( '⚠ Needs attention', 'talenttrack' ),
                $buckets['attention'],
                sprintf(
                    /* translators: %d: count of past planned activities */
                    _n( '%d past, still planned', '%d past, still planned', $buckets['attention_count'], 'talenttrack' ),
                    $buckets['attention_count']
                )
            );
        }

        if ( $buckets['today'] ) {
            // Header: "Today · Wed 28 May" — day-of-week + date.
            $today_label = self::formatTodayHeader( $today_str );
            self::renderBucket(
                'today',
                $today_label,
                $buckets['today'],
                sprintf(
                    /* translators: %d: count of activities today */
                    _n( '%d activity', '%d activities', count( $buckets['today'] ), 'talenttrack' ),
                    count( $buckets['today'] )
                )
            );
        }

        if ( $buckets['this_week'] ) {
            self::renderBucket(
                'this-week',
                __( 'This week', 'talenttrack' ),
                $buckets['this_week'],
                sprintf(
                    /* translators: %d: count of activities */
                    _n( '%d activity', '%d activities', count( $buckets['this_week'] ), 'talenttrack' ),
                    count( $buckets['this_week'] )
                )
            );
        }

        if ( $buckets['next_week'] ) {
            self::renderBucket(
                'next-week',
                __( 'Next week', 'talenttrack' ),
                $buckets['next_week'],
                sprintf(
                    /* translators: %d: count of activities */
                    _n( '%d activity', '%d activities', count( $buckets['next_week'] ), 'talenttrack' ),
                    count( $buckets['next_week'] )
                )
            );
        }

        if ( $buckets['later_this_month'] ) {
            self::renderBucket(
                'later-this-month',
                __( 'Later this month', 'talenttrack' ),
                $buckets['later_this_month'],
                sprintf(
                    /* translators: %d: count of activities */
                    _n( '%d activity', '%d activities', count( $buckets['later_this_month'] ), 'talenttrack' ),
                    count( $buckets['later_this_month'] )
                )
            );
        }

        if ( $buckets['later'] ) {
            self::renderBucket(
                'later',
                __( 'Later', 'talenttrack' ),
                $buckets['later'],
                sprintf(
                    /* translators: %d: count of activities */
                    _n( '%d activity', '%d activities', count( $buckets['later'] ), 'talenttrack' ),
                    count( $buckets['later'] )
                )
            );
        }

        echo '</ul>';

        echo '</div>'; // .tt-act-surface
    }

    /**
     * Render a non-past bucket — header + cards.
     *
     * @param array<int,object> $rows
     */
    private static function renderBucket( string $bucket_key, string $title, array $rows, string $count_label ): void {
        $attention = $bucket_key === 'attention';
        $today     = $bucket_key === 'today';

        $cls = 'tt-act-bucket-head';
        if ( $attention ) $cls .= ' tt-act-bucket-head--attention';

        echo '<li><div class="' . esc_attr( $cls ) . '" data-bucket="' . esc_attr( $bucket_key ) . '">';
        echo '<span>' . esc_html( $title ) . '</span>';
        echo '<span class="tt-act-bucket-head__count">' . esc_html( $count_label ) . '</span>';
        echo '</div></li>';

        $row_mode = $attention ? 'attention' : ( $today ? 'today' : 'future' );
        foreach ( $rows as $row ) {
            echo self::renderActivityCard( $row, $row_mode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper.
        }
    }

    /**
     * Render the past-toggle button. The form is a GET that flips
     * `include_past` between absent and `1`, preserving every other
     * existing query param.
     */
    private static function renderPastToggle( int $past_count, bool $include_past ): void {
        // Build the toggle URL — same query string, toggled include_past.
        $toggle_url = $include_past
            ? remove_query_arg( 'include_past' )
            : add_query_arg( 'include_past', '1' );

        // Carry the click via <a> so it's noscript-resilient and a
        // proper 48px tap target.
        $count_text = esc_html( sprintf(
            /* translators: %d: count of past activities */
            _n( '%d past activity', '%d past activities', $past_count, 'talenttrack' ),
            $past_count
        ) );
        $label = $include_past
            ? esc_html__( 'shown · Hide', 'talenttrack' )
            : esc_html__( 'hidden · Show', 'talenttrack' );

        echo '<a class="tt-act-past-toggle" href="' . esc_url( $toggle_url ) . '" data-past-state="' . ( $include_past ? 'expanded' : 'collapsed' ) . '">';
        echo '<span class="tt-act-past-toggle__count">' . $count_text . '</span> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _n esc'd above.
        echo '<span class="tt-act-past-toggle__label">' . $label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span class="tt-act-past-toggle__chev" aria-hidden="true">▼</span>';
        echo '</a>';
    }

    /**
     * Render one activity card.
     *
     * @param object $row activity row from loadActivitiesForList()
     * @param string $mode 'past' | 'attention' | 'today' | 'future'
     */
    private static function renderActivityCard( object $row, string $mode ): string {
        $id = (int) ( $row->id ?? 0 );
        if ( $id <= 0 ) return '';

        $session_date = (string) ( $row->session_date ?? '' );
        $title        = (string) ( $row->title ?? '' );
        $team_name    = (string) ( $row->team_name ?? '' );
        $type_key     = (string) ( $row->activity_type_key ?? '' );
        $status_key   = (string) ( $row->activity_status_key ?? '' );
        $location     = (string) ( $row->location ?? '' );
        $start_time   = (string) ( $row->start_time ?? '' );

        $detail_url = RecordLink::detailUrlForWithBack( 'activities', $id );

        // Date badge — "May / 28" stacked.
        $month_short = '';
        $day_num     = '';
        if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $session_date, $m ) ) {
            $ts = strtotime( $session_date );
            if ( $ts ) {
                // Localised abbreviated month name.
                $month_short = wp_date( 'M', (int) $ts );
                $day_num     = (int) $m[3];
            }
        }
        if ( $day_num === '' ) {
            $month_short = '—';
            $day_num     = '';
        }

        $date_cls = 'tt-act-date';
        if ( $mode === 'today' )      $date_cls .= ' tt-act-date--today';
        if ( $mode === 'attention' )  $date_cls .= ' tt-act-date--overdue';

        $type_pill_key = self::typeKeyForPill( $type_key );
        $type_label    = self::lookupLabelByName( 'activity_type', $type_key );
        $status_label  = self::lookupLabelByName( 'activity_status', $status_key );

        // Build meta line: type pill | (optional status pill for past) | team + time + location.
        $meta_bits = [];
        $meta_bits[] = '<span class="tt-act-pill" data-type="' . esc_attr( $type_pill_key ) . '">' . esc_html( $type_label !== '' ? $type_label : ucfirst( $type_key ) ) . '</span>';

        if ( $mode === 'past' && in_array( $status_key, [ ActivityStatusKey::COMPLETED, ActivityStatusKey::CANCELLED ], true ) ) {
            $meta_bits[] = '<span class="tt-act-pill" data-status="' . esc_attr( $status_key ) . '">' . esc_html( $status_label !== '' ? $status_label : ucfirst( $status_key ) ) . '</span>';
        }

        // Team / time / "still planned" tail (plain text).
        $tail = [];
        if ( $team_name !== '' ) $tail[] = esc_html( $team_name );
        if ( $start_time !== '' ) {
            // Truncate seconds; show HH:MM.
            $tail[] = esc_html( substr( $start_time, 0, 5 ) );
        }
        if ( $mode === 'attention' ) {
            $tail[] = '<em class="tt-act-meta__attn">' . esc_html__( 'still planned', 'talenttrack' ) . '</em>';
        }
        if ( $location !== '' && $mode !== 'past' ) {
            $tail[] = esc_html( $location );
        }

        $card  = '<li class="tt-act-card" data-type="' . esc_attr( $type_pill_key ) . '">';
        $card .= '<a class="tt-act-card__link" href="' . esc_url( $detail_url ) . '">';
        $card .= '<div class="' . esc_attr( $date_cls ) . '">';
        $card .= '<span class="tt-act-date__m">' . esc_html( $month_short ) . '</span>';
        $card .= '<span class="tt-act-date__d">' . esc_html( (string) $day_num ) . '</span>';
        $card .= '</div>';
        $card .= '<div class="tt-act-card__body">';
        $card .= '<p class="tt-act-card__title">' . esc_html( $title !== '' ? $title : __( '(untitled activity)', 'talenttrack' ) ) . '</p>';
        $card .= '<p class="tt-act-card__meta">' . implode( ' ', $meta_bits );
        if ( $tail !== [] ) {
            $card .= ' <span class="tt-act-card__tail">' . implode( ' · ', $tail ) . '</span>';
        }
        $card .= '</p>';
        $card .= '</div>';
        $card .= '<span class="tt-act-card__chev" aria-hidden="true">›</span>';
        $card .= '</a>';
        $card .= '</li>';
        return $card;
    }

    /**
     * Normalize a stored `activity_type_key` to one of the four pill
     * colour buckets the mockup defines: training / match / friendly /
     * other. The seeded keys ('training', 'game', 'tournament',
     * 'meeting', 'other') and any operator-renamed keys all collapse
     * down to these four. 'game' → 'match' (same red), 'tournament' →
     * 'match' (also competitive). Everything else falls through to
     * 'other'.
     */
    private static function typeKeyForPill( string $type_key ): string {
        $k = strtolower( trim( $type_key ) );
        if ( $k === '' ) return 'other';
        if ( strpos( $k, 'train' ) !== false )                  return 'training';
        if ( in_array( $k, [ 'match', ActivityTypeKey::GAME, ActivityTypeKey::TOURNAMENT ], true ) ) return 'match';
        if ( strpos( $k, 'friend' ) !== false )                 return 'friendly';
        return 'other';
    }

    /**
     * Resolve the localised label for a lookup-backed key. Returns ''
     * when the lookup isn't found.
     */
    private static function lookupLabelByName( string $type, string $name ): string {
        if ( $name === '' ) return '';
        static $cache = [];
        if ( ! isset( $cache[ $type ] ) ) {
            $cache[ $type ] = [];
            foreach ( QueryHelpers::get_lookups( $type ) as $r ) {
                $cache[ $type ][ (string) $r->name ] = $r;
            }
        }
        $row = $cache[ $type ][ $name ] ?? null;
        return $row ? LookupTranslator::name( $row ) : '';
    }

    /**
     * Format the Today bucket header "Today · Wed 28 May".
     */
    private static function formatTodayHeader( string $today_str ): string {
        $ts = strtotime( $today_str );
        if ( ! $ts ) return __( 'Today', 'talenttrack' );
        return sprintf(
            /* translators: 1: localised "Today", 2: day-of-week + date (e.g. "Wed 28 May") */
            __( '%1$s · %2$s', 'talenttrack' ),
            __( 'Today', 'talenttrack' ),
            wp_date( 'D j M', (int) $ts )
        );
    }

    /**
     * Bucketize a list of activity rows by `session_date` relative to
     * `$today_str`. Past = `plan_state IN ('completed','cancelled')` AND
     * `session_date < today`. Past-planned (still planned) lands in
     * "attention" instead.
     *
     * Buckets:
     *  - past             — past + completed/cancelled (pinned-top block)
     *  - attention        — past + planned (Needs attention pseudo-bucket)
     *  - today            — session_date == today
     *  - this_week        — today < session_date <= upcoming Sunday
     *  - next_week        — next Mon → next Sun
     *  - later_this_month — beyond next_week, <= end-of-month
     *  - later            — > end-of-month
     *
     * @param array<int,object> $rows
     * @return array{
     *     past: array<int,object>,
     *     attention: array<int,object>,
     *     attention_count: int,
     *     today: array<int,object>,
     *     this_week: array<int,object>,
     *     next_week: array<int,object>,
     *     later_this_month: array<int,object>,
     *     later: array<int,object>
     * }
     */
    private static function bucketize( array $rows, string $today_str ): array {
        $tz = wp_timezone();
        try {
            $today_dt = new \DateTimeImmutable( $today_str, $tz );
        } catch ( \Exception $e ) {
            $today_dt = new \DateTimeImmutable( 'today', $tz );
        }

        // "this week" ends on the upcoming Sunday (inclusive). PHP's
        // 'sunday this week' anchors to the current week's Sunday;
        // 'next sunday' if today IS Sunday rolls forward 7 days. Use
        // 'sunday this week' which yields today's date when today is
        // Sunday (so This Week becomes empty, which is the correct
        // semantic — no remaining days in this week).
        $end_of_this_week = $today_dt->modify( 'sunday this week' );
        $next_week_start  = $end_of_this_week->modify( '+1 day' );
        $next_week_end    = $next_week_start->modify( '+6 days' );
        $end_of_month     = $today_dt->modify( 'last day of this month' );

        $today_ymd          = $today_dt->format( 'Y-m-d' );
        $end_of_this_week_y = $end_of_this_week->format( 'Y-m-d' );
        $next_week_start_y  = $next_week_start->format( 'Y-m-d' );
        $next_week_end_y    = $next_week_end->format( 'Y-m-d' );
        $end_of_month_y     = $end_of_month->format( 'Y-m-d' );

        $past             = [];
        $attention        = [];
        $today            = [];
        $this_week        = [];
        $next_week        = [];
        $later_this_month = [];
        $later            = [];

        foreach ( $rows as $row ) {
            $sd = (string) ( $row->session_date ?? '' );
            if ( $sd === '' ) continue;

            $plan_state = strtolower( (string) ( $row->plan_state ?? '' ) );
            $status     = strtolower( (string) ( $row->activity_status_key ?? '' ) );

            // The "closed" predicate accepts EITHER the plan_state
            // taxonomy (completed/cancelled) OR the older
            // activity_status_key taxonomy. Belt-and-braces because
            // pilot installs have a mix of both on legacy rows.
            $is_closed = in_array( $plan_state, [ ActivityStatusKey::COMPLETED, ActivityStatusKey::CANCELLED ], true )
                      || in_array( $status,     [ ActivityStatusKey::COMPLETED, ActivityStatusKey::CANCELLED ], true );

            if ( $sd < $today_ymd ) {
                if ( $is_closed ) {
                    $past[] = $row;
                } else {
                    $attention[] = $row;
                }
                continue;
            }
            if ( $sd === $today_ymd ) {
                $today[] = $row;
                continue;
            }
            // future
            if ( $sd <= $end_of_this_week_y ) {
                $this_week[] = $row;
                continue;
            }
            if ( $sd >= $next_week_start_y && $sd <= $next_week_end_y ) {
                $next_week[] = $row;
                continue;
            }
            if ( $sd <= $end_of_month_y ) {
                $later_this_month[] = $row;
                continue;
            }
            $later[] = $row;
        }

        // Sort each forward bucket ascending so the next-upcoming row
        // sits at the top. Past sorts descending — most-recent first.
        $asc = static function ( $a, $b ) {
            return strcmp( (string) ( $a->session_date ?? '' ), (string) ( $b->session_date ?? '' ) );
        };
        $desc = static function ( $a, $b ) {
            return strcmp( (string) ( $b->session_date ?? '' ), (string) ( $a->session_date ?? '' ) );
        };
        usort( $today,            $asc );
        usort( $this_week,        $asc );
        usort( $next_week,        $asc );
        usort( $later_this_month, $asc );
        usort( $later,            $asc );
        usort( $attention,        $asc );
        usort( $past,             $desc );

        return [
            'past'             => $past,
            'attention'        => $attention,
            'attention_count'  => count( $attention ),
            'today'            => $today,
            'this_week'        => $this_week,
            'next_week'        => $next_week,
            'later_this_month' => $later_this_month,
            'later'            => $later,
        ];
    }

    /**
     * Server-side activity query for the redesigned list view (v4.7.0,
     * #973). Mirrors `ActivitiesRestController::list_sessions`'s WHERE
     * and scope rules so the rendered list shows the same set the REST
     * endpoint would return for an equivalent filter — just without
     * pagination (bucketing is a presentation concern over the whole
     * matched set).
     *
     * The REST controller continues to be the canonical contract for
     * non-WordPress consumers (CLAUDE.md §4); this is the PHP-render
     * sibling, not a divergence.
     *
     * @return array<int,object> raw `tt_activities` rows + team_name.
     */
    private static function loadActivitiesForList( int $team_filter, string $type_filter ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 's.club_id = %d', 's.archived_at IS NULL' ];
        $params = [ CurrentClub::id() ];

        // Demo-mode scope predicate (e.g. demo-only activities).
        $scope = QueryHelpers::apply_demo_scope( 's', 'activity' );

        // Coach-scope guard — mirrors REST list_sessions. Personas with
        // matrix `activities:r[global]` (scout, head_of_development,
        // academy_admin) bypass; everyone else is restricted to teams
        // they head-coach.
        $uid = get_current_user_id();
        if ( ! QueryHelpers::user_has_global_entity_read( $uid, 'activities' ) ) {
            $coach_teams = QueryHelpers::get_teams_for_coach( $uid );
            if ( ! $coach_teams ) {
                // No accessible teams → empty list.
                return [];
            }
            $team_ids = array_map( static function ( $t ) { return (int) $t->id; }, $coach_teams );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $where[] = "s.team_id IN ($placeholders)";
            $params = array_merge( $params, $team_ids );
        }

        if ( $team_filter > 0 ) {
            $where[]  = 's.team_id = %d';
            $params[] = $team_filter;
        }
        if ( $type_filter !== '' ) {
            $where[]  = 's.activity_type_key = %s';
            $params[] = $type_filter;
        }

        $where_sql = implode( ' AND ', $where ) . ' ' . $scope;

        // No LIMIT — the activities surface deliberately renders the
        // full filtered set so the buckets reflect reality. Pilot
        // volumes are tractable (low hundreds); the past-pagination
        // follow-up sits in the issue's "Out of scope" list.
        $sql = "SELECT s.*, t.name AS team_name
                FROM {$p}tt_activities s
                LEFT JOIN {$p}tt_teams t ON t.id = s.team_id AND t.club_id = s.club_id
                WHERE {$where_sql}
                ORDER BY s.session_date ASC, s.id ASC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return is_array( $rows ) ? $rows : [];
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

        // v4.20.10 (#1154) — was: roster spanned every team the coach
        // has access to, so the entire academy shipped in the hidden
        // form fields. That produced the off-roster `is_guest = 0`
        // artefacts #1148 had to write-time-filter and DELETE-backfill.
        // Now: roster is the activity's current team only, unioned with
        // any players who already have an attendance row on this
        // activity (legacy historical squad data preserved through
        // #1148's audit-trail-aware backfill — those rows must stay
        // editable in the form even when the player has since moved
        // teams).
        //
        // Team-dropdown switch edge case: when the admin changes the
        // team `<select>`, the picker still shows the OLD team's roster
        // until the activity is saved + page reloaded. This is the
        // niche edge case (#1154 issue body) — coaches typically pick
        // the team first, then mark attendance. Save-and-reload is the
        // documented recovery.
        $players_by_team = [];
        $all_players     = [];
        if ( $selected_team > 0 ) {
            $current_roster = QueryHelpers::get_players( $selected_team );
            foreach ( $current_roster as $pl ) {
                $all_players[ (int) $pl->id ] = $pl;
                $players_by_team[ $selected_team ][] = (int) $pl->id;
            }
        }
        // Union in players who already have an attendance row on this
        // activity but aren't on the current roster. Keeps historical
        // squad data editable.
        if ( $attendance ) {
            $missing_ids = array_diff( array_map( 'intval', array_keys( $attendance ) ), array_keys( $all_players ) );
            if ( $missing_ids ) {
                global $wpdb;
                $placeholders = implode( ',', array_fill( 0, count( $missing_ids ), '%d' ) );
                /** @var object[] $legacy_rows */
                $legacy_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}tt_players
                      WHERE id IN ($placeholders) AND archived_at IS NULL",
                    ...$missing_ids
                ) );
                if ( is_array( $legacy_rows ) ) {
                    foreach ( $legacy_rows as $pl ) {
                        $pid = (int) $pl->id;
                        $all_players[ $pid ] = $pl;
                        $players_by_team[ (int) $pl->team_id ][] = $pid;
                    }
                }
            }
        }

        $statuses      = QueryHelpers::get_lookup_names( 'attendance_status' );
        $game_subtypes = QueryHelpers::get_lookup_names( 'game_subtype' );
        // #0050 — Type lookup-driven; admins can rename or add types
        // via Configuration → Activity Types. Conditional Subtype /
        // Other-label rows stay anchored to the seeded keys.
        $activity_type_rows   = QueryHelpers::get_lookups( 'activity_type' );
        $activity_status_rows = QueryHelpers::get_lookups( 'activity_status' );

        $current_type    = (string) ( $session->activity_type_key ?? ActivityTypeKey::TRAINING );
        $current_status  = (string) ( $session->activity_status_key ?? ActivityStatusKey::PLANNED );
        $current_subtype = (string) ( $session->game_subtype_key ?? '' );
        $current_other   = (string) ( $session->other_label ?? '' );

        // Edit mode → PUT /activities/{id}; create → POST /activities.
        $is_edit   = $session !== null;
        $rest_path = $is_edit ? 'activities/' . (int) $session->id : 'activities';
        $rest_meth = $is_edit ? 'PUT' : 'POST';
        $form_id   = 'tt-activity-form';

        // v3.110.51 — when the form was reached via a link that captured
        // the originating page (e.g. the team planner's "+ Schedule
        // activity" or "+ Add" links pass `tt_back=<planner URL>`),
        // redirect back there on save instead of falling through to the
        // generic activities list. The user's mental model is "I came
        // from the planner, take me back when I'm done." Falls back to
        // the existing `data-redirect-after-save="list"` behaviour when
        // no back-target is in the URL.
        $back_resolved = \TT\Shared\Frontend\Components\BackLink::resolve();
        $back_url      = is_array( $back_resolved ) ? (string) $back_resolved['url'] : '';

        ?>
        <form id="<?php echo esc_attr( $form_id ); ?>" class="tt-ajax-form tt-activity-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_meth ); ?>"<?php
            if ( $back_url !== '' ) :
                ?> data-redirect-after-save-url="<?php echo esc_attr( $back_url ); ?>"<?php
            else :
                ?> data-redirect-after-save="list"<?php
            endif;
        ?>>
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
                <div class="tt-field" id="tt-activity-subtype-row" style="<?php echo $current_type === ActivityTypeKey::GAME ? '' : 'display:none;'; ?>">
                    <label class="tt-field-label" for="tt-activity-subtype"><?php esc_html_e( 'Game subtype', 'talenttrack' ); ?></label>
                    <select id="tt-activity-subtype" class="tt-input" name="game_subtype_key">
                        <option value=""><?php esc_html_e( '— Choose —', 'talenttrack' ); ?></option>
                        <?php foreach ( $game_subtypes as $sub ) : ?>
                            <option value="<?php echo esc_attr( (string) $sub ); ?>" <?php selected( $current_subtype, (string) $sub ); ?>><?php echo esc_html( (string) $sub ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-field" id="tt-activity-other-row" style="<?php echo $current_type === ActivityTypeKey::OTHER ? '' : 'display:none;'; ?>">
                    <label class="tt-field-label tt-field-required" for="tt-activity-other-label"><?php esc_html_e( 'Other label', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-activity-other-label" class="tt-input" name="other_label" maxlength="120" value="<?php echo esc_attr( $current_other ); ?>" placeholder="<?php esc_attr_e( 'e.g. Team-building day', 'talenttrack' ); ?>" />
                </div>
                <?php
                // #0006 — when the team-planner sends the user here
                // with `?session_date=YYYY-MM-DD&plan_state=scheduled`,
                // pre-fill the date from the URL on create. Editing
                // existing activities ignores the URL — the row's own
                // date wins.
                $prefill_date = (string) ( $session->session_date ?? '' );
                if ( $prefill_date === '' ) {
                    $url_date = isset( $_GET['session_date'] ) ? sanitize_text_field( (string) $_GET['session_date'] ) : '';
                    $prefill_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $url_date ) ? $url_date : current_time( 'Y-m-d' );
                }
                echo DateInputComponent::render( [
                    'name'     => 'session_date',
                    'label'    => __( 'Date', 'talenttrack' ),
                    'required' => true,
                    'value'    => $prefill_date,
                ] ); ?>
                <?php
                // #0006 — pass the planner's `plan_state=scheduled`
                // through the form as a hidden field so the REST
                // controller can branch on it. Edits leave the
                // existing `plan_state` alone (the URL param is only
                // honoured on create).
                $plan_state_url = isset( $_GET['plan_state'] ) ? sanitize_key( (string) $_GET['plan_state'] ) : '';
                if ( $session === null && in_array( $plan_state_url, [ 'draft', 'scheduled' ], true ) ) :
                ?>
                <input type="hidden" name="plan_state" value="<?php echo esc_attr( $plan_state_url ); ?>" />
                <?php endif; ?>
                <?php echo TeamPickerComponent::render( [
                    'name'     => 'team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'required' => true,
                    'teams'    => $teams,
                    'selected' => $selected_team,
                ] ); ?>
                <?php
                // #1126 — optional start + end time. Both empty by
                // default; renderer omits the time line when both are
                // blank (no placeholder).
                $current_start = (string) ( $session->start_time ?? '' );
                $current_end   = (string) ( $session->end_time   ?? '' );
                ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-activity-start-time"><?php esc_html_e( 'Start time (optional)', 'talenttrack' ); ?></label>
                    <input type="time" id="tt-activity-start-time" class="tt-input" name="start_time" value="<?php echo esc_attr( substr( $current_start, 0, 5 ) ); ?>" />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-activity-end-time"><?php esc_html_e( 'End time (optional)', 'talenttrack' ); ?></label>
                    <input type="time" id="tt-activity-end-time" class="tt-input" name="end_time" value="<?php echo esc_attr( substr( $current_end, 0, 5 ) ); ?>" />
                </div>
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
                if ( ! empty( $all_principles ) ) :
                    // #1122 — replace the flat multiselect with the
                    // bucket → principles two-level picker. Same shape
                    // as PrinciplesStep (wizard); both surfaces keep
                    // visual parity so a coach moving between
                    // wizard-create + form-edit sees the same picker.
                    $function_labels = \TT\Modules\Methodology\MethodologyEnums::teamFunctions();
                    $task_labels     = \TT\Modules\Methodology\MethodologyEnums::teamTasks();
                    $grouped = [];
                    $unbucketed = [];
                    foreach ( $all_principles as $pr ) {
                        $fk = (string) ( $pr->team_function_key ?? '' );
                        $tk = (string) ( $pr->team_task_key     ?? '' );
                        if ( $fk === '' && $tk === '' ) { $unbucketed[] = $pr; continue; }
                        $grouped[ $fk ][ $tk ][] = $pr;
                    }
                    $task_order = array_keys( $task_labels );
                    ?>
                <div class="tt-field">
                    <span class="tt-field-label"><?php esc_html_e( 'Connected principles', 'talenttrack' ); ?></span>
                    <p class="tt-field-hint" style="margin-top:0;"><?php esc_html_e( 'Group by team function + team task; tick whichever apply. Leave all unticked to save without links.', 'talenttrack' ); ?></p>
                    <div class="tt-act-principle-picker" style="display:flex; flex-direction:column; gap:10px; margin-top:6px;">
                        <?php foreach ( $function_labels as $fk => $fn_label ) :
                            if ( empty( $grouped[ $fk ] ) ) continue;
                            uksort( $grouped[ $fk ], static function ( $a, $b ) use ( $task_order ): int {
                                $ia = array_search( $a, $task_order, true );
                                $ib = array_search( $b, $task_order, true );
                                if ( $ia === false ) $ia = PHP_INT_MAX;
                                if ( $ib === false ) $ib = PHP_INT_MAX;
                                return $ia <=> $ib;
                            } );
                            ?>
                            <details open style="border:1px solid var(--tt-line, #d6dadd); border-radius:8px; padding:10px 12px; background:#fff;">
                                <summary style="cursor:pointer; font-weight:700; font-size:14px; color:var(--tt-ink, #1a1d21);"><?php echo esc_html( $fn_label ); ?></summary>
                                <?php foreach ( $grouped[ $fk ] as $tk => $rows ) :
                                    $task_label = $task_labels[ $tk ] ?? ucfirst( str_replace( '_', ' ', (string) $tk ) );
                                    ?>
                                    <div style="margin:10px 0 4px;">
                                        <?php if ( (string) $tk !== '' ) : ?>
                                            <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:var(--tt-muted, #5b6e75); margin-bottom:6px;"><?php echo esc_html( (string) $task_label ); ?></div>
                                        <?php endif; ?>
                                        <ul style="list-style:none; margin:0; padding:0; display:grid; grid-template-columns:1fr; gap:6px;">
                                            <?php foreach ( $rows as $pr ) :
                                                $title  = \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                                                $label  = trim( (string) $pr->code . ( $title !== '' ? ' · ' . $title : '' ) );
                                                $pid    = (int) $pr->id;
                                                $is_sel = in_array( $pid, $linked_ids, true );
                                                ?>
                                                <li>
                                                    <label style="display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; background:<?php echo $is_sel ? '#eef4fb' : '#f9fafb'; ?>; cursor:pointer; min-height:44px;">
                                                        <input type="checkbox" name="activity_principle_ids[]" value="<?php echo $pid; ?>"<?php checked( $is_sel ); ?> style="width:20px; height:20px;">
                                                        <span style="flex:1; font-size:14px;"><?php echo esc_html( $label ); ?></span>
                                                    </label>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endforeach; ?>
                        <?php if ( $unbucketed !== [] ) : ?>
                            <details style="border:1px solid var(--tt-line, #d6dadd); border-radius:8px; padding:10px 12px; background:#fff;">
                                <summary style="cursor:pointer; font-weight:700; font-size:14px; color:var(--tt-muted, #5b6e75);"><?php esc_html_e( 'Other principles', 'talenttrack' ); ?></summary>
                                <ul style="list-style:none; margin:10px 0 4px; padding:0; display:grid; grid-template-columns:1fr; gap:6px;">
                                    <?php foreach ( $unbucketed as $pr ) :
                                        $title  = \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                                        $label  = trim( (string) $pr->code . ( $title !== '' ? ' · ' . $title : '' ) );
                                        $pid    = (int) $pr->id;
                                        $is_sel = in_array( $pid, $linked_ids, true );
                                        ?>
                                        <li>
                                            <label style="display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:6px; background:<?php echo $is_sel ? '#eef4fb' : '#f9fafb'; ?>; cursor:pointer; min-height:44px;">
                                                <input type="checkbox" name="activity_principle_ids[]" value="<?php echo $pid; ?>"<?php checked( $is_sel ); ?> style="width:20px; height:20px;">
                                                <span style="flex:1; font-size:14px;"><?php echo esc_html( $label ); ?></span>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </div>
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
            $attendance_visible = ( $current_status === ActivityStatusKey::COMPLETED );
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

            <?php
            // v3.110.58 — CLAUDE.md § 6.
            $dash_url   = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
            $list_url   = add_query_arg( [ 'tt_view' => 'activities' ], $dash_url );
            $detail_url = $is_edit ? add_query_arg( [ 'tt_view' => 'activities', 'id' => (int) $session->id ], $dash_url ) : $list_url;
            $back       = \TT\Shared\Frontend\Components\BackLink::resolve();
            $cancel_url = $back !== null ? $back['url'] : ( $is_edit ? $detail_url : $list_url );
            echo FormSaveButton::render( [
                'label'      => $is_edit ? __( 'Update activity', 'talenttrack' ) : __( 'Save activity', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
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
            <table class="tt-table tt-attendance-table tt-guest-table" data-tt-guest-table>
                <?php // #943 — column widths: 35ch Player / 10ch Status / rest Notes (mobile stacks via data-label) ?>
                <colgroup>
                    <col style="width: 35ch;">
                    <col style="width: 10ch;">
                    <col style="width: auto;">
                </colgroup>
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
                            <?php // #943 — Evaluate shortcut removed for linked guests; they surface in
                                //  the existing "Continue rating" wizard flow via RateActorsStep alongside
                                //  roster players (per the shaping delta on #943).
                                //  "Add as player" promote shortcut removed for anon guests; promotion
                                //  is a broader process (trial → assess → assign team → permissions)
                                //  that doesn't belong on a single activity row. ?>
                            <input type="text" class="tt-input tt-guest-notes-input"
                                   data-tt-guest-notes-id="<?php echo (int) $g->id; ?>"
                                   data-initial="<?php echo esc_attr( (string) ( $g->guest_notes ?? '' ) ); ?>"
                                   value="<?php echo esc_attr( (string) ( $g->guest_notes ?? '' ) ); ?>"
                                   placeholder="<?php esc_attr_e( 'Notes…', 'talenttrack' ); ?>" />
                            <div class="tt-guest-row-actions" style="margin-top:6px; font-size:12px;">
                                <button type="button" class="tt-btn-link" data-tt-guest-remove="<?php echo (int) $g->id; ?>" style="color:#b32d2e;">
                                    <?php esc_html_e( 'Remove', 'talenttrack' ); ?>
                                </button>
                            </div>
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
                'remove'          => __( 'Remove',         'talenttrack' ),
                // #943 — confirmRemove now drives the app dialog modal's
                // body; companion title + confirm strings keep the
                // standard ok/cancel triple.
                'confirmRemove'        => __( 'Remove this guest?',            'talenttrack' ),
                'confirmRemoveTitle'   => __( 'Remove guest',                  'talenttrack' ),
                'confirmRemoveConfirm' => __( 'Remove',                        'talenttrack' ),
                'confirmRemoveCancel'  => __( 'Cancel',                        'talenttrack' ),
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
