<?php
namespace TT\Modules\Journey\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\SuspensionRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\EmptyStateCard;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * PlayerSuspensionsTab (#4103) — the Suspensions tab on a player's
 * profile. A panel composed into `FrontendPlayerDetailView`, not a view of
 * its own, so it emits no navigation (CLAUDE.md §5).
 *
 * The player and their parents read it too, reason and note included: a
 * suspension is an official sanction (decision 5). The Record button is
 * offered only to someone who may record one for this player.
 */
final class PlayerSuspensionsTab {

    public static function render( int $player_id, int $user_id ): void {
        if ( ! AuthorizationService::canAccessSuspensions( $user_id, $player_id, 'read' ) ) {
            EmptyStateCard::render( [
                'headline'  => __( 'Suspensions are not in your scope', 'talenttrack' ),
                'explainer' => __( 'A player\'s suspensions are kept with the coaches of their team. Talk to your academy admin if you should have access.', 'talenttrack' ),
            ] );
            return;
        }

        $can_write = AuthorizationService::canAccessSuspensions( $user_id, $player_id, 'change' );
        $add_url   = $can_write ? self::addUrl( $player_id ) : '';
        $rows      = ( new SuspensionRepository() )->listForPlayer( $player_id );

        if ( $rows === [] ) {
            EmptyStateCard::render( array_filter( [
                'headline'  => __( 'No suspensions recorded', 'talenttrack' ),
                'explainer' => __( 'A suspension recorded here marks the player unavailable for exactly the matches it covers, and shows on their journey.', 'talenttrack' ),
                'cta_label' => $add_url !== '' ? __( 'Record suspension', 'talenttrack' ) : null,
                'cta_url'   => $add_url !== '' ? $add_url : null,
            ] ) );
            return;
        }

        echo '<div class="tt-player-card">';
        echo '<div class="tt-player-card__head">';
        echo '<h3 class="tt-player-card__title">' . esc_html_x( 'Suspensions', 'disciplinary record', 'talenttrack' ) . '</h3>';
        if ( $add_url !== '' ) {
            echo '<a class="tt-btn tt-btn-primary tt-btn-small" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Record suspension', 'talenttrack' ) . '</a>';
        }
        echo '</div>';
        echo '<div class="tt-player-card__body">';
        SuspensionCards::render( $rows, $user_id, false );
        echo '</div>';
        echo '</div>';
    }

    /**
     * The wizard when it is available, the flat form otherwise. Both carry
     * the player, so the wizard skips its first step.
     */
    public static function addUrl( int $player_id ): string {
        $flat = BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'suspensions', 'action' => 'new', 'player_id' => $player_id ],
            RecordLink::dashboardUrl()
        ) );
        return WizardEntryPoint::urlFor( 'new-suspension', $flat, [ 'player_id' => $player_id ] );
    }
}
