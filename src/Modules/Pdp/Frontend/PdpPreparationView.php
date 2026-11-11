<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Forms\PdpFormSummary;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\FormAutosave;
use TT\Shared\Frontend\Components\RatingInputComponent;
use TT\Shared\Frontend\Components\SaveState;

/**
 * PdpPreparationView (#4979, epic #4975) — the player prepares a form
 * conversation in My PDP. Composed into `FrontendMyPdpView`; not a view of
 * its own.
 *
 * Not a wizard: nothing is created (CLAUDE.md §3). It is a composing surface
 * (save model A): one step per section and one for the strongest quality,
 * every score and answer autosaved to `PATCH …/form/player`. "Klaar" is its
 * own control, `PUT …/form/player/preparation`, never a field the debounce
 * sends, and "Toch aanpassen" is the `DELETE` while the part is unlocked.
 *
 * What the player may do comes from `PdpFormReader` (and `PdpCycleState`
 * behind it): whether the window is open, when it opens, whether the part
 * is locked, whether it is marked ready. The view only draws that state.
 */
final class PdpPreparationView {

    /**
     * Draw the preparation for the next talk, when it uses a conversation
     * form. False when it does not, so the caller draws the free-text
     * reflection instead. This view is the player's own: for anyone else it
     * draws nothing and still answers true, so no reflection appears in its
     * place. Staff read the player's part elsewhere, once it is marked ready
     * (the conversation screen and `GET …/form`); a parent reads it only
     * after sign-off.
     *
     * @param array<string,mixed>|null $conv the next talk, from `PdpFamilyReader`
     */
    public static function render( ?array $conv, bool $is_self ): bool {
        if ( $conv === null ) return false;
        $access = PdpFormAccess::forConversation( get_current_user_id(), (int) $conv['id'] );
        if ( $access === null || ! $access->canRead() ) {
            // Not the player, nor staff: decide by whether the talk uses a
            // form at all, without reading anything of it.
            return $access !== null && \TT\Modules\Pdp\Forms\PdpFormChoice::formFor( $access->conversation(), $access->playerId() ) !== null;
        }
        $form = ( new PdpFormReader() )->read( $access, $access->conversation() );
        if ( $form['form'] === null ) return false;
        if ( ! $is_self || ! $access->isPlayer() ) return true;

        self::enqueue();
        $definition = (array) $form['form']['definition'];
        $state      = (array) $form['state'];
        $player     = is_array( $form['player'] ) ? $form['player'] : [ 'scores' => [], 'answers' => [] ];
        $id         = (int) $form['conversation_id'];
        $scheduled  = (string) ( $conv['scheduled_at'] ?? '' );

        echo '<section class="tt-card tt-pdp-prep" aria-labelledby="tt-pdp-prep-h-' . $id . '">';
        echo '<p class="tt-eyebrow">' . esc_html__( 'Preparing for your talk', 'talenttrack' ) . '</p>';
        echo '<h2 class="tt-pdp-prep__h" id="tt-pdp-prep-h-' . $id . '">' . esc_html__( 'Prepare your development talk', 'talenttrack' ) . '</h2>';
        if ( $scheduled !== '' ) {
            echo '<p class="tt-pdp-prep__meta">' . esc_html( (string) $conv['template_label'] . ' · ' . TTDate::dateTimeFromGmt( $scheduled ) ) . '</p>';
        }

        $opens_at = $state['preparation_opens_at'] ?? null;
        if ( $opens_at === null ) {
            echo '<p class="tt-pdp-prep__guard">' . esc_html__( 'You can prepare once the conversation is planned.', 'talenttrack' ) . '</p>';
        } elseif ( ! $state['preparation_open'] ) {
            echo '<p class="tt-pdp-prep__guard">' . esc_html( sprintf(
                /* translators: %s: the date the player's preparation opens */
                __( 'You can prepare from %s.', 'talenttrack' ),
                // `opens_at` is UTC; the date the player reads is the site's.
                TTDate::date( get_date_from_gmt( (string) $opens_at ) )
            ) ) . '</p>';
        } elseif ( ! empty( $state['player_locked'] ) ) {
            echo '<p class="tt-pdp-prep__guard">' . esc_html__( 'The conversation has started, so your part of the form can no longer be changed.', 'talenttrack' ) . '</p>';
            self::renderSummary( $definition, $player, null );
        } elseif ( ( $state['player_prepared_at'] ?? null ) !== null ) {
            self::renderReady( $id, $definition, $player, $scheduled );
        } else {
            self::renderSteps( $id, $definition, $player );
        }

        echo '</section>';
        return true;
    }

