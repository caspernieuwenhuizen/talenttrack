/**
 * #2160 — minutes report drill-down toggle.
 *
 * The Analytics Team·Minutes report (?tt_view=minutes-report-team)
 * renders a per-match breakdown row directly after each player row.
 * Those rows are collapsed by default here (so the table stays compact)
 * and toggled open when the player's Total cell is activated. With JS
 * off, every breakdown row stays visible and the totals still reconcile.
 */
( function () {
	'use strict';

	function init() {
		var rows = document.querySelectorAll( '.tt-min-breakdown-row' );
		if ( ! rows.length ) {
			return;
		}
		// Collapse all breakdown rows now that JS is running.
		rows.forEach( function ( row ) {
			row.hidden = true;
		} );

		document.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest( '[data-tt-minutes-toggle]' );
			if ( ! trigger ) {
				return;
			}
			e.preventDefault();
			var id = trigger.getAttribute( 'data-tt-minutes-toggle' );
			var target = document.getElementById( 'tt-min-bd-' + id );
			if ( ! target ) {
				return;
			}
			var open = target.hidden;
			target.hidden = ! open;
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		// Keyboard: the trigger is an anchor, so Enter already activates
		// it via the click handler above. Nothing extra needed.
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
