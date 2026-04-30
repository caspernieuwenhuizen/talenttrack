<?php
namespace TT\Modules\Stats\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;

/**
 * PlayerCardView — FIFA-style collectible trading-card summary.
 *
 * Sprint 2B (v2.15.0). Renders a player card tiered by rolling average:
 *   Gold   (>= 4.0): warm amber/champagne
 *   Silver (>= 3.0): cool platinum
 *   Bronze (<  3.0): copper/rust
 *   None:  no rated evaluations — muted desaturated frame
 *
 * Visual design is original (no EA/FIFA-copyrighted assets or
 * vocabulary). Styling lives in assets/css/player-card.css.
 *
 * Reused across:
 *   - Admin rate-card page (toggle between Standard and Card views)
 *   - Player front-end dashboard (own card on Overview / Mijn team)
 *   - Coach front-end dashboard (team top-3 podium per coached team)
 *
 * Call renderCard($player_id) for a single card. Call renderPodium(...)
 * for a 3-slot top players arrangement on team surfaces.
 */
class PlayerCardView {

    private const ROLLING_N = 5;
    private const GOLD_THRESHOLD   = 4.0;
    private const SILVER_THRESHOLD = 3.0;

    /**
     * Enqueue the card stylesheet. Safe to call multiple times per request.
     * WordPress deduplicates by handle.
     */
    public static function enqueueStyles(): void {
        wp_enqueue_style(
            'tt-player-card',
            TT_PLUGIN_URL . 'assets/css/player-card.css',
            [],
            TT_VERSION
        );
    }

    // Single card

