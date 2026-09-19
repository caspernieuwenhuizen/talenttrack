<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Services\PdpFamilyReader;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Dates\TTDate;

/**
 * FrontendMyPdpView — the player's timeline-first development view (#1990).
 *
 * The season is the spine: the development conversations
 * (ontwikkelgesprekken) sit on a horizontal rail as markers — done /
 * overdue / next / future. Tapping a marker expands that conversation's detail
 * inline (no long scroll). Below the rail: the player's active focus
 * goals, then a single self-reflection input for the one next-planned
 * conversation (when its 2-week window is open), with any saved
 * reflection shown alongside. The end-of-season verdict card closes
 * the page.
 *
 * Same surface for parents (resolved via tt_player_parents → wp_user_id),
 * read-only: parents see the timeline + saved reflection but no editable
 * reflection input, and acknowledge via their own ack column.
 *
 * Business logic (which talk is "next planned", whether its window is
 * open, the cycle state) lives in PdpCycleState — this view only
 * composes (CLAUDE.md §4).
 *
 * #3645 — what the page shows is assembled by
 * `\TT\Modules\Pdp\Services\PdpFamilyReader`, which also answers
 * `GET /players/{id}/pdp`. The screen and the family API therefore read
 * the same projection: which conversation is next, which notes have been
 * signed off and are safe to show, which goals were discussed. Before
 * this the view built all of that itself and the API had no family route
 * at all, so a parent could acknowledge a talk over REST that they had no
 * way of reading.
 */
class FrontendMyPdpView extends FrontendViewBase {

