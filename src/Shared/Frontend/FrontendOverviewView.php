<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Shared\Frontend\CustomFieldRenderer;

/**
 * FrontendOverviewView — the player's "My card" tile destination.
 *
 * v3.62.0: My profile is folded into My card. The hero strip + FIFA
 * card stay at the top (desktop = side-by-side, mobile = stacked);
 * the four developer-facing sections from the old My profile view
 * (Playing details, Recent performance, Active goals, Upcoming) are
 * composed below via `FrontendMyProfileView::renderSections()`. The
 * Account section moved to `?tt_view=my-settings` under the user
 * dropdown.
 */
class FrontendOverviewView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-overview',
            TT_PLUGIN_URL . 'assets/css/frontend-overview.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My card', 'talenttrack' ) );
        self::renderHeader( __( 'My card', 'talenttrack' ) );

        $max     = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $service = new PlayerStatsService();
        $heads   = $service->getHeadlineNumbers( (int) $player->id, [], 5 );
        $rolling = isset( $heads['rolling'] ) && $heads['rolling'] !== null ? (float) $heads['rolling'] : null;
        $alltime = isset( $heads['alltime'] ) && $heads['alltime'] !== null ? (float) $heads['alltime'] : null;

        ?>
        <section class="tt-ov">
            <?php
            self::renderHeroStrip( $player, $rolling, $alltime, $max );
            self::renderKpiRow( $heads, $max );
            ?>

            <div class="tt-ov-grid">
                <div class="tt-ov-side">
                    <?php self::renderCustomFields( (int) $player->id ); ?>

                    <?php
                    $radar = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
                    if ( ! empty( $radar['datasets'] ) ) :
                        ?>
                        <div class="tt-ov-card">
                            <h3 class="tt-ov-cf-heading"><?php esc_html_e( 'Skills profile', 'talenttrack' ); ?></h3>
                            <div class="tt-ov-radar tt-radar-wrap">
                                <?php echo QueryHelpers::radar_chart_svg( $radar['labels'], $radar['datasets'], $max ); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tt-ov-card tt-ov-card--fifa">
                    <?php
                    // v3.92.2 — was a full-width "Print report" button
                    // anchored at the bottom of the side column. Pilot
                    // operator: should be subtle, top-right, icon-only.
                    // Print icon SVG + aria-label; tt-print-icon-btn
                    // styles position:absolute top:8px right:8px in
                    // public.css (or inline-styled here so no CSS file
                    // change is required for the polish ship).
                    $print_url   = add_query_arg( [ 'tt_print' => (int) $player->id ], remove_query_arg( [ 'tt_view' ] ) );
                    $print_label = __( 'Print report', 'talenttrack' );
                    ?>
                    <a class="tt-print-icon-btn"
                       href="<?php echo esc_url( $print_url ); ?>"
                       target="_blank" rel="noopener"
                       title="<?php echo esc_attr( $print_label ); ?>"
                       aria-label="<?php echo esc_attr( $print_label ); ?>"
                       style="position:absolute; top:8px; right:8px; display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:6px; background:transparent; border:1px solid transparent; color:#5b6e75; text-decoration:none; transition:background 0.15s ease, border-color 0.15s ease, color 0.15s ease;"
                       onmouseover="this.style.background='#f3f4f6';this.style.borderColor='#d6dadd';this.style.color='#1a1d21';"
                       onmouseout="this.style.background='transparent';this.style.borderColor='transparent';this.style.color='#5b6e75';">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <polyline points="6 9 6 2 18 2 18 9"></polyline>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                            <rect x="6" y="14" width="12" height="8"></rect>
                        </svg>
                    </a>
                    <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
                </div>
            </div>

            <?php
            // v3.62.0 — My profile sections folded into My card. Hero +
            // FIFA card sit above; the four developer-facing sections
            // (Playing details / Recent performance / Active goals /
            // Upcoming) sit below.
            FrontendMyProfileView::renderSections( $player );
            ?>
        </section>
        <?php
    }

    /**
     * #1850 — public hero entry point so the development home can reuse
     * the exact same player header without duplicating the markup. Loads
     * the overview stylesheet (the hero's `.tt-ov-hero*` rules live
     * there) and computes the headline numbers the badge needs.
     */
    public static function renderHero( object $player ): void {
        wp_enqueue_style(
            'tt-frontend-overview',
            TT_PLUGIN_URL . 'assets/css/frontend-overview.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        $max     = (float) QueryHelpers::get_config( 'rating_max', '10' );
        $heads   = ( new PlayerStatsService() )->getHeadlineNumbers( (int) $player->id, [], 5 );
        $rolling = isset( $heads['rolling'] ) && $heads['rolling'] !== null ? (float) $heads['rolling'] : null;
        $alltime = isset( $heads['alltime'] ) && $heads['alltime'] !== null ? (float) $heads['alltime'] : null;
        self::renderHeroStrip( $player, $rolling, $alltime, $max );
    }

    /**
     * Player header card — photo + name + team / position / foot meta +
     * an OVR rating badge (gold-on-green, borrows the 2026 deck's
     * `.tag-ovr` language). The badge shows the player's headline rating
     * (rolling "Last 5", falling back to all-time), or a warm
     * "coming soon" state before the first evaluation.
     */
    private static function renderHeroStrip( object $player, ?float $rolling, ?float $alltime, float $max ): void {
        $name = QueryHelpers::player_display_name( $player );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $pos  = json_decode( (string) $player->preferred_positions, true );
        $pos_str = is_array( $pos ) ? implode( ', ', $pos ) : '';
        $jersey  = $player->jersey_number ? '#' . (int) $player->jersey_number : '';

        $ovr      = $rolling !== null ? $rolling : $alltime;
        $ovr_kind = $rolling !== null ? __( 'Last 5', 'talenttrack' ) : __( 'All-time', 'talenttrack' );
        ?>
        <header class="tt-ov-hero">
            <div class="tt-ov-hero-photo">
                <?php if ( ! empty( $player->photo_url ) ) : ?>
                    <img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" />
                <?php else : ?>
                    <div class="tt-ov-hero-photo-ph" aria-hidden="true">
                        <?php echo esc_html( strtoupper( substr( (string) $player->first_name, 0, 1 ) . substr( (string) $player->last_name, 0, 1 ) ) ); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="tt-ov-hero-body">
                <h2 class="tt-ov-hero-name"><?php echo esc_html( $name ); ?></h2>
                <p class="tt-ov-hero-meta">
                    <?php
                    $bits = [];
                    if ( $team )            $bits[] = esc_html( (string) $team->name );
                    if ( $pos_str !== '' )  $bits[] = esc_html( $pos_str );
                    if ( $jersey !== '' )   $bits[] = esc_html( $jersey );
                    if ( ! empty( $player->preferred_foot ) ) {
                        /* translators: %s is preferred foot label (Left / Right / Both) */
                        $bits[] = esc_html( sprintf( __( '%s foot', 'talenttrack' ), \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'foot_option', (string) $player->preferred_foot ) ) );
                    }
                    echo implode( ' · ', $bits );
                    ?>
                </p>
            </div>
            <?php if ( $ovr !== null ) : ?>
                <div class="tt-ov-ovr" title="<?php echo esc_attr( sprintf( '%s · %s / %s', $ovr_kind, number_format_i18n( $ovr, 1 ), number_format_i18n( $max, 0 ) ) ); ?>">
                    <span class="tt-ov-ovr-label"><?php echo esc_html( $ovr_kind ); ?></span>
                    <span class="tt-ov-ovr-val"><?php echo esc_html( number_format_i18n( $ovr, 1 ) ); ?></span>
                </div>
            <?php else : ?>
                <div class="tt-ov-ovr tt-ov-ovr--empty">
                    <span class="tt-ov-ovr-val"><?php esc_html_e( 'First evaluation coming soon', 'talenttrack' ); ?></span>
                </div>
            <?php endif; ?>
        </header>
        <?php
    }

    /**
     * Headline-stats KPI tile row. Renders the real evaluation headline
     * numbers (latest, rolling Last 5, all-time, evaluation count) as
     * 2026 KPI tiles via the shared FrontendAppChrome::kpiTile() helper.
     * The Last-5 tile carries a delta vs. the all-time mean so a player
     * or parent can see momentum at a glance. Renders nothing before the
     * first rated evaluation — the hero's "coming soon" badge covers
     * that state.
     */
    private static function renderKpiRow( array $heads, float $max ): void {
        $latest  = isset( $heads['latest'] )  && $heads['latest']  !== null ? (float) $heads['latest']  : null;
        $rolling = isset( $heads['rolling'] ) && $heads['rolling'] !== null ? (float) $heads['rolling'] : null;
        $alltime = isset( $heads['alltime'] ) && $heads['alltime'] !== null ? (float) $heads['alltime'] : null;
        $evals   = (int) ( $heads['eval_count'] ?? 0 );

        if ( $latest === null && $rolling === null && $alltime === null ) {
            return;
        }

        $max_str = number_format_i18n( $max, 0 );
        $dash    = '—';

        // Momentum: rolling Last-5 mean vs. the all-time mean.
        $delta = '';
        $trend = 'flat';
        if ( $rolling !== null && $alltime !== null ) {
            $diff = round( $rolling - $alltime, 1 );
            if ( $diff > 0 )      { $trend = 'up';   $delta = '+' . number_format_i18n( $diff, 1 ); }
            elseif ( $diff < 0 )  { $trend = 'down'; $delta = number_format_i18n( $diff, 1 ); }
            else                  { $trend = 'flat'; $delta = '±0'; }
        }

        $chrome = '\\TT\\Shared\\Frontend\\Components\\FrontendAppChrome';
        ?>
        <div class="tt-ov-kpis">
            <?php
            echo $chrome::kpiTile( [
                'label' => __( 'Latest', 'talenttrack' ),
                'value' => $latest !== null ? number_format_i18n( $latest, 1 ) . ' / ' . $max_str : $dash,
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $chrome::kpiTile( [
                'label' => __( 'Last 5', 'talenttrack' ),
                'value' => $rolling !== null ? number_format_i18n( $rolling, 1 ) . ' / ' . $max_str : $dash,
                'delta' => $delta,
                'trend' => $trend,
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $chrome::kpiTile( [
                'label' => __( 'All-time', 'talenttrack' ),
                'value' => $alltime !== null ? number_format_i18n( $alltime, 1 ) . ' / ' . $max_str : $dash,
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $chrome::kpiTile( [
                'label' => __( 'Evaluations', 'talenttrack' ),
                'value' => number_format_i18n( $evals ),
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </div>
        <?php
    }

    private static function renderCustomFields( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) return;
        $values = ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id );

        $has_any = false;
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v !== null && $v !== '' ) { $has_any = true; break; }
        }
        if ( ! $has_any ) return;

        ?>
        <div class="tt-ov-card">
            <h3 class="tt-ov-cf-heading"><?php esc_html_e( 'Additional information', 'talenttrack' ); ?></h3>
            <dl class="tt-ov-cf-list">
                <?php foreach ( $fields as $f ) :
                    $v = $values[ (string) $f->field_key ] ?? null;
                    if ( $v === null || $v === '' ) continue;
                    ?>
                    <dt><?php echo esc_html( (string) $f->label ); ?></dt>
                    <dd><?php echo CustomFieldRenderer::display( $f, $v ); ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php
    }
}