    /**
     * Render one player's card.
     *
     * @param int         $player_id
     * @param string      $size          'sm' | 'md' | 'lg'
     * @param bool        $show_tier     Show the tier badge ribbon in the corner
     * @param string|null $tier_override When set (e.g. 'gold', 'silver', 'bronze'),
     *                                   forces that tier regardless of rating. Used
     *                                   by podium rendering where position, not
     *                                   rating, determines tier. When null (default),
     *                                   renders with the neutral colorway — v2.16.0
     *                                   decision: tiers are a ranking signal, not an
     *                                   intrinsic rating tier.
     */
    public static function renderCard( int $player_id, string $size = 'md', bool $show_tier = false, ?string $tier_override = null ): void {
        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            echo '<p><em>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $svc      = new PlayerStatsService();
        $headline = $svc->getHeadlineNumbers( $player_id, [], self::ROLLING_N );
        $mains    = $svc->getMainCategoryBreakdown( $player_id, [] );

        $rolling = $headline['rolling']; // ?float

        // v2.16.0: tier is determined by explicit override (podium positions),
        // else neutral. Rating-based tiering removed from the default path —
        // see CHANGES.md 2.16.0 for the rationale.
        if ( $tier_override !== null && in_array( $tier_override, [ 'gold', 'silver', 'bronze', 'none', 'neutral' ], true ) ) {
            $tier = $tier_override;
        } else {
            // If the player has no rated evaluations at all, mark as 'none'
            // (muted gray) so the card reads as "unrated" rather than
            // pretending to be neutral-premium.
            $tier = $rolling === null ? 'none' : 'neutral';
        }

        $photo_url = self::resolvePhotoUrl( $player );
        $initials  = self::initialsFromPlayer( $player );
        $team_name = self::resolveTeamName( $player );
        $position  = self::resolvePositionAbbr( $player );

        // Per-main stat values for the grid (use all-time mean; matches
        // the rate card page's "All-time" column). Order = display_order
        // from tt_eval_categories.
        $stats = [];
        $cats_repo = new EvalCategoriesRepository();
        foreach ( $cats_repo->getMainCategories( true ) as $cat ) {
            $mid = (int) $cat->id;
            $raw_label = (string) $cat->label;
            $translated = EvalCategoriesRepository::displayLabel( $raw_label );
            $stats[] = [
                'label' => self::statAbbreviation( $translated ),
                'value' => isset( $mains[ $mid ] ) && $mains[ $mid ]['alltime'] !== null
                    ? (string) $mains[ $mid ]['alltime']
                    : '—',
            ];
        }

        $classes = [ 'tt-pc', 'tt-pc--' . $size, 'tt-pc--' . $tier ];
        if ( $show_tier ) $classes[] = 'tt-pc--show-tier';

        $tier_label = self::tierLabel( $tier );
        $rating_display = $rolling !== null
            ? self::formatRatingNumber( $rolling )
            : '—';
        // #0063 — wrap the card in a link to the frontend player detail
        // so podium cards click through to the player profile per the
        // user's "cards should lead to player profile" ask.
        $detail_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'players', $player_id );
        ?>
        <a href="<?php echo esc_url( $detail_url ); ?>" class="tt-pc-link" style="text-decoration:none; color:inherit; display:block;">
        <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" role="img"
             aria-label="<?php echo esc_attr( sprintf(
                 /* translators: 1: player name, 2: tier (Gold/Silver/Bronze/Unrated), 3: rolling average. */
                 __( '%1$s — %2$s tier, %3$s rolling average', 'talenttrack' ),
                 QueryHelpers::player_display_name( $player ),
                 $tier_label,
                 $rating_display
             ) ); ?>">
            <div class="tt-pc__surface"></div>
            <div class="tt-pc__facets"></div>
            <div class="tt-pc__shine"></div>

            <div class="tt-pc__tier"><?php echo esc_html( $tier_label ); ?></div>

            <div class="tt-pc__photo">
                <?php if ( $photo_url ) : ?>
                    <img src="<?php echo esc_url( $photo_url ); ?>" alt="" loading="lazy" />
                <?php else : ?>
                    <span class="tt-pc__initials"><?php echo esc_html( $initials ); ?></span>
                <?php endif; ?>
            </div>

            <div class="tt-pc__content">
                <div class="tt-pc__top">
                    <div class="tt-pc__rating"><?php echo esc_html( $rating_display ); ?></div>
                    <?php if ( $position !== '' ) : ?>
                        <div class="tt-pc__position"><?php echo esc_html( $position ); ?></div>
                    <?php endif; ?>
                </div>

                <div></div><!-- photo sits absolutely positioned; grid placeholder -->

                <div class="tt-pc__name" title="<?php echo esc_attr( QueryHelpers::player_display_name( $player ) ); ?>">
                    <?php echo esc_html( QueryHelpers::player_display_name( $player ) ); ?>
                </div>

                <div class="tt-pc__stats">
                    <?php foreach ( $stats as $s ) : ?>
                        <div class="tt-pc__stat">
                            <span class="tt-pc__stat-value"><?php echo esc_html( $s['value'] ); ?></span>
                            <span class="tt-pc__stat-label"><?php echo esc_html( $s['label'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( $team_name !== '' ) : ?>
                    <div class="tt-pc__team"><?php echo esc_html( $team_name ); ?></div>
                <?php endif; ?>
            </div>

            <div class="tt-pc__frame"></div>
        </div>
        </a>
        <?php
    }

    // Podium

    /**
     * Render a top-3 podium for a team. Slot 1 in the middle (larger),
     * slot 2 to the left (medium, elevated), slot 3 to the right
     * (medium, elevated). Missing slots render a dashed-outline empty
     * placeholder so the layout doesn't collapse.
     *
     * @param array<int, array{player_id:int, rolling:?float, eval_count:int}> $top
     *        Up to 3 entries, already sorted by rolling average desc.
     */
    public static function renderPodium( array $top ): void {
        // Reorder: 2 | 1 | 3 for the classic podium arrangement.
        $slots = [
            2 => $top[1] ?? null,
            1 => $top[0] ?? null,
            3 => $top[2] ?? null,
        ];
        // v2.16.0: tier is determined by podium position, NOT by the player's
        // rolling average. The #1 player always gets a gold card regardless
        // of their actual rating. This means tier on a podium is a ranking
        // award, not an intrinsic rating — matching how real podiums work.
        $tier_by_rank = [ 1 => 'gold', 2 => 'silver', 3 => 'bronze' ];
        ?>
        <div class="tt-pc-podium">
            <?php foreach ( $slots as $rank => $entry ) : ?>
                <div class="tt-pc-podium__slot tt-pc-podium__slot--<?php echo (int) $rank; ?>">
                    <div class="tt-pc-podium__rank">
                        <?php echo self::ordinalLabel( (int) $rank ); ?>
                    </div>
                    <?php if ( $entry && ! empty( $entry['player_id'] ) ) :
                        $size = $rank === 1 ? 'md' : 'sm';
                        self::renderCard( (int) $entry['player_id'], $size, true, $tier_by_rank[ $rank ] );
                    else : ?>
                        <div class="tt-pc-empty">
                            <?php esc_html_e( 'Not enough ranked players yet', 'talenttrack' ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // Helpers

    /**
     * Tier from rolling average. Returns the token used as the CSS class
     * suffix (tt-pc--gold etc.).
     */
    public static function tierForRating( ?float $rating ): string {
        if ( $rating === null ) return 'none';
        if ( $rating >= self::GOLD_THRESHOLD )   return 'gold';
        if ( $rating >= self::SILVER_THRESHOLD ) return 'silver';
        return 'bronze';
    }

    private static function tierLabel( string $tier ): string {
        switch ( $tier ) {
            case 'gold':    return __( 'Gold', 'talenttrack' );
            case 'silver':  return __( 'Silver', 'talenttrack' );
            case 'bronze':  return __( 'Bronze', 'talenttrack' );
            case 'neutral': return __( 'Player', 'talenttrack' );
            default:        return __( 'Unrated', 'talenttrack' );
        }
    }

    /**
     * Rating display — one decimal, always (so 4 renders as "4.0"), for
     * visual consistency across tiers.
     */
    private static function formatRatingNumber( float $v ): string {
        return number_format_i18n( $v, 1 );
    }

    /**
     * Abbreviate a translated main category label to 3 letters for the
     * stat grid. Respects Dutch: "Technisch" → "TEC", "Fysiek" → "FYS".
     * Falls back to the first 3 uppercase characters of whatever's given.
     */
    private static function statAbbreviation( string $label ): string {
        $known = [
            'Technisch' => 'TEC',
            'Tactisch'  => 'TAC',
            'Fysiek'    => 'FYS',
            'Mentaal'   => 'MEN',
            'Technical' => 'TEC',
            'Tactical'  => 'TAC',
            'Physical'  => 'PHY',
            'Mental'    => 'MEN',
        ];
        if ( isset( $known[ $label ] ) ) return $known[ $label ];
        return strtoupper( mb_substr( $label, 0, 3 ) );
    }

    private static function initialsFromPlayer( object $player ): string {
        $first = isset( $player->first_name ) ? (string) $player->first_name : '';
        $last  = isset( $player->last_name )  ? (string) $player->last_name  : '';
        $fi    = $first !== '' ? mb_substr( $first, 0, 1 ) : '';
        $li    = $last  !== '' ? mb_substr( $last,  0, 1 ) : '';
        $out   = mb_strtoupper( $fi . $li );
        return $out !== '' ? $out : '?';
    }

    /**
     * Resolve the player's photo URL. Plugin stores it as an attachment
     * id on $player->photo_id; fall back to empty if none configured.
     */
    private static function resolvePhotoUrl( object $player ): string {
        $pid = isset( $player->photo_id ) ? (int) $player->photo_id : 0;
        if ( $pid <= 0 ) return '';
        $url = wp_get_attachment_image_url( $pid, 'medium' );
        return $url ? (string) $url : '';
    }

    private static function resolveTeamName( object $player ): string {
        $team_id = isset( $player->team_id ) ? (int) $player->team_id : 0;
        if ( $team_id <= 0 ) return '';
        $team = QueryHelpers::get_team( $team_id );
        return $team ? (string) $team->name : '';
    }

    /**
     * Primary preferred position as a short code (e.g. "LB"). Players
     * store preferred positions as a JSON array of lookup names; we
     * take the first entry.
     */
    private static function resolvePositionAbbr( object $player ): string {
        $raw = isset( $player->preferred_positions ) ? (string) $player->preferred_positions : '';
        if ( $raw === '' ) return '';
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || empty( $decoded ) ) return '';
        $first = (string) $decoded[0];
        // Positions are already short codes in tt_lookups (GK, CB, LB, etc.)
        return strtoupper( mb_substr( $first, 0, 4 ) );
    }

    private static function ordinalLabel( int $rank ): string {
        switch ( $rank ) {
            case 1: return '1st';
            case 2: return '2nd';
            case 3: return '3rd';
            default: return (string) $rank;
        }
    }
}
