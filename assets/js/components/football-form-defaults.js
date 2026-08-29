/**
 * Football form defaults (#3044).
 *
 * The config endpoint stores one scalar per key, so the per-age-category
 * selects are assembled into a single JSON map on a hidden field that the
 * standard config-form submit handler ships as
 * `config[football_form_by_age_group]`.
 *
 * Kept in a file rather than inline so the page has no `<script>` block of
 * its own (CLAUDE.md § 2, WordPress-specific).
 */
( function () {
    'use strict';

    var form = document.querySelector( '[data-tt-ff-form]' );
    if ( ! form ) return;

    var hidden = form.querySelector( '[data-tt-ff-json]' );
    if ( ! hidden ) return;

    function sync() {
        var map = {};
        var selects = form.querySelectorAll( '[data-tt-ff-select]' );
        Array.prototype.forEach.call( selects, function ( select ) {
            var group = select.getAttribute( 'data-tt-ff-group' ) || '';
            var value = select.value || '';
            if ( group && value ) map[ group ] = value;
        } );
        hidden.value = JSON.stringify( map );
    }

    form.addEventListener( 'change', function ( e ) {
        if ( e.target && e.target.hasAttribute && e.target.hasAttribute( 'data-tt-ff-select' ) ) sync();
    } );

    sync();
} )();
