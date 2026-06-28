<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Holidays\Repositories\HolidaysRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\ArchiveRowActions;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendHolidaysView (#1480) — academy-wide holiday management at
 * `?tt_view=holidays`. Lists holidays + the wizard-first create CTA +
 * a gated delete + an Edit row action and click-to-open rows (#1602).
 * Read-gated on `tt_view_holidays`; create / edit / delete on
 * `tt_manage_holidays` (the row actions + CTA carry their own cap).
 */
final class FrontendHolidaysView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_holidays' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view holidays.', 'talenttrack' ) . '</p>';
            return;
        }

        // #1602 — edit form replaces the list when ?edit={id} is present.
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        if ( $edit_id > 0 ) {
            self::renderEditForm( $edit_id );
            return;
        }

        // #1997 — read-only detail view replaces the list when ?id={id}
        // is present. Available to every `tt_view_holidays` viewer, so
        // clicking any holiday row opens a scheduling-centric summary.
        $detail_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $detail_id > 0 ) {
            self::renderDetail( $detail_id );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Holidays', 'talenttrack' ) );
        self::renderHeader( __( 'Academy holidays', 'talenttrack' ) );

        echo '<p class="tt-muted" style="max-width:640px; margin:0 0 12px;">'
            . esc_html__( 'Academy-wide holiday periods. They show as a banner across the affected days on every team planner.', 'talenttrack' )
            . '</p>';

        $wizard_url = add_query_arg(
            [ 'tt_view' => 'wizard', 'slug' => 'holiday' ],
            RecordLink::dashboardUrl()
        );

        echo FrontendListTable::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
            'rest_path' => 'holidays',
            // #1602 — click-to-open rows. The REST `detail_url` field
            // carries the per-row edit URL (only populated for users
            // with the manage cap), so read-only viewers get inert rows.
            'row_url_key' => 'detail_url',
            'columns'   => [
                'name'       => [ 'label' => __( 'Name', 'talenttrack' ),  'sortable' => true ],
                'start_date' => [ 'label' => __( 'Start', 'talenttrack' ), 'sortable' => true, 'render' => 'date' ],
                'end_date'   => [ 'label' => __( 'End', 'talenttrack' ),   'sortable' => true, 'render' => 'date' ],
                'note'       => [ 'label' => __( 'Note', 'talenttrack' ) ],
            ],
            // #1784 — Active / Archived tab so the Restore + Delete-
            // permanently actions (below) have somewhere to surface.
            'filters' => [
                'status' => [
                    'type'    => 'select',
                    'render'  => 'status',
                    'label'   => __( 'Status', 'talenttrack' ),
                    'options' => [
                        'active'   => __( 'Active', 'talenttrack' ),
                        'archived' => __( 'Archived', 'talenttrack' ),
                    ],
                ],
            ],
            'row_actions' => array_merge( [
                'edit' => [
                    // Raw href template — the list-table JS substitutes
                    // `{id}` literally (no URL-encoding of the placeholder),
                    // so build the base with add_query_arg then append the
                    // unencoded placeholder.
                    'label' => __( 'Edit', 'talenttrack' ),
                    'href'  => add_query_arg( [ 'tt_view' => 'holidays' ], RecordLink::dashboardUrl() ) . '&edit={id}',
                    'cap'   => 'tt_manage_holidays',
                ],
                'delete' => [
                    'label'       => __( 'Archive', 'talenttrack' ),
                    'rest_method' => 'DELETE',
                    'rest_path'   => 'holidays/{id}',
                    'confirm'     => __( 'Archive this holiday?', 'talenttrack' ),
                    'cap'         => 'tt_manage_holidays',
                    'variant'     => 'danger',
                ],
                // #1784 — Restore + referential-integrity permanent delete,
                // shown only on archived rows.
            ], ArchiveRowActions::build( 'holidays', 'tt_manage_holidays', 'holiday' ) ),
            'search'       => [ 'placeholder' => __( 'Search holidays…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'start_date', 'order' => 'asc' ],
            'empty_state'  => __( 'No holidays match your search.', 'talenttrack' ),
            'empty_state_card' => [
                'icon'      => 'activities',
                'headline'  => __( 'No holidays yet', 'talenttrack' ),
                'explainer' => __( 'Add the academy holiday periods so coaches see them on the planner and avoid scheduling on closed days.', 'talenttrack' ),
                'cta_label' => __( 'Add first holiday', 'talenttrack' ),
                'cta_url'   => $wizard_url,
                'cta_cap'   => 'tt_manage_holidays',
            ],
        ] );
    }

    /**
     * #1602 — flat edit form for an existing holiday. Composes the
     * record via the repository and hands the save to the existing
     * `PUT /holidays/{id}` REST endpoint (capability-gated server-side).
     * Mirrors the new-holiday wizard's fields: name + date range + note.
     */
    private static function renderEditForm( int $id ): void {
        $list_url = add_query_arg( [ 'tt_view' => 'holidays' ], RecordLink::dashboardUrl() );

        if ( ! current_user_can( 'tt_manage_holidays' ) ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not authorized', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'holidays', __( 'Holidays', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage holidays.', 'talenttrack' ) . '</p>';
            return;
        }

        $holiday = ( new HolidaysRepository() )->findById( $id );
        if ( $holiday === null ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Holiday not found', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'holidays', __( 'Holidays', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'That holiday no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Edit holiday', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'holidays', __( 'Holidays', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Edit holiday', 'talenttrack' ) );

        self::enqueueHolidaysJs( $list_url );

        // CLAUDE.md § 6 — Cancel → the holidays list, unless a captured
        // tt_back says otherwise.
        $back       = BackLink::resolve();
        $cancel_url = $back !== null ? $back['url'] : $list_url;

        $name  = (string) $holiday->name;
        $start = (string) $holiday->start_date;
        $end   = (string) $holiday->end_date;
        $note  = $holiday->note !== null ? (string) $holiday->note : '';
        ?>
        <form class="tt-form" data-tt-holiday-form="1" data-tt-holiday-id="<?php echo esc_attr( (string) $id ); ?>">
            <div class="tt-field">
                <label class="tt-field-label" for="tt-holiday-name"><?php esc_html_e( 'Name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-holiday-name" class="tt-input" name="name" value="<?php echo esc_attr( $name ); ?>" required autocomplete="off" maxlength="100" placeholder="<?php echo esc_attr__( 'e.g. Christmas break', 'talenttrack' ); ?>" />
            </div>
            <div class="tt-grid tt-grid-2" style="margin-top:var(--tt-sp-3);">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-holiday-start"><?php esc_html_e( 'Start date', 'talenttrack' ); ?></label>
                    <input type="date" id="tt-holiday-start" class="tt-input" name="start_date" value="<?php echo esc_attr( $start ); ?>" required />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-holiday-end"><?php esc_html_e( 'End date', 'talenttrack' ); ?></label>
                    <input type="date" id="tt-holiday-end" class="tt-input" name="end_date" value="<?php echo esc_attr( $end ); ?>" required />
                </div>
            </div>
            <div class="tt-field" style="margin-top:var(--tt-sp-3);">
                <label class="tt-field-label" for="tt-holiday-note"><?php esc_html_e( 'Note (optional)', 'talenttrack' ); ?></label>
                <textarea id="tt-holiday-note" class="tt-input" name="note" rows="2"><?php echo esc_textarea( $note ); ?></textarea>
            </div>
            <?php
            echo FormSaveButton::render( [
                'label'      => __( 'Update holiday', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
            ?>
            <div class="tt-form-msg" style="margin-top:10px;"></div>
        </form>
        <?php
    }

    /**
     * #1997 — read-only, scheduling-centric detail view for a single
     * holiday at `?tt_view=holidays&id=N`. Read-gated on
     * `tt_view_holidays` (every viewer, not just managers). Composes the
     * record via the repository; the inclusive day-count comes from
     * `HolidaysRepository::dayCount()` (business logic stays out of the
     * view — SaaS §4). Read-only, so CLAUDE.md §6 Save/Cancel does not
     * apply; the only nav affordances are the breadcrumb chain + the
     * auto-rendered `tt_back` pill (§5). Managers additionally get an
     * Edit button into the existing flat edit form.
     */
    private static function renderDetail( int $id ): void {
        $holidays_crumb = [ FrontendBreadcrumbs::viewCrumb( 'holidays', __( 'Holidays', 'talenttrack' ) ) ];

        $holiday = ( new HolidaysRepository() )->findById( $id );
        if ( $holiday === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Holiday not found', 'talenttrack' ), $holidays_crumb );
            echo '<p class="tt-notice">' . esc_html__( 'That holiday no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-holiday-detail',
            TT_PLUGIN_URL . 'assets/css/frontend-holiday-detail.css',
            [],
            TT_VERSION
        );

        $name  = (string) $holiday->name;
        $start = (string) $holiday->start_date;
        $end   = (string) $holiday->end_date;
        $note  = $holiday->note !== null ? (string) $holiday->note : '';
        $color = $holiday->color !== null ? (string) $holiday->color : '';

        FrontendBreadcrumbs::fromDashboard( $name, $holidays_crumb );

        // Edit button (manage cap only) → existing flat edit form.
        $actions_html = '';
        if ( current_user_can( 'tt_manage_holidays' ) ) {
            $edit_url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'holidays', 'edit' => $id ],
                RecordLink::dashboardUrl()
            ) );
            $actions_html = self::pageActionsHtml( [
                [
                    'label'   => __( 'Edit', 'talenttrack' ),
                    'href'    => $edit_url,
                    'primary' => true,
                    'cap'     => 'tt_manage_holidays',
                ],
            ] );
        }

        self::renderHeader( $name, $actions_html );

        $date_fmt = (string) get_option( 'date_format', 'j M Y' );
        $start_ts = strtotime( $start );
        $end_ts   = strtotime( $end );
        $period   = ( $start_ts !== false && $end_ts !== false )
            ? sprintf(
                /* translators: 1: start date, 2: end date */
                _x( '%1$s – %2$s', 'holiday period range', 'talenttrack' ),
                date_i18n( $date_fmt, $start_ts ),
                date_i18n( $date_fmt, $end_ts )
            )
            : '—';

        $day_count = HolidaysRepository::dayCount( $start, $end );
        $duration  = $day_count > 0
            ? sprintf(
                /* translators: %d: number of days */
                _n( '%d day', '%d days', $day_count, 'talenttrack' ),
                $day_count
            )
            : '—';

        echo '<div class="tt-holiday-detail">';

        echo '<dl class="tt-holiday-detail__facts">';

        echo '<div class="tt-holiday-detail__row">';
        echo '<dt>' . esc_html__( 'Period', 'talenttrack' ) . '</dt>';
        echo '<dd>' . esc_html( $period ) . '</dd>';
        echo '</div>';

        echo '<div class="tt-holiday-detail__row">';
        echo '<dt>' . esc_html__( 'Duration', 'talenttrack' ) . '</dt>';
        echo '<dd>' . esc_html( $duration ) . '</dd>';
        echo '</div>';

        echo '<div class="tt-holiday-detail__row">';
        echo '<dt>' . esc_html__( 'Note', 'talenttrack' ) . '</dt>';
        echo '<dd>' . ( $note !== '' ? esc_html( $note ) : '<span class="tt-holiday-detail__empty">—</span>' ) . '</dd>';
        echo '</div>';

        if ( $color !== '' ) {
            echo '<div class="tt-holiday-detail__row">';
            echo '<dt>' . esc_html__( 'Colour', 'talenttrack' ) . '</dt>';
            echo '<dd class="tt-holiday-detail__colour">';
            // The swatch fill is a per-record dynamic value that can't
            // live in a static sheet — grandfathered per CLAUDE.md §2.
            echo '<span class="tt-holiday-detail__swatch" style="background:' . esc_attr( $color ) . ';" aria-hidden="true"></span>'; /* tt-inline-ok */
            echo '<span class="tt-holiday-detail__swatch-label">' . esc_html( $color ) . '</span>';
            echo '</dd>';
            echo '</div>';
        }

        echo '</dl>';

        echo '<p class="tt-holiday-detail__banner-note">'
            . esc_html__( 'This holiday shows as a banner across these days on every team planner, so coaches plan around the closed days.', 'talenttrack' )
            . '</p>';

        echo '</div>';
    }

    private static function enqueueHolidaysJs( string $list_url ): void {
        wp_enqueue_script(
            'tt-frontend-holidays',
            TT_PLUGIN_URL . 'assets/js/frontend-holidays.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-holidays', 'TT_HOLIDAYS', [
            'restUrl' => esc_url_raw( rest_url( 'talenttrack/v1/holidays' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'listUrl' => $list_url,
            'i18n'    => [
                'saving'       => __( 'Saving…', 'talenttrack' ),
                'genericError' => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'badRange'     => __( 'End date must be after the start date.', 'talenttrack' ),
            ],
        ] );
    }
}
