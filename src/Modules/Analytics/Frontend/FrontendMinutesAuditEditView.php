<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\MinutesAuditQuery;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMinutesAuditEditView (#2367) — per-match minutes editor.
 *
 * URL: `?tt_view=minutes-audit&match_id=N` (the overview's "Bewerk" pill
 * deep-links here). The editable half of the Minutes-audit tool: an
 * admin / head coach corrects the recorded minutes per player for one
 * game, writing the SAME authoritative tables the reports + Match-
 * execution read, so a match opened in Match-execution after an audit
 * edit reflects the changed numbers (the whole point, #2367).
 *
 * Write-through routing (the arbiter, #2301) is decided per activity and
 * driven client-side from `owned_by_execution` in the read payload:
 *   - a match a coach prepped/ran (has an execution row) → the minutes
 *     override endpoint PATCH /match-execution/{activity}/minutes; the
 *     override lives in its own column and survives recompute.
 *   - a paper / no-execution match (#2159) → the manual attendance path
 *     PATCH /attendance/{attendance_id} {minutes_played}. The 409
 *     `minutes_owned_by_execution` gate can never fire here because the
 *     client only takes this branch when no execution owns the activity.
 *
 * Scope: minutes are stored per player per activity (a single effective
 * value), so this editor commits ONE minutes figure per player. Per-half
 * split, starting line-up per half, and substitution editing (the other
 * two sections of the design mockup) are a scoped follow-up — the
 * attendance table has no per-half minutes column, and lineup/subs
 * editing needs the full match-execution sub-log surface. Shipping the
 * total-minutes editor now delivers the primary auditability value
 * without a half-working write path.
 *
 * Cap-gated on `tt_edit_activities` — the SAME capability the two write
 * endpoints enforce (§7 single source of truth). A viewer-only user
 * never reaches this surface: the overview's "Bewerk" pill is hidden for
 * them and the view early-returns. Two-affordance nav (§5): breadcrumb
 * chain + tt_back pill. Save + Cancel (§6).
 */
final class FrontendMinutesAuditEditView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-minutes-audit',
            TT_PLUGIN_URL . 'assets/css/frontend-minutes-audit.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-minutes-audit-edit',
            TT_PLUGIN_URL . 'assets/js/frontend-minutes-audit-edit.js',
            [ 'tt-public' ],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-minutes-audit-edit',
            'TTMinutesAuditEdit',
            [
                'restBase' => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
                'nonce'    => wp_create_nonce( 'wp_rest' ),
                'i18n'     => [
                    'saving'       => __( 'Saving…', 'talenttrack' ),
                    'saved'        => __( 'Saved', 'talenttrack' ),
                    'saveError'    => __( 'Could not save. Check the value and try again.', 'talenttrack' ),
                    'ownedByExec'  => __( 'Minutes for this match are managed on the match-execution screen.', 'talenttrack' ),
                    'noRosterRow'  => __( 'This player has no roster row to record minutes against.', 'talenttrack' ),
                ],
            ]
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $reports_crumb = FrontendBreadcrumbs::viewCrumb( 'reports', __( 'Reports', 'talenttrack' ) );
        $audit_crumb   = [
            'label' => __( 'Minutes audit', 'talenttrack' ),
            'url'   => BackLink::appendTo( add_query_arg( [ 'tt_view' => 'minutes-audit' ], RecordLink::dashboardUrl() ) ), /* tt-xview-ok - same-tool back-link to the minutes-audit overview; editor is cap-gated */
        ];

        // §7 — the editor is gated on the SAME cap the write endpoints
        // enforce. Deny cleanly (breadcrumbs on every path, §5).
        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Record minutes', 'talenttrack' ), [ $reports_crumb, $audit_crumb ] );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to edit recorded minutes.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'report_minutes_audit' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Record minutes', 'talenttrack' ), [ $reports_crumb, $audit_crumb ] );
            echo '<p class="tt-notice">' . esc_html__( 'This report has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity_id = isset( $_GET['match_id'] ) ? absint( $_GET['match_id'] ) : 0;
        $data = $activity_id > 0 ? ( new MinutesAuditQuery() )->editorRows( $activity_id ) : null;

        if ( $data === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Record minutes', 'talenttrack' ), [ $reports_crumb, $audit_crumb ] );
            echo '<p class="tt-notice">' . esc_html__( 'That match was not found, or it is not a game activity.', 'talenttrack' ) . '</p>';
            return;
        }

        // Team scope: a coach may only edit matches on a team they coach.
        $is_scope_admin = $is_admin
            || \TT\Modules\Authorization\AllTeamsScope::canSeeAllTeamsActivities( $user_id );
        if ( ! $is_scope_admin ) {
            $allowed = array_values( array_map(
                'intval',
                array_column( \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( $user_id ), 'id' )
            ) );
            if ( ! in_array( (int) $data['activity']['team_id'], $allowed, true ) ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Record minutes', 'talenttrack' ), [ $reports_crumb, $audit_crumb ] );
                echo '<p class="tt-notice">' . esc_html__( 'You do not coach this match’s team.', 'talenttrack' ) . '</p>';
                return;
            }
        }

        $title = (string) $data['activity']['title'];
        if ( $title === '' ) $title = __( 'Match', 'talenttrack' );

        FrontendBreadcrumbs::fromDashboard( $title, [ $reports_crumb, $audit_crumb ] );
        self::renderHeader( __( 'Record minutes', 'talenttrack' ) );

        self::renderMatchHead( $data );

        echo '<p class="tt-maud-lead">' . esc_html__(
            'Correct the recorded minutes per player for this match. Saved minutes write the authoritative recorded value — the same source the reports and match-execution read.',
            'talenttrack'
        ) . '</p>';

        self::renderMinutesForm( $data, $activity_id );
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function renderMatchHead( array $data ): void {
        $activity = $data['activity'];
        $date = $activity['session_date'] !== ''
            ? date_i18n( get_option( 'date_format' ), strtotime( (string) $activity['session_date'] ) )
            : '';
        $type = (string) $activity['type_key'];

        echo '<div class="tt-maud-matchhead">';
        echo '<b>' . esc_html( (string) $activity['title'] !== '' ? (string) $activity['title'] : __( 'Match', 'talenttrack' ) ) . '</b>';
        if ( $date !== '' ) echo '<span>' . esc_html( $date ) . '</span>';
        if ( $type !== '' ) echo '<span>&middot;</span><span>' . esc_html( $type ) . '</span>';
        if ( ! empty( $data['owned_by_execution'] ) ) {
            echo '<span class="tt-maud-chip tt-maud-chip--ok"><span class="tt-maud-chip__dot"></span>'
                . esc_html__( 'Match-execution match', 'talenttrack' ) . '</span>';
        }
        echo '</div>';
    }

    /**
     * The editable minutes grid + Save/Cancel. Each row PATCHes on its own
     * (data-attrs carry the routing); Save re-submits every dirty row via
     * the enqueued JS. Business logic (routing / write) lives in the REST
     * layer + JS, never here — this method only composes the markup (§4).
     *
     * @param array<string,mixed> $data
     */
    private static function renderMinutesForm( array $data, int $activity_id ): void {
        /** @var list<array<string,mixed>> $players */
        $players = $data['players'];

        // Cancel → back to the overview (or tt_back when captured, §6).
        $back = BackLink::resolve();
        $cancel_url = $back['url']
            ?? add_query_arg( [ 'tt_view' => 'minutes-audit' ], RecordLink::dashboardUrl() ); /* tt-xview-ok - same-tool back-link to the minutes-audit overview; editor is cap-gated */

        $owned = ! empty( $data['owned_by_execution'] );

        echo '<form class="tt-maud-edit" '
            . 'data-activity-id="' . esc_attr( (string) $activity_id ) . '" '
            . 'data-owned="' . ( $owned ? '1' : '0' ) . '">';

        echo '<div class="tt-maud-card">';
        echo '<div class="tt-maud-card__head">';
        echo '<h2 class="tt-maud-card__title">' . esc_html__( 'Minutes played per player', 'talenttrack' ) . '</h2>';
        echo '<span class="tt-maud-card__hint">' . esc_html__( 'editable — writes the recorded value', 'talenttrack' ) . '</span>';
        echo '</div>';

        if ( $players === [] ) {
            echo '<p class="tt-notice tt-maud-empty">' . esc_html__(
                'No squad players are recorded for this match yet. Add attendance on the activity first, then record minutes here.',
                'talenttrack'
            ) . '</p>';
            echo '</div></form>';
            return;
        }

        echo '<div class="tt-maud-scroll">';
        echo '<table class="tt-maud-mins"><thead><tr>';
        echo '<th class="tt-maud-mins__name" scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Minutes', 'talenttrack' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Derived', 'talenttrack' ) . '</th>';
        echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'State', 'talenttrack' ) . '</span></th>';
        echo '</tr></thead><tbody>';

        foreach ( $players as $pl ) {
            $pid     = (int) $pl['player_id'];
            $name    = self::playerName( $pl );
            $eff     = $pl['minutes'] !== null ? (int) $pl['minutes'] : null;
            $derived = $pl['minutes_derived'] !== null ? (int) $pl['minutes_derived'] : null;
            $has_ovr = $pl['minutes_override'] !== null;
            $att_id  = (int) $pl['attendance_id'];

            echo '<tr class="tt-maud-mins__row' . ( $has_ovr ? ' is-override' : '' ) . '" '
                . 'data-player-id="' . esc_attr( (string) $pid ) . '" '
                . 'data-attendance-id="' . esc_attr( (string) $att_id ) . '">';

            echo '<td class="tt-maud-mins__name">' . esc_html( $name );
            if ( $has_ovr ) {
                echo ' <span class="tt-maud-flag">' . esc_html__( 'override', 'talenttrack' ) . '</span>';
            }
            echo '</td>';

            $input_id = 'tt-maud-min-' . $pid;
            echo '<td>';
            echo '<label class="screen-reader-text" for="' . esc_attr( $input_id ) . '">'
                . esc_html( sprintf(
                    /* translators: %s: player name */
                    __( 'Minutes for %s', 'talenttrack' ),
                    $name
                ) ) . '</label>';
            echo '<input class="tt-min-in" type="number" inputmode="numeric" min="0" max="200" step="1" '
                . 'id="' . esc_attr( $input_id ) . '" '
                . 'name="minutes[' . esc_attr( (string) $pid ) . ']" '
                . 'value="' . ( $eff !== null ? esc_attr( (string) $eff ) : '' ) . '" '
                . 'data-original="' . ( $eff !== null ? esc_attr( (string) $eff ) : '' ) . '">';
            echo '</td>';

            echo '<td class="tt-maud-mins__derived">' . ( $derived !== null ? esc_html( (string) $derived ) : '&mdash;' ) . '</td>';
            echo '<td class="tt-maud-mins__state" aria-live="polite"></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>'; // .tt-maud-card

        // Save + Cancel (§6). Save drives the per-row writes via the JS,
        // which flips the shared .tt-save-btn state machine.
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes.
            'label'        => __( 'Save minutes', 'talenttrack' ),
            'label_saving' => __( 'Saving…', 'talenttrack' ),
            'label_saved'  => __( 'Saved', 'talenttrack' ),
            'cancel_url'   => $cancel_url,
            'cancel_label' => __( 'Cancel', 'talenttrack' ),
        ] );

        echo '</form>';
    }

    /**
     * @param array<string,mixed> $pl
     */
    private static function playerName( array $pl ): string {
        $first  = (string) ( $pl['first_name'] ?? '' );
        $last   = (string) ( $pl['last_name'] ?? '' );
        $jersey = $pl['jersey_number'] ?? null;
        $name = trim( $first . ' ' . $last );
        if ( $name === '' ) $name = '#' . (int) ( $pl['player_id'] ?? 0 );
        return $jersey !== null ? ( (int) $jersey . ' · ' . $name ) : $name;
    }
}
