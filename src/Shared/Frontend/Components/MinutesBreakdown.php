<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Dates\TTDate;

/**
 * MinutesBreakdown (#2348) — the single per-match minutes breakdown table
 * for one player. Consolidates the two near-identical renderers that used
 * to live in `FrontendStandardReportsView` (team minutes distribution) and
 * `FrontendMinutesTeamReportView`, which had already drifted in wrapper
 * markup while sharing the exact same data model.
 *
 * The rows come from {@see \TT\Modules\Analytics\Reports\MinutesQuery::matchBreakdownForPlayer()}
 * so they sum EXACTLY to the player's report total — this component only
 * renders; it never recomputes minutes (CLAUDE.md §4: components compose,
 * they don't decide). Every row is a persisted actual-minutes row (#2193),
 * so the Source column is always "actual".
 *
 * Presentation contract (unchanged from the two originals it replaces):
 *   - five columns: Date | Match | Type | Source | Min
 *   - a reconciling Total row that sums the Min column
 *   - keyboard-operable / no-JS stable (it renders plain table markup; the
 *     consuming view supplies the <details>/toggle chrome around it)
 *   - mobile-clean at 360px via the shared `.tt-minutes-breakdown` sheet
 *     rules in frontend-app-chrome.css
 */
final class MinutesBreakdown {

	/**
	 * Echo the breakdown table for one player.
	 *
	 * @param list<array{activity_id:int,session_date:string,title:string,type_key:string,minutes:int,record_type:string}> $breakdown
	 */
	public static function render( array $breakdown, int $player_id = 0 ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes internally.
		echo self::html( $breakdown, $player_id );
	}

	/**
	 * Build the breakdown table HTML.
	 *
	 * @param list<array{activity_id:int,session_date:string,title:string,type_key:string,minutes:int,record_type:string}> $breakdown
	 */
	public static function html( array $breakdown, int $player_id = 0 ): string {
		$out = '<div class="tt-minutes-breakdown">';
		if ( ! $breakdown ) {
			$out .= '<p class="tt-rep-section__hint">' . esc_html__( 'No per-match minutes recorded in this window.', 'talenttrack' ) . '</p>';
			$out .= '</div>';
			return $out;
		}

		$sum = 0;
		foreach ( $breakdown as $b ) $sum += (int) $b['minutes'];

		$out .= '<table class="tt-table"><thead><tr>'
			. '<th>' . esc_html__( 'Date', 'talenttrack' ) . '</th>'
			. '<th>' . esc_html__( 'Match', 'talenttrack' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'talenttrack' ) . '</th>'
			. '<th>' . esc_html__( 'Source', 'talenttrack' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Min', 'talenttrack' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $breakdown as $b ) {
			$url   = RecordLink::detailUrlForWithBack( 'activities', (int) $b['activity_id'] );
			$title = (string) $b['title'];
			if ( $title === '' ) $title = '—';
			// #2193 — every breakdown row is a persisted actual-minutes row;
			// minutes are never recomputed at report time, so the source is
			// always "actual".
			$source = __( 'actual', 'talenttrack' );
			$out .= '<tr>';
			$out .= '<td>' . esc_html( TTDate::date( (string) $b['session_date'] ) ) . '</td>';
			$out .= '<td><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></td>';
			$out .= '<td>' . esc_html( (string) $b['type_key'] ) . '</td>';
			$out .= '<td>' . esc_html( $source ) . '</td>';
			$out .= '<td class="num">' . (int) $b['minutes'] . '</td>';
			$out .= '</tr>';
		}

		$out .= '<tr class="tt-minutes-breakdown__total"><td colspan="4">' . esc_html__( 'Total', 'talenttrack' ) . '</td><td class="num">' . (int) $sum . '</td></tr>';
		$out .= '</tbody></table>';
		$out .= '</div>';
		return $out;
	}
}
