<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormChoice;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\FormAutosave;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RatingInputComponent;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\RecordSpine;
use TT\Shared\Frontend\Components\SaveState;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\PresentationSurfaces;

/**
 * FrontendPdpConversationModeView (#5082) — a PDP talk on the screen in the
 * room: `?tt_view=pdp-conversation-mode&id=<conversation>`.
 *
 * A presentation surface (`config/presentation_surfaces.php`): one record,
 * one close control back to the talk's page, and the normal screen with its
 * breadcrumbs for anyone refused. Every refusal below runs before
 * `PresentationSurfaces::present()`; nothing is drawn before it on the
 * success path.
 *
 * What it shows is `PdpFormReader::forDisplay()`, an allowlist decided in
 * the domain: never the background, the trainer's section notes, sign-off,
 * or any other text field. The trainer can still score here, through the
 * same `PATCH …/form/coach` (save model A). The form carries only the
 * scores, so it attaches without the page's `storageKey` and offers undo
 * only.
 *
 * Wizard plan: exemption — nothing is created.
 */
final class FrontendPdpConversationModeView extends FrontendViewBase {

    public const SLUG = 'pdp-conversation-mode';

    public static function render( int $user_id ): void {
        $conv_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $access  = PdpFormAccess::forConversation( $user_id, $conv_id );

        if ( $access === null ) {
            self::refuse( __( 'Conversation not found', 'talenttrack' ), __( 'That conversation no longer exists.', 'talenttrack' ) );
            return;
        }
        if ( ! $access->readsRoomDisplay() ) {
            self::refuse( __( 'Not authorized', 'talenttrack' ), __( 'You do not have access to this conversation mode.', 'talenttrack' ) );
            return;
        }
        $conv = $access->conversation();
        if ( PdpCycleState::isNotHeld( $conv ) || PdpFormChoice::formFor( $conv, $access->playerId() ) === null ) {
            self::refuse( __( 'Conversation mode', 'talenttrack' ), __( 'This talk has no conversation form to show.', 'talenttrack' ) );
            return;
        }
        $display = ( new PdpFormReader() )->forDisplay( $access, $conv );
        if ( $display['form'] === null ) {
            self::refuse( __( 'Conversation mode', 'talenttrack' ), __( 'This talk has no conversation form to show.', 'talenttrack' ) );
            return;
        }

        if ( ! PresentationSurfaces::present( self::closeUrl( (int) $display['file_id'], $conv_id ) ) ) {
            // Not listed as a presentation surface (any more): the same
            // content as a normal view, with its chain as the way out.
            FrontendBreadcrumbs::fromDashboard( __( 'Conversation mode', 'talenttrack' ), [ FrontendBreadcrumbs::viewCrumb( 'pdp', __( 'PDP', 'talenttrack' ) ) ] );
        }

        self::enqueue( $conv_id );
        self::renderMode( $conv_id, $display );
    }

    /**
     * The talk's own page, on its conversation tab, as the close control
     * and Escape go back to it.
     */
    public static function closeUrl( int $file_id, int $conv_id ): string {
        return add_query_arg(
            [ 'tt_view' => 'pdp', 'id' => $file_id, 'conv' => $conv_id ],
            RecordLink::dashboardUrl()
        ) . '#tt-pdp-pane-conversation';
    }

    /**
     * The mode's own address. A builder, not an affordance: the button on
     * the talk's page renders it through `CrossViewLink`, gated on the
     * `pdp-conversation-mode` entry in `CoreSurfaceRegistration`.
     */
    public static function urlFor( int $conv_id ): string {
        return add_query_arg( [ 'tt_view' => self::SLUG, 'id' => $conv_id ], RecordLink::dashboardUrl() ); /* tt-xview-ok */
    }

    /** A refusal is a normal view, in the shell, with its chain. */
    private static function refuse( string $title, string $message ): void {
        FrontendBreadcrumbs::fromDashboard( $title, [ FrontendBreadcrumbs::viewCrumb( 'pdp', __( 'PDP', 'talenttrack' ) ) ] );
        echo '<p class="tt-notice">' . esc_html( $message ) . '</p>';
    }

