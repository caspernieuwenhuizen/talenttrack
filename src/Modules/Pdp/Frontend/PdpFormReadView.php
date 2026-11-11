<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Forms\PdpFormSummary;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PdpFormReadView (#4981, epic #4975) — a signed-off conversation form, as
 * the player and the family read it in My PDP. Composed into the
 * conversation's panel; not a view of its own.
 *
 * What may be read is `PdpFormReader`'s answer for this reader, so the
 * rules of `PdpFormAccess` hold: nothing before sign-off, and for a parent
 * only while the child shares the PDP. Each section is a fold with both
 * averages in its title; each item shows both scores; a player who did not
 * prepare reads "not filled in" in their column (decision 5).
 */
final class PdpFormReadView {

    /**
     * Draw the signed form for the current reader. False when the talk has
     * no form or this reader may not read both parts of it yet.
     */
    public static function render( int $conv_id, ?int $parent_view_player_id = null ): bool {
        $access = PdpFormAccess::forConversation( get_current_user_id(), $conv_id );
        if ( $access === null || ! $access->canRead() ) return false;
        $form = ( new PdpFormReader() )->read( $access, $access->conversation() );
        if ( $form['form'] === null || ! is_array( $form['player'] ) || ! is_array( $form['coach'] ) ) return false;
        if ( ( $form['state']['coach_signoff_at'] ?? null ) === null ) return false;

        $definition = (array) $form['form']['definition'];
        $player     = $form['player'];
        $coach      = $form['coach'];
        $prepared   = ( $form['state']['player_prepared_at'] ?? null ) !== null;
        $labels     = [];
        for ( $i = 1; $i <= 5; $i++ ) $labels[ $i ] = (string) ( $definition['scale'][ $i ]['label'] ?? (string) $i );

        $theirs = PdpFormSummary::of( $definition, $player['scores'], $player['answers'], 'player' );
        $mine   = PdpFormSummary::of( $definition, $coach['scores'], $coach['answers'], 'coach' );
        $avg    = static function ( ?float $v ): string {
            return $v === null ? '—' : number_format_i18n( $v, 1 );
        };

        echo '<div class="tt-pdp-formread">';
        foreach ( (array) $definition['sections'] as $i => $section ) {
            $p_avg = $prepared ? $avg( $theirs['sections'][ $i ]['average'] ?? null ) : __( 'not filled in', 'talenttrack' );
            echo '<details class="tt-pdp-formread__section"' . ( $i === 0 ? ' open' : '' ) . '>';
            echo '<summary class="tt-pdp-formread__sum"><span class="tt-pdp-formread__stitle">' . esc_html( (string) $section['title'] ) . '</span>'
                . '<span class="tt-pdp-formread__savg">' . esc_html( sprintf(
                    /* translators: 1: the player's average for the section, 2: the trainer's */
                    __( 'Player %1$s · Trainer %2$s', 'talenttrack' ),
                    $p_avg,
                    $avg( $mine['sections'][ $i ]['average'] ?? null )
                ) ) . '</span></summary>';
            echo '<ul class="tt-pdp-formread__items">';
            foreach ( (array) $section['items'] as $item ) {
                $key = (string) $item['key'];
                $p   = $player['scores'][ $key ] ?? null;
                $c   = $coach['scores'][ $key ] ?? null;
                echo '<li class="tt-pdp-formread__item">';
                echo '<span class="tt-pdp-formread__iname">' . esc_html( (string) $item['label'] ) . '</span>';
                echo '<span class="tt-pdp-formread__player">' . esc_html( sprintf(
                    /* translators: %s: the player's score and its label, or "not filled in" */
                    __( 'Player: %s', 'talenttrack' ),
                    ( ! $prepared || $p === null ) ? __( 'not filled in', 'talenttrack' ) : (int) $p . ' · ' . ( $labels[ (int) $p ] ?? '' )
                ) ) . '</span>';
                echo '<span class="tt-pdp-formread__coach">' . esc_html( sprintf(
                    /* translators: %s: the trainer's score and its label */
                    __( 'Trainer: %s', 'talenttrack' ),
                    $c === null ? '—' : (int) $c . ' · ' . ( $labels[ (int) $c ] ?? '' )
                ) ) . '</span>';
                echo '</li>';
            }
            echo '</ul>';
            $note = trim( (string) ( $coach['answers'][ 'notes.' . (string) $section['key'] ] ?? '' ) );
            if ( $note !== '' ) {
                echo '<p class="tt-pdp-formread__note"><strong>' . esc_html__( 'Trainer\'s notes:', 'talenttrack' ) . '</strong> ' . nl2br( esc_html( $note ) ) . '</p>';
            }
            echo '</details>';
        }

        $prompts = [];
        foreach ( (array) $definition['prompts'] as $prompt ) $prompts[ (string) $prompt['key'] ] = $prompt;
        foreach ( PdpFormRegistry::sharedFields( $definition ) as $field ) {
            $agreed = trim( (string) ( $coach['answers'][ $field ] ?? '' ) );
            $wrote  = trim( (string) ( $player['answers'][ $field ] ?? '' ) );
            echo '<h4 class="tt-pdp-formread__h">' . esc_html( (string) ( $prompts[ $field ]['label'] ?? $field ) ) . '</h4>';
            echo '<p class="tt-pdp-formread__agreed">' . nl2br( esc_html( $agreed ) ) . '</p>';
            echo '<p class="tt-pdp-formread__wrote">' . esc_html( sprintf(
                /* translators: %s: what the player wrote, or "not filled in" */
                __( 'The player wrote: %s', 'talenttrack' ),
                ( ! $prepared || $wrote === '' ) ? __( 'not filled in', 'talenttrack' ) : $wrote
            ) ) . '</p>';
        }

        $goals = is_array( $form['goals'] ) ? $form['goals'] : [];
        if ( $goals !== [] ) {
            echo '<h4 class="tt-pdp-formread__h">' . esc_html__( 'Development points', 'talenttrack' ) . '</h4>';
            echo '<ol class="tt-pdp-formread__goals">';
            foreach ( $goals as $goal ) {
                echo '<li class="tt-pdp-formread__goal">';
                echo '<p class="tt-pdp-formread__action">' . esc_html( (string) $goal['action'] ) . '</p>';
                if ( (string) $goal['support'] !== '' ) {
                    echo '<p class="tt-pdp-formread__support">' . esc_html( sprintf(
                        /* translators: %s: how the trainer helps */
                        __( 'The trainer helps: %s', 'talenttrack' ),
                        (string) $goal['support']
                    ) ) . '</p>';
                }
                if ( ! empty( $goal['due_date'] ) ) {
                    echo '<p class="tt-pdp-formread__due">' . esc_html( sprintf(
                        /* translators: %s: the development point's deadline */
                        __( 'Deadline: %s', 'talenttrack' ),
                        TTDate::date( (string) $goal['due_date'] )
                    ) ) . '</p>';
                }
                $url = ! empty( $goal['goal_id'] ) ? RecordLink::meDetailUrl( 'my-goals', (int) $goal['goal_id'], $parent_view_player_id ) : '';
                if ( $url !== '' ) {
                    echo '<a class="tt-pdp-formread__goallink" href="' . esc_url( $url ) . '">' . esc_html__( 'Open the goal', 'talenttrack' ) . '</a>';
                }
                echo '</li>';
            }
            echo '</ol>';
        }
        echo '</div>';
        return true;
    }
}
