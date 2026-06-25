<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyPdpView — read-only PDP file for the linked player. Same
 * surface for parents (resolved via tt_player_parents → wp_user_id).
 *
 * The "ack" buttons hit /pdp-conversations/{id} to set parent_ack_at
 * or player_ack_at. Self-reflection is editable on the open conversation
 * for the player themselves; parents see a read-only preview.
 */
class FrontendMyPdpView extends FrontendViewBase {

    /**
     * #1686 — enqueue the POP / PDP chrome stylesheet on top of the
     * shared frontend assets. Loaded here (not in FrontendViewBase)
     * because only the PDP surfaces use it; depends on the global
     * app-chrome sheet for the shared tokens.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-pop',
            TT_PLUGIN_URL . 'assets/css/frontend-pop.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( object $player ): void {
        self::enqueueAssets();

        // #1849 — a parent reaching this for their child sees the child's
        // name framing, not "My …".
        $is_self   = (int) ( $player->wp_user_id ?? 0 ) === get_current_user_id();
        $is_parent = ! $is_self;
        $title     = $is_self
            ? __( 'My development plan', 'talenttrack' )
            : sprintf(
                /* translators: %s = the child's name (parent viewing their child) */
                __( "%s's development plan", 'talenttrack' ),
                \TT\Infrastructure\Query\QueryHelpers::player_display_name( $player )
            );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $current = ( new SeasonsRepository() )->current();
        if ( ! $current ) {
            echo '<p class="tt-notice">' . esc_html__( 'No current season is set.', 'talenttrack' ) . '</p>';
            return;
        }

        $files = new PdpFilesRepository();
        $file  = $files->findByPlayerSeason( (int) $player->id, (int) $current->id );
        if ( ! $file ) {
            $msg = $is_self
                ? __( 'No PDP file has been opened for you this season yet.', 'talenttrack' )
                : __( 'No PDP file has been opened for this player this season yet.', 'talenttrack' );
            echo '<p><em>' . esc_html( $msg ) . '</em></p>';
            return;
        }

        $convs   = ( new PdpConversationsRepository() )->listForFile( (int) $file->id );
        $verdict = ( new PdpVerdictsRepository() )->findForFile( (int) $file->id );

        echo '<p style="color:var(--tt-muted,#5b6e75); margin-bottom:0.75rem;">' . esc_html( sprintf(
            /* translators: %s = season name */
            __( 'Season: %s', 'talenttrack' ),
            (string) $current->name
        ) ) . '</p>';

        // #1851 — surface where the player is in the development-talk
        // cycle. The state is derived (PdpCycleState) and only *promotes*
        // the existing surfaces by state — it never gates: every
        // conversation card, the self-reflection editor and the ack
        // flow stay exactly as they were below.
        $viewer = $is_parent ? PdpCycleState::VIEWER_PARENT : PdpCycleState::VIEWER_PLAYER;
        $cycle  = PdpCycleState::derive( $convs, $viewer );
        self::renderCycleLead( $cycle, $player, $is_self );

        // #1686 — section anchor for the conversation cards, matching the
        // mockup's "Gesprek" panel label. In a working period the talks
        // recede behind the goals lead, so the heading is muted.
        $secondary = $cycle->state === PdpCycleState::WORKING ? ' tt-pop-gesprek__title--secondary' : '';
        echo '<h2 class="tt-pop-gesprek__title' . esc_attr( $secondary ) . '">' . esc_html__( 'Conversation', 'talenttrack' ) . '</h2>';

        // Promote the focus conversation (the one the state is keyed to)
        // to the top in the review-window / post moments, so "prepare for
        // your talk" / "review your last talk" is the first card.
        foreach ( self::orderConversations( $convs, $cycle ) as $c ) {
            self::renderConversationCard( $file, $c, $is_self, $is_parent );
        }

        if ( $verdict !== null ) {
            self::renderVerdictCard( $verdict );
        }
    }

    /**
     * #1851 — order conversation cards so the focus conversation leads in
     * the REVIEW_WINDOW / POST moments. Working / idle keep the natural
     * sequence order. Pure reordering — no row is added or dropped.
     *
     * @param array<int, object> $convs
     * @return array<int, object>
     */
    private static function orderConversations( array $convs, PdpCycleState $cycle ): array {
        $focus = $cycle->conversation;
        if ( $focus === null
            || ( $cycle->state !== PdpCycleState::REVIEW_WINDOW && $cycle->state !== PdpCycleState::POST ) ) {
            return $convs;
        }
        $focus_id = (int) ( $focus->id ?? 0 );
        $lead     = [];
        $rest     = [];
        foreach ( $convs as $c ) {
            if ( (int) ( $c->id ?? 0 ) === $focus_id ) {
                $lead[] = $c;
            } else {
                $rest[] = $c;
            }
        }
        return array_merge( $lead, $rest );
    }

    /**
     * #1851 — the state-aware lead block. Orients the player at the top
     * of My PDP:
     *
     *   WORKING       — "Your focus": top active goals + the next-talk
     *                   date. The talks below recede.
     *   REVIEW_WINDOW — "Prepare for your talk on <date>" — helpful, not
     *                   required. The self-review editor + agenda live in
     *                   the promoted conversation card just below.
     *   POST          — "Your last development talk" — points at the
     *                   notes / agreed actions / acknowledgement below,
     *                   plus the goals to carry forward.
     *
     * Renders nothing for IDLE. Parents see the same surface, read-only.
     */
    private static function renderCycleLead( PdpCycleState $cycle, object $player, bool $is_self ): void {
        if ( $cycle->state === PdpCycleState::IDLE ) return;

        $date = self::formatTalkDate( $cycle->talk_date );

        echo '<div class="tt-pop-lead tt-pop-lead--' . esc_attr( $cycle->state ) . '">';

        if ( $cycle->state === PdpCycleState::WORKING ) {
            echo '<p class="tt-pop-lead__eyebrow">' . esc_html__( 'Your focus', 'talenttrack' ) . '</p>';
            if ( $date !== '' ) {
                echo '<p class="tt-pop-lead__headline">' . esc_html( sprintf(
                    /* translators: %s = date of the next development talk */
                    __( 'Next development talk: %s', 'talenttrack' ),
                    $date
                ) ) . '</p>';
            } else {
                echo '<p class="tt-pop-lead__headline">' . esc_html__( 'Your next development talk will be planned soon.', 'talenttrack' ) . '</p>';
            }
            self::renderFocusGoals( $player, $is_self );
        } elseif ( $cycle->state === PdpCycleState::REVIEW_WINDOW ) {
            echo '<p class="tt-pop-lead__eyebrow">' . esc_html__( 'Coming up', 'talenttrack' ) . '</p>';
            $headline = $date !== ''
                ? sprintf(
                    /* translators: %s = date of the upcoming development talk */
                    __( 'Prepare for your talk on %s', 'talenttrack' ),
                    $date
                )
                : __( 'Prepare for your upcoming development talk', 'talenttrack' );
            echo '<p class="tt-pop-lead__headline">' . esc_html( $headline ) . '</p>';
            $hint = $is_self
                ? __( 'Adding a short self-reflection before your talk helps your coach. It is optional, never required. You can find it on the talk just below.', 'talenttrack' )
                : __( 'A self-reflection can be added before the talk to help the coach. It is optional, never required.', 'talenttrack' );
            echo '<p class="tt-pop-lead__hint">' . esc_html( $hint ) . '</p>';
        } elseif ( $cycle->state === PdpCycleState::POST ) {
            echo '<p class="tt-pop-lead__eyebrow">' . esc_html__( 'Your last development talk', 'talenttrack' ) . '</p>';
            $headline = $date !== ''
                ? sprintf(
                    /* translators: %s = date of the most recent development talk */
                    __( 'Your talk on %s is ready to review', 'talenttrack' ),
                    $date
                )
                : __( 'Your last talk is ready to review', 'talenttrack' );
            echo '<p class="tt-pop-lead__headline">' . esc_html( $headline ) . '</p>';
            echo '<p class="tt-pop-lead__hint">' . esc_html__( 'See the notes and agreed actions below, and acknowledge the talk. These are the goals to carry forward.', 'talenttrack' ) . '</p>';
            self::renderFocusGoals( $player, $is_self );
        }

        echo '</div>';
    }

    /**
     * Top active goals as a compact "Your focus" list, linking through to
     * My goals. Read from the goals repository (no logic in the view).
     * The goal↔conversation data link is Phase 5 (#1853); for now this is
     * the player's current active goals.
     */
    private static function renderFocusGoals( object $player, bool $is_self ): void {
        $goals = ( new GoalsRepository() )->topActiveForPlayer( (int) $player->id, 3 );
        if ( empty( $goals ) ) {
            echo '<p class="tt-pop-lead__empty">' . esc_html__( 'No active goals yet. Your coach will set some during your next talk.', 'talenttrack' ) . '</p>';
            return;
        }
        echo '<ul class="tt-pop-lead__goals">';
        foreach ( $goals as $g ) {
            $title = (string) ( $g->title ?? '' );
            $due   = (string) ( $g->due_date ?? '' );
            echo '<li class="tt-pop-lead__goal">';
            echo '<span class="tt-pop-lead__goal-title">' . esc_html( $title ) . '</span>';
            if ( $due !== '' ) {
                echo '<span class="tt-pop-lead__goal-due">' . esc_html( sprintf(
                    /* translators: %s = goal due date */
                    __( 'Due %s', 'talenttrack' ),
                    $due
                ) ) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '<p class="tt-pop-lead__link"><a href="' . esc_url( self::goalsUrl( $player, $is_self ) ) . '">'
            . esc_html__( 'See all goals', 'talenttrack' ) . '</a></p>';
    }

    /**
     * Build the My goals URL, carrying player_id when a parent is viewing
     * their child so the scoped Me-view router resolves the right subject.
     */
    private static function goalsUrl( object $player, bool $is_self ): string {
        $base = remove_query_arg( [ 'tt_view', 'player_id', 'id', 'tt_back' ] );
        $url  = add_query_arg( 'tt_view', 'my-goals', $base ?: home_url( '/' ) );
        if ( ! $is_self ) {
            $url = add_query_arg( 'player_id', (int) $player->id, $url );
        }
        return $url;
    }

    /** Human-friendly talk date (locale-aware), or '' when unscheduled. */
    private static function formatTalkDate( ?string $ymd ): string {
        if ( $ymd === null || $ymd === '' ) return '';
        $ts = strtotime( $ymd . ' UTC' );
        if ( $ts === false ) return $ymd;
        return date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
    }

    private static function renderConversationCard( object $file, object $conv, bool $is_self, bool $is_parent ): void {
        $title = sprintf(
            /* translators: %1$d sequence, %2$s template */
            __( 'Conversation %1$d (%2$s)', 'talenttrack' ),
            (int) $conv->sequence,
            self::templateLabel( (string) $conv->template_key )
        );
        $signed = ! empty( $conv->coach_signoff_at );

        // #1686 — conversation cards restyled to the 2026 chrome:
        // signed-off meetings carry the gold "Gesprek" accent, pending
        // ones stay neutral. The bubble content + ack / reflection forms
        // below are unchanged.
        $card_mod = $signed ? ' tt-pop-goal--doing' : '';
        echo '<div class="tt-pop-goal' . esc_attr( $card_mod ) . '" style="margin-bottom:0.75rem;">';
        echo '<h3 style="margin:0 0 0.35rem; font-size:1rem; color:var(--tt-primary,#0b3d2e);">' . esc_html( $title ) . '</h3>';
        echo '<p style="margin:0 0 0.4rem; color:var(--tt-muted,#5b6e75); font-size:0.8rem;">';
        if ( ! empty( $conv->scheduled_at ) ) {
            echo esc_html( sprintf(
                /* translators: %s = date */
                __( 'Scheduled %s', 'talenttrack' ),
                substr( (string) $conv->scheduled_at, 0, 16 )
            ) );
        }
        if ( ! empty( $conv->conducted_at ) ) {
            echo ' · ' . esc_html( sprintf(
                /* translators: %s = date */
                __( 'Conducted %s', 'talenttrack' ),
                substr( (string) $conv->conducted_at, 0, 16 )
            ) );
        }
        echo '</p>';

        if ( $signed ) {
            // Show notes + agreed actions.
            if ( ! empty( $conv->notes ) ) {
                echo '<div class="tt-pop-bubble" style="margin-top:0.5rem;"><strong>' . esc_html__( 'Notes', 'talenttrack' ) . '</strong><div>'
                    . wp_kses_post( (string) $conv->notes ) . '</div></div>';
            }
            if ( ! empty( $conv->agreed_actions ) ) {
                echo '<div class="tt-pop-bubble" style="margin-top:0.5rem;"><strong>' . esc_html__( 'Agreed actions', 'talenttrack' ) . '</strong><div>'
                    . wp_kses_post( (string) $conv->agreed_actions ) . '</div></div>';
            }
        } else {
            // Pre-meeting: agenda + (player only) editable self-reflection.
            if ( ! empty( $conv->agenda ) ) {
                echo '<div class="tt-pop-bubble" style="margin-top:0.5rem;"><strong>' . esc_html__( 'Agenda', 'talenttrack' ) . '</strong><div>'
                    . wp_kses_post( (string) $conv->agenda ) . '</div></div>';
            }
        }

        // #1853 — goals discussed in this talk (the read side of the
        // "combine"): the self-review reflects on these goals.
        $gl_ids = ( new \TT\Modules\Pdp\Repositories\GoalLinksRepository() )->goalsForConversation( (int) $conv->id );
        if ( ! empty( $gl_ids ) ) {
            $goals_repo = new \TT\Infrastructure\Goals\GoalsRepository();
            $titles = [];
            foreach ( $gl_ids as $gid ) {
                $g = $goals_repo->findForPlayer( (int) $gid, (int) $file->player_id );
                if ( $g ) $titles[] = (string) ( $g->title ?? '' );
            }
            if ( ! empty( $titles ) ) {
                echo '<div class="tt-pop-bubble"><strong>' . esc_html__( 'Goals discussed', 'talenttrack' ) . '</strong><ul class="tt-pop-goal-discussed">';
                foreach ( $titles as $t ) {
                    echo '<li>' . esc_html( $t ) . '</li>';
                }
                echo '</ul></div>';
            }
        }

        if ( ! empty( $conv->player_reflection ) ) {
            echo '<div class="tt-pop-bubble" style="margin-top:0.5rem;">';
            echo '<strong>' . esc_html__( 'Self-reflection', 'talenttrack' ) . '</strong>';
            echo '<div>' . wp_kses_post( (string) $conv->player_reflection ) . '</div>';
            echo '</div>';
        }

        // Editable self-reflection — player only, before sign-off.
        //
        // v3.110.x — gated to a 2-week pre-meeting window: the form
        // opens 14 days before `scheduled_at` and stays open until
        // the coach signs off. Earlier behaviour opened it as soon as
        // the conversation existed, which surfaced an empty
        // self-reflection input months ahead of the meeting and
        // confused players ("am I supposed to write something now?").
        // When the meeting hasn't been scheduled yet (`scheduled_at`
        // empty) or it's > 2 weeks away, render an explainer line so
        // the player understands when the prompt will reappear.
        if ( $is_self && ! $signed ) {
            $window_open = self::selfReflectionWindowOpen( $conv );
            if ( $window_open ) {
                $rest_path = 'pdp-conversations/' . (int) $conv->id;
                ?>
                <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PATCH" data-redirect-after-save="reload" style="margin-top:8px;">
                    <label class="tt-field-label" for="tt-myrefl-<?php echo (int) $conv->id; ?>"><?php esc_html_e( 'Add or update your self-reflection', 'talenttrack' ); ?></label>
                    <textarea id="tt-myrefl-<?php echo (int) $conv->id; ?>" name="player_reflection" class="tt-input" rows="3"><?php echo esc_textarea( (string) ( $conv->player_reflection ?? '' ) ); ?></textarea>
                    <div class="tt-form-actions" style="margin-top:8px;">
                        <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm"><?php esc_html_e( 'Save reflection', 'talenttrack' ); ?></button>
                    </div>
                    <div class="tt-form-msg"></div>
                </form>
                <?php
            } else {
                echo '<p class="tt-muted" style="margin: 8px 0 0; font-size: 13px;">';
                echo esc_html__( 'You can add your self-reflection up to 2 weeks before this meeting. Check back closer to the planned date.', 'talenttrack' );
                echo '</p>';
            }
        }

        // Acknowledge buttons — once the coach has signed off.
        if ( $signed ) {
            $rest_path = 'pdp-conversations/' . (int) $conv->id;
            if ( $is_self && empty( $conv->player_ack_at ) ) {
                ?>
                <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PATCH" data-redirect-after-save="reload" style="margin-top:8px;">
                    <input type="hidden" name="player_ack_at" value="<?php echo esc_attr( current_time( 'mysql', true ) ); ?>" />
                    <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm"><?php esc_html_e( 'I acknowledge this conversation', 'talenttrack' ); ?></button>
                    <div class="tt-form-msg"></div>
                </form>
                <?php
            } elseif ( ! empty( $conv->player_ack_at ) ) {
                // v3.92.5 — switched inline color to a semantic class so
                // the success-green token can be re-themed via the
                // Theme & fonts surface without a code change.
                echo '<p class="tt-pdp-acked"><em>' . esc_html__( 'You acknowledged this conversation.', 'talenttrack' ) . '</em></p>';
            }
            if ( $is_parent && empty( $conv->parent_ack_at ) ) {
                ?>
                <form class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="PATCH" data-redirect-after-save="reload" style="margin-top:8px;">
                    <input type="hidden" name="parent_ack_at" value="<?php echo esc_attr( current_time( 'mysql', true ) ); ?>" />
                    <button type="submit" class="tt-btn tt-btn-primary tt-btn-sm"><?php esc_html_e( 'Acknowledge as parent / guardian', 'talenttrack' ); ?></button>
                    <div class="tt-form-msg"></div>
                </form>
                <?php
            } elseif ( ! empty( $conv->parent_ack_at ) ) {
                echo '<p class="tt-pdp-acked"><em>' . esc_html__( 'A parent acknowledged this conversation.', 'talenttrack' ) . '</em></p>';
            }
        }

        echo '</div>';
    }

    private static function renderVerdictCard( object $verdict ): void {
        echo '<div class="tt-pop-goal tt-pop-goal--done" style="margin-top:1.25rem;">';
        echo '<h3 style="margin:0 0 0.5rem; font-size:1rem; color:var(--tt-primary,#0b3d2e);">' . esc_html__( 'End-of-season verdict', 'talenttrack' ) . '</h3>';
        // #1080 — repository pre-hydrates `decision_localised` via
        // PdpVerdictsRepository::label() (LookupTranslator + canonical
        // English fallback). The inline `decisionLabel()` switch
        // below that this view previously used has been removed.
        echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Decision:', 'talenttrack' ) . '</strong> '
            . esc_html( (string) ( $verdict->decision_localised ?? '' ) ) . '</p>';
        if ( ! empty( $verdict->summary ) ) {
            echo '<div style="margin:8px 0;">' . wp_kses_post( (string) $verdict->summary ) . '</div>';
        }
        if ( ! empty( $verdict->signed_off_at ) ) {
            echo '<p style="margin:0; color:#5b6e75;"><em>' . esc_html( sprintf(
                /* translators: %s = signoff timestamp */
                __( 'Signed off on %s', 'talenttrack' ),
                (string) $verdict->signed_off_at
            ) ) . '</em></p>';
        }
        echo '</div>';
    }

    /**
     * v3.110.x — self-reflection editing window for the PDP player
     * surface. Returns true when the conversation has a
     * `scheduled_at` AND that scheduled time is at most 14 days
     * away. Returns false when the meeting hasn't been scheduled yet
     * OR it's more than 2 weeks out — those states show an explainer
     * line instead of the form.
     *
     * Once the meeting passes, the form remains open (the gate is
     * "no earlier than 2 weeks before") until the coach signs off,
     * which is the existing close-condition the caller checks.
     *
     * v3.110.52 — `scheduled_at` is stored as a UTC datetime string
     * (the repository writes via `gmdate('Y-m-d H:i:s', ...)`), so
     * parse it with an explicit `UTC` suffix. Without that, PHP's
     * `strtotime()` interprets the string in the server's local TZ,
     * which on any non-UTC install (e.g. Europe/Amsterdam, UTC+2)
     * produces a timestamp shifted by the TZ offset and opens the
     * window a few hours earlier than the 14-day boundary. Reported
     * by a pilot operator as "the form opens earlier than 2 weeks".
     */
    private static function selfReflectionWindowOpen( object $conv ): bool {
        $scheduled = (string) ( $conv->scheduled_at ?? '' );
        if ( $scheduled === '' ) return false;
        $ts = strtotime( $scheduled . ' UTC' );
        if ( $ts === false ) return false;
        $now    = (int) current_time( 'timestamp', true );
        $window = 14 * DAY_IN_SECONDS;
        // Open from (scheduled - 14 days) onwards. No upper bound here —
        // the caller's `! $signed` check handles the close.
        return ( $ts - $now ) <= $window;
    }

    private static function templateLabel( string $key ): string {
        switch ( $key ) {
            case 'start': return __( 'Start of season', 'talenttrack' );
            case 'mid':   return __( 'Mid season', 'talenttrack' );
            case 'mid_a': return __( 'Mid-season A', 'talenttrack' );
            case 'mid_b': return __( 'Mid-season B', 'talenttrack' );
            case 'end':   return __( 'End of season', 'talenttrack' );
        }
        return $key;
    }

}
