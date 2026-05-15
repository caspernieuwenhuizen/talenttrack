<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\DateInputComponent;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendScoutingPlanView (v3.110.119) — scouting visits list +
 * new / edit form.
 *
 * Modes selected via query string:
 *
 *   ?tt_view=scouting-visits                 — list view
 *   ?tt_view=scouting-visits&action=new      — create form
 *   ?tt_view=scouting-visits&action=edit&id=N — edit form
 *
 * A *scouting visit* is an off-site event a scout plans to attend
 * to observe potential prospects. Distinct from `tt_test_trainings`
 * (one-off training a prospect attends at the club) — a scouting
 * visit is *outbound* from the club to where prospects already are.
 *
 * Detail view (single visit + linked prospects + the "add scouting
 * find from this visit" CTA) lives on `FrontendScoutingVisitDetailView`
 * at `?tt_view=scouting-visit&id=N` (singular slug).
 *
 * Cap: `tt_view_prospects` to read, `tt_edit_prospects` to mutate.
 * Scope: a scout sees only their own visits; HoD / admin see all.
 */
class FrontendScoutingPlanView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_prospects' ) && ! $is_admin ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'Scouting visits', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to scouting visits.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        $parent_crumb = [ FrontendBreadcrumbs::viewCrumb( 'scouting-visits', __( 'Scouting visits', 'talenttrack' ) ) ];

        if ( $action === 'new' ) {
            FrontendBreadcrumbs::fromDashboard( __( 'New scouting visit', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'New scouting visit', 'talenttrack' ) );
            self::renderForm( $user_id, null );
            return;
        }

        if ( $action === 'edit' && $id > 0 ) {
            $repo  = new ScoutingVisitsRepository();
            $visit = $repo->find( $id );
            if ( ! $visit || ! self::canEdit( $visit, $user_id, $is_admin ) ) {
                FrontendBreadcrumbs::fromDashboard( __( 'Scouting visit not found', 'talenttrack' ), $parent_crumb );
                self::renderHeader( __( 'Scouting visit not found', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'This scouting visit no longer exists or you do not have permission to edit it.', 'talenttrack' ) . '</p>';
                return;
            }
            FrontendBreadcrumbs::fromDashboard( __( 'Edit scouting visit', 'talenttrack' ), $parent_crumb );
            self::renderHeader( __( 'Edit scouting visit', 'talenttrack' ) );
            self::renderForm( $user_id, $visit );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Scouting visits', 'talenttrack' ) );
        $page_actions = [];
        if ( current_user_can( 'tt_edit_prospects' ) ) {
            $base_url = remove_query_arg( [ 'action', 'id' ] );
            $page_actions[] = [
                'label'   => __( 'New scouting visit', 'talenttrack' ),
                'href'    => add_query_arg( [ 'tt_view' => 'scouting-visits', 'action' => 'new' ], $base_url ),
                'primary' => true,
                'icon'    => '+',
            ];
        }
        self::renderHeader( __( 'Scouting visits', 'talenttrack' ), self::pageActionsHtml( $page_actions ) );
        self::renderList( $user_id, $is_admin );
    }

    public static function statusLabel( string $key ): string {
        switch ( $key ) {
            case 'completed': return __( 'Completed', 'talenttrack' );
            case 'cancelled': return __( 'Cancelled', 'talenttrack' );
            case 'planned':
            default:          return __( 'Planned', 'talenttrack' );
        }
    }

    public static function statusPillHtml( string $key, string $label ): string {
        // Mirrors LookupPill's inline-style approach so we don't need
        // to seed a tt_lookups vocabulary just for a 3-value status.
        $colors = [
            'planned'   => '#0284c7', // blue
            'completed' => '#16a34a', // green
            'cancelled' => '#dc2626', // red
        ];
        $color = $colors[ $key ] ?? '#5b6e75';
        return sprintf(
            '<span class="tt-pill" style="display:inline-block;padding:2px 10px;border-radius:999px;background:%s;color:#fff;font-size:11px;font-weight:600;line-height:1.6;letter-spacing:0.02em;">%s</span>',
            esc_attr( $color ),
            esc_html( $label )
        );
    }

    private static function canEdit( object $visit, int $user_id, bool $is_admin ): bool {
        if ( $is_admin || current_user_can( 'tt_manage_prospects' ) ) return true;
        if ( ! current_user_can( 'tt_edit_prospects' ) ) return false;
        return (int) ( $visit->scout_user_id ?? 0 ) === $user_id;
    }

    private static function renderList( int $user_id, bool $is_admin ): void {
        $filters = [];
        if ( ! $is_admin && ! current_user_can( 'tt_manage_prospects' ) ) {
            $filters['scout_user_id'] = $user_id;
        }
        $rows = ( new ScoutingVisitsRepository() )->search( $filters );

        if ( empty( $rows ) ) {
            $msg = ( ! $is_admin && ! current_user_can( 'tt_manage_prospects' ) )
                ? __( 'You have not planned any scouting visits yet.', 'talenttrack' )
                : __( 'No scouting visits planned.', 'talenttrack' );
            echo '<p class="tt-empty">' . esc_html( $msg ) . '</p>';
            return;
        }

        $today = current_time( 'Y-m-d' );
        ?>
        <div class="tt-table-wrap">
            <table class="tt-table tt-table-sortable">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Location', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Event', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Prospects', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Scout', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $visit_id   = (int) $row->id;
                    $date_iso   = (string) ( $row->visit_date ?? '' );
                    $time_part  = (string) ( $row->visit_time ?? '' );
                    $is_past    = $date_iso !== '' && $date_iso < $today;
                    $date_label = $date_iso !== '' ? mysql2date( get_option( 'date_format' ), $date_iso, true ) : '';
                    if ( $time_part !== '' && $time_part !== '00:00:00' ) {
                        $date_label .= ' · ' . substr( $time_part, 0, 5 );
                    }
                    $detail_url = RecordLink::detailUrlForWithBack( 'scouting-visit', $visit_id );
                    $count      = ( new ScoutingVisitsRepository() )->prospectCount( $visit_id );
                    $scout_name = '';
                    $scout      = get_userdata( (int) ( $row->scout_user_id ?? 0 ) );
                    if ( $scout ) $scout_name = (string) $scout->display_name;
                    $status_key   = (string) ( $row->status ?? 'planned' );
                    $status_label = self::statusLabel( $status_key );
                    $status_html  = self::statusPillHtml( $status_key, $status_label );
                    ?>
                    <tr class="<?php echo $is_past ? 'tt-row-past' : ''; ?>">
                        <td data-sort="<?php echo esc_attr( $date_iso . ( $time_part ?: '00:00:00' ) ); ?>">
                            <a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( $date_label ); ?></a>
                        </td>
                        <td><?php echo esc_html( (string) ( $row->location ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $row->event_description ?? '' ) ); ?></td>
                        <td><?php echo $status_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — LookupPill escapes ?></td>
                        <td><?php echo esc_html( (string) $count ); ?></td>
                        <td><?php echo esc_html( $scout_name ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderForm( int $user_id, ?object $visit ): void {
        $is_edit = $visit !== null;
        $id_attr = $is_edit ? (int) $visit->id : 0;
        $rest_path = $is_edit ? 'scouting-visits/' . $id_attr : 'scouting-visits';

        $visit_date = $is_edit ? (string) ( $visit->visit_date ?? '' ) : date( 'Y-m-d', strtotime( '+1 week' ) );
        $visit_time = $is_edit ? (string) ( $visit->visit_time ?? '' ) : '';
        if ( $visit_time !== '' && $visit_time !== '00:00:00' ) {
            $visit_time = substr( $visit_time, 0, 5 );
        } else {
            $visit_time = '';
        }
        $location          = $is_edit ? (string) ( $visit->location ?? '' ) : '';
        $event_description = $is_edit ? (string) ( $visit->event_description ?? '' ) : '';
        $age_groups_csv    = $is_edit ? (string) ( $visit->age_groups_csv ?? '' ) : '';
        $notes             = $is_edit ? (string) ( $visit->notes ?? '' ) : '';
        $status            = $is_edit ? (string) ( $visit->status ?? 'planned' ) : 'planned';

        $age_groups = [];
        foreach ( QueryHelpers::get_lookup_names( 'age_group' ) as $ag ) {
            $age_groups[] = (string) $ag;
        }
        $selected_age_groups = $age_groups_csv !== '' ? array_filter( array_map( 'trim', explode( ',', $age_groups_csv ) ) ) : [];
        ?>
        <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="POST" data-redirect-after-save="<?php echo esc_attr( $is_edit ? 'detail:scouting-visit:' . $id_attr : 'list:scouting-visits' ); ?>">
            <div class="tt-grid tt-grid-2">
                <?php echo DateInputComponent::render( [
                    'name'     => 'visit_date',
                    'label'    => __( 'Visit date', 'talenttrack' ),
                    'required' => true,
                    'value'    => $visit_date,
                ] ); ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-sv-time"><?php esc_html_e( 'Time (optional)', 'talenttrack' ); ?></label>
                    <input type="time" id="tt-sv-time" class="tt-input" name="visit_time" value="<?php echo esc_attr( $visit_time ); ?>" />
                </div>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-sv-location"><?php esc_html_e( 'Location', 'talenttrack' ); ?></label>
                <input type="text" id="tt-sv-location" class="tt-input" name="location" required placeholder="<?php esc_attr_e( 'e.g. SC Heerenveen — sportpark Skoatterwâld', 'talenttrack' ); ?>" value="<?php echo esc_attr( $location ); ?>" />
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-sv-event"><?php esc_html_e( 'Event description', 'talenttrack' ); ?></label>
                <input type="text" id="tt-sv-event" class="tt-input" name="event_description" placeholder="<?php esc_attr_e( 'e.g. U13 regional tournament', 'talenttrack' ); ?>" value="<?php echo esc_attr( $event_description ); ?>" />
            </div>
            <?php if ( ! empty( $age_groups ) ) : ?>
                <fieldset class="tt-field tt-fieldset">
                    <legend class="tt-field-label"><?php esc_html_e( 'Age groups expected', 'talenttrack' ); ?></legend>
                    <div class="tt-checkbox-grid">
                        <?php foreach ( $age_groups as $ag ) :
                            $checked = in_array( $ag, $selected_age_groups, true ); ?>
                            <label class="tt-checkbox">
                                <input type="checkbox" name="age_groups_csv_parts[]" value="<?php echo esc_attr( $ag ); ?>" <?php checked( $checked ); ?> />
                                <?php echo esc_html( $ag ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="age_groups_csv" value="<?php echo esc_attr( $age_groups_csv ); ?>" data-tt-checkbox-csv="age_groups_csv_parts" />
                </fieldset>
            <?php endif; ?>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-sv-status"><?php esc_html_e( 'Status', 'talenttrack' ); ?></label>
                <select id="tt-sv-status" class="tt-input" name="status">
                    <option value="planned"   <?php selected( $status, 'planned' ); ?>><?php esc_html_e( 'Planned', 'talenttrack' ); ?></option>
                    <option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'talenttrack' ); ?></option>
                    <option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'talenttrack' ); ?></option>
                </select>
            </div>
            <div class="tt-field">
                <label class="tt-field-label" for="tt-sv-notes"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                <textarea id="tt-sv-notes" class="tt-input" name="notes" rows="3"
                          placeholder="<?php esc_attr_e( 'Players to watch, contacts, logistics…', 'talenttrack' ); ?>"><?php echo esc_textarea( $notes ); ?></textarea>
            </div>
            <?php
            $back = BackLink::resolve();
            if ( $back !== null ) {
                $cancel_url = $back['url'];
            } elseif ( $is_edit ) {
                $cancel_url = RecordLink::detailUrlFor( 'scouting-visit', $id_attr );
            } else {
                $cancel_url = remove_query_arg( [ 'action', 'id' ], add_query_arg( [ 'tt_view' => 'scouting-visits' ] ) );
            }
            echo FormSaveButton::render( [
                'label'      => $is_edit ? __( 'Save changes', 'talenttrack' ) : __( 'Plan visit', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }
}
