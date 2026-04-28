/**
 * TalentTrack — session attendance helpers
 * #0019 Sprint 2 session 2.3
 *
 * Lives on the session create / edit form. Three responsibilities:
 *
 *   1. Hide attendance rows for teams the form isn't currently
 *      pointed at. The PHP renders rows for every team the coach has
 *      access to (so changing the team dropdown doesn't lose state),
 *      but only the current team's roster should be visible.
 *
 *   2. "Mark all present" button — sets every visible row's status
 *      select to "Present" in one tap. The 80% case at the pitch
 *      side: most players show, mark exceptions individually.
 *
 *   3. Mobile pagination at 15 players. On viewports ≤640px the
 *      attendance list collapses to the first 15 visible rows with a
 *      "Show all" button to reveal the rest. Desktop always shows
 *      everyone.
 *
 *   4. Live "5 of 18 marked" summary so the coach can see progress.
 */
(function(){
    'use strict';

    var MOBILE_BREAKPOINT = 640;
    var MOBILE_INITIAL_LIMIT = 15;

    function visibleRowsForCurrentTeam(root) {
        var currentTeam = parseInt(root.getAttribute('data-current-team') || '0', 10);
        var rows = Array.prototype.slice.call(root.querySelectorAll('.tt-attendance-row'));
        return rows.filter(function(r) {
            var rowTeam = parseInt(r.getAttribute('data-team-id') || '0', 10);
            return rowTeam === currentTeam;
        });
    }

    function applyMobileLimit(root) {
        var rows = visibleRowsForCurrentTeam(root);
        var isMobile = window.innerWidth <= MOBILE_BREAKPOINT;
        var showAllWrap = root.querySelector('[data-tt-attendance-show-all="1"]');
        var expanded = root.getAttribute('data-mobile-expanded') === '1';

        rows.forEach(function(row, idx) {
            // Mobile + not expanded + over limit → hide.
            if (isMobile && !expanded && idx >= MOBILE_INITIAL_LIMIT) {
                row.classList.add('is-mobile-hidden');
            } else {
                row.classList.remove('is-mobile-hidden');
            }
        });

        if (showAllWrap) {
            var needsShowAll = isMobile && !expanded && rows.length > MOBILE_INITIAL_LIMIT;
            showAllWrap.hidden = !needsShowAll;
            if (needsShowAll) {
                var btn = showAllWrap.querySelector('button');
                if (btn) {
                    var i18n = (window.TT && window.TT.i18n) || {};
                    var template = i18n.show_all_count || 'Show all (%d)';
                    btn.textContent = template.replace('%d', String(rows.length));
                }
            }
        }
    }

    function applyTeamFilter(root) {
        var currentTeam = parseInt(root.getAttribute('data-current-team') || '0', 10);
        root.querySelectorAll('.tt-attendance-row').forEach(function(row) {
            var rowTeam = parseInt(row.getAttribute('data-team-id') || '0', 10);
            row.style.display = (rowTeam === currentTeam) ? '' : 'none';
        });
    }

    function presentValueFor(root) {
        // The PHP layer passes the canonical "Present" value via
        // data-tt-attendance-present-value. Falls back to literal 'Present'
        // for older renders that haven't been re-saved.
        return root.getAttribute('data-tt-attendance-present-value') || 'Present';
    }

    function updateSummary(root) {
        var rows = visibleRowsForCurrentTeam(root);
        var total = rows.length;
        var presentValue = presentValueFor(root);
        var present = 0;
        rows.forEach(function(r) {
            var sel = r.querySelector('[data-tt-attendance-status="1"]');
            if (sel && sel.value === presentValue) present++;
        });
        var summary = root.querySelector('[data-tt-attendance-summary="1"]');
        if (!summary) return;
        var i18n = (window.TT && window.TT.i18n) || {};
        var template = i18n.attendance_summary || '%1$d of %2$d marked Present';
        summary.textContent = template.replace('%1$d', String(present)).replace('%2$d', String(total));
    }

    function markAllPresent(root) {
        var presentValue = presentValueFor(root);
        visibleRowsForCurrentTeam(root).forEach(function(row) {
            var sel = row.querySelector('[data-tt-attendance-status="1"]');
            if (!sel) return;
            // Match against the localized "Present" value first; fall back
            // to the first option if the lookup table has shifted under us.
            var matched = false;
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === presentValue) {
                    sel.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            if (!matched && sel.options.length > 0) sel.selectedIndex = 0;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        });
        updateSummary(root);
    }

    function wire(root) {
        // React to team-dropdown changes on the same form.
        var form = root.closest('form');
        if (form) {
            var teamSel = form.querySelector('select[name="team_id"]');
            if (teamSel) {
                teamSel.addEventListener('change', function() {
                    root.setAttribute('data-current-team', String(parseInt(teamSel.value, 10) || 0));
                    root.setAttribute('data-mobile-expanded', '0');
                    applyTeamFilter(root);
                    applyMobileLimit(root);
                    updateSummary(root);
                });
            }
        }

        // "Mark all present"
        var markAllBtn = root.querySelector('[data-tt-attendance-mark-all="1"]');
        if (markAllBtn) markAllBtn.addEventListener('click', function() { markAllPresent(root); });

        // "Show all" expander.
        var showAllWrap = root.querySelector('[data-tt-attendance-show-all="1"]');
        if (showAllWrap) {
            var btn = showAllWrap.querySelector('button');
            if (btn) btn.addEventListener('click', function() {
                root.setAttribute('data-mobile-expanded', '1');
                applyMobileLimit(root);
            });
        }

        // Status changes update the summary.
        root.addEventListener('change', function(e) {
            if (e.target && e.target.matches('[data-tt-attendance-status="1"]')) updateSummary(root);
        });

        // Re-evaluate on resize — debounced.
        var resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() { applyMobileLimit(root); }, 120);
        });

        // Initial paint.
        applyTeamFilter(root);
        applyMobileLimit(root);
        updateSummary(root);
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard [data-tt-attendance="1"]').forEach(wire);
    });
})();
