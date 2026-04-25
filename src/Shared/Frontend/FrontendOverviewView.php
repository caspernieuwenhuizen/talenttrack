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
 * #0004 polish (v3.18.0): visual rebuild aligned with #0003.
 *
 *   - Compact **hero strip** at the top: photo + name + team + position
 *     + jersey + rolling-rating chip in tier color.
 *   - Embedded **FIFA card** (existing PlayerCardView) on the right /
 *     centered on mobile.
 *   - **Custom fields + radar** beneath the hero, when populated.
 *   - **"View full profile" link** points at the existing profile tile
 *     (will deep-link into #0014's rebuild once that ships).
 *   - All inline styles moved to `frontend-admin.css` per spec.
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

                    <p class="tt-mc-actions">
                        <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( add_query_arg( [ 'tt_view' => 'profile' ], remove_query_arg( [ 'tt_view' ] ) ) ); ?>">
                            <?php esc_html_e( 'View full profile', 'talenttrack' ); ?>
                        </a>
                        <a class="tt-btn tt-btn-secondary" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( [ 'tt_print' => (int) $player->id ], remove_query_arg( [ 'tt_view' ] ) ) ); ?>">
                            <?php esc_html_e( 'Print report', 'talenttrack' ); ?>
                        </a>
                    </p>
                </div>

                <div class="tt-mc-card">
                    <?php \TT\Modules\Stats\Admin\PlayerCardView::renderCard( (int) $player->id, 'md', true ); ?>
                </div>
            </div>
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
