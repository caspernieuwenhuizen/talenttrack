<?php
namespace TT\Modules\Pdp\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
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

    public static function render( object $player ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My development plan', 'talenttrack' ) );
        self::renderHeader( __( 'My development plan', 'talenttrack' ) );

        $current = ( new SeasonsRepository() )->current();
        if ( ! $current ) {
            echo '<p class="tt-notice">' . esc_html__( 'No current season is set.', 'talenttrack' ) . '</p>';
            return;
        }

        $files = new PdpFilesRepository();
        $file  = $files->findByPlayerSeason( (int) $player->id, (int) $current->id );
        if ( ! $file ) {
            echo '<p><em>' . esc_html__( 'No PDP file has been opened for you this season yet.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $is_self   = (int) ( $player->wp_user_id ?? 0 ) === get_current_user_id();
        $is_parent = ! $is_self;

        $convs   = ( new PdpConversationsRepository() )->listForFile( (int) $file->id );
        $verdict = ( new PdpVerdictsRepository() )->findForFile( (int) $file->id );

        echo '<p style="color:#5b6e75; margin-bottom:12px;">' . esc_html( sprintf(
            /* translators: %s = season name */
            __( 'Season: %s', 'talenttrack' ),
            (string) $current->name
        ) ) . '</p>';

        foreach ( $convs as $c ) {
            self::renderConversationCard( $file, $c, $is_self, $is_parent );
        }

        if ( $verdict !== null ) {
            self::renderVerdictCard( $verdict );
        }
    }

    private static function renderConversationCard( object $file, object $conv, bool $is_self, bool $is_parent ): void {
        $title = sprintf(
            /* translators: %1$d sequence, %2$s template */
            __( 'Conversation %1$d (%2$s)', 'talenttrack' ),
            (int) $conv->sequence,
            self::templateLabel( (string) $conv->template_key )
        );
        $signed = ! empty( $conv->coach_signoff_at );

        echo '<div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px; margin-bottom:12px;">';
        echo '<h3 style="margin:0 0 6px; font-size:16px;">' . esc_html( $title ) . '</h3>';
        echo '<p style="margin:0 0 6px; color:#5b6e75; font-size:13px;">';
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
                echo '<div style="margin:8px 0;"><strong>' . esc_html__( 'Notes', 'talenttrack' ) . '</strong><div>'
                    . wp_kses_post( (string) $conv->notes ) . '</div></div>';
            }
            if ( ! empty( $conv->agreed_actions ) ) {
                echo '<div style="margin:8px 0;"><strong>' . esc_html__( 'Agreed actions', 'talenttrack' ) . '</strong><div>'
                    . wp_kses_post( (string) $conv->agreed_actions ) . '</div></div>';
            }
        } else {
            // Pre-meeting: agenda + (player only) editable self-reflection.
            if ( ! empty( $conv->agenda ) ) {
                echo '<div style="margin:8px 0;"><strong>' . esc_html__( 'Agenda', 'talenttrack' ) . '</strong><div>'
                    . wp_kses_post( (string) $conv->agenda ) . '</div></div>';
            }
        }

        if ( ! empty( $conv->player_reflection ) ) {
            echo '<div style="margin:8px 0; padding:8px; background:#fafbfc; border-radius:4px;">';
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
        echo '<div class="tt-card" style="background:#fff; border:2px solid #1d7874; border-radius:8px; padding:16px; margin-top:24px;">';
        echo '<h3 style="margin:0 0 8px; font-size:16px;">' . esc_html__( 'End-of-season verdict', 'talenttrack' ) . '</h3>';
        echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Decision:', 'talenttrack' ) . '</strong> '
            . esc_html( self::decisionLabel( (string) $verdict->decision ) ) . '</p>';
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

    private static function decisionLabel( string $decision ): string {
        switch ( $decision ) {
            case 'promote':  return __( 'Promote', 'talenttrack' );
            case 'retain':   return __( 'Retain', 'talenttrack' );
            case 'release':  return __( 'Release', 'talenttrack' );
            case 'transfer': return __( 'Transfer', 'talenttrack' );
        }
        return $decision;
    }
}