    /**
     * Enqueue the PDP chrome stylesheet on top of the shared frontend
     * assets. Depends on the global app-chrome sheet for the shared
     * tokens; brand colours arrive at runtime from BrandStyles.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-pop',
            TT_PLUGIN_URL . 'assets/css/frontend-pop.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-my-pdp',
            TT_PLUGIN_URL . 'assets/js/components/my-pdp.js',
            [],
            TT_VERSION,
            true
        );
    }

    public static function render( object $player ): void {
        self::enqueueAssets();

        // A parent reaching this for their child sees the child's name
        // framing, not "My …".
        //
        // #3477 — `$is_parent` used to be `! $is_self`, which made every
        // non-player reader a parent: a coach opening a player's plan through
        // `?player_id` was offered the parent-acknowledgement control on each
        // conversation. The voice tells the three readers apart.
        $voice     = \TT\Shared\Frontend\Components\SubjectVoice::forPlayer( $player );
        $is_self   = $voice->isSelf();
        $is_parent = $voice->isParent();
        $title     = $voice->pick(
            __( 'My development plan', 'talenttrack' ),
            sprintf(
                /* translators: %s = the player's name, to a parent or a coach. */
                __( "%s's development plan", 'talenttrack' ),
                $voice->name()
            )
        );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $plan   = ( new PdpFamilyReader() )->forPlayer( (int) $player->id );
        $season = $plan['season'];

        if ( $season === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'No current season is set.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $plan['file'] === null ) {
            $msg = $is_self
                ? __( 'No PDP file has been opened for you this season yet.', 'talenttrack' )
                : __( 'No PDP file has been opened for this player this season yet.', 'talenttrack' );
            echo '<p><em>' . esc_html( $msg ) . '</em></p>';
            return;
        }

        // The single next-planned conversation: the only talk that may
        // carry an editable self-reflection. Derived in the domain layer.
        $next_planned = null;
        foreach ( $plan['conversations'] as $conv ) {
            if ( (int) $conv['id'] === $plan['next_conversation_id'] && $plan['next_conversation_id'] > 0 ) {
                $next_planned = $conv;
                break;
            }
        }

        self::renderSeasonTimeline(
            $season['name'],
            $plan['conversations'],
            (int) $plan['next_conversation_id'],
            $is_self,
            $is_parent
        );
        self::renderActiveGoals( $player, $voice, $plan['goals'] );
        self::renderSelfReflection( $next_planned, $is_self, $voice );

        $verdict = $plan['verdict'];
        if ( $verdict !== null ) {
            self::renderVerdictCard( $verdict );
        }
    }

    /**
     * Season timeline — the spine. A horizontal rail of conversation
     * markers (done / overdue / next / future) with a progress fill up to
     * the latest completed talk. Each marker is a real <button>; tapping it
     * expands that conversation's detail panel inline below the rail
     * (no long scroll). Keyboard-operable; Escape closes via my-pdp.js.
     *
     * `aria-current="step"` marks the next talk in the cycle, which stays
     * the next talk whether or not its date has slipped (#3692) — so it is
     * keyed to the reader's `next_conversation_id`, not to the chip state.
     *
     * @param list<array<string,mixed>> $convs
     */
    private static function renderSeasonTimeline( string $season_name, array $convs, int $next_id, bool $is_self, bool $is_parent ): void {
        $done = 0;
        foreach ( $convs as $c ) {
            if ( $c['state'] === 'done' ) $done++;
        }
        $total = max( 1, count( $convs ) );
        $fill  = (int) round( ( $done / $total ) * 100 );

        echo '<section class="tt-card tt-pdp-season">';
        echo '<div class="tt-pdp-season-head">';
        echo '<h2 class="tt-pdp-season__name">' . esc_html( sprintf(
            /* translators: %s = season name */
            __( 'Season %s', 'talenttrack' ),
            $season_name
        ) ) . '</h2>';
        echo '<span class="tt-pdp-season__meta">' . esc_html( sprintf(
            /* translators: %d = number of development conversations in the season */
            _n( '%d development conversation', '%d development conversations', count( $convs ), 'talenttrack' ),
            count( $convs )
        ) ) . '</span>';
        echo '</div>';

        if ( empty( $convs ) ) {
            echo '<p class="tt-pdp-empty">' . esc_html__( 'No development conversations have been planned yet.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }

        echo '<div class="tt-pdp-rail" aria-label="' . esc_attr__( 'Season timeline with development conversations', 'talenttrack' ) . '">';
        echo '<div class="tt-pdp-track">';
        // Progress fill up to the latest completed talk. Width is a
        // genuinely dynamic value derived from the data — allowed inline.
        echo '<span class="tt-pdp-fill" style="width:' . (int) $fill . '%"></span>'; /* tt-inline-ok */

        foreach ( $convs as $c ) {
            $cid   = (int) $c['id'];
            $state = (string) $c['state'];
            $label = (string) $c['template_label'];
            $date  = self::markerDate( $c, $state );
            $panel = 'tt-pdp-panel-' . $cid;

            echo '<button type="button" class="tt-pdp-marker ' . esc_attr( $state ) . '"'
                . ' aria-expanded="false" aria-controls="' . esc_attr( $panel ) . '"'
                . ( $next_id > 0 && $cid === $next_id ? ' aria-current="step"' : '' ) . '>';
            echo '<span class="tt-pdp-dot">' . self::markerGlyph( $c, $state ) . '</span>';
            echo '<span class="tt-pdp-mlabel">' . esc_html( $label ) . '</span>';
            if ( $date !== '' ) {
                echo '<span class="tt-pdp-mdate">' . esc_html( $date ) . '</span>';
            }
            echo '<span class="tt-pdp-chip ' . esc_attr( $state ) . '">' . esc_html( self::stateLabel( $state ) ) . '</span>';
            echo '</button>';
        }

        echo '</div>'; // .tt-pdp-track
        echo '</div>'; // .tt-pdp-rail

        // Inline detail panels — one per conversation, hidden until its
        // marker is tapped. Holds notes / agreed actions / goals
        // discussed / saved reflection, and the acknowledgement flow
        // (unchanged from before).
        echo '<div class="tt-pdp-panels">';
        foreach ( $convs as $c ) {
            self::renderConversationPanel( $c, $is_self, $is_parent );
        }
        echo '</div>';

        echo '</section>';
    }

    /**
     * The inline detail for one conversation, revealed when its marker is
     * tapped. Read content + the acknowledgement flow (preserved as-is).
     * The editable self-reflection lives in its own dedicated section,
     * not here.
     *
     * @param array<string,mixed> $conv one entry from PdpFamilyReader.
     */
    private static function renderConversationPanel( array $conv, bool $is_self, bool $is_parent ): void {
        $cid    = (int) $conv['id'];
        $signed = $conv['coach_signoff_at'] !== null;
        $title  = sprintf(
            /* translators: %1$d sequence, %2$s template */
            __( 'Conversation %1$d (%2$s)', 'talenttrack' ),
            (int) $conv['sequence'],
            (string) $conv['template_label']
        );

        echo '<div id="tt-pdp-panel-' . (int) $cid . '" class="tt-pdp-panel" hidden>';
        echo '<h3 class="tt-pdp-panel__title">' . esc_html( $title ) . '</h3>';

        $meta = [];
        if ( $conv['scheduled_at'] !== null ) {
            $meta[] = sprintf(
                /* translators: %s = date */
                __( 'Scheduled %s', 'talenttrack' ),
                substr( (string) $conv['scheduled_at'], 0, 16 )
            );
        }
        if ( $conv['conducted_at'] !== null ) {
            $meta[] = sprintf(
                /* translators: %s = date */
                __( 'Conducted %s', 'talenttrack' ),
                substr( (string) $conv['conducted_at'], 0, 16 )
            );
        }
        if ( ! empty( $meta ) ) {
            echo '<p class="tt-pdp-panel__meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        }

        // The reader has already withheld both until the coach signs the
        // talk off, so there is nothing left for the view to decide.
        if ( $conv['notes'] !== null ) {
            echo '<div class="tt-pop-bubble"><strong>' . esc_html__( 'Notes', 'talenttrack' ) . '</strong><div>'
                . wp_kses_post( (string) $conv['notes'] ) . '</div></div>';
        }
        if ( $conv['agreed_actions'] !== null ) {
            echo '<div class="tt-pop-bubble"><strong>' . esc_html__( 'Agreed actions', 'talenttrack' ) . '</strong><div>'
                . wp_kses_post( (string) $conv['agreed_actions'] ) . '</div></div>';
        }

        // #3306 (epic #3301) — the coach's `agenda` used to appear here on
        // an upcoming talk. It is now their preparation, and preparation is
        // coach + head-of-academy only: it is where a coach writes candidly
        // about a minor before sitting down with them. What the player and
        // their family see is what was agreed in the talk itself — the
        // notes and the agreed actions above.

        // Goals discussed in this talk (the self-review reflects on these).
        $titles = is_array( $conv['goals_discussed'] ) ? $conv['goals_discussed'] : [];
        if ( ! empty( $titles ) ) {
            echo '<div class="tt-pop-bubble"><strong>' . esc_html__( 'Goals discussed', 'talenttrack' ) . '</strong><ul class="tt-pop-goal-discussed">';
            foreach ( $titles as $t ) {
                echo '<li>' . esc_html( (string) $t ) . '</li>';
            }
            echo '</ul></div>';
        }

        // A previously-saved reflection is shown read-only here; the
        // editable input for the next-planned talk lives in its own
        // section below the goals.
        if ( $conv['player_reflection'] !== null ) {
            echo '<div class="tt-pop-bubble"><strong>' . esc_html__( 'Self-reflection', 'talenttrack' ) . '</strong><div>'
                . wp_kses_post( (string) $conv['player_reflection'] ) . '</div></div>';
        }

        // Acknowledgement flow — preserved exactly as-is (out of scope of
        // the redesign): once the coach has signed off.
        if ( $signed ) {
            $rest_path = 'pdp-conversations/' . $cid;
            if ( $is_self && $conv['player_ack_at'] === null ) {
                ?>
                <form class="tt-ajax-form tt-pdp-ack" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PATCH" data-redirect-after-save="reload">
                    <input type="hidden" name="player_ack_at" value="<?php echo esc_attr( current_time( 'mysql', true ) ); ?>" />
                    <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm"><?php esc_html_e( 'I acknowledge this conversation', 'talenttrack' ); ?></button>
                    <div class="tt-form-msg"></div>
                </form>
                <?php
            } elseif ( $conv['player_ack_at'] !== null ) {
                echo '<p class="tt-pdp-acked"><em>' . esc_html__( 'You acknowledged this conversation.', 'talenttrack' ) . '</em></p>';
            }
            if ( $is_parent && $conv['parent_ack_at'] === null ) {
                ?>
                <form class="tt-ajax-form tt-pdp-ack" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PATCH" data-redirect-after-save="reload">
                    <input type="hidden" name="parent_ack_at" value="<?php echo esc_attr( current_time( 'mysql', true ) ); ?>" />
                    <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm"><?php esc_html_e( 'Acknowledge as parent / guardian', 'talenttrack' ); ?></button>
                    <div class="tt-form-msg"></div>
                </form>
                <?php
            } elseif ( $conv['parent_ack_at'] !== null ) {
                echo '<p class="tt-pdp-acked"><em>' . esc_html__( 'A parent acknowledged this conversation.', 'talenttrack' ) . '</em></p>';
            }
        }

        echo '</div>';
    }

    /**
     * Active focus goals below the timeline — the player's current
     * non-archived, not-completed goals (not the full archive). Status
     * label is goal-specific via `status_localised` ("In ontwikkeling"),
     * not a generic "Pending".
     *
     * @param list<array<string,mixed>> $goals from PdpFamilyReader.
     */
    private static function renderActiveGoals( object $player, \TT\Shared\Frontend\Components\SubjectVoice $voice, array $goals ): void {
        echo '<section class="tt-card">';
        echo '<p class="tt-eyebrow">' . esc_html( $voice->pick(
            __( 'Your active goals', 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( "%s's active goals", 'talenttrack' ), $voice->firstName() )
        ) ) . '</p>';
        echo '<h2 class="tt-pdp-goals__h">' . esc_html( $voice->pick(
            __( 'What you are working on now', 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( 'What %s is working on now', 'talenttrack' ), $voice->firstName() )
        ) ) . '</h2>';

        if ( empty( $goals ) ) {
            echo '<p class="tt-pdp-empty">' . esc_html( $voice->pick(
                __( 'No active goals yet. Your coach will set some during your next talk.', 'talenttrack' ),
                /* translators: %s = the player's first name. */
                sprintf( __( 'No active goals yet. A coach will set some at the next development talk with %s.', 'talenttrack' ), $voice->firstName() )
            ) ) . '</p>';
            echo '</section>';
            return;
        }

        // #3397 — a parent reading their child's PDP needs `player_id` on
        // the goal link; the player themselves does not.
        $is_self   = (int) ( $player->wp_user_id ?? 0 ) === get_current_user_id();
        $parent_id = $is_self ? null : (int) $player->id;

        echo '<div class="tt-goal-grid">';
        foreach ( $goals as $g ) {
            $status = (string) $g['status_localised'];
            // #3397 — through TTDate, like every other surface showing this
            // date. The raw column value read as 2026-05-14 here and as
            // 14 mei 2026 one click away on My goals.
            $due = $g['due_date'] !== null
                ? \TT\Shared\Dates\TTDate::date( (string) $g['due_date'] )
                : '';
            // #3397 — the card opens the goal. This is the surface that says
            // "what you are working on now", sitting directly under the
            // conversation the goal came out of, and it was the only place a
            // goal could not be opened.
            $goal_url = \TT\Shared\Frontend\Components\RecordLink::meDetailUrl(
                'my-goals',
                (int) $g['id'],
                $parent_id
            );
            if ( $goal_url !== '' ) {
                echo '<a class="tt-goal tt-record-link" href="' . esc_url( $goal_url ) . '">';
            } else {
                echo '<div class="tt-goal">';
            }
            echo '<div class="tt-goal__top">';
            // #3397 — the same translation layer My goals renders the title
            // through, so the two surfaces agree in a non-English locale.
            // The reader applies it, so every consumer gets it.
            echo '<span class="tt-goal__title">' . esc_html( (string) $g['title'] ) . '</span>';
            if ( $status !== '' ) {
                echo '<span class="tt-goal__status">' . esc_html( $status ) . '</span>';
            }
            echo '</div>';
            if ( $due !== '' ) {
                echo '<span class="tt-goal__due">' . esc_html( sprintf(
                    /* translators: %s = goal due date */
                    __( 'Target date: %s', 'talenttrack' ),
                    $due
                ) ) . '</span>';
            }
            echo $goal_url !== '' ? '</a>' : '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * Self-reflection — the single next-planned conversation only. Never
     * more than one form. Past and future talks never render an input.
     * Input on the left, any saved reflection on the right at ≥768px;
     * stacked on mobile (`.tt-reflect-split`). Parents see the saved
     * reflection read-only, no input.
     *
     * @param array<string,mixed>|null $conv the next-planned talk, or null.
     */
    private static function renderSelfReflection( ?array $conv, bool $is_self, \TT\Shared\Frontend\Components\SubjectVoice $voice ): void {
        if ( $conv === null ) return;

        $cid    = (int) $conv['id'];
        $label  = (string) $conv['template_label'];
        $date   = self::formatTalkDate( substr( (string) ( $conv['scheduled_at'] ?? '' ), 0, 10 ) );
        $saved  = (string) ( $conv['player_reflection'] ?? '' );
        $open   = (bool) $conv['reflection_window_open'];

        echo '<section class="tt-card tt-reflect">';
        echo '<p class="tt-eyebrow">' . esc_html( $voice->pick(
            __( 'Preparing for your talk', 'talenttrack' ),
            /* translators: %s = the player's first name. */
            sprintf( __( "Preparing for %s's talk", 'talenttrack' ), $voice->firstName() )
        ) ) . '</p>';
        $heading = $date !== ''
            ? sprintf(
                /* translators: %1$s = conversation template label, %2$s = talk date */
                __( 'Self-reflection · %1$s (%2$s)', 'talenttrack' ),
                $label, $date
            )
            : sprintf(
                /* translators: %s = conversation template label */
                __( 'Self-reflection · %s', 'talenttrack' ),
                $label
            );
        echo '<h2 class="tt-reflect__h">' . esc_html( $heading ) . '</h2>';

        // Parents: read-only — show the saved reflection, no input.
        if ( ! $is_self ) {
            if ( $saved !== '' ) {
                echo '<div class="tt-saved"><div class="tt-saved__body">' . wp_kses_post( $saved ) . '</div></div>';
            } else {
                echo '<p class="tt-pdp-empty">' . esc_html__( 'No self-reflection has been added for this talk yet.', 'talenttrack' ) . '</p>';
            }
            echo '</section>';
            return;
        }

        echo '<p class="tt-reflect__intro">' . esc_html__( 'Write your own reflection before the talk. Only the next planned talk can take a reflection — the other talks cannot. It is optional, never required.', 'talenttrack' ) . '</p>';

        if ( ! $open ) {
            echo '<p class="tt-reflect__guard">' . esc_html__( 'The reflection window opens 2 weeks before the talk. Check back closer to the planned date.', 'talenttrack' ) . '</p>';
        }

        echo '<div class="tt-reflect-split">';

        // LEFT: input (disabled until the window opens).
        $rest_path = 'pdp-conversations/' . $cid;
        $disabled  = $open ? '' : ' disabled';
        ?>
        <?php
        // #3008 (epic #2881) — the reflection saves itself while the window
        // is open, and stops with the window. A player writing about their
        // own season is composing, and a teenager typing on a phone is the
        // last person who should lose a paragraph to a mis-tap.
        //
        // Closed window means no autosave *and* no Save button: the field
        // is disabled either way, and the endpoint would answer 403.
        if ( $open ) {
            \TT\Shared\Frontend\Components\FormAutosave::enqueue();
        }
        ?>
        <form class="<?php echo $open ? 'tt-autosave-form' : 'tt-ajax-form'; ?>"
              <?php if ( $open ) {
                  echo \TT\Shared\Frontend\Components\FormAutosave::formAttrs( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the component escapes each attribute
                      $rest_path,
                      'PATCH',
                      'pdp-reflection:' . $cid
                  );
              } else {
                  printf(
                      'data-rest-path="%s" data-rest-method="PATCH" data-redirect-after-save="reload"',
                      esc_attr( $rest_path )
                  );
              } ?>>
            <label class="tt-field-label" for="tt-myrefl-<?php echo (int) $cid; ?>"><?php esc_html_e( 'Add or update your self-reflection', 'talenttrack' ); ?></label>
            <textarea id="tt-myrefl-<?php echo (int) $cid; ?>" name="player_reflection" class="tt-input" rows="5" inputmode="text"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static literal ?>><?php echo esc_textarea( $saved ); ?></textarea>
            <div class="tt-form-actions">
                <?php if ( $open ) {
                    \TT\Shared\Frontend\Components\SaveState::render();
                } else { ?>
                    <button type="submit" class="tt-btn tt-btn-primary" disabled><?php esc_html_e( 'Save', 'talenttrack' ); ?></button>
                <?php } ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php

        // RIGHT: previously-saved reflection (stacked below on mobile).
        if ( $saved !== '' ) {
            echo '<div class="tt-saved">';
            if ( $conv['updated_at'] !== null ) {
                echo '<div class="tt-saved__when">' . esc_html( sprintf(
                    /* translators: %s = last-saved timestamp */
                    __( 'Last saved · %s', 'talenttrack' ),
                    substr( (string) $conv['updated_at'], 0, 16 )
                ) ) . '</div>';
            }
            echo '<div class="tt-saved__body">' . wp_kses_post( $saved ) . '</div>';
            echo '</div>';
        } else {
            echo '<div class="tt-saved tt-saved--empty">' . esc_html__( 'No reflection saved yet.', 'talenttrack' ) . '</div>';
        }

        echo '</div>'; // .tt-reflect-split
        echo '</section>';
    }

    /** @param array<string,mixed> $verdict from PdpFamilyReader. */
    private static function renderVerdictCard( array $verdict ): void {
        echo '<section class="tt-card tt-pop-goal tt-pop-goal--done">';
        echo '<h2 class="tt-pdp-verdict__h">' . esc_html__( 'End-of-season verdict', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-pdp-verdict__row"><strong>' . esc_html__( 'Decision:', 'talenttrack' ) . '</strong> '
            . esc_html( (string) $verdict['decision_localised'] ) . '</p>';
        if ( ! empty( $verdict['summary'] ) ) {
            echo '<div class="tt-pdp-verdict__summary">' . wp_kses_post( (string) $verdict['summary'] ) . '</div>';
        }
        if ( $verdict['signed_off_at'] !== null ) {
            echo '<p class="tt-pdp-verdict__signoff"><em>' . esc_html( sprintf(
                /* translators: %s = signoff timestamp */
                __( 'Signed off on %s', 'talenttrack' ),
                (string) $verdict['signed_off_at']
            ) ) . '</em></p>';
        }
        echo '</section>';
    }

    /**
     * The glyph inside a marker dot: ✓ for done, sequence otherwise.
     *
     * @param array<string,mixed> $conv
     */
    private static function markerGlyph( array $conv, string $state ): string {
        if ( $state === 'done' ) return '&#10003;';
        return (string) (int) $conv['sequence'];
    }

    /**
     * Date shown under a marker — conducted date for done, else scheduled.
     *
     * @param array<string,mixed> $conv
     */
    private static function markerDate( array $conv, string $state ): string {
        $raw = $state === 'done' && $conv['conducted_at'] !== null
            ? substr( (string) $conv['conducted_at'], 0, 10 )
            : substr( (string) ( $conv['scheduled_at'] ?? '' ), 0, 10 );
        return self::formatTalkDate( $raw );
    }

    private static function stateLabel( string $state ): string {
        switch ( $state ) {
            case 'done': return __( 'Completed', 'talenttrack' );
            case 'next': return __( 'Planned', 'talenttrack' );
            // #3692 — a one-word chip, so it carries a context: on its own
            // "Overdue" translates as easily to a late payment as to a talk
            // that never happened.
            case 'overdue': return _x( 'Overdue', 'pdp talk state', 'talenttrack' );
        }
        return __( 'Later', 'talenttrack' );
    }

    /** Human-friendly talk date (locale-aware), or '' when unscheduled. */
    private static function formatTalkDate( ?string $ymd ): string {
        if ( $ymd === null || $ymd === '' ) return '';
        $ts = strtotime( $ymd . ' UTC' );
        if ( $ts === false ) return $ymd;
        return TTDate::date( $ts );
    }

}
