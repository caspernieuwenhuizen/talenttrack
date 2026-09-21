<?php
namespace TT\Modules\Players\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\Players\Services\ScoutPlayerCard;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendScoutPlayerCardView (#3807) — `?tt_view=scout-player-card&id=N`.
 *
 * The rendered half of the scout card. A scout comparing a trialist
 * against the squad the club already has could read a squad player's
 * minutes share and not one line about who that player is; every
 * per-player screen and route refused them.
 *
 * Composition only. Every field, and the entitlement behind them, comes
 * from `ScoutPlayerCard`, so this screen and
 * `GET /players/{id}/scout-card` cannot answer differently — and a
 * reviewer has one file to check when asking what a scout may read about
 * a child (CLAUDE.md §4).
 *
 * Two navigation affordances and no more (CLAUDE.md §5a): the breadcrumb
 * chain, and the `tt_back` pill when the entry URL carried one. The card
 * is a leaf — there is nothing below it to navigate to.
 */
class FrontendScoutPlayerCardView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin = false ): void {
        $title = __( 'Player card', 'talenttrack' );

        $player_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $player_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'No player was named.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( ! ScoutPlayerCard::canRead( $user_id, $player_id ) ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You are not linked to this player. Ask the head of development to add them to your list, or to put you on their panel.', 'talenttrack' ) . '</p>';
            return;
        }

        $card = ScoutPlayerCard::forPlayer( $player_id, $user_id );
        if ( $card === null ) {
            FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard(
            (string) $card['name'],
            [ FrontendBreadcrumbs::viewCrumb( 'scout-my-players', __( 'My players', 'talenttrack' ) ) ]
        );
        self::renderHeader( (string) $card['name'] );

        echo '<div class="tt-scout-card">';
        self::renderFacts( $card );
        self::renderMinutes( $card );
        self::renderObservations( $card );
        echo '<p class="tt-scout-card__scope">' . esc_html__( 'This card is what a scout may read about a squad player: who they are, where they play, how much they play and where the club has them. Evaluations, measurements, family contact and anything medical stay with the coaching staff.', 'talenttrack' ) . '</p>';
        echo '</div>';
    }

    /** @param array<string,mixed> $card */
    private static function renderFacts( array $card ): void {
        $rows = [
            __( 'Birth year', 'talenttrack' ) => $card['birth_year'] !== null ? (string) $card['birth_year'] : '—',
            __( 'Team', 'talenttrack' )       => (string) ( $card['team_name'] ?? '' ) !== '' ? (string) $card['team_name'] : '—',
            __( 'Position', 'talenttrack' )   => (string) ( $card['position'] ?? '' ) !== '' ? (string) $card['position'] : '—',
            __( 'Status', 'talenttrack' )     => LabelTranslator::playerStatus( (string) ( $card['status'] ?? '' ) ),
        ];

        echo '<dl class="tt-scout-card__facts">';
        foreach ( $rows as $label => $value ) {
            echo '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
        }
        echo '</dl>';
    }

    /** @param array<string,mixed> $card */
    private static function renderMinutes( array $card ): void {
        echo '<section class="tt-scout-card__minutes">';
        echo '<h2>' . esc_html__( 'Minutes share', 'talenttrack' ) . '</h2>';

        $share = is_array( $card['minutes_share'] ?? null ) ? $card['minutes_share'] : null;
        if ( $share === null ) {
            // "Not measured" and "measured at zero" are different answers,
            // and a scout comparing squads needs to be able to tell.
            echo '<p class="tt-player-empty">' . esc_html__( 'No minutes recorded for this player in the last year.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }

        $pct     = $share['share_pct'] ?? null;
        $minutes = $share['minutes'] ?? null;
        echo '<p>' . esc_html( sprintf(
            /* translators: 1: percentage of the team's available minutes, 2: minutes played, 3: window start date, 4: window end date. */
            __( '%1$s%% of the team\'s available minutes — %2$s minutes between %3$s and %4$s.', 'talenttrack' ),
            $pct === null ? '—' : (string) round( (float) $pct, 1 ),
            $minutes === null ? '—' : (string) (int) $minutes,
            (string) ( $share['from'] ?? '' ),
            (string) ( $share['to'] ?? '' )
        ) ) . '</p>';
        echo '</section>';
    }

    /** @param array<string,mixed> $card */
    private static function renderObservations( array $card ): void {
        $observations = is_array( $card['observations'] ?? null ) ? $card['observations'] : [];

        echo '<section class="tt-scout-card__observations">';
        echo '<h2>' . esc_html__( 'Your observations', 'talenttrack' ) . '</h2>';

        if ( $observations === [] ) {
            echo '<p class="tt-player-empty">' . esc_html__( 'You have not recorded an observation of this player.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }

        echo '<ul class="tt-scout-card__observation-list">';
        foreach ( $observations as $row ) {
            $when  = (string) ( $row['observed_at'] ?? '' );
            $where = (string) ( $row['location'] ?? '' );
            $notes = (string) ( $row['notes'] ?? '' );
            echo '<li><strong>' . esc_html( $when !== '' ? $when : __( 'Undated', 'talenttrack' ) ) . '</strong>';
            if ( $where !== '' ) echo ' <span class="tt-meta">' . esc_html( $where ) . '</span>';
            if ( $notes !== '' ) echo '<p>' . esc_html( $notes ) . '</p>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</section>';
    }

    /**
     * The card's URL, so callers do not hand-build the query string.
     *
     * `withBack()` because a scout arrives here from somewhere — their
     * player list, a trial case — and §5's pill is how they get back.
     */
    public static function urlFor( int $player_id, bool $with_back = true ): string {
        return $with_back
            ? RecordLink::detailUrlForWithBack( 'scout-player-card', $player_id )
            : RecordLink::detailUrlFor( 'scout-player-card', $player_id );
    }
}
