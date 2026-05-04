<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Shared\Frontend\CustomFieldRenderer;
use TT\Shared\Frontend\Components\RatingPillComponent;

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
        self::renderHeader( __( 'My card', 'talenttrack' ) );

        $max     = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $service = new PlayerStatsService();
        $heads   = $service->getHeadlineNumbers( (int) $player->id, [], 5 );
        $rolling = isset( $heads['rolling'] ) && $heads['rolling'] !== null ? (float) $heads['rolling'] : null;
        $alltime = isset( $heads['alltime'] ) && $heads['alltime'] !== null ? (float) $heads['alltime'] : null;

        ?>
        <section class="tt-mc">
            <?php self::renderHeroStrip( $player, $rolling, $alltime, $max ); ?>

            <div class="tt-mc-grid">
                <div class="tt-mc-side">
                    <?php self::renderCustomFields( (int) $player->id ); ?>

                    <?php
                    $radar = QueryHelpers::player_radar_datasets( (int) $player->id, 3 );
                    if ( ! empty( $radar['datasets'] ) ) :
                        ?>
                        <div class="tt-mc-radar tt-radar-wrap">
                            <?php echo QueryHelpers::radar_chart_svg( $radar['labels'], $radar['datasets'], $max ); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="tt-mc-card" style="position:relative;">
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
     * Compact hero strip — photo + name + team + position + rolling
     * rating pill. Lives at the top of the My card tile and replaces
     * the previous "tt-card" + scattered <p> blocks.
     */
    private static function renderHeroStrip( object $player, ?float $rolling, ?float $alltime, float $max ): void {
        $name = QueryHelpers::player_display_name( $player );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $pos  = json_decode( (string) $player->preferred_positions, true );
        $pos_str = is_array( $pos ) ? implode( ', ', $pos ) : '';
        $jersey  = $player->jersey_number ? '#' . (int) $player->jersey_number : '';
        ?>
        <header class="tt-mc-hero">
            <div class="tt-mc-hero-photo">
                <?php if ( ! empty( $player->photo_url ) ) : ?>
                    <img src="<?php echo esc_url( (string) $player->photo_url ); ?>" alt="" />
                <?php else : ?>
                    <div class="tt-mc-hero-photo-placeholder" aria-hidden="true">
                        <?php echo esc_html( strtoupper( substr( (string) $player->first_name, 0, 1 ) . substr( (string) $player->last_name, 0, 1 ) ) ); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="tt-mc-hero-body">
                <h2 class="tt-mc-hero-name"><?php echo esc_html( $name ); ?></h2>
                <p class="tt-mc-hero-meta">
                    <?php
                    $bits = [];
                    if ( $team )            $bits[] = esc_html( (string) $team->name );
                    if ( $pos_str !== '' )  $bits[] = esc_html( $pos_str );
                    if ( $jersey !== '' )   $bits[] = esc_html( $jersey );
                    if ( ! empty( $player->preferred_foot ) ) {
                        /* translators: %s is preferred foot label (Left / Right / Both) */
                        $bits[] = esc_html( sprintf( __( '%s foot', 'talenttrack' ), (string) $player->preferred_foot ) );
                    }
                    echo implode( ' · ', $bits );
                    ?>
                </p>
            </div>
            <div class="tt-mc-hero-rating">
                <?php if ( $rolling !== null ) : ?>
                    <span class="tt-mc-hero-rating-label"><?php esc_html_e( 'Last 5', 'talenttrack' ); ?></span>
                    <?php echo RatingPillComponent::chip( $rolling, $max ); ?>
                <?php elseif ( $alltime !== null ) : ?>
                    <span class="tt-mc-hero-rating-label"><?php esc_html_e( 'All-time', 'talenttrack' ); ?></span>
                    <?php echo RatingPillComponent::chip( $alltime, $max ); ?>
                <?php else : ?>
                    <span class="tt-mc-hero-rating-empty"><?php esc_html_e( 'First evaluation coming soon', 'talenttrack' ); ?></span>
                <?php endif; ?>
            </div>
        </header>
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
        <div class="tt-mc-cf">
            <h3 class="tt-mc-cf-heading"><?php esc_html_e( 'Additional information', 'talenttrack' ); ?></h3>
            <dl class="tt-mc-cf-list">
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