    /**
     * @param array<string,mixed> $display `PdpFormReader::forDisplay()`
     */
    private static function renderMode( int $conv_id, array $display ): void {
        $writable = ( $display['state']['coach_writable'] ?? false ) === true;
        $ready    = $display['player_ready'] === true;
        /** @var list<array<string,mixed>> $sections */
        $sections = array_values( array_map( static fn( $s ): array => (array) $s, (array) $display['sections'] ) );
        $scores   = (array) $display['scores'];
        $labels   = [];
        foreach ( (array) $display['form']['scale'] as $step ) $labels[] = (string) ( (array) $step )['label'];
        /** @var list<array<string,mixed>> $shared */
        $shared = array_values( array_map( static fn( $s ): array => (array) $s, (array) $display['shared'] ) );
        /** @var list<array<string,mixed>> $goals */
        $goals  = array_values( array_map( static fn( $g ): array => (array) $g, (array) $display['goals'] ) );

        echo '<form class="tt-pdp-mode" data-tt-pdp-mode'
            . ' data-rest-path="' . esc_attr( 'pdp-conversations/' . $conv_id . '/form/coach' ) . '" data-rest-method="PATCH"'
            . ' data-display-path="' . esc_attr( 'pdp-conversations/' . $conv_id . '/form/display' ) . '"'
            . ' aria-labelledby="tt-pdp-mode-name">';

        self::renderHead( $display, $writable );

        // The steps are panels of one talk, so their pills are record tabs
        // (§5c), from the spine.
        $tabs = [ [ 'label' => __( 'Overview', 'talenttrack' ), 'panel' => 'tt-pdp-mode-step-overview', 'active' => true ] ];
        foreach ( $sections as $section ) {
            $tabs[] = [ 'label' => (string) $section['title'], 'panel' => self::panelId( (string) $section['key'] ) ];
        }
        $tabs[] = [ 'label' => __( 'Strongest quality and development points', 'talenttrack' ), 'panel' => 'tt-pdp-mode-step-agreed' ];
        echo '<div class="tt-pdp-mode__steps">';
        RecordSpine::render( [ 'tabs_always' => true, 'tabs' => $tabs ] );
        echo '</div>';

        echo '<div class="tt-pdp-mode__body">';
        if ( ! $ready ) {
            echo '<p class="tt-pdp-mode__note">' . esc_html__( 'The player has not marked the preparation ready yet, so only the trainer\'s scores show.', 'talenttrack' ) . '</p>';
        }

        self::renderOverview( $sections, $ready );
        foreach ( $sections as $section ) {
            self::renderSection( $section, $scores, $labels, $writable, $ready );
        }
        self::renderAgreed( $shared, $goals, $ready );
        echo '</div>';

        echo '<div class="tt-pdp-mode__foot">';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-pdp-mode-prev disabled>' . esc_html_x( 'Previous', 'PDP conversation mode: the step before', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-pdp-mode-next>' . esc_html( self::nextLabel( (string) ( $tabs[1]['label'] ?? '' ) ) ) . '</button>';
        echo '</div>';

        echo '</form>';
    }

