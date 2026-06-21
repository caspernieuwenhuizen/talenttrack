<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;

/**
 * TeamCard (#1614) — the "Variant B" rich team card for the teams list.
 *
 * The teams list (`?tt_view=teams`) renders a responsive grid of these
 * cards instead of the old columnar table. Each card is a single `<a>`
 * wrapping the whole tile so the entire surface is one keyboard-focusable
 * tap target (≥48px) that lands on the team detail page.
 *
 * Per card:
 *   - a coloured top accent + a crest (team initials) tinted by age band
 *     — teams carry no logo / colour field, so the accent is derived
 *     deterministically from the age group so the same team always reads
 *     the same colour;
 *   - the team name + a head-coach line ("No head coach yet" when empty);
 *   - a 2-up stat strip: Players + Upcoming (next-14-day activity count).
 *
 * The card markup is built from the values the teams REST row already
 * assembles (`name`, `age_group`, `coach_name`, `player_count`,
 * `upcoming_count`, `detail_url`) — no business logic here, only
 * presentation. `TeamsRestController::fmtRow()` calls `html()` so the
 * fragment ships in the REST payload the way `name_link_html` already
 * does, and the list hydrator emits it verbatim.
 */
final class TeamCard {

    /**
     * Deterministic accent palette keyed by a hash of the age group, so
     * a team's crest / accent colour is stable across reloads without a
     * stored colour field. Mid-saturation tones that clear 3:1 contrast
     * against white text on the crest chip.
     *
     * @var list<string>
     */
    private const PALETTE = [
        '#1e7a52', '#1f5da8', '#a8722a', '#7a3b8f',
        '#b23b4e', '#2c7a7a', '#5a6bb0', '#8a6d1f',
    ];

    /**
     * Build the full card `<a>` fragment for one team.
     *
     * @param array{
     *     id?: int,
     *     name?: string,
     *     age_group?: string,
     *     coach_name?: string,
     *     player_count?: int|null,
     *     upcoming_count?: int|null,
     *     detail_url?: string
     * } $team
     */
    public static function html( array $team ): string {
        $id         = (int) ( $team['id'] ?? 0 );
        $name       = (string) ( $team['name'] ?? '' );
        $age_raw    = (string) ( $team['age_group'] ?? '' );
        $coach      = (string) ( $team['coach_name'] ?? '' );
        $players    = isset( $team['player_count'] ) ? (int) $team['player_count'] : 0;
        $upcoming   = isset( $team['upcoming_count'] ) ? (int) $team['upcoming_count'] : 0;
        $detail_url = (string) ( $team['detail_url'] ?? '' );

        if ( $name === '' ) {
            $name = '#' . $id;
        }
        $age_label = $age_raw !== '' ? LookupTranslator::byTypeAndName( 'age_group', $age_raw ) : '';
        $accent    = self::accentFor( $age_raw !== '' ? $age_raw : $name );
        $initials  = self::initials( $name );

        $coach_line = $coach !== ''
            ? sprintf(
                /* translators: %s is the head coach name(s) for a team */
                __( 'Head coach · %s', 'talenttrack' ),
                $coach
            )
            : __( 'No head coach yet', 'talenttrack' );
        $coach_cls = 'tt-team-card__coach' . ( $coach === '' ? ' is-empty' : '' );

        // Accessible name: team name, plus the age-group label when set.
        // Built from already-translated parts (the age label comes from
        // the lookup translator), so no separate format string is needed.
        $aria = $age_label !== '' ? $name . ', ' . $age_label : $name;

        ob_start();
        ?>
        <a class="tt-team-card" href="<?php echo esc_url( $detail_url ); ?>" aria-label="<?php echo esc_attr( $aria ); ?>">
            <span class="tt-team-card__accent" style="background:<?php echo esc_attr( $accent ); ?>" aria-hidden="true"></span>
            <span class="tt-team-card__inner">
                <span class="tt-team-card__top">
                    <span class="tt-team-card__crest" style="background:<?php echo esc_attr( $accent ); ?>" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
                    <span class="tt-team-card__head">
                        <span class="tt-team-card__name"><?php echo esc_html( $name ); ?></span>
                        <span class="<?php echo esc_attr( $coach_cls ); ?>"><?php echo esc_html( $coach_line ); ?></span>
                    </span>
                </span>
                <span class="tt-team-card__stats">
                    <span class="tt-team-card__stat">
                        <span class="tt-team-card__stat-k"><?php echo esc_html__( 'Players', 'talenttrack' ); ?></span>
                        <span class="tt-team-card__stat-v"><?php echo esc_html( (string) $players ); ?></span>
                    </span>
                    <span class="tt-team-card__stat">
                        <span class="tt-team-card__stat-k"><?php echo esc_html__( 'Upcoming', 'talenttrack' ); ?></span>
                        <span class="tt-team-card__stat-v"><?php echo esc_html( (string) $upcoming ); ?></span>
                    </span>
                </span>
            </span>
        </a>
        <?php
        return trim( (string) ob_get_clean() );
    }

    /**
     * Up to two uppercase initials from a team name, e.g. "Ajax U17" → "AU".
     */
    private static function initials( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) return '?';
        $words = preg_split( '/\s+/', $name ) ?: [];
        $out   = '';
        foreach ( $words as $word ) {
            $first = mb_substr( $word, 0, 1 );
            if ( $first === '' ) continue;
            $out .= mb_strtoupper( $first );
            if ( mb_strlen( $out ) >= 2 ) break;
        }
        if ( $out === '' ) {
            $out = mb_strtoupper( mb_substr( $name, 0, 2 ) );
        }
        return $out;
    }

    /**
     * Deterministic accent colour from a seed (age group, falling back to
     * the team name). Same seed → same colour on every render.
     */
    private static function accentFor( string $seed ): string {
        $seed = $seed !== '' ? $seed : 'team';
        $idx  = hexdec( substr( md5( $seed ), 0, 8 ) ) % count( self::PALETTE );
        return self::PALETTE[ $idx ];
    }
}
