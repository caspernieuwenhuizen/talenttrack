<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendSeasonsView (#1481) — frontend Seasons manager. Brings the
 * create / edit / set-current / delete flow that previously lived only
 * in wp-admin (`SeasonsPage`) onto the frontend admin surface, so an
 * academy never has to drop into wp-admin to manage seasons.
 *
 * All mutations go through the REST contract
 * (`/wp-json/talenttrack/v1/seasons`) — the view composes data via the
 * repository and hands every write to REST (CLAUDE.md §4). Gated by
 * `tt_edit_settings`, matching the REST write permission so the UI never
 * shows an action the API would refuse.
 *
 * Delete is guarded: the current season and any season with linked
 * records can't be removed (the REST layer + repository enforce this);
 * the list hides the button in those cases and explains why.
 */
class FrontendSeasonsView extends FrontendViewBase {

    public const CAP = 'tt_edit_settings';

    public static function render( int $user_id, bool $is_admin ): void {
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage seasons.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueSeasonsJs();

        if ( $action === 'new' || $action === 'edit' ) {
            self::renderForm( $action );
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Seasons', 'talenttrack' ) );
        $new_url = add_query_arg( [ 'tt_view' => 'seasons', 'action' => 'new' ], self::dashboardUrl() );
        self::renderHeader(
            __( 'Seasons', 'talenttrack' ),
            '<a class="tt-btn tt-btn-primary" href="' . esc_url( $new_url ) . '">' . esc_html__( '+ New season', 'talenttrack' ) . '</a>'
        );

        echo '<p style="max-width:680px; color:#5b6e75;">'
            . esc_html__( 'Exactly one season is current. PDP files are scoped to a season, and the carryover job runs whenever you change the current season.', 'talenttrack' )
            . '</p>';

        $repo    = new SeasonsRepository();
        $seasons = $repo->all();

        if ( empty( $seasons ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No seasons yet. Create your first one.', 'talenttrack' ) . '</p>';
            return;
        }
        ?>
        <div class="tt-table-wrap">
            <table class="tt-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Season', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Starts', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Ends', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Current', 'talenttrack' ); ?></th>
                    <th style="text-align:right;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $seasons as $s ) :
                    $sid        = (int) $s->id;
                    $is_current = (int) $s->is_current === 1;
                    $referenced = self::isReferenced( $repo, $sid );
                    $edit_url   = add_query_arg( [ 'tt_view' => 'seasons', 'action' => 'edit', 'id' => $sid ], self::dashboardUrl() );
                    ?>
                    <tr<?php echo $is_current ? ' style="background:#f0f7f6;"' : ''; ?>>
                        <td><strong><?php echo esc_html( (string) $s->name ); ?></strong></td>
                        <td><?php echo esc_html( TTDate::date( (string) $s->start_date ) ); ?></td>
                        <td><?php echo esc_html( TTDate::date( (string) $s->end_date ) ); ?></td>
                        <td>
                            <?php if ( $is_current ) : ?>
                                <span class="tt-badge" style="background:#e7f0e9; color:#1e6b3a; font-weight:600; padding:3px 10px; border-radius:999px; font-size:12px;"><?php esc_html_e( 'Current', 'talenttrack' ); ?></span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <a class="tt-btn tt-btn-secondary tt-btn-sm" href="<?php echo esc_url( $edit_url ); ?>" style="min-height:48px;"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                            <?php if ( ! $is_current ) : ?>
                                <button type="button" class="tt-btn tt-btn-secondary tt-btn-sm" style="min-height:48px;" data-tt-season-current="<?php echo esc_attr( (string) $sid ); ?>"><?php esc_html_e( 'Set current', 'talenttrack' ); ?></button>
                            <?php endif; ?>
                            <?php if ( ! $is_current && ! $referenced ) : ?>
                                <button type="button" class="tt-btn tt-btn-danger tt-btn-sm" style="min-height:48px;" data-tt-season-delete="<?php echo esc_attr( (string) $sid ); ?>" data-tt-season-name="<?php echo esc_attr( (string) $s->name ); ?>"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></button>
                            <?php elseif ( ! $is_current && $referenced ) : ?>
                                <span class="tt-field-hint" style="font-size:11px; color:var(--tt-muted);" title="<?php esc_attr_e( 'Has linked records — edit instead of deleting.', 'talenttrack' ); ?>"><?php esc_html_e( 'In use', 'talenttrack' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderForm( string $action ): void {
        $repo    = new SeasonsRepository();
        $editing = $action === 'edit';
        $id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $season  = $editing ? $repo->find( $id ) : null;

        if ( $editing && $season === null ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Season not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'That season no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        $list_url = add_query_arg( [ 'tt_view' => 'seasons' ], self::dashboardUrl() );
        $title    = $editing ? __( 'Edit season', 'talenttrack' ) : __( 'New season', 'talenttrack' );
        FrontendBreadcrumbs::fromDashboard(
            $title,
            [ FrontendBreadcrumbs::viewCrumb( 'seasons', __( 'Seasons', 'talenttrack' ) ) ]
        );
        self::renderHeader( $title );

        $name  = $season ? (string) $season->name : '';
        $start = $season ? (string) $season->start_date : '';
        $end   = $season ? (string) $season->end_date : '';
        ?>
        <form class="tt-form" data-tt-season-form="1" data-tt-season-id="<?php echo esc_attr( (string) ( $editing ? $id : 0 ) ); ?>">
            <div class="tt-field">
                <label class="tt-field-label" for="tt-season-name"><?php esc_html_e( 'Season name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-season-name" class="tt-input" name="name" value="<?php echo esc_attr( $name ); ?>" required autocomplete="off" maxlength="100" />
            </div>
            <div class="tt-grid tt-grid-2" style="margin-top:var(--tt-sp-3);">
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-season-start"><?php esc_html_e( 'Start date', 'talenttrack' ); ?></label>
                    <input type="date" id="tt-season-start" class="tt-input" name="start_date" value="<?php echo esc_attr( $start ); ?>" required />
                </div>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-season-end"><?php esc_html_e( 'End date', 'talenttrack' ); ?></label>
                    <input type="date" id="tt-season-end" class="tt-input" name="end_date" value="<?php echo esc_attr( $end ); ?>" required />
                </div>
            </div>
            <?php
            echo FormSaveButton::render( [
                'label'      => $editing ? __( 'Save season', 'talenttrack' ) : __( 'Create season', 'talenttrack' ),
                'cancel_url' => $list_url,
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
            ?>
            <div class="tt-form-msg" style="margin-top:10px;"></div>
        </form>
        <?php
    }

    private static function enqueueSeasonsJs(): void {
        wp_enqueue_script(
            'tt-frontend-seasons',
            TT_PLUGIN_URL . 'assets/js/frontend-seasons.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-frontend-seasons', 'TT_SEASONS', [
            'restUrl'  => esc_url_raw( rest_url( 'talenttrack/v1/seasons' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'listUrl'  => add_query_arg( [ 'tt_view' => 'seasons' ], self::dashboardUrl() ),
            'i18n'     => [
                'confirmCurrent' => __( 'Make this the current season? Carryover runs for any open PDP file from the previous season.', 'talenttrack' ),
                'confirmDelete'  => __( 'Delete the season “%s”? This cannot be undone.', 'talenttrack' ),
                'saving'         => __( 'Saving…', 'talenttrack' ),
                'genericError'   => __( 'Something went wrong. Please try again.', 'talenttrack' ),
                'badRange'       => __( 'End date must be after the start date.', 'talenttrack' ),
            ],
        ] );
    }

    private static function isReferenced( SeasonsRepository $repo, int $id ): bool {
        return $repo->isReferenced( $id );
    }

    private static function dashboardUrl(): string {
        return \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
    }
}
