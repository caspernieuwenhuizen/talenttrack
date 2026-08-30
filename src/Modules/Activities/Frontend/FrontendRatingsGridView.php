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
                /* translators: %s is a main evaluation category, e.g. Technical. */
                'showSubs'  => __( 'Show sub-categories of %s', 'talenttrack' ),
                /* translators: %s is a main evaluation category, e.g. Technical. */
                'hideSubs'  => __( 'Hide sub-categories of %s', 'talenttrack' ),
                /* translators: 1: number of unsaved scores, 2: the main category they are hidden under. */
                'hidden'    => __( '%1$d unsaved score(s) are hidden under %2$s', 'talenttrack' ),
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

        // #3107 — the grid is Pro; rating a player is not. See the note.
        if ( ! \TT\Modules\License\LicenseGate::allows( 'ratings_grid' ) ) {
            self::crumbs();
            echo \TT\Modules\License\UpgradePanel::render( 'ratings_grid', [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — UpgradePanel returns escaped HTML
                'note' => __( 'Ratings themselves are not affected: you can still rate a player from their profile and from the activity. The grid is the faster desktop way to rate a whole squad at once.', 'talenttrack' ),
            ] );
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

        self::renderGrid( $activity_id, $data['groups'], $categories, $players, $data['values'], $data['scale'] );
    }

    /**
     * @param list<array{id:int,label:string,own:array{id:int,label:string}|null,subs:list<array{id:int,label:string}>,expanded:bool}> $groups
     * @param list<array{id:int,label:string,parent_id:int|null}> $categories
     * @param list<object>                     $players
     * @param array<int, array<int, float>>    $values
     * @param array{min:float,max:float,step:float} $scale
     */
    private static function renderGrid( int $activity_id, array $groups, array $categories, array $players, array $values, array $scale ): void {
        // §6 — Cancel returns to the activity, unless the entry URL captured
        // a tt_back hint, which overrides it.
        $back       = BackLink::resolve();
        $cancel_url = $back['url'] ?? RecordLink::detailUrlFor( 'activities', $activity_id );

        // Column id → which group it sits in, so a body cell can mark itself
        // as a sub, start collapsed with its group, and name its parent in
        // the accessible label. Built once rather than searched per cell:
        // this runs players × categories times.
        $column_ctx = [];
        // Groups that own no rateable column of their own collapse to zero
        // visible columns, and a header can't span zero. Those get a
        // placeholder column, shown only while the group is collapsed, so
        // the main's label and its expand toggle keep a column to sit over.
        $slot_groups = [];
        foreach ( $groups as $g ) {
            $first = true;
            if ( $g['own'] === null && $g['subs'] ) {
                $slot_groups[ $g['id'] ] = (bool) $g['expanded'];
            }
            if ( $g['own'] !== null ) {
                $column_ctx[ $g['own']['id'] ] = [
                    'sub_of'      => 0,
                    'group_label' => $g['label'],
                    'expanded'    => true,
                    'first'       => true,
                ];
                $first = false;
            }
            foreach ( $g['subs'] as $sub ) {
                $column_ctx[ $sub['id'] ] = [
                    'sub_of'      => $g['id'],
                    'group_label' => $g['label'],
                    'expanded'    => $g['expanded'],
                    // Marks where one main's block of columns starts, so the
                    // separator rule has something to hang off while the eye
                    // tracks a block across a wide horizontal scroll.
                    'first'       => $first,
                ];
                $first = false;
            }
        }
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
                        <?php
                        // Two header rows: main categories span their own
                        // column plus their subs, subs sit underneath. The
                        // tier is carried by scope="colgroup" / scope="col"
                        // rather than by styling, so it survives a screen
                        // reader (§2) — colour alone would not.
                        ?>
                        <tr class="tt-rgrid-head-main">
                            <th scope="col" rowspan="2" class="tt-rgrid-player-col"><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <?php foreach ( $groups as $g ) :
                                $has_subs = (bool) $g['subs'];
                                $own_cols = $g['own'] !== null ? 1 : 0;
                                if ( $own_cols + count( $g['subs'] ) < 1 ) continue;

                                // The span has to count the columns actually
                                // on screen, not every column the group owns:
                                // a collapsed sub is display:none, which drops
                                // it out of the table entirely. A span left at
                                // the full width invents columns no row fills,
                                // and every later group's header slides off its
                                // own block (#2474).
                                $span = max( 1, $own_cols + ( $g['expanded'] ? count( $g['subs'] ) : 0 ) );
                                ?>
                                <?php if ( ! $has_subs ) : ?>
                                    <?php // A main with no subs is one column; spanning both rows keeps the header from going ragged. ?>
                                    <th scope="col" rowspan="2" class="tt-rgrid-group tt-rgrid-group--flat"><?php echo esc_html( $g['label'] ); ?></th>
                                <?php else : ?>
                                    <?php // The two counts travel with the header so the JS can recompute the span on toggle without re-deriving the tree. ?>
                                    <th scope="colgroup" colspan="<?php echo (int) $span; ?>"
                                        class="tt-rgrid-group"
                                        data-tt-rgrid-group="<?php echo (int) $g['id']; ?>"
                                        data-tt-rgrid-own="<?php echo (int) $own_cols; ?>"
                                        data-tt-rgrid-subs="<?php echo count( $g['subs'] ); ?>">
                                        <button type="button" class="tt-rgrid-toggle"
                                            data-tt-rgrid-toggle="<?php echo (int) $g['id']; ?>"
                                            data-label="<?php echo esc_attr( $g['label'] ); ?>"
                                            aria-expanded="<?php echo $g['expanded'] ? 'true' : 'false'; ?>">
                                            <span class="tt-rgrid-toggle-caret" aria-hidden="true"></span>
                                            <span class="tt-rgrid-toggle-label"><?php echo esc_html( $g['label'] ); ?></span>
                                            <span class="tt-rgrid-pending" data-tt-rgrid-pending hidden></span>
                                        </button>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                        <tr class="tt-rgrid-head-sub">
                            <?php foreach ( $groups as $g ) :
                                if ( ! $g['subs'] ) continue;
                                $hidden = $g['expanded'] ? '' : ' is-hidden';
                                ?>
                                <?php if ( $g['own'] !== null ) : ?>
                                    <?php // The main's own score column, distinct from its subs — not a computed average of them. ?>
                                    <th scope="col" class="tt-rgrid-own tt-rgrid-group-start"
                                        title="<?php echo esc_attr( $g['label'] ); ?>">
                                        <?php esc_html_e( 'Main score', 'talenttrack' ); ?>
                                    </th>
                                <?php elseif ( isset( $slot_groups[ $g['id'] ] ) ) : ?>
                                    <?php // Placeholder column for a collapsed group with nothing rateable of its own. ?>
                                    <th scope="col" class="tt-rgrid-slot tt-rgrid-group-start<?php echo $g['expanded'] ? ' is-hidden' : ''; ?>"
                                        data-tt-rgrid-slot-of="<?php echo (int) $g['id']; ?>"></th>
                                <?php endif; ?>
                                <?php foreach ( $g['subs'] as $i => $sub ) : ?>
                                    <th scope="col" class="tt-rgrid-sub<?php echo esc_attr( $hidden ); ?><?php echo $g['own'] === null && $i === 0 ? ' tt-rgrid-group-start' : ''; ?>"
                                        data-tt-rgrid-sub-of="<?php echo (int) $g['id']; ?>">
                                        <?php echo esc_html( $sub['label'] ); ?>
                                    </th>
                                <?php endforeach; ?>
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
                                    $cid  = (int) $c['id'];
                                    $val  = $values[ $pid ][ $cid ] ?? null;
                                    $ctx  = $column_ctx[ $cid ] ?? null;
                                    $sub_of = $ctx['sub_of'] ?? 0;

                                    // A sub cell names its parent too, so a
                                    // screen reader hears "Technical /
                                    // Passing" rather than a bare "Passing"
                                    // that could belong to any main.
                                    $label = $sub_of > 0
                                        ? sprintf(
                                            /* translators: 1: main evaluation category, 2: sub-category. */
                                            __( '%1$s / %2$s', 'talenttrack' ),
                                            (string) ( $ctx['group_label'] ?? '' ),
                                            $c['label']
                                        )
                                        : (string) $c['label'];
                                    ?>
                                    <?php if ( ! empty( $ctx['first'] ) && isset( $slot_groups[ $sub_of ] ) ) : ?>
                                        <?php // Body half of the collapsed group's placeholder column. Holds no input — there is no main score to type here. ?>
                                        <td class="tt-rgrid-slot tt-rgrid-group-start<?php echo $slot_groups[ $sub_of ] ? ' is-hidden' : ''; ?>"
                                            data-tt-rgrid-slot-of="<?php echo (int) $sub_of; ?>"></td>
                                    <?php endif; ?>
                                    <?php
                                    $td_class  = $sub_of > 0 ? 'tt-rgrid-sub' : '';
                                    $td_class .= $sub_of > 0 && empty( $ctx['expanded'] ) ? ' is-hidden' : '';
                                    $td_class .= ! empty( $ctx['first'] ) ? ' tt-rgrid-group-start' : '';
                                    ?>
                                    <td class="<?php echo esc_attr( trim( $td_class ) ); ?>"
                                        <?php if ( $sub_of > 0 ) : ?>data-tt-rgrid-sub-of="<?php echo (int) $sub_of; ?>"<?php endif; ?>>
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
                                                $label
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
