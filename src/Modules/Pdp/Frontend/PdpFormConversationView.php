<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
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
 *
 * @phpstan-type TalkForm array{
 *   conversation_id:int,
 *   form:array{key:string,version:int,definition:array<string,mixed>}|null,
 *   state:array<string,mixed>,
 *   player:array{scores:array<string,int|null>,answers:array<string,string>}|null,
 *   coach:array{scores:array<string,int|null>,answers:array<string,string>}|null,
 *   goals:list<array<string,mixed>>|null,
 *   differences:list<string>|null
 * }
 * @phpstan-type Prepared array{form_talk:bool, draws:bool, access:?PdpFormAccess, conv:?object, form:TalkForm|null}
 */
final class PdpFormConversationView {

    /**
     * #5080 — what this talk's page draws, decided once for both parts.
     *
     * `form_talk`: the talk uses a conversation form (`PdpFormChoice::formFor()`),
     * so part 1 drops its notes and agreed actions and the legacy sign-off
     * does not apply. `draws`: part 2 is drawn, which also needs a reader of
     * the trainer's part and a talk that was not marked not held. The caller
     * numbers the parts from `draws` and hands the same answer to
     * `renderPrepared()`, so the two can never disagree.
     *
     * @return Prepared
     */
    public static function prepare( int $conv_id, int $user_id ): array {
        $out    = [ 'form_talk' => false, 'draws' => false, 'access' => null, 'conv' => null, 'form' => null ];
        $access = PdpFormAccess::forConversation( $user_id, $conv_id );
        if ( $access === null ) return $out;
        $conv              = $access->conversation();
        $out['access']     = $access;
        $out['conv']       = $conv;
        $out['form_talk']  = \TT\Modules\Pdp\Forms\PdpFormChoice::formFor( $conv, $access->playerId() ) !== null;
        if ( ! $out['form_talk'] || ! $access->canRead() || PdpCycleState::isNotHeld( $conv ) || ! $access->readsCoachPart() ) {
            return $out;
        }
        $form = ( new PdpFormReader() )->read( $access, $conv );
        if ( $form['form'] === null ) return $out;
        $out['form']  = $form;
        $out['draws'] = true;
        return $out;
    }

    /**
     * Draw the trainer's form when the talk uses one. False when it does not,
     * so the caller keeps its own sign-off. A talk marked not held draws
     * nothing here: its not-held line says what there is to say.
     */
    public static function render( int $conv_id, int $user_id ): bool {
        return self::renderPrepared( $conv_id, self::prepare( $conv_id, $user_id ) );
    }

