/* activity-end-time-default.js — #1863
 *
 * Prefill a match activity's end time to kick-off + N minutes (default
 * 105 = 90' play + 15' half-time). Match activities only, prefill-once,
 * and never overrides a value the user typed. Works on both create
 * surfaces:
 *   - flat activity form: reads the live activity-type <select>;
 *   - wizard details step: the type is fixed, so the end-time input
 *     carries data-tt-end-default-match="1".
 *
 * An end-time input opts in with:
 *   data-tt-end-default-mins="105"
 *   data-tt-end-default-from="start_time"   (start input name)
 *   data-tt-end-default-match="1"           (wizard: this IS a match)
 */
(function () {
	'use strict';

	function addMinutes(hhmm, mins) {
		var m = /^(\d{1,2}):(\d{2})/.exec(hhmm);
		if (!m) return '';
		var total = (parseInt(m[1], 10) * 60 + parseInt(m[2], 10) + mins) % 1440;
		if (total < 0) total += 1440;
		var h = Math.floor(total / 60);
		var mm = total % 60;
		return (h < 10 ? '0' : '') + h + ':' + (mm < 10 ? '0' : '') + mm;
	}

	function wire(end) {
		var form = end.form || end.closest('form') || document;
		var startName = end.getAttribute('data-tt-end-default-from') || 'start_time';
		var start = form.querySelector('[name="' + startName + '"]');
		if (!start) return;

		var mins = parseInt(end.getAttribute('data-tt-end-default-mins') || '105', 10);
		if (!mins) return;

		// Flat form: type is chosen live. Wizard: type is fixed to a match.
		var typeSel = form.querySelector('[name="activity_type_key"]');
		var staticMatch = end.getAttribute('data-tt-end-default-match') === '1';

		var touched = false;
		end.addEventListener('input', function () { touched = true; });

		function isMatch() {
			return typeSel ? typeSel.value === 'game' : staticMatch;
		}

		function maybeFill() {
			if (touched || !start.value || end.value || !isMatch()) return;
			var v = addMinutes(start.value, mins);
			if (v) end.value = v;
		}

		start.addEventListener('change', maybeFill);
		if (typeSel) typeSel.addEventListener('change', maybeFill);
	}

	function init() {
		var ends = document.querySelectorAll('[data-tt-end-default-mins]');
		for (var i = 0; i < ends.length; i++) wire(ends[i]);
	}

	if (document.readyState !== 'loading') init();
	else document.addEventListener('DOMContentLoaded', init);
})();
