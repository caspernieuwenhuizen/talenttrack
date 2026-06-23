/**
 * Team planner team multi-select dropdown (#1715).
 *
 * The team picker is a `<details>` disclosure styled like a select: the
 * summary is the collapsed control, the panel holds the `team_ids[]`
 * checkboxes. This script keeps the summary label in sync with the
 * checked boxes and closes the dropdown on outside-click / Escape. The
 * form still GET-submits `team_ids[]`, so no behaviour depends on JS.
 */
( function () {
	'use strict';

	var cfg = window.TT_PLANNER_TEAM_DD || {};

	function init() {
		var dd = document.querySelector( '[data-tt-team-dd]' );
		if ( ! dd ) return;

		var textEl = dd.querySelector( '[data-tt-team-dd-text]' );
		var boxes = dd.querySelectorAll( 'input[type="checkbox"][name="team_ids[]"]' );

		function labelFor( box ) {
			var label = box.closest( 'label' );
			var span = label ? label.querySelector( 'span' ) : null;
			return span ? span.textContent.trim() : box.value;
		}

		function update() {
			var checked = Array.prototype.filter.call( boxes, function ( b ) { return b.checked; } );
			var text;
			if ( checked.length === 0 ) {
				text = cfg.all || 'All teams';
			} else if ( checked.length === 1 ) {
				text = labelFor( checked[ 0 ] );
			} else {
				text = ( cfg.many || '%d teams selected' ).replace( '%d', checked.length );
			}
			if ( textEl ) textEl.textContent = text;
		}

		Array.prototype.forEach.call( boxes, function ( b ) {
			b.addEventListener( 'change', update );
		} );
		update();

		// Close when clicking outside the open dropdown.
		document.addEventListener( 'click', function ( e ) {
			if ( ! dd.open ) return;
			if ( dd.contains( e.target ) ) return;
			dd.open = false;
		} );

		// Close on Escape; return focus to the summary toggle.
		dd.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && dd.open ) {
				dd.open = false;
				var summary = dd.querySelector( 'summary' );
				if ( summary ) summary.focus();
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
