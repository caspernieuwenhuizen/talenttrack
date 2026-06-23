/*
 * methodology-accordion.js — persists the open/closed state of the
 * Framework (Raamwerk) tab's collapsible <details> sections across
 * visits, keyed per section by its stable data-acc-id. (#1671)
 *
 * Vanilla JS, no build step. Defers all work until DOM ready. The
 * server renders a sensible default (first section open, rest closed);
 * this script only *overrides* that default with the visitor's last
 * choice when a stored value exists, then records every toggle.
 */
( function () {
    'use strict';

    var PREFIX = 'tt-mlogy-acc:';

    function storageGet( key ) {
        try {
            return window.localStorage.getItem( PREFIX + key );
        } catch ( e ) {
            return null;
        }
    }

    function storageSet( key, value ) {
        try {
            window.localStorage.setItem( PREFIX + key, value );
        } catch ( e ) {
            /* storage unavailable (private mode / quota) — degrade to
               the server-rendered defaults, no persistence. */
        }
    }

    function init() {
        var items = document.querySelectorAll( '.tt-mlogy-acc[data-acc-id]' );
        if ( ! items.length ) {
            return;
        }

        Array.prototype.forEach.call( items, function ( el ) {
            var id = el.getAttribute( 'data-acc-id' );
            if ( ! id ) {
                return;
            }

            // Restore persisted state, if any. Absent value keeps the
            // server-rendered default.
            var stored = storageGet( id );
            if ( stored === 'open' ) {
                el.open = true;
            } else if ( stored === 'closed' ) {
                el.open = false;
            }

            // Record every toggle.
            el.addEventListener( 'toggle', function () {
                storageSet( id, el.open ? 'open' : 'closed' );
            } );
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