    /**
     * `render()` for a caller that already holds `prepare()`'s answer.
     *
     * @param Prepared $prepared
     */
    public static function renderPrepared( int $conv_id, array $prepared ): bool {
        if ( ! $prepared['form_talk'] ) return false;
        if ( ! $prepared['draws'] || $prepared['form'] === null ) return true;
        $form = $prepared['form'];
        if ( $form['form'] === null ) return true;

        $definition = (array) $form['form']['definition'];
        $state      = (array) $form['state'];
        $signed     = ( $state['coach_signoff_at'] ?? null ) !== null;
        $writable   = ( $state['coach_writable'] ?? false ) === true;
        $signs_off  = ( $state['signs_off'] ?? false ) === true;

        self::enqueue();

        $player  = is_array( $form['player'] ) ? $form['player'] : null;
        $coach   = is_array( $form['coach'] ) ? $form['coach'] : [ 'scores' => [], 'answers' => [] ];
        $diffs   = is_array( $form['differences'] ) ? $form['differences'] : [];
        $changed = is_array( $state['player_changed_items'] ?? null ) ? $state['player_changed_items'] : [];
        $labels  = self::scaleLabels( $definition );
        $off     = $writable ? '' : ' disabled';
        $path    = 'pdp-conversations/' . $conv_id;

        // #5080 — part 2 of the page, a card whose header carries its own
        // save state and undo, and stays in view while the trainer scrolls
        // through the sections. The header is inside the form, so the save
        // state belongs to this form's fields alone.
        $is_keeper = (string) ( $definition['position_group'] ?? '' ) === PdpFormRegistry::GROUP_GOALKEEPER;
        echo '<section class="tt-pdp-part tt-pdp-coachform" aria-labelledby="tt-pdp-coachform-h">';
        echo '<form class="tt-pdp-coachform__form" data-tt-pdp-coachform'
            . ' data-rest-path="' . esc_attr( $path . '/form/coach' ) . '" data-rest-method="PATCH"'
            . ' data-autosave-key="' . esc_attr( 'pdp-coachform:' . $conv_id ) . '"'
            . ' data-signoff-path="' . esc_attr( $path . '/signoff' ) . '">';
        echo '<div class="tt-pdp-part__head tt-pdp-part__head--sticky">';
        echo '<h2 class="tt-pdp-part__title tt-pdp-coachform__h" id="tt-pdp-coachform-h">'
            . '<span class="tt-pdp-part__num" aria-hidden="true">2</span>'
            . '<span>' . esc_html( sprintf(
                /* translators: %s: the form in use, "Outfield player" or "Goalkeeper" */
                __( 'Conversation form · %s', 'talenttrack' ),
                $is_keeper ? __( 'Goalkeeper', 'talenttrack' ) : __( 'Outfield player', 'talenttrack' )
            ) ) . '</span></h2>';
        if ( $writable ) {
            echo '<div class="tt-pdp-part__state">';
            SaveState::render();
            echo '</div>';
        }
        echo '</div>';
        echo '<div class="tt-pdp-part__body">';

        echo '<ul class="tt-pdp-coachform__legend" aria-label="' . esc_attr__( 'How the scores are shown', 'talenttrack' ) . '">';
        echo '<li><span class="tt-pdp-coachform__swatch tt-pdp-coachform__swatch--player" aria-hidden="true"></span>' . esc_html__( 'Player', 'talenttrack' ) . '</li>';
        echo '<li><span class="tt-pdp-coachform__swatch tt-pdp-coachform__swatch--coach" aria-hidden="true"></span>' . esc_html__( 'Trainer (you)', 'talenttrack' ) . '</li>';
        echo '<li><span class="tt-pdp-coachform__flag">' . esc_html_x( 'Discuss', 'PDP form: the player and trainer scores differ', 'talenttrack' ) . '</span>' . esc_html__( 'the scores differ', 'talenttrack' ) . '</li>';
        // #5081 — tapping the chosen score again is a gesture nobody finds
        // by themselves; say it, next to the other ways the row works.
        if ( $writable ) {
            echo '<li>' . esc_html__( 'Tap your score again, or choose Clear, to empty it.', 'talenttrack' ) . '</li>';
        }
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

        $prompts = [];
        foreach ( (array) $definition['prompts'] as $prompt ) $prompts[ (string) $prompt['key'] ] = $prompt;

        // #5081 — the sections read as folds: open all and close all, and
        // each summary a full-width row with a chevron, a count line and the
        // word for what a tap does.
        echo '<div class="tt-pdp-coachform__folds">';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-pdp-folds="open">' . esc_html__( 'Expand all', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-pdp-folds="close">' . esc_html__( 'Collapse all', 'talenttrack' ) . '</button>';
        echo '</div>';

        $n = 0;
        foreach ( (array) $definition['sections'] as $section ) {
            $n++;
            $keys      = array_map( static fn( $item ): string => (string) $item['key'], (array) $section['items'] );
            $to_talk   = count( array_intersect( $keys, $diffs ) );
            $scored    = 0;
            foreach ( $keys as $k ) {
                if ( ( $coach['scores'][ $k ] ?? null ) !== null ) $scored++;
            }
            echo '<details class="tt-pdp-coachform__section" data-tt-pdp-section' . ( $n === 1 ? ' open' : '' ) . '>';
            echo self::summary( $n . ' · ' . (string) $section['title'], self::scoredLine( count( $keys ), $scored ), $to_talk ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.

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
            echo '<details class="tt-pdp-coachform__section" data-tt-pdp-section open>';
            $agreed = trim( (string) ( $coach['answers'][ $field ] ?? '' ) ) !== '';
            echo self::summary( (string) $prompt['label'], self::filledLine( $agreed ), 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
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
        echo '<details class="tt-pdp-coachform__section" data-tt-pdp-section open>';
        echo self::summary( __( 'Development points (SMART)', 'talenttrack' ), self::goalsLine( (array) $form['goals'] ), 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
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

        echo '</div>'; // .tt-pdp-part__body
        echo '</form>';
        echo '</section>';

        // Sign-off is outside both parts, under its own heading (§6: an
        // irreversible commit is never part of an autosaving form).
        if ( $signed || $signs_off ) {
            echo self::finishHeading(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
        }
        if ( $signed ) {
            echo '<p class="tt-pdp-coachform__signed"><strong>' . esc_html__( 'Signed off', 'talenttrack' ) . '</strong> — '
                . esc_html( TTDate::dateTimeFromGmt( (string) $state['coach_signoff_at'] ) ) . '</p>';
            // #4982 — the signed form as the academy's A4 form.
            PdpFormPrintAction::render( $conv_id );
        } elseif ( $signs_off ) {
            $missing = [];
            foreach ( (array) ( $state['missing'] ?? [] ) as $m ) {
                if ( is_array( $m ) ) $missing[] = (string) ( $m['label'] ?? '' );
            }
            self::renderSignoff( $missing );
        }

        return true;
    }

    /**
     * #5081 — a section's summary as a full-width row: a chevron that turns
     * when the section is open, the title with its count line under it, the
     * "N to discuss" badge, and the word for what a tap does. The word is
     * hidden from assistive tech, which announces the open state of a
     * `<details>` itself.
     *
     * @param string $count_html the count line's content, already escaped
     */
    private static function summary( string $title, string $count_html, int $to_talk ): string {
        $out  = '<summary class="tt-pdp-coachform__sum">';
        $out .= '<span class="tt-pdp-coachform__chev" aria-hidden="true"></span>';
        $out .= '<span class="tt-pdp-coachform__sumtext"><span class="tt-pdp-coachform__sumtitle">' . esc_html( $title ) . '</span>';
        if ( $count_html !== '' ) {
            $out .= '<span class="tt-pdp-coachform__count">' . $count_html . '</span>';
        }
        $out .= '</span>';
        if ( $to_talk > 0 ) {
            $out .= '<span class="tt-pdp-coachform__flag">' . esc_html( sprintf(
                /* translators: %d: items in the section where the player's and the trainer's scores differ */
                _n( '%d to discuss', '%d to discuss', $to_talk, 'talenttrack' ),
                $to_talk
            ) ) . '</span>';
        }
        $out .= '<span class="tt-pdp-coachform__toggle" aria-hidden="true">'
            . '<span class="tt-pdp-coachform__toggle-open">' . esc_html_x( 'Expand', 'PDP form section: what tapping a closed section does', 'talenttrack' ) . '</span>'
            . '<span class="tt-pdp-coachform__toggle-close">' . esc_html_x( 'Collapse', 'PDP form section: what tapping an open section does', 'talenttrack' ) . '</span>'
            . '</span>';
        return $out . '</summary>';
    }

    /** "5 items · 2 of 5 scored", for a section of scored items. */
    private static function scoredLine( int $items, int $scored ): string {
        return esc_html( sprintf(
            /* translators: %d: items in the section */
            _nx( '%d item', '%d items', $items, 'PDP form section: the items it holds', 'talenttrack' ),
            $items
        ) )
            . ' · <span data-tt-pdp-scored data-total="' . (int) $items . '">' . esc_html( sprintf(
                /* translators: 1: items the trainer scored, 2: items in the section */
                __( '%1$d of %2$d scored', 'talenttrack' ),
                $scored,
                $items
            ) ) . '</span>';
    }

    /** "Filled in" / "Not filled in yet", for a text the trainer agrees. */
    private static function filledLine( bool $filled ): string {
        return '<span data-tt-pdp-filled>' . esc_html( $filled
            ? _x( 'Filled in', 'PDP form section: its text is written', 'talenttrack' )
            : _x( 'Not filled in yet', 'PDP form section: its text is still empty', 'talenttrack' ) ) . '</span>';
    }

    /**
     * "2 development points · 1 still empty", for the SMART points. A point
     * is empty until its action, help and deadline are all filled in, the
     * same three sign-off asks for.
     *
     * @param array<int|string,mixed> $goals
     */
    private static function goalsLine( array $goals ): string {
        $total = count( $goals );
        $empty = 0;
        foreach ( $goals as $goal ) {
            $g = (array) $goal;
            foreach ( [ 'action', 'support', 'due_date' ] as $f ) {
                if ( trim( (string) ( $g[ $f ] ?? '' ) ) === '' ) { $empty++; break; }
            }
        }
        return esc_html( sprintf(
            /* translators: %d: development points on the form */
            _n( '%d development point', '%d development points', $total, 'talenttrack' ),
            $total
        ) )
            . ' · <span data-tt-pdp-goals-empty>' . esc_html( self::emptyText( $empty ) ) . '</span>';
    }

    private static function emptyText( int $empty ): string {
        return $empty === 0
            ? _x( 'all filled in', 'PDP form: every development point is complete', 'talenttrack' )
            /* translators: %d: development points still missing a field */
            : sprintf( __( '%d still empty', 'talenttrack' ), $empty );
    }

    /**
     * #5080 — the "Finish" heading over the irreversible steps of a talk:
     * sign-off, marking it not held, recording acknowledgements. Shared with
     * `FrontendPdpManageView`, which draws it for a talk without a form.
     */
    public static function finishHeading(): string {
        return '<h2 class="tt-pdp-finish-h">' . esc_html_x( 'Finish', 'PDP talk heading over sign-off and the other irreversible steps', 'talenttrack' ) . '</h2>';
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
                /* translators: 1: items the trainer scored, 2: items in the section */
                'scored'   => __( '%1$d of %2$d scored', 'talenttrack' ),
                'filled'   => _x( 'Filled in', 'PDP form section: its text is written', 'talenttrack' ),
                'unfilled' => _x( 'Not filled in yet', 'PDP form section: its text is still empty', 'talenttrack' ),
                /* translators: %d: development points still missing a field */
                'empty'    => __( '%d still empty', 'talenttrack' ),
                'allFull'  => _x( 'all filled in', 'PDP form: every development point is complete', 'talenttrack' ),
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
