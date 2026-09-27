<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Journey\SuspensionService;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * SuspensionCards (#4103) — one card per suspension, shared by the squad
 * overview and the player's Suspensions tab so the two read alike.
 *
 * Composition only: the state ("1 of 3 served", "Served on …") comes from
 * `SuspensionService::status()`, the same call the REST response makes.
 * The Edit link is offered per card against the per-player gate.
 */
final class SuspensionCards {

    public static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-suspensions',
            TT_PLUGIN_URL . 'assets/css/frontend-suspensions.css',
            [],
            TT_VERSION
        );
    }

    /**
     * @param list<array<string, mixed>> $rows        From SuspensionRepository.
     * @param bool                       $show_player Name the player (squad overview).
     */
    public static function render( array $rows, int $user_id, bool $show_player ): void {
        self::enqueue();

        echo '<ul class="tt-suspension-list">';
        foreach ( $rows as $row ) {
            self::card( $row, $user_id, $show_player );
        }
        echo '</ul>';
    }

    /** @param array<string, mixed> $row */
    private static function card( array $row, int $user_id, bool $show_player ): void {
        $id        = (int) ( $row['id'] ?? 0 );
        $player_id = (int) ( $row['player_id'] ?? 0 );
        $status    = SuspensionService::status( $row );
        $served    = $status['state'] === 'served';
        $reason    = SuspensionRepository::reasonLabel( (int) ( $row['reason_lookup_id'] ?? 0 ) );
        $started   = (string) ( $row['started_on'] ?? '' );
        $notes     = (string) ( $row['notes'] ?? '' );

        $title = $reason !== '' ? $reason : _x( 'Suspension', 'disciplinary record', 'talenttrack' );
        if ( $show_player ) {
            $name  = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            $title = ( $name !== '' ? $name : '#' . $player_id ) . ' · ' . $title;
        }

        echo '<li class="tt-suspension-card' . ( $served ? ' tt-suspension-card--served' : '' ) . '">';

        echo '<div class="tt-suspension-card__head">';
        echo '<h3 class="tt-suspension-card__title">';
        if ( $show_player ) {
            $url = RecordLink::detailUrlForWithBack( 'players', $player_id ); /* tt-xview-ok — the overview lists only teams the viewer reads, and their players' files with them */
            echo $url !== ''
                ? '<a href="' . esc_url( add_query_arg( 'tab', 'suspensions', $url ) ) . '">' . esc_html( $title ) . '</a>'
                : esc_html( $title );
        } else {
            echo esc_html( $title );
        }
        echo '</h3>';
        echo '<span class="tt-suspension-state' . ( $served ? ' tt-suspension-state--served' : '' ) . '">'
            . esc_html( self::stateLabel( $status ) ) . '</span>';
        echo '</div>';

        $meta = [];
        $meta[] = sprintf(
            /* translators: %d: number of matches */
            _n( '%d match', '%d matches', $status['of'], 'talenttrack' ),
            $status['of']
        );
        if ( $started !== '' ) {
            /* translators: %s: a date */
            $meta[] = sprintf( __( 'counts from %s', 'talenttrack' ), TTDate::date( $started ) );
        }
        if ( $show_player && ! empty( $row['team_name'] ) ) {
            $meta[] = (string) $row['team_name'];
        }
        echo '<p class="tt-suspension-card__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';

        if ( $notes !== '' ) {
            echo '<p class="tt-suspension-card__notes">' . esc_html( $notes ) . '</p>';
        }

        if ( $id > 0 && AuthorizationService::canAccessSuspensions( $user_id, $player_id, 'change' ) ) {
            $edit = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => 'suspensions', 'action' => 'edit', 'id' => $id ],
                RecordLink::dashboardUrl()
            ) );
            echo '<div class="tt-suspension-card__actions">';
            echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'talenttrack' ) . '</a>';
            echo '</div>';
        }

        echo '</li>';
    }

    /**
     * @param array{state:string, served:int, of:int, served_on:string} $status
     */
    public static function stateLabel( array $status ): string {
        if ( $status['state'] === 'served' ) {
            return $status['served_on'] !== ''
                /* translators: %s: a date */
                ? sprintf( __( 'Served on %s', 'talenttrack' ), TTDate::date( $status['served_on'] ) )
                : _x( 'Served', 'suspension state', 'talenttrack' );
        }
        /* translators: 1: matches already served, 2: matches in total */
        return sprintf( __( '%1$d of %2$d served', 'talenttrack' ), $status['served'], $status['of'] );
    }
}
