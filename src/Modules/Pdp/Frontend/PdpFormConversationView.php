<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\FormAutosave;
use TT\Shared\Frontend\Components\RatingInputComponent;
use TT\Shared\Frontend\Components\SaveState;

/**
 * PdpFormConversationView (#4980, epic #4975) — the trainer's side of a PDP
 * conversation form, on the conversation screen. Composed into
 * `FrontendPdpManageView`'s Conversation pane; not a view of its own.
 *
 * Wizard plan: exemption. The conversation exists; this fills an autosaved
 * draft on it (save model A, `PATCH …/form/coach`) and sign-off commits it
 * (`POST …/signoff`), its own control with a confirm, never a field.
 *
 * Everything it decides comes from `PdpFormReader`, as the trainer reads it:
 * the player's part (null before "klaar"), the differences ("Bespreken"),
 * the items the player changed after "klaar" ("aangepast"), and what
 * sign-off still needs. Sections are folds, not a tab strip (§5c).
 */
final class PdpFormConversationView {

    /**
     * Draw the trainer's form when the talk uses one. False when it does not,
     * so the caller keeps its own sign-off. A talk marked not held draws
     * nothing here: its not-held line says what there is to say.
     */
    public static function render( int $conv_id, int $user_id ): bool {
        $access = PdpFormAccess::forConversation( $user_id, $conv_id );
        if ( $access === null || ! $access->canRead() ) return false;
        $conv = $access->conversation();
        $form = ( new PdpFormReader() )->read( $access, $conv );
        if ( $form['form'] === null ) return false;
        if ( PdpCycleState::isNotHeld( $conv ) || ! $access->readsCoachPart() ) return true;

        $definition = (array) $form['form']['definition'];
        $state      = (array) $form['state'];
        $signed     = ( $state['coach_signoff_at'] ?? null ) !== null;
        $active     = $conv_id === ( new PdpConversationsRepository() )->activeConversationId( (int) ( ( (array) $conv )['pdp_file_id'] ?? 0 ) );
        $writable   = ! $signed && $active && $access->writesCoachPart();

        self::enqueue();

        $player  = is_array( $form['player'] ) ? $form['player'] : null;
        $coach   = is_array( $form['coach'] ) ? $form['coach'] : [ 'scores' => [], 'answers' => [] ];
        $diffs   = is_array( $form['differences'] ) ? $form['differences'] : [];
        $changed = is_array( $state['player_changed_items'] ?? null ) ? $state['player_changed_items'] : [];
        $labels  = self::scaleLabels( $definition );
        $off     = $writable ? '' : ' disabled';
        $path    = 'pdp-conversations/' . $conv_id;

        echo '<section class="tt-pdp-coachform" aria-labelledby="tt-pdp-coachform-h">';
        echo '<h2 class="tt-pdp-coachform__h" id="tt-pdp-coachform-h">' . esc_html__( 'Conversation form', 'talenttrack' ) . '</h2>';

        echo '<ul class="tt-pdp-coachform__legend" aria-label="' . esc_attr__( 'How the scores are shown', 'talenttrack' ) . '">';
        echo '<li><span class="tt-pdp-coachform__swatch tt-pdp-coachform__swatch--player" aria-hidden="true"></span>' . esc_html__( 'Player', 'talenttrack' ) . '</li>';
        echo '<li><span class="tt-pdp-coachform__swatch tt-pdp-coachform__swatch--coach" aria-hidden="true"></span>' . esc_html__( 'Trainer (you)', 'talenttrack' ) . '</li>';
        echo '<li><span class="tt-pdp-coachform__flag">' . esc_html_x( 'Discuss', 'PDP form: the player and trainer scores differ', 'talenttrack' ) . '</span>' . esc_html__( 'the scores differ', 'talenttrack' ) . '</li>';
        echo '</ul>';

        if ( $player === null ) {
            echo '<p class="tt-pdp-coachform__status">' . esc_html__( 'The player has not marked the preparation ready yet. You see their scores once they do.', 'talenttrack' ) . '</p>';
        } else {
            echo '<p class="tt-pdp-coachform__status">' . esc_html( sprintf(
                /* translators: %s: date and time the player marked the preparation ready */
                __( 'The player marked the preparation ready on %s.', 'talenttrack' ),
                TTDate::dateTimeFromGmt( (string) $state['player_prepared_at'] )
            ) ) . '</p>';
        }

        echo '<form class="tt-pdp-coachform__form" data-tt-pdp-coachform'
            . ' data-rest-path="' . esc_attr( $path . '/form/coach' ) . '" data-rest-method="PATCH"'
            . ' data-autosave-key="' . esc_attr( 'pdp-coachform:' . $conv_id ) . '"'
            . ' data-signoff-path="' . esc_attr( $path . '/signoff' ) . '">';

        $prompts = [];
        foreach ( (array) $definition['prompts'] as $prompt ) $prompts[ (string) $prompt['key'] ] = $prompt;

        $n = 0;
        foreach ( (array) $definition['sections'] as $section ) {
            $n++;
            $keys      = array_map( static fn( $item ): string => (string) $item['key'], (array) $section['items'] );
            $to_talk   = count( array_intersect( $keys, $diffs ) );
            echo '<details class="tt-pdp-coachform__section"' . ( $n === 1 ? ' open' : '' ) . '>';
            echo '<summary class="tt-pdp-coachform__sum"><span>' . esc_html( $n . ' · ' . (string) $section['title'] ) . '</span>';
            if ( $to_talk > 0 ) {
                echo '<span class="tt-pdp-coachform__flag">' . esc_html( sprintf(
                    /* translators: %d: items in the section where the player's and the trainer's scores differ */
                    _n( '%d to discuss', '%d to discuss', $to_talk, 'talenttrack' ),
                    $to_talk
                ) ) . '</span>';
            }
            echo '</summary>';

            foreach ( (array) $section['items'] as $item ) {
                $key   = (string) $item['key'];
                $mine  = $coach['scores'][ $key ] ?? null;
                $their = $player !== null ? ( $player['scores'][ $key ] ?? null ) : null;
                echo '<div class="tt-pdp-coachform__item" data-tt-item="' . esc_attr( $key ) . '">';
                echo '<p class="tt-pdp-coachform__iname"><span>' . esc_html( (string) $item['label'] ) . '</span>';
                if ( in_array( $key, $diffs, true ) ) echo '<span class="tt-pdp-coachform__flag">' . esc_html_x( 'Discuss', 'PDP form: the player and trainer scores differ', 'talenttrack' ) . '</span>';
                if ( in_array( $key, $changed, true ) ) echo '<span class="tt-pdp-coachform__changed">' . esc_html_x( 'changed', 'PDP form: the player changed this score after marking ready', 'talenttrack' ) . '</span>';
                echo '</p>';
                echo '<p class="tt-pdp-coachform__idef">' . esc_html( (string) $item['definition'] ) . '</p>';
                echo RatingInputComponent::renderListRow( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes its own output.
                    'name'           => 'scores[' . $key . ']',
                    'value'          => $mine,
                    'label'          => sprintf(
                        /* translators: %s: the item being scored */
                        __( 'Trainer score for %s', 'talenttrack' ),
                        (string) $item['label']
                    ),
                    'label_hidden'   => true,
                    'min'            => 1,
                    'max'            => 5,
                    'step'           => 1,
                    'disabled'       => ! $writable,
                    'variant'        => RatingInputComponent::VARIANT_CHOICES,
                    'labels'         => $labels,
                    'marked'         => $their,
                    'marked_note'    => __( 'the player\'s score', 'talenttrack' ),
                    'empty_text'     => __( 'Trainer: not scored yet', 'talenttrack' ),
                    /* translators: 1: the score, 2: its label in the form's scale */
                    'readout_format' => __( 'Trainer: %1$s · %2$s', 'talenttrack' ),
                ] );
                echo '<p class="tt-pdp-coachform__player">' . esc_html( $player === null
                    ? __( 'Player: not ready yet', 'talenttrack' )
                    : ( $their === null
                        ? __( 'Player: not scored', 'talenttrack' )
                        : sprintf(
                            /* translators: 1: the player's score, 2: its label in the form's scale */
                            __( 'Player: %1$d · %2$s', 'talenttrack' ),
                            (int) $their,
                            $labels[ (int) $their - 1 ] ?? ''
                        ) )
                ) . '</p>';
                echo '</div>';
            }

            $note_key = 'notes.' . (string) $section['key'];
            if ( isset( $prompts[ $note_key ] ) ) {
                $id = 'tt-pdp-coachform-note-' . sanitize_html_class( (string) $section['key'] );
                echo '<div class="tt-field">';
                echo '<label class="tt-field-label" for="' . esc_attr( $id ) . '">' . esc_html( (string) $prompts[ $note_key ]['label'] ) . '</label>';
                echo '<textarea class="tt-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'answers[' . $note_key . ']' ) . '" rows="3"' . $off . '>'
                    . esc_textarea( (string) ( $coach['answers'][ $note_key ] ?? '' ) ) . '</textarea>';
                echo '</div>';
            }
            echo '</details>';
        }

