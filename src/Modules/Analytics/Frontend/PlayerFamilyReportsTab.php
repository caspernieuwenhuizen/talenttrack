<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportSnapshots;
use TT\Shared\Dates\TTDate;

/**
 * PlayerFamilyReportsTab (#3955, epic #3871) — the Reports tab on a player's
 * file, for the player and their parents: the reports a coach shared with the
 * family, newest first, each opening the frozen copy.
 *
 * Read-only by design. Families do not generate reports, so there is nothing
 * here to compose, print or annotate — every report on this tab is one a coach
 * chose to send. Which sections of it a reader sees is decided in
 * `PlayerReportSnapshots::read()`, by the same rules the rest of the child's
 * file follows.
 */
final class PlayerFamilyReportsTab {

    public const TAB = 'reports';

    /** Is the tab offered to this reader, on this player's file? The family only. */
    public static function isOffered( int $user_id, int $player_id ): bool {
        return PlayerReportSnapshots::isFamilyReader( $user_id, $player_id );
    }

    /**
     * @param string $tab_url this tab's own URL on the player's file, which the
     *                        list links extend with the report to open.
     */
    public static function render( int $player_id, int $user_id, string $tab_url ): void {
        if ( ! self::isOffered( $user_id, $player_id ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'Reports on this player are shared with the player and their parents.', 'talenttrack' ) . '</p>';
            return;
        }

        PlayerReportPage::enqueuePublic();

        $rows = PlayerReportSnapshots::sharedWithFamily( $player_id, $user_id );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
        $open = isset( $_GET['report'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['report'] ) ) : '';

        echo '<h2 class="tt-section-title">' . esc_html__( 'Reports', 'talenttrack' ) . '</h2>';

        if ( $rows === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No reports have been shared yet. When a coach shares a report about this player, it appears here.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ul class="tt-pr-family-list">';
        foreach ( $rows as $row ) {
            $current = $row['uuid'] === $open;
            echo '<li><a href="' . esc_url( add_query_arg( 'report', $row['uuid'], $tab_url ) ) . '"' . ( $current ? ' aria-current="true"' : '' ) . '>'
                . '<span>' . esc_html( $row['title'] ) . '</span>'
                . '<span class="tt-pr-family-list__meta">' . esc_html( sprintf(
                    /* translators: 1: period start, 2: period end */
                    __( 'Covers %1$s – %2$s', 'talenttrack' ),
                    TTDate::date( $row['period_from'] ),
                    TTDate::date( $row['period_to'] )
                ) ) . ' · ' . esc_html( PlayerReportSnapshotPage::sharedLine( $row['created_by'], $row['created_at'] ) ) . '</span>'
                . '</a></li>';
        }
        echo '</ul>';

        if ( $open !== '' && ! PlayerReportSnapshotPage::renderForFamily( $open, $player_id ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'This report is not available.', 'talenttrack' ) . '</p>';
        }
    }
}