    private static function enqueue(): void {
        FormAutosave::enqueue();
        RatingInputComponent::enqueue();
        wp_enqueue_script(
            'tt-pdp-preparation',
            TT_PLUGIN_URL . 'assets/js/components/pdp-preparation.js',
            [ 'tt-form-autosave', 'tt-rating-input' ],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-pdp-preparation', 'TT_PdpPreparation', [
            'i18n' => [
                /* translators: %s: the name of the next step */
                'next'      => __( 'Next: %s', 'talenttrack' ),
                'saving'    => __( 'Saving your preparation…', 'talenttrack' ),
                'failed'    => __( 'Your preparation could not be marked ready. Try again.', 'talenttrack' ),
                'reopenErr' => __( 'Your preparation could not be reopened. Try again.', 'talenttrack' ),
                'closed'    => __( 'Your preparation can no longer be saved. Reload the page to see why.', 'talenttrack' ),
                'unsaved'   => __( 'Your last change has not been saved yet. Check your connection and try Ready again.', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * The stepped form: a step per section, then the text fields.
     *
     * @param array<string,mixed>                                                   $definition
     * @param array{scores:array<string,int|null>, answers:array<string,string>} $player
     */
    private static function renderSteps( int $id, array $definition, array $player ): void {
        $steps = [];
        foreach ( (array) $definition['sections'] as $section ) {
            $steps[] = self::stepLabel( (string) $section['main'], (string) $section['title'] );
        }
        $fields = self::playerFields( $definition );
        if ( $fields !== [] ) $steps[] = _x( 'Strength', 'PDP preparation step: the strongest quality', 'talenttrack' );

        $path = 'pdp-conversations/' . $id . '/form/player';
        // Wired by pdp-preparation.js rather than FormAutosave's own
        // bootstrap, so the "klaar" control can flush the pending save first.
        echo '<form class="tt-pdp-prep__form" data-tt-pdp-prep'
            . ' data-rest-path="' . esc_attr( $path ) . '" data-rest-method="PATCH"'
            . ' data-autosave-key="' . esc_attr( 'pdp-prep:' . $id ) . '"'
            . ' data-prepared-path="' . esc_attr( $path . '/preparation' ) . '">';

        echo '<ol class="tt-pdp-prep__progress" aria-label="' . esc_attr__( 'Steps', 'talenttrack' ) . '">';
        foreach ( $steps as $i => $label ) {
            echo '<li class="tt-pdp-prep__pstep"><button type="button" class="tt-pdp-prep__pbtn" data-tt-prep-goto="' . (int) $i . '"'
                . ( $i === 0 ? ' aria-current="step"' : '' ) . '>'
                . '<span class="tt-pdp-prep__pbar" aria-hidden="true"></span>'
                . '<span class="tt-pdp-prep__plabel">' . esc_html( $label ) . '</span></button></li>';
        }
        echo '</ol>';

        echo '<p class="tt-pdp-prep__intro">' . esc_html__( 'Give yourself a score from 1 to 5 for each item. Your trainer sees your scores once you mark your preparation as ready. You can change everything until the conversation starts.', 'talenttrack' ) . '</p>';
        // #5081 — the same row clears for the player as for the trainer.
        echo '<p class="tt-help-text tt-pdp-prep__clearhint">' . esc_html__( 'Tap your score again, or choose Clear, to empty it.', 'talenttrack' ) . '</p>';
        self::renderLegend( $definition );

        $labels = self::scaleLabels( $definition );
        $step   = 0;
        foreach ( (array) $definition['sections'] as $section ) {
            echo '<fieldset class="tt-pdp-prep__step" data-tt-prep-step="' . (int) $step . '">';
            echo '<legend class="tt-pdp-prep__sh">' . esc_html( ( $step + 1 ) . ' · ' . (string) $section['title'] ) . '</legend>';
            foreach ( (array) $section['items'] as $item ) {
                $key   = (string) $item['key'];
                $score = $player['scores'][ $key ] ?? null;
                echo '<div class="tt-pdp-prep__item">';
                echo '<p class="tt-pdp-prep__iname">' . esc_html( (string) $item['label'] ) . '</p>';
                echo '<p class="tt-pdp-prep__idef">' . esc_html( (string) $item['definition'] ) . '</p>';
                echo RatingInputComponent::renderListRow( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes its own output.
                    'name'           => 'scores[' . $key . ']',
                    'value'          => $score,
                    'label'          => sprintf(
                        /* translators: %s: the item being scored */
                        __( 'My score for %s', 'talenttrack' ),
                        (string) $item['label']
                    ),
                    'label_hidden'   => true,
                    'min'            => 1,
                    'max'            => 5,
                    'step'           => 1,
                    'variant'        => RatingInputComponent::VARIANT_CHOICES,
                    'labels'         => $labels,
                    'empty_text'     => __( 'Not filled in yet', 'talenttrack' ),
                    /* translators: 1: the score, 2: its label in the form's scale */
                    'readout_format' => __( 'Your score: %1$s · %2$s', 'talenttrack' ),
                ] );
                echo '</div>';
            }
            echo '</fieldset>';
            $step++;
        }

        if ( $fields !== [] ) {
            echo '<fieldset class="tt-pdp-prep__step" data-tt-prep-step="' . (int) $step . '">';
            echo '<legend class="tt-pdp-prep__sh">' . esc_html( ( $step + 1 ) . ' · ' . (string) $fields[0]['label'] ) . '</legend>';
            foreach ( $fields as $field ) {
                $input = 'tt-pdp-prep-' . $id . '-' . preg_replace( '/[^a-z0-9_-]/', '-', (string) $field['key'] );
                echo '<div class="tt-field">';
                echo '<label class="tt-field-label" for="' . esc_attr( $input ) . '">' . esc_html( (string) $field['label'] ) . '</label>';
                if ( (string) $field['question'] !== '' ) {
                    echo '<p class="tt-help-text" id="' . esc_attr( $input . '-help' ) . '">' . esc_html( (string) $field['question'] ) . '</p>';
                }
                echo '<textarea class="tt-input" id="' . esc_attr( $input ) . '" name="' . esc_attr( 'answers[' . $field['key'] . ']' ) . '" rows="4" inputmode="text"'
                    . ( (string) $field['question'] !== '' ? ' aria-describedby="' . esc_attr( $input . '-help' ) . '"' : '' ) . '>'
                    . esc_textarea( (string) ( $player['answers'][ (string) $field['key'] ] ?? '' ) ) . '</textarea>';
                echo '</div>';
            }
            echo '</fieldset>';
        }

        echo '<div class="tt-pdp-prep__nav">';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-prep-back>' . esc_html__( 'Back', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-prep-next>' . esc_html( sprintf(
            /* translators: %s: the name of the next step */
            __( 'Next: %s', 'talenttrack' ),
            $steps[1] ?? ''
        ) ) . '</button>';
        // Its own control: never a field, so no debounce can send it.
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-prep-done>' . esc_html_x( 'Ready', 'PDP preparation: mark it finished', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '<div class="tt-form-actions tt-pdp-prep__save">';
        SaveState::render();
        echo '</div>';
        echo '<div class="tt-form-msg" role="status" aria-live="polite"></div>';
        echo '</form>';
    }

    /**
     * Marked ready, still unlocked: the summary, and the way back in.
     *
     * @param array<string,mixed>                                                   $definition
     * @param array{scores:array<string,int|null>, answers:array<string,string>} $player
     */
    private static function renderReady( int $id, array $definition, array $player, string $scheduled ): void {
        $summary = PdpFormSummary::of( $definition, $player['scores'], $player['answers'], 'player' );
        echo '<div class="tt-pdp-prep__ready">';
        echo '<p class="tt-pdp-prep__readyh"><span class="tt-pdp-prep__tick" aria-hidden="true">&#10003;</span> ' . esc_html__( 'Your preparation is ready', 'talenttrack' ) . '</p>';
        echo '<p class="tt-pdp-prep__readycount">' . esc_html( sprintf(
            /* translators: 1: items scored, 2: items on the form */
            _n( '%1$d of %2$d item scored', '%1$d of %2$d items scored', $summary['items'], 'talenttrack' ),
            $summary['scored'],
            $summary['items']
        ) ) . '</p>';
        echo '<p class="tt-pdp-prep__readytext">' . esc_html( $scheduled !== ''
            ? sprintf(
                /* translators: %s: date and time of the talk */
                __( 'Your talk is on %s. Your trainer can now read your scores. You can still change everything until the conversation starts.', 'talenttrack' ),
                TTDate::dateTimeFromGmt( $scheduled )
            )
            : __( 'Your trainer can now read your scores. You can still change everything until the conversation starts.', 'talenttrack' )
        ) . '</p>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-prep-reopen="' . esc_attr( 'pdp-conversations/' . $id . '/form/player/preparation' ) . '">'
            . esc_html__( 'Change it after all', 'talenttrack' ) . '</button>';
        echo '<div class="tt-form-msg" role="status" aria-live="polite"></div>';
        echo '</div>';
        self::renderSummary( $definition, $player, $summary );
    }

    /**
     * The player's scores in short, per section, and their text answers.
     *
     * @param array<string,mixed>                                                   $definition
     * @param array{scores:array<string,int|null>, answers:array<string,string>} $player
     * @param array<string,mixed>|null                                              $summary
     */
    private static function renderSummary( array $definition, array $player, ?array $summary ): void {
        $summary = $summary ?? PdpFormSummary::of( $definition, $player['scores'], $player['answers'], 'player' );
        echo '<h3 class="tt-pdp-prep__sumh">' . esc_html__( 'My scores in short', 'talenttrack' ) . '</h3>';
        echo '<ul class="tt-pdp-prep__sum">';
        foreach ( (array) $summary['sections'] as $section ) {
            echo '<li class="tt-pdp-prep__sumrow">';
            echo '<span class="tt-pdp-prep__sumname">' . esc_html( (string) $section['title'] ) . '</span>';
            echo '<span class="tt-pdp-prep__sumcount">' . esc_html( sprintf(
                /* translators: 1: items scored, 2: items in the section */
                __( '%1$d of %2$d', 'talenttrack' ),
                (int) $section['scored'],
                (int) $section['items']
            ) ) . '</span>';
            echo '<span class="tt-pdp-prep__sumavg">' . esc_html( $section['average'] !== null ? number_format_i18n( (float) $section['average'], 1 ) : '—' ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        foreach ( self::playerFields( $definition ) as $field ) {
            $text = trim( (string) ( $player['answers'][ (string) $field['key'] ] ?? '' ) );
            echo '<h3 class="tt-pdp-prep__sumh">' . esc_html( (string) $field['label'] ) . '</h3>';
            echo '<p class="tt-pdp-prep__answer">' . ( $text !== '' ? nl2br( esc_html( $text ) ) : '<em>' . esc_html__( 'Not filled in yet', 'talenttrack' ) . '</em>' ) . '</p>';
        }
    }

    /**
     * The form's scale as "1 · Label · hint" lines, behind a toggle.
     *
     * @param array<string,mixed> $definition
     */
    private static function renderLegend( array $definition ): void {
        echo '<details class="tt-pdp-prep__legend">';
        echo '<summary>' . esc_html__( 'What do the scores mean?', 'talenttrack' ) . '</summary>';
        echo '<ul>';
        foreach ( (array) $definition['scale'] as $value => $point ) {
            $hint = (string) ( $point['hint'] ?? '' );
            echo '<li><strong>' . (int) $value . '</strong> ' . esc_html( (string) $point['label'] . ( $hint !== '' ? ' · ' . $hint : '' ) ) . '</li>';
        }
        echo '</ul>';
        echo '</details>';
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<string> the scale's labels, 1 first
     */
    private static function scaleLabels( array $definition ): array {
        $out = [];
        for ( $i = 1; $i <= 5; $i++ ) {
            $out[] = (string) ( $definition['scale'][ $i ]['label'] ?? (string) $i );
        }
        return $out;
    }

    /**
     * The text prompts the player answers, in form order.
     *
     * @param array<string,mixed> $definition
     * @return list<array<string,mixed>>
     */
    private static function playerFields( array $definition ): array {
        $keys = PdpFormRegistry::answerFields( $definition, 'player' );
        $out  = [];
        foreach ( (array) $definition['prompts'] as $prompt ) {
            if ( in_array( (string) $prompt['key'], $keys, true ) ) $out[] = $prompt;
        }
        return $out;
    }

    /**
     * The short name of a section's step in the progress bar; a section
     * outside the four main categories keeps its own title.
     */
    private static function stepLabel( string $main, string $title ): string {
        switch ( $main ) {
            case 'technical': return _x( 'Technique', 'PDP preparation step', 'talenttrack' );
            case 'tactical':  return _x( 'Tactics', 'PDP preparation step', 'talenttrack' );
            case 'physical':  return _x( 'Physical', 'PDP preparation step', 'talenttrack' );
            case 'mental':    return _x( 'Mental', 'PDP preparation step', 'talenttrack' );
        }
        return $title;
    }
}