        // The strongest quality: what the player wrote, and what was agreed.
        foreach ( PdpFormRegistry::sharedFields( $definition ) as $field ) {
            $prompt = $prompts[ $field ] ?? [ 'label' => $field, 'question' => '' ];
            $id     = 'tt-pdp-coachform-' . sanitize_html_class( $field );
            echo '<details class="tt-pdp-coachform__section" open>';
            echo '<summary class="tt-pdp-coachform__sum"><span>' . esc_html( (string) $prompt['label'] ) . '</span></summary>';
            if ( (string) ( $prompt['question'] ?? '' ) !== '' ) {
                echo '<p class="tt-help-text">' . esc_html( (string) $prompt['question'] ) . '</p>';
            }
            $wrote = $player !== null ? trim( (string) ( $player['answers'][ $field ] ?? '' ) ) : '';
            echo '<div class="tt-pdp-coachform__theirs"><strong>' . esc_html__( 'The player wrote:', 'talenttrack' ) . '</strong> '
                . ( $player === null
                    ? esc_html__( 'not ready yet', 'talenttrack' )
                    : ( $wrote !== '' ? nl2br( esc_html( $wrote ) ) : '<em>' . esc_html_x( 'nothing', 'PDP form: the player wrote nothing here', 'talenttrack' ) . '</em>' ) )
                . '</div>';
            echo '<div class="tt-field">';
            echo '<label class="tt-field-label" for="' . esc_attr( $id ) . '">' . esc_html__( 'What you agreed', 'talenttrack' ) . '</label>';
            echo '<textarea class="tt-input" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'answers[' . $field . ']' ) . '" rows="3"' . $off . '>'
                . esc_textarea( (string) ( $coach['answers'][ $field ] ?? '' ) ) . '</textarea>';
            echo '</div>';
            echo '</details>';
        }

