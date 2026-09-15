/*
 * comparison-charts.js — the radar + trend overlays on the player
 * comparison view (#3352, epic #3335).
 *
 * The bootstrap used to be an inline IIFE printed by the view. That is fine
 * for a page load and useless for an in-place filter refresh: markup
 * inserted with `innerHTML` never executes its scripts, so changing the
 * window would have swapped in two blank canvases with nothing to say why.
 *
 * So the view prints the datasets as a JSON island and this draws them —
 * on load, and again on `tt:filter-refreshed`, the event filter-refresh.js
 * fires after it swaps the region.
 */
( function () {
	'use strict';

	var radar = null;
	var trend = null;

	function payload() {
		var el = document.querySelector( '[data-tt-compare-charts]' );
		if ( ! el ) { return null; }
		try {
			return JSON.parse( el.textContent || '{}' );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Drop the previous chart before drawing.
	 *
	 * The canvas element it was attached to has usually been replaced by the
	 * swap, so Chart.js cannot find it to clean up on its own — without this
	 * every refresh leaks an instance and its resize listener.
	 */
	function discard( chart ) {
		if ( chart ) {
			try { chart.destroy(); } catch ( e ) { /* already gone */ }
		}
		return null;
	}

	function draw() {
		if ( typeof Chart === 'undefined' ) { return; }

		var data = payload();
		radar = discard( radar );
		trend = discard( trend );
		if ( ! data ) { return; }

		var ratingMax = data.ratingMax;

		var radarEl = document.getElementById( 'tt-fcompare-radar' );
		var radarLabels = data.radarLabels || [];
		var radarSets = data.radarSets || [];
		if ( radarEl && radarLabels.length > 0 && radarSets.length > 0 ) {
			radar = new Chart( radarEl.getContext( '2d' ), {
				type: 'radar',
				data: {
					labels: radarLabels,
					datasets: radarSets.map( function ( s ) {
						return {
							label: s.label,
							data: s.values,
							borderColor: s.color,
							backgroundColor: s.color + '22',
							pointBackgroundColor: s.color,
							spanGaps: true
						};
					} )
				},
				options: {
					responsive: true, maintainAspectRatio: false,
					scales: { r: { min: 0, max: ratingMax, ticks: { stepSize: 1 } } },
					plugins: { legend: { position: 'bottom' } }
				}
			} );
		}

		var trendEl = document.getElementById( 'tt-fcompare-trend' );
		var trendLabels = data.trendLabels || [];
		var trendSets = data.trendSets || [];
		if ( trendEl && trendLabels.length > 0 && trendSets.length > 0 ) {
			trend = new Chart( trendEl.getContext( '2d' ), {
				type: 'line',
				data: {
					labels: trendLabels,
					datasets: trendSets.map( function ( s ) {
						return {
							label: s.label,
							data: s.points,
							borderColor: s.color,
							backgroundColor: s.color + '22',
							pointBackgroundColor: s.color,
							spanGaps: true,
							pointRadius: 3
						};
					} )
				},
				options: {
					responsive: true, maintainAspectRatio: false,
					scales: {
						y: { min: 0, max: ratingMax, ticks: { stepSize: 1 } },
						x: { ticks: { maxTicksLimit: 8, autoSkip: true } }
					},
					plugins: { legend: { position: 'bottom' } }
				}
			} );
		}
	}

	if ( document.readyState !== 'loading' ) { draw(); }
	else { document.addEventListener( 'DOMContentLoaded', draw ); }

	document.addEventListener( 'tt:filter-refreshed', draw );
} )();
