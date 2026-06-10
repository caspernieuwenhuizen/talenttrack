/**
 * wizard-attendance-roster.js (#1297)
 *
 * Tiny hydrator for AttendanceRosterStep on the new-activity wizard:
 *
 *   - Check all / Uncheck all buttons toggle every roster checkbox.
 *   - Row background tint follows the checkbox state.
 *   - "Set attendance later" checkbox hides the roster + guest
 *     disclosure while ticked (the server still receives the picks
 *     should the operator untick, since the inputs aren't removed
 *     from the DOM).
 *
 * Idempotent — runs once per form on DOMContentLoaded; multiple step
 * instances on the same page each find their own scoped form.
 */
(function () {
    'use strict';

    var ACTIVE_BG   = '#eef4fb';
    var INACTIVE_BG = '#f9fafb';

    function hydrateForm( form ) {
        var list       = form.querySelector( '[data-tt-roster-list]' );
        var checkAll   = form.querySelector( '[data-tt-roster-check-all]' );
        var uncheckAll = form.querySelector( '[data-tt-roster-uncheck-all]' );
        var skipCb     = form.querySelector( '[data-tt-roster-skip]' );
        var hideable   = form.querySelectorAll(
            '.tt-attendance-roster-controls, .tt-attendance-roster-list, .tt-attendance-roster-guests'
        );

        function setRowBg( cb ) {
            var row = cb.closest( 'label' );
            if ( row ) row.style.background = cb.checked ? ACTIVE_BG : INACTIVE_BG;
        }

        if ( list && checkAll ) {
            checkAll.addEventListener( 'click', function () {
                var boxes = list.querySelectorAll( '[data-tt-roster-checkbox]' );
                for ( var i = 0; i < boxes.length; i++ ) {
                    boxes[ i ].checked = true;
                    setRowBg( boxes[ i ] );
                }
            } );
        }
        if ( list && uncheckAll ) {
            uncheckAll.addEventListener( 'click', function () {
                var boxes = list.querySelectorAll( '[data-tt-roster-checkbox]' );
                for ( var i = 0; i < boxes.length; i++ ) {
                    boxes[ i ].checked = false;
                    setRowBg( boxes[ i ] );
                }
            } );
        }
        if ( list ) {
            list.addEventListener( 'change', function ( e ) {
                var cb = e.target && e.target.matches && e.target.matches( '[data-tt-roster-checkbox]' ) ? e.target : null;
                if ( ! cb ) return;
                setRowBg( cb );
            } );
        }
        if ( skipCb ) {
            var apply = function ( on ) {
                for ( var i = 0; i < hideable.length; i++ ) {
                    hideable[ i ].style.display = on ? 'none' : '';
                }
            };
            skipCb.addEventListener( 'change', function () { apply( !! skipCb.checked ); } );
            if ( skipCb.checked ) apply( true );
        }
    }

    function init() {
        // The step renders inside the wizard's form; scope by the
        // step's own marker so we don't double-bind if multiple
        // wizards ever land on one page.
        var rosters = document.querySelectorAll( '.tt-attendance-roster' );
        for ( var i = 0; i < rosters.length; i++ ) {
            var form = rosters[ i ].closest( 'form' );
            if ( form && ! form.hasAttribute( 'data-tt-roster-hydrated' ) ) {
                form.setAttribute( 'data-tt-roster-hydrated', '1' );
                hydrateForm( form );
            }
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})();
