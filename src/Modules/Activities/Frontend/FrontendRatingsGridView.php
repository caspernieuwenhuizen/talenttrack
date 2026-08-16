<?php
namespace TT\Modules\Activities\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Activities\Reports\RatingsGridQuery;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendRatingsGridView (#2414, epic #2381) — the desktop ratings-entry
 * grid: one activity, the team's active players in rows, that activity's
 * evaluation categories in columns, one directly-typed score per cell.
 *
 * URL: `?tt_view=ratings-grid&activity_id=N`
 *
 * Why per-activity rather than the period shape its sibling grids use: a
 * rating is N category scores per player per activity, so players ×
 * activities could only show one collapsed number per cell. Pivoting on
 * categories keeps every cell a real `tt_eval_ratings.rating` — nothing
 * derived, no popover. The weighted overall stays derived at read time.
 *
 * Write-capable, so it gates on `tt_edit_activities` — the same capability
 * the bulk endpoint enforces, so the affordance can't outlive the gate
 * (§7). Desktop-only (≥1024px); the evaluation wizard remains the
 * mobile/pitch path and the flat eval form the power-user fallback (§3).
 */
final class FrontendRatingsGridView extends FrontendViewBase {

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-ratings-grid',
            TT_PLUGIN_URL . 'assets/css/frontend-ratings-grid.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-ratings-grid',
            TT_PLUGIN_URL . 'assets/js/frontend-ratings-grid.js',
            [],
            TT_VERSION,
            true
        );
        // §4 — never hardcode English in JS; strings come from the server.
        wp_localize_script( 'tt-frontend-ratings-grid', 'TTRatingsGrid', [
            'i18n' => [
                'saving'    => __( 'Saving…', 'talenttrack' ),
                'saved'     => __( 'All changes saved', 'talenttrack' ),
                'error'     => __( 'Could not save — try again', 'talenttrack' ),
                'network'   => __( 'Network error — try again', 'talenttrack' ),
                /* translators: %d is the number of unsaved cell changes. */
                'unsaved'   => __( '%d unsaved change(s)', 'talenttrack' ),
                /* translators: 1: lowest allowed score, 2: highest allowed score. */
                'range'     => __( 'Score must be between %1$s and %2$s', 'talenttrack' ),
                /* translators: %s is the step the rating scale moves in, e.g. 0.5. */
                'step'      => __( 'Score must be in steps of %s', 'talenttrack' ),
                /* translators: %d is the number of cells with an invalid score. */
                'blocked'   => __( '%d score(s) out of range — fix the highlighted cells to save', 'talenttrack' ),
                /* translators: %d is the number of scores the server refused. */
                'rejected'  => __( '%d score(s) were refused and NOT saved — the highlighted cells are still unsaved', 'talenttrack' ),
            ],
        ] );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        self::enqueueAssets();

        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;

        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            self::crumbs();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to record ratings.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! FeatureRegistry::isEnabled( 'ratings_grid' ) ) {
            self::crumbs();
            echo '<p class="tt-notice">' . esc_html__( 'The ratings grid has been switched off for your academy.', 'talenttrack' ) . '</p>';
            return;
        }

        $data     = RatingsGridQuery::forActivity( $activity_id );
        $activity = $data['activity'];

        if ( ! $activity ) {
            self::crumbs();
            echo '<p class="tt-notice">' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>';
            return;
        }

        // Team scope: a coach may only rate on a team they coach. Mirrors
        // the check the bulk endpoint enforces, so the two agree (§7).
        if ( ! self::canRateTeam( $user_id, (int) $activity->team_id, $is_admin ) ) {
            self::crumbs( (string) $activity->title, $activity_id );
            echo '<p class="tt-notice">' . esc_html__( 'You do not coach this activity\'s team.', 'talenttrack' ) . '</p>';
            return;
        }

        self::crumbs( (string) $activity->title, $activity_id );
        self::renderHeader( __( 'Ratings grid', 'talenttrack' ) );

        echo '<p class="tt-rgrid-lead">' . esc_html( sprintf(
            /* translators: 1: activity title, 2: activity date. */
            __( 'Rating %1$s (%2$s). Rows are players, columns are the categories this activity is rated on. Type a score per cell and Save.', 'talenttrack' ),
            (string) $activity->title,
            \TT\Shared\Dates\TTDate::date( (string) $activity->session_date )
        ) ) . '</p>';

        $categories = $data['categories'];
        $players    = $data['players'];

        if ( ! $categories ) {
            echo '<p class="tt-notice">' . esc_html__( 'No evaluation categories are configured, so there is nothing to rate on yet.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! $players ) {
            echo '<p class="tt-notice">' . esc_html__( 'This activity has no team roster, so there are no players to rate.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderGrid( $activity_id, $categories, $players, $data['values'], $data['scale'] );
    }

    /**
     * @param list<array{id:int,label:string}> $categories
     * @param list<object>                     $players
     * @param array<int, array<int, float>>    $values
     * @param array{min:float,max:float,step:float} $scale
     */
    private static function renderGrid( int $activity_id, array $categories, array $players, array $values, array $scale ): void {
        // §6 — Cancel returns to the activity, unless the entry URL captured
        // a tt_back hint, which overrides it.
        $back       = BackLink::resolve();
        $cancel_url = $back['url'] ?? RecordLink::detailUrlFor( 'activities', $activity_id );
        ?>
        <div class="tt-rgrid" data-tt-rgrid
            data-activity-id="<?php echo (int) $activity_id; ?>"
            data-rest="<?php echo esc_url( rest_url( 'talenttrack/v1/activities/' . $activity_id . '/ratings/bulk' ) ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">

            <p class="tt-rgrid-desktop-only tt-notice">
                <?php esc_html_e( 'The ratings grid needs a wider screen. On a phone, use the evaluation wizard instead.', 'talenttrack' ); ?>
            </p>

            <div class="tt-rgrid-scroll">
                <table class="tt-rgrid-table">
                    <thead>
                        <tr>
                            <th scope="col" class="tt-rgrid-player-col"><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <?php foreach ( $categories as $c ) : ?>
                                <th scope="col"><?php echo esc_html( $c['label'] ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $players as $pl ) :
                            $pid  = (int) $pl->id;
                            $name = trim( QueryHelpers::player_display_name( $pl ) );
                            ?>
                            <tr>
                                <th scope="row" class="tt-rgrid-player-col">
                                    <?php echo esc_html( $name ); ?>
                                    <?php if ( ! empty( $pl->jersey_number ) ) : ?>
                                        <span class="tt-rgrid-jersey">#<?php echo esc_html( (string) $pl->jersey_number ); ?></span>
                                    <?php endif; ?>
                                </th>
                                <?php foreach ( $categories as $c ) :
                                    $cid = (int) $c['id'];
                                    $val = $values[ $pid ][ $cid ] ?? null;
                                    ?>
                                    <td>
                                        <input type="number" inputmode="decimal"
                                            class="tt-rgrid-input"
                                            data-player-id="<?php echo (int) $pid; ?>"
                                            data-category-id="<?php echo (int) $cid; ?>"
                                            min="<?php echo esc_attr( (string) $scale['min'] ); ?>"
                                            max="<?php echo esc_attr( (string) $scale['max'] ); ?>"
                                            step="<?php echo esc_attr( (string) $scale['step'] ); ?>"
                                            value="<?php echo $val === null ? '' : esc_attr( (string) $val ); ?>"
                                            aria-label="<?php echo esc_attr( sprintf(
                                                /* translators: 1: player name, 2: evaluation category. */
                                                __( '%1$s — %2$s', 'talenttrack' ),
                                                $name,
                                                $c['label']
                                            ) ); ?>" />
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="tt-rgrid-status" data-tt-rgrid-status role="status"></p>

            <?php
            // §6 — Cancel + Save side by side, Save right, via the shared
            // helper (which owns the flex order + Tab order). The JS binds
            // to the rendered `.tt-save-btn` inside this container.
            echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FormSaveButton escapes its own labels + URL.
                'label'      => __( 'Save ratings', 'talenttrack' ),
                'cancel_url' => $cancel_url,
            ] );
            ?>
        </div>
        <?php
    }

    /**
     * Academy-wide roles rate any team; a coach only their own. Same
     * question the bulk endpoint asks, so the affordance and the write
     * agree (§7).
     */
    private static function canRateTeam( int $user_id, int $team_id, bool $is_admin ): bool {
        if ( $team_id <= 0 ) return false;
        if ( $is_admin || current_user_can( 'tt_view_all_teams' ) ) return true;
        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $t ) {
            if ( (int) $t->id === $team_id ) return true;
        }
        return false;
    }

    /**
     * §5 — breadcrumb chain on every path, permission-denied included. The
     * activity crumb IS the back-to-record affordance; no extra back link.
     */
    private static function crumbs( string $activity_title = '', int $activity_id = 0 ): void {
        $trail = [ FrontendBreadcrumbs::viewCrumb( 'activities', __( 'Activities', 'talenttrack' ) ) ];
        if ( $activity_title !== '' && $activity_id > 0 ) {
            $trail[] = FrontendBreadcrumbs::viewCrumb( 'activities', $activity_title, [ 'id' => $activity_id ] );
        }
        FrontendBreadcrumbs::fromDashboard( __( 'Ratings grid', 'talenttrack' ), $trail );
    }
}