    /** @param array<string,mixed> $display */
    private static function renderHead( array $display, bool $writable ): void {
        $player = (array) $display['player'];
        $talk   = (array) $display['talk'];
        $club   = (array) $display['club'];

        $meta = [];
        if ( (string) $player['team'] !== '' ) $meta[] = (string) $player['team'];
        if ( $player['shirt_number'] !== null ) $meta[] = '#' . (int) $player['shirt_number'];
        if ( (string) $player['position'] !== '' ) $meta[] = (string) $player['position'];

        $when = $talk['conducted_at'] !== null
            ? TTDate::date( (string) $talk['conducted_at'] )
            : ( $talk['scheduled_at'] !== null ? TTDate::dateTime( (string) $talk['scheduled_at'] ) : '' );

        echo '<header class="tt-pdp-mode__head">';
        echo '<div class="tt-pdp-mode__club">';
        if ( (string) $club['logo_url'] !== '' ) {
            echo '<img class="tt-pdp-mode__logo" src="' . esc_url( (string) $club['logo_url'] ) . '" alt="" width="48" height="48" />';
        }
        echo '<span class="tt-pdp-mode__clubname">' . esc_html( (string) $club['name'] ) . '</span>';
        echo '</div>';

        echo '<div class="tt-pdp-mode__who">';
        echo '<span class="tt-pdp-mode__avatar" aria-hidden="true">';
        if ( (string) $player['photo_url'] !== '' ) {
            echo '<img class="tt-pdp-mode__photo" src="' . esc_url( (string) $player['photo_url'] ) . '" alt="" width="64" height="64" />';
        } else {
            echo esc_html( RecordSpine::initials( (string) $player['name'] ) );
        }
        echo '</span>';
        echo '<div class="tt-pdp-mode__whotext">';
        echo '<h1 class="tt-pdp-mode__name" id="tt-pdp-mode-name">' . esc_html( (string) $player['name'] ) . '</h1>';
        if ( $meta !== [] ) echo '<p class="tt-pdp-mode__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        echo '<p class="tt-pdp-mode__talk">' . esc_html( sprintf(
            /* translators: 1: talk template, e.g. "Mid season", 2: its number in the cycle */
            __( '%1$s · conversation %2$d', 'talenttrack' ),
            (string) $talk['template'],
            (int) $talk['sequence']
        ) . ( $when !== '' ? ' · ' . $when : '' ) ) . '</p>';
        echo '</div></div>';

        echo '<div class="tt-pdp-mode__tools">';
        if ( $writable ) {
            echo '<div class="tt-pdp-mode__state">';
            SaveState::render();
            echo '</div>';
        }
        // The close control `present()` drew moves in here when the
        // script runs; without it, it stays at the top of the page.
        echo '<div class="tt-pdp-mode__close" data-tt-pdp-mode-close-slot></div>';
        echo '</div>';
        echo '</header>';
    }

    /**
     * @param list<array<string,mixed>> $sections
     */
    private static function renderOverview( array $sections, bool $ready ): void {
        echo '<section class="tt-pdp-mode__step" id="tt-pdp-mode-step-overview" role="tabpanel" aria-labelledby="' . esc_attr( RecordSpine::tabId( 'tt-pdp-mode-step-overview' ) ) . '" tabindex="0">';
        echo '<h2 class="tt-pdp-mode__h">' . esc_html__( 'Overview', 'talenttrack' ) . '</h2>';
        echo '<div class="tt-pdp-mode__overview" role="table" aria-label="' . esc_attr__( 'Averages per section', 'talenttrack' ) . '">';
        echo '<div class="tt-pdp-mode__orow tt-pdp-mode__orow--head" role="row">'
            . '<span role="columnheader">' . esc_html__( 'Section', 'talenttrack' ) . '</span>'
            . '<span role="columnheader">' . esc_html__( 'Player', 'talenttrack' ) . '</span>'
            . '<span role="columnheader">' . esc_html__( 'Trainer', 'talenttrack' ) . '</span>'
            . '<span role="columnheader">' . esc_html_x( 'Discuss', 'PDP form: the player and trainer scores differ', 'talenttrack' ) . '</span>'
            . '</div>';
        foreach ( $sections as $section ) {
            $key = (string) $section['key'];
            echo '<div class="tt-pdp-mode__orow" role="row">';
            echo '<span role="rowheader" class="tt-pdp-mode__otitle">' . esc_html( (string) $section['title'] ) . '</span>';
            echo '<span role="cell" class="tt-pdp-mode__avg" data-tt-pdp-mode-avg="' . esc_attr( $key . ':player' ) . '">' . esc_html( $ready ? self::average( $section['player_average'] ) : '—' ) . '</span>';
            echo '<span role="cell" class="tt-pdp-mode__avg tt-pdp-mode__avg--coach" data-tt-pdp-mode-avg="' . esc_attr( $key . ':coach' ) . '">' . esc_html( self::average( $section['coach_average'] ) ) . '</span>';
            echo '<span role="cell" data-tt-pdp-mode-discuss="' . esc_attr( $key ) . '">' . esc_html( (string) (int) $section['to_discuss'] ) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param array<string,mixed>  $section
     * @param array<string,mixed>  $scores
     * @param list<string>         $labels
     */
    private static function renderSection( array $section, array $scores, array $labels, bool $writable, bool $ready ): void {
        $panel = self::panelId( (string) $section['key'] );
        echo '<section class="tt-pdp-mode__step" id="' . esc_attr( $panel ) . '" role="tabpanel" aria-labelledby="' . esc_attr( RecordSpine::tabId( $panel ) ) . '" tabindex="0" hidden>';
        echo '<h2 class="tt-pdp-mode__h">' . esc_html( (string) $section['title'] ) . '</h2>';
        echo '<ul class="tt-pdp-mode__legend" aria-label="' . esc_attr__( 'How the scores are shown', 'talenttrack' ) . '">';
        echo '<li><span class="tt-pdp-coachform__swatch tt-pdp-coachform__swatch--player" aria-hidden="true"></span>' . esc_html__( 'Player', 'talenttrack' ) . '</li>';
        echo '<li><span class="tt-pdp-coachform__swatch tt-pdp-coachform__swatch--coach" aria-hidden="true"></span>' . esc_html__( 'Trainer', 'talenttrack' ) . '</li>';
        echo '</ul>';
        echo '<div class="tt-pdp-mode__items">';
        foreach ( (array) $section['items'] as $item ) {
            $key   = (string) $item['key'];
            $row   = (array) ( $scores[ $key ] ?? [] );
            $their = $ready ? ( $row['player'] ?? null ) : null;
            echo '<div class="tt-pdp-mode__item" data-tt-item="' . esc_attr( $key ) . '">';
            echo '<p class="tt-pdp-mode__iname"><span>' . esc_html( (string) $item['label'] ) . '</span>'
                . '<span class="tt-pdp-coachform__flag" data-tt-pdp-mode-flag="' . esc_attr( $key ) . '"' . ( empty( $row['discuss'] ) ? ' hidden' : '' ) . '>'
                . esc_html_x( 'Discuss', 'PDP form: the player and trainer scores differ', 'talenttrack' ) . '</span></p>';
            echo '<p class="tt-pdp-mode__idef">' . esc_html( (string) $item['definition'] ) . '</p>';
            echo RatingInputComponent::renderListRow( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes its own output.
                'name'           => 'scores[' . $key . ']',
                'value'          => $row['coach'] ?? null,
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
            echo '<p class="tt-pdp-mode__player">' . esc_html( ! $ready
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
        echo '</div>';
        echo '</section>';
    }

    /**
     * @param list<array<string,mixed>> $shared
     * @param list<array<string,mixed>> $goals
     */
    private static function renderAgreed( array $shared, array $goals, bool $ready ): void {
        echo '<section class="tt-pdp-mode__step" id="tt-pdp-mode-step-agreed" role="tabpanel" aria-labelledby="' . esc_attr( RecordSpine::tabId( 'tt-pdp-mode-step-agreed' ) ) . '" tabindex="0" hidden>';
        foreach ( $shared as $field ) {
            echo '<div class="tt-pdp-mode__card">';
            echo '<h2 class="tt-pdp-mode__h">' . esc_html( (string) $field['label'] ) . '</h2>';
            echo '<div class="tt-pdp-mode__pair">';
            echo '<div><p class="tt-pdp-mode__plabel">' . esc_html__( 'The player wrote', 'talenttrack' ) . '</p>'
                . '<p class="tt-pdp-mode__text">' . self::text( $ready ? $field['player'] : null, $ready ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
            echo '<div><p class="tt-pdp-mode__plabel">' . esc_html__( 'What was agreed', 'talenttrack' ) . '</p>'
                . '<p class="tt-pdp-mode__text">' . self::text( $field['agreed'], true ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
            echo '</div></div>';
        }

        echo '<div class="tt-pdp-mode__card">';
        echo '<h2 class="tt-pdp-mode__h">' . esc_html__( 'Development points', 'talenttrack' ) . '</h2>';
        echo '<div class="tt-pdp-mode__goals">';
        foreach ( $goals as $goal ) {
            echo '<div class="tt-pdp-mode__goal">';
            echo '<h3 class="tt-pdp-mode__glabel">' . esc_html( (string) $goal['label'] ) . '</h3>';
            echo '<dl class="tt-pdp-mode__gfields">';
            echo '<dt>' . esc_html__( 'What the player does', 'talenttrack' ) . '</dt><dd>' . self::text( $goal['action'], true ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
            echo '<dt>' . esc_html__( 'How the trainer helps', 'talenttrack' ) . '</dt><dd>' . self::text( $goal['support'], true ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped in the helper.
            echo '<dt>' . esc_html__( 'Deadline', 'talenttrack' ) . '</dt><dd>' . esc_html( ! empty( $goal['due_date'] ) ? TTDate::date( (string) $goal['due_date'] ) : '—' ) . '</dd>';
            if ( ! empty( $goal['item_label'] ) ) {
                echo '<dt>' . esc_html__( 'Item it develops', 'talenttrack' ) . '</dt><dd>' . esc_html( (string) $goal['item_label'] ) . '</dd>';
            }
            echo '</dl></div>';
        }
        echo '</div></div>';
        echo '</section>';
    }

    /**
     * Escaped text with its line breaks, or a dash for nothing yet.
     *
     * @param mixed $value
     */
    private static function text( $value, bool $ready ): string {
        if ( ! $ready ) return esc_html__( 'not ready yet', 'talenttrack' );
        $value = trim( (string) ( $value ?? '' ) );
        return $value !== '' ? nl2br( esc_html( $value ) ) : '—';
    }

    /** @param mixed $value */
    private static function average( $value ): string {
        return $value === null ? '—' : number_format_i18n( (float) $value, 1 );
    }

    private static function panelId( string $section_key ): string {
        return 'tt-pdp-mode-step-' . sanitize_html_class( $section_key );
    }

    private static function nextLabel( string $next ): string {
        return sprintf(
            /* translators: %s: the name of the next step */
            _x( 'Next: %s', 'PDP conversation mode: the step after', 'talenttrack' ),
            $next
        );
    }

    private static function enqueue( int $conv_id ): void {
        parent::enqueueAssets();
        FormAutosave::enqueue();
        RatingInputComponent::enqueue();

        wp_enqueue_style( 'tt-frontend-pop', TT_PLUGIN_URL . 'assets/css/frontend-pop.css', [ 'tt-frontend-app-chrome' ], TT_VERSION );
        wp_enqueue_style( 'tt-frontend-pdp-conversation-mode', TT_PLUGIN_URL . 'assets/css/frontend-pdp-conversation-mode.css', [ 'tt-frontend-pop' ], TT_VERSION );

        wp_enqueue_script( 'tt-pdp-coach-form', TT_PLUGIN_URL . 'assets/js/components/pdp-coach-form.js', [ 'tt-form-autosave', 'tt-rating-input' ], TT_VERSION, true );
        wp_enqueue_script( 'tt-pdp-conversation-mode', TT_PLUGIN_URL . 'assets/js/components/pdp-conversation-mode.js', [ 'tt-pdp-coach-form' ], TT_VERSION, true );
        wp_localize_script( 'tt-pdp-conversation-mode', 'TT_PdpConversationMode', [
            'conversation' => $conv_id,
            'i18n'         => [
                /* translators: %s: the name of the next step */
                'next'   => _x( 'Next: %s', 'PDP conversation mode: the step after', 'talenttrack' ),
                'done'   => _x( 'Last step', 'PDP conversation mode: no step after this one', 'talenttrack' ),
                'closed' => __( 'The form can no longer be saved. Reload the page to see why.', 'talenttrack' ),
            ],
        ] );
    }
}
