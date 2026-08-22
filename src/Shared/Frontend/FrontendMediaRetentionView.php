<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Media\MediaKind;
use TT\Modules\Media\Retention\MediaRetentionService;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendMediaRetentionView (#2666, epic #2589) — the media a retention
 * period says should be reviewed.
 *
 * Reachable at `?tt_view=media-retention`, gated on `tt_manage_media`.
 *
 * **Nothing on this page happens on its own.** The retention period marks
 * attachments for review; a person decides. That is the whole reason the
 * feature is a queue rather than a scheduled delete — automatically
 * destroying the only record of a player's development is not something
 * to do quietly, and an academy needs somewhere to say "not this one, and
 * here is why".
 *
 * Each row is an **attachment**, not an item (#2666 R2). Removing it
 * unlinks that player; the photo survives for the other players and for
 * the training it came from, and is deleted only when nothing links it
 * any more.
 *
 * Two nav affordances, per CLAUDE.md §5: the canonical breadcrumb chain
 * (Dashboard › Media retention) and the auto-rendered `tt_back` pill.
 * All shaping lives in {@see MediaRetentionService}; this view composes
 * HTML and defers every mutation to REST, which re-checks the cap.
 */
class FrontendMediaRetentionView extends FrontendViewBase {

    private const CAP = 'tt_manage_media';

    public static function render( int $user_id, bool $is_admin ): void {
        // Breadcrumb on EVERY path, including permission-denied (§5).
        FrontendBreadcrumbs::fromDashboard( __( 'Media retention', 'talenttrack' ) );

        if ( ! current_user_can( self::CAP ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to review media retention.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueRetentionAssets();
        self::renderHeader( __( 'Media retention', 'talenttrack' ) );

        $service = new MediaRetentionService();

        if ( ! MediaRetentionService::isEnabled() ) {
            echo '<p class="tt-notice">' . esc_html__(
                'This academy keeps media indefinitely. Set a retention period under Configuration to have photos and video surface here for review after a player leaves.',
                'talenttrack'
            ) . '</p>';
            return;
        }

        $years = MediaRetentionService::years();

        echo '<p class="tt-media-retention__intro">' . esc_html(
            sprintf(
                /* translators: %d is the configured retention period in years. */
                _n(
                    'This academy keeps a player\'s photos and video for %d year after they leave. Nothing below has been deleted — each one is waiting for you to decide.',
                    'This academy keeps a player\'s photos and video for %d years after they leave. Nothing below has been deleted — each one is waiting for you to decide.',
                    $years,
                    'talenttrack'
                ),
                $years
            )
        ) . '</p>';

        self::renderQueue( $service->candidates() );
        self::renderHeld( $service->held() );
    }

    // Internals

    /** @param list<array<string,mixed>> $rows */
    private static function renderQueue( array $rows ): void {
        if ( $rows === [] ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'Nothing is waiting for review.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<table class="tt-list-table-table tt-media-retention">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Media', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Left', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Decision', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $title = (string) $row['title'];
            if ( $title === '' ) $title = MediaKind::label( (string) $row['kind'] );

            echo '<tr data-role="retention-row" data-link-id="' . (int) $row['link_id'] . '">';

            echo '<td><span class="tt-media-retention__title">' . esc_html( $title ) . '</span> ';
            echo '<span class="tt-media-retention__kind">' . esc_html( MediaKind::label( (string) $row['kind'] ) ) . '</span></td>';

            echo '<td>' . esc_html( (string) $row['player_name'] ) . '</td>';

            echo '<td>' . esc_html( (string) $row['departed_on'] );
            if ( ! empty( $row['estimated'] ) ) {
                // Honest about a weaker date rather than presenting a guess
                // as a fact — it only affects when the row appears, since
                // nothing is deleted without a decision.
                echo ' <span class="tt-media-retention__estimated" title="'
                    . esc_attr__( 'This player has no recorded leaving date, so the date their record last changed is used instead.', 'talenttrack' )
                    . '">' . esc_html__( 'estimated', 'talenttrack' ) . '</span>';
            }
            echo '</td>';

            echo '<td class="tt-media-retention__actions">';
            echo '<button type="button" class="tt-btn tt-btn-danger" data-role="retention-remove">'
                . esc_html__( 'Remove', 'talenttrack' ) . '</button> ';
            echo '<button type="button" class="tt-btn tt-btn-secondary" data-role="retention-keep">'
                . esc_html__( 'Keep', 'talenttrack' ) . '</button>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /** @param object[] $rows */
    private static function renderHeld( array $rows ): void {
        if ( $rows === [] ) return;

        echo '<h3 class="tt-media-retention__held-title">' . esc_html__( 'Kept on purpose', 'talenttrack' ) . '</h3>';
        echo '<p class="description">' . esc_html__(
            'These are past their retention date and are being kept anyway. A policy with an invisible list of exceptions is not one anybody can check, so they are listed here with the reason given.',
            'talenttrack'
        ) . '</p>';

        echo '<table class="tt-list-table-table tt-media-retention tt-media-retention--held">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Media', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Decision', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $title = (string) $row->title;
            if ( $title === '' ) $title = MediaKind::label( (string) $row->kind );

            $name = trim( (string) $row->first_name . ' ' . (string) $row->last_name );

            echo '<tr data-role="retention-row" data-link-id="' . (int) $row->link_id . '">';
            echo '<td>' . esc_html( $title ) . '</td>';
            echo '<td>' . esc_html( $name ) . '</td>';
            echo '<td>' . esc_html( (string) $row->retention_hold_reason ) . '</td>';
            echo '<td><button type="button" class="tt-btn tt-btn-secondary" data-role="retention-release">'
                . esc_html__( 'Put back in the queue', 'talenttrack' ) . '</button></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function enqueueRetentionAssets(): void {
        wp_enqueue_style(
            'tt-media',
            plugins_url( 'assets/css/frontend-media.css', TT_PLUGIN_FILE ),
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-media-retention',
            plugins_url( 'assets/js/frontend-media-retention.js', TT_PLUGIN_FILE ),
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-media-retention', 'TT_MediaRetention', [
            'root'  => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'confirmRemove' => __( 'Remove this from the player\'s file? If nothing else is attached to it, the file is deleted for good.', 'talenttrack' ),
                'askReason'     => __( 'Why is this being kept? (Required — it is what makes the exception auditable.)', 'talenttrack' ),
                'failed'        => __( 'That could not be saved.', 'talenttrack' ),
                'removedKept'   => __( 'Removed from this player. The file is still attached elsewhere.', 'talenttrack' ),
                'removedGone'   => __( 'Removed, and the file was deleted.', 'talenttrack' ),
            ],
        ] );
    }
}
