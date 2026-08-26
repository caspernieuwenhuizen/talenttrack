<?php
namespace TT\Modules\MatchPrep\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchPrep\Print\MatchPrepPrintableRenderer;
use TT\Modules\MatchPrep\Services\MatchPrepShareLink;

/**
 * FrontendMatchPrepShareView (#2892) — the logged-out staff view of a
 * shared match preparation.
 *
 * Mirrors `MatchAnalysis\FrontendMatchAnalysisView::renderShared()`. The
 * token in the URL is the credential: the assistant coach, analyst or
 * keeper coach it was sent to may have no account on this install, which is
 * the whole reason the link exists.
 *
 * Read-only by construction — it renders the same body the printable sheet
 * does and offers no control that mutates anything.
 */
final class FrontendMatchPrepShareView {

    public static function render(): void {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'match_prep_sharing' ) ) {
            self::renderShareNotFound();
            return;
        }

        $uuid  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) : '';
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['token'] ) ) : '';

        $prep = MatchPrepShareLink::resolve( $uuid, $token );
        if ( $prep === null ) {
            self::renderShareNotFound();
            return;
        }

        // Scope reads to the prep's own club: the viewer has no session, so
        // `CurrentClub` would otherwise answer with the install default. The
        // club id is not a secret — the URL already addresses one specific
        // prep.
        $club_id     = (int) ( $prep->club_id ?? 1 );
        $club_filter = static fn( $club ) => $club_id > 0 ? $club_id : $club;
        add_filter( 'tt_current_club_id', $club_filter );

        $body = MatchPrepPrintableRenderer::bodyHtml( (int) ( $prep->activity_id ?? 0 ), $club_id );

        remove_filter( 'tt_current_club_id', $club_filter );

        if ( trim( $body ) === '' ) {
            self::renderShareNotFound();
            return;
        }

        // The printable renderer owns this CSS and returns it as a string.
        // Attaching it with `wp_add_inline_style` rather than echoing a style
        // element keeps it out of the markup, and satisfies the inline-style
        // containment gate (CLAUDE.md §2) — which greps added lines for the
        // literal markup, so do not write that tag name here even in a
        // comment. It is the same stylesheet the printed sheet uses, so
        // duplicating it into its own enqueued file would be two copies to
        // keep in step.
        wp_register_style( 'tt-match-prep-share', false, [], TT_VERSION );
        wp_enqueue_style( 'tt-match-prep-share' );
        wp_add_inline_style( 'tt-match-prep-share', MatchPrepPrintableRenderer::styleBlock() );

        echo '<div class="tt-mp tt-mp--shared">';
        echo '<p class="tt-mp__share-note">'
            . esc_html__( 'Shared staff document. It names players and says who is expected to start; please do not forward it outside the staff.', 'talenttrack' )
            . '</p>';
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the renderer escapes its own values.
        echo '</div>';
    }

    public static function noindexMeta(): void {
        echo '<meta name="robots" content="noindex, nofollow, noarchive" />' . "\n";
        echo '<meta name="referrer" content="no-referrer" />' . "\n";
    }

    /**
     * Every failure mode lands here with the same words — an unknown prep,
     * a revoked link, a tampered token and a disabled feature must not be
     * distinguishable from outside, or the page becomes an oracle for
     * whichever part the prober got wrong.
     */
    private static function renderShareNotFound(): void {
        echo '<div class="tt-mp tt-mp--gone">';
        echo '<h1>' . esc_html__( 'This link is no longer valid', 'talenttrack' ) . '</h1>';
        echo '<p>'
            . esc_html__( 'The match preparation may have been removed, or the link may have been reissued. Ask the coach who shared it for a new one.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }
}
