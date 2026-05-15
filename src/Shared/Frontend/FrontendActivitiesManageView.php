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
                // v3.110.97 — Continue rating. Only on completed
                // activities (attendance + rating only make sense
                // after the session happened). Cap-gated on the
                // mark-attendance wizard's `tt_edit_evaluations`.
                $is_completed = ( (string) ( $session->activity_status_key ?? '' ) === 'completed' );
                if ( $is_completed && current_user_can( 'tt_edit_evaluations' ) ) {
                    $rate_url = add_query_arg(
                        [
                            'tt_view'     => 'wizard',
                            'slug'        => 'mark-attendance',
                            'activity_id' => (int) $session->id,
                            'restart'     => 1,
                        ],
                        \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
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

        // v3.110.x — surface the methodology principles connected to
        // this activity in the read-only detail page. Was previously
        // only visible on the edit form, so coaches landing on the
        // detail view couldn't see what the activity was anchored to
        // without clicking Edit. Defensive: skipped when the
        // Methodology module isn't loaded.
        if ( class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrincipleLinksRepository' )
             && class_exists( '\\TT\\Modules\\Methodology\\Repositories\\PrinciplesRepository' )
        ) {
            $linked_ids = ( new \TT\Modules\Methodology\Repositories\PrincipleLinksRepository() )
                ->principlesForActivity( (int) $session->id );
            if ( ! empty( $linked_ids ) ) {
                $repo  = new \TT\Modules\Methodology\Repositories\PrinciplesRepository();
                $names = [];
                foreach ( $linked_ids as $pid ) {
                    $pr = $repo->find( (int) $pid );
                    if ( ! $pr ) continue;
                    $title = '';
                    if ( class_exists( '\\TT\\Modules\\Methodology\\Helpers\\MultilingualField' ) ) {
                        $title = (string) \TT\Modules\Methodology\Helpers\MultilingualField::string( $pr->title_json );
                    }
                    $names[] = trim( (string) $pr->code . ( $title !== '' ? ' · ' . $title : '' ) );
                }
                if ( ! empty( $names ) ) {
                    echo '<dt>' . esc_html__( 'Connected principles', 'talenttrack' ) . '</dt>';
                    echo '<dd>' . esc_html( implode( ', ', $names ) ) . '</dd>';
                }
            }
        }

        echo '</dl>';

        // v3.110.95 — Attendance summary block. Surfaces the same
        // figures the activity-list "Att. %" column shows (now that
        // both queries share the "attendance row for player on
        // current active roster" predicate). The percentage is a
        // clickable link to the edit form, which IS the per-player
        // attendance list with marks — exactly what the user asked
        // for. Visible only on completed activities, since planned
        // / cancelled rows have no meaningful attendance.
        $status_key_for_att = (string) ( $session->activity_status_key ?? 'planned' );
        if ( $status_key_for_att === 'completed' ) {
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
     * List view — FrontendListTable.
     *
     * v3.110.53 — `+ New activity` moved to the page-header actions
     * slot rendered by render(). Row Edit / Delete dropped — the
     * clickable activity title is the only row affordance; Edit /
     * Archive live on the activity detail page.
     */
    private static function renderList( int $user_id, bool $is_admin ): void {
        $base_url = remove_query_arg( [ 'action', 'id' ] );

        $row_actions = [];

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
            if ( $draft_key !== '' ) : ?> data-draft-key="<?php echo esc_attr( $draft_key ); ?>"<?php endif;
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
                    <label class="tt-field-label" for="tt-activity-principles"><?php esc_html_e( 'Connected principles', 'talenttrack' ); ?></label>
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