        // The SMART points: each becomes a goal at sign-off.
        $items = PdpFormRegistry::items( $definition );
        echo '<details class="tt-pdp-coachform__section" open>';
        echo '<summary class="tt-pdp-coachform__sum"><span>' . esc_html__( 'Development points (SMART)', 'talenttrack' ) . '</span></summary>';
        echo '<p class="tt-help-text">' . esc_html__( 'Each development point becomes one of the player\'s goals when you sign off, so its progress can be followed.', 'talenttrack' ) . '</p>';
        foreach ( (array) $form['goals'] as $goal ) {
            $slot   = (int) $goal['slot'];
            $prompt = $prompts[ (string) $goal['prompt_key'] ] ?? [ 'label' => (string) $goal['prompt_key'], 'question' => '' ];
            $base   = 'tt-pdp-coachform-goal-' . $slot;
            $name   = static fn( string $f ): string => 'goals[' . $slot . '][' . $f . ']';
            echo '<fieldset class="tt-pdp-coachform__goal">';
            echo '<legend>' . esc_html( (string) $prompt['label'] ) . '</legend>';
            if ( (string) ( $prompt['question'] ?? '' ) !== '' ) echo '<p class="tt-help-text">' . esc_html( (string) $prompt['question'] ) . '</p>';
            if ( ! empty( $goal['goal_id'] ) ) echo '<p class="tt-pdp-coachform__made">' . esc_html__( 'Goal created', 'talenttrack' ) . '</p>';
            echo '<div class="tt-field"><label class="tt-field-label" for="' . esc_attr( $base . '-action' ) . '">' . esc_html__( 'What the player does', 'talenttrack' ) . '</label>'
                . '<textarea class="tt-input" id="' . esc_attr( $base . '-action' ) . '" name="' . esc_attr( $name( 'action' ) ) . '" rows="3"' . $off . '>' . esc_textarea( (string) $goal['action'] ) . '</textarea></div>';
            echo '<div class="tt-field"><label class="tt-field-label" for="' . esc_attr( $base . '-support' ) . '">' . esc_html__( 'How the trainer helps', 'talenttrack' ) . '</label>'
                . '<textarea class="tt-input" id="' . esc_attr( $base . '-support' ) . '" name="' . esc_attr( $name( 'support' ) ) . '" rows="2"' . $off . '>' . esc_textarea( (string) $goal['support'] ) . '</textarea></div>';
            echo '<div class="tt-field"><label class="tt-field-label" for="' . esc_attr( $base . '-due' ) . '">' . esc_html__( 'Deadline', 'talenttrack' ) . '</label>'
                . '<input type="date" class="tt-input" id="' . esc_attr( $base . '-due' ) . '" name="' . esc_attr( $name( 'due_date' ) ) . '" value="' . esc_attr( (string) ( $goal['due_date'] ?? '' ) ) . '"' . $off . ' /></div>';
            echo '<div class="tt-field"><label class="tt-field-label" for="' . esc_attr( $base . '-item' ) . '">' . esc_html__( 'Item it develops (optional)', 'talenttrack' ) . '</label>'
                . '<select class="tt-input" id="' . esc_attr( $base . '-item' ) . '" name="' . esc_attr( $name( 'item_key' ) ) . '"' . $off . '>'
                . '<option value="">' . esc_html__( '— none —', 'talenttrack' ) . '</option>';
            foreach ( $items as $item ) {
                echo '<option value="' . esc_attr( $item['key'] ) . '"' . selected( (string) ( $goal['item_key'] ?? '' ), $item['key'], false ) . '>' . esc_html( $item['label'] ) . '</option>';
            }
            echo '</select></div>';
            echo '</fieldset>';
        }
        echo '</details>';

