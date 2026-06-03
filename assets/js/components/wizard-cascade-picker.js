/**
 * wizard-cascade-picker.js (v4.20.6 / #1156)
 *
 * Generic team → player cascade dropdown handler. Used by the new-goal
 * wizard's PlayerStep; reusable for any future wizard step that needs
 * the same shape.
 *
 * Wiring (from PHP):
 *   <select data-tt-cascade-filter data-tt-cascade-target="player_select_id">
 *     <option value="0">— Pick a team —</option>
 *     <option value="N">Team N</option>
 *   </select>
 *   <select id="player_select_id" name="player_id" required>
 *     <option value="0">— Pick a player —</option>
 *     <optgroup label="Team N" data-tt-team-id="N">
 *       <option value="X">Player X</option>
 *     </optgroup>
 *   </select>
 *
 * Behaviour:
 *   - On filter change, hide every optgroup whose `data-tt-team-id`
 *     doesn't match the filter's value. Filter value 0 ("Pick a team")
 *     shows all optgroups.
 *   - If the currently-selected option ends up inside a hidden group,
 *     reset the target select to 0 and dispatch a synthetic `change`
 *     event so wizard-validation.js re-checks the Next button state.
 *   - Run once on DOMContentLoaded so the initial server-rendered
 *     state matches the filter's initial value (handles
 *     pre-selected players from wizard state).
 */
(function () {
    'use strict';

    function wireOne( filter ) {
        var targetId = filter.getAttribute( 'data-tt-cascade-target' );
        if ( ! targetId ) return;
        var target = document.getElementById( targetId );
        if ( ! target ) return;

        function applyFilter() {
            var tid = parseInt( filter.value || '0', 10 );
            var groups = target.querySelectorAll( 'optgroup[data-tt-team-id]' );
            for ( var i = 0; i < groups.length; i++ ) {
                var gid = parseInt( groups[ i ].getAttribute( 'data-tt-team-id' ) || '0', 10 );
                var visible = tid === 0 || gid === tid;
                groups[ i ].hidden = ! visible;
                groups[ i ].disabled = ! visible;
            }
            var selectedOpt = target.options[ target.selectedIndex ];
            if ( selectedOpt && selectedOpt.parentNode && selectedOpt.parentNode.tagName === 'OPTGROUP' ) {
                if ( selectedOpt.parentNode.hidden ) {
                    target.value = '0';
                    target.dispatchEvent( new Event( 'change', { bubbles: true } ) );
                }
            }
        }

        filter.addEventListener( 'change', applyFilter );
        applyFilter();
    }

    function init() {
        var filters = document.querySelectorAll( '[data-tt-cascade-filter]' );
        for ( var i = 0; i < filters.length; i++ ) wireOne( filters[ i ] );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})();
