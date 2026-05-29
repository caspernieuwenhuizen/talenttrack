/* TalentTrack — Exports page column picker (#986).
 *
 * Wires:
 *   - the `<details>` summary count ("Columns · all selected" →
 *     "Columns · 3 of 6 selected") as the user toggles checkboxes,
 *   - the All / None quick-toggle buttons inside each picker,
 *   - the show/hide behaviour when a multi-format card switches its
 *     format chip from CSV / XLSX to a non-tabular format (PDF, JSON,
 *     iCal, ZIP).
 *
 * Strings come from `TT_EXPORTS_I18N` localised by
 * `FrontendExportsView::enqueueAssets()`. Vanilla JS only — no jQuery
 * per CLAUDE.md §2.
 */
(function () {
    var i18n = window.TT_EXPORTS_I18N || {
        allSelected: 'all selected',
        partial:     '%1$d of %2$d selected'
    };

    function updateSummary( details ) {
        var checks  = details.querySelectorAll( 'input[type="checkbox"][name="columns[]"]' );
        var total   = checks.length;
        var kept    = 0;
        for ( var i = 0; i < checks.length; i++ ) {
            if ( checks[ i ].checked ) kept++;
        }
        var summary = details.querySelector( '[data-tt-columns-summary]' );
        if ( ! summary ) return;
        if ( kept === total ) {
            summary.textContent = '· ' + i18n.allSelected;
        } else {
            summary.textContent = '· ' + i18n.partial
                .replace( '%1$d', String( kept ) )
                .replace( '%2$d', String( total ) );
        }
    }

    function applyFormatVisibility( form ) {
        var details = form.querySelector( '[data-tt-export-columns]' );
        if ( ! details ) return;
        var picked = form.querySelector( 'input[name="format"]:checked' )
                  || form.querySelector( 'input[name="format"]' );
        if ( ! picked ) return;
        var tabular = ( details.getAttribute( 'data-tabular-formats' ) || '' ).split( ',' );
        if ( tabular.indexOf( picked.value ) === -1 ) {
            details.style.display = 'none';
        } else {
            details.style.display = '';
        }
    }

    function init() {
        var pickers = document.querySelectorAll( '[data-tt-export-columns]' );
        for ( var p = 0; p < pickers.length; p++ ) {
            ( function ( details ) {
                updateSummary( details );
                details.addEventListener( 'change', function ( e ) {
                    if ( e.target && e.target.name === 'columns[]' ) updateSummary( details );
                } );
                var allBtn = details.querySelector( '[data-tt-columns-all]' );
                if ( allBtn ) allBtn.addEventListener( 'click', function () {
                    var checks = details.querySelectorAll( 'input[type="checkbox"][name="columns[]"]' );
                    for ( var i = 0; i < checks.length; i++ ) checks[ i ].checked = true;
                    updateSummary( details );
                } );
                var noneBtn = details.querySelector( '[data-tt-columns-none]' );
                if ( noneBtn ) noneBtn.addEventListener( 'click', function () {
                    var checks = details.querySelectorAll( 'input[type="checkbox"][name="columns[]"]' );
                    for ( var i = 0; i < checks.length; i++ ) checks[ i ].checked = false;
                    updateSummary( details );
                } );
            } )( pickers[ p ] );
        }

        var forms = document.querySelectorAll( '.tt-export-card__form' );
        for ( var f = 0; f < forms.length; f++ ) {
            ( function ( form ) {
                applyFormatVisibility( form );
                var radios = form.querySelectorAll( 'input[name="format"]' );
                for ( var r = 0; r < radios.length; r++ ) {
                    radios[ r ].addEventListener( 'change', function () {
                        applyFormatVisibility( form );
                    } );
                }
            } )( forms[ f ] );
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})();