        if ( $writable ) {
            echo '<div class="tt-form-actions tt-pdp-coachform__save">';
            SaveState::render();
            echo '</div>';
        }
        echo '</form>';

        if ( $signed ) {
            echo '<p class="tt-pdp-coachform__signed"><strong>' . esc_html__( 'Signed off', 'talenttrack' ) . '</strong> — '
                . esc_html( TTDate::dateTimeFromGmt( (string) $state['coach_signoff_at'] ) ) . '</p>';
        } elseif ( $writable && $access->signsOff() ) {
            $missing = [];
            foreach ( (array) ( $state['missing'] ?? [] ) as $m ) {
                if ( is_array( $m ) ) $missing[] = (string) ( $m['label'] ?? '' );
            }
            self::renderSignoff( $missing );
        }

        echo '</section>';
        return true;
    }

    /**
     * Sign-off, its own control (§6): closed while the form is incomplete,
     * naming what is missing. The player's preparation is never required
     * (decision 5). The script keeps the list current as the form saves.
     *
     * @param list<string> $missing the labels of what is still needed
     */
    private static function renderSignoff( array $missing ): void {
        echo '<div class="tt-pdp-coachform__signoff" data-tt-coach-signoff-box>';
        echo '<div class="tt-pdp-coachform__missing" data-tt-coach-missing' . ( $missing === [] ? ' hidden' : '' ) . '>';
        echo '<p>' . esc_html__( 'Still needed before you can sign off:', 'talenttrack' ) . '</p>';
        echo '<ul>';
        foreach ( $missing as $label ) echo '<li>' . esc_html( $label ) . '</li>';
        echo '</ul></div>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-coach-signoff' . ( $missing !== [] ? ' disabled' : '' ) . '>'
            . esc_html__( 'Finish and sign the conversation', 'talenttrack' ) . '</button>';
        echo '<p class="tt-help-text">' . esc_html__( 'Signing off fixes the scores, saves them as an evaluation, creates the two goals, and asks the player and the parent to confirm the conversation.', 'talenttrack' ) . '</p>';
        echo '<div class="tt-form-msg" role="status" aria-live="polite"></div>';
        echo '</div>';
    }

    private static function enqueue(): void {
        FormAutosave::enqueue();
        RatingInputComponent::enqueue();
        wp_enqueue_script(
            'tt-pdp-coach-form',
            TT_PLUGIN_URL . 'assets/js/components/pdp-coach-form.js',
            [ 'tt-form-autosave', 'tt-rating-input' ],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-pdp-coach-form', 'TT_PdpCoachForm', [
            'i18n' => [
                'confirm'  => __( 'Finish and sign this conversation? The scores are fixed and saved as an evaluation, the two development points become goals, and the player and the parent are asked to confirm. This cannot be undone.', 'talenttrack' ),
                'unsaved'  => __( 'Your last change has not been saved yet. Check your connection and try again.', 'talenttrack' ),
                'closed'   => __( 'The form can no longer be saved. Reload the page to see why.', 'talenttrack' ),
                'failed'   => __( 'The conversation could not be signed off. Try again.', 'talenttrack' ),
                'signing'  => __( 'Signing off…', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * @param array<string,mixed> $definition
     * @return list<string> the scale's labels, 1 first
     */
    private static function scaleLabels( array $definition ): array {
        $out = [];
        for ( $i = 1; $i <= 5; $i++ ) $out[] = (string) ( $definition['scale'][ $i ]['label'] ?? (string) $i );
        return $out;
    }
}
