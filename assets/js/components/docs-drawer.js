/* docs-drawer.js — context-aware help drawer (#0016 Part B).
 *
 * Triggered by any element with [data-tt-docs-drawer-open]. On open,
 * reads the current ?tt_view= query param, looks up the matching
 * topic slug from TT_DocsDrawer.view_to_topic, falls back to
 * `default_slug`, and fetches the rendered HTML via
 * /wp-json/talenttrack/v1/docs/{slug}. Cap-gated server-side. */
(function () {
    'use strict';

    var CFG = window.TT_DocsDrawer || {};
    var REST_URL  = CFG.rest_url   || '/wp-json/talenttrack/v1/docs';
    var NONCE     = CFG.rest_nonce || '';
    var MAP       = CFG.view_to_topic || {};
    var DEFAULT   = CFG.default_slug || 'getting-started';
    var I18N      = CFG.i18n || {};

    function $( sel, root ) { return ( root || document ).querySelector( sel ); }

    function currentView() {
        try {
            var params = new URLSearchParams( window.location.search );
            return ( params.get( 'tt_view' ) || '' ).trim();
        } catch ( _e ) { return ''; }
    }

    function topicForCurrentView() {
        var view = currentView();
        return MAP[ view ] || DEFAULT;
    }

    function openDrawer( drawer ) {
        drawer.hidden = false;
        drawer.setAttribute( 'aria-hidden', 'false' );
        document.body.classList.add( 'tt-docs-drawer-open' );
        loadTopic( drawer, topicForCurrentView() );
    }

    function closeDrawer( drawer ) {
        drawer.hidden = true;
        drawer.setAttribute( 'aria-hidden', 'true' );
        document.body.classList.remove( 'tt-docs-drawer-open' );
    }

    function setBody( drawer, html ) {
        var body = drawer.querySelector( '[data-tt-docs-drawer-body]' );
        if ( body ) body.innerHTML = html;
    }

    function setTitle( drawer, title ) {
        var h = drawer.querySelector( '[data-tt-docs-drawer-title]' );
        if ( h ) h.textContent = title;
    }

    function setExpand( drawer, slug ) {
        var a = drawer.querySelector( '[data-tt-docs-drawer-expand]' );
        if ( ! a ) return;
        try {
            var u = new URL( a.href, window.location.origin );
            u.searchParams.set( 'topic', slug );
            a.href = u.toString();
        } catch ( _e ) { /* leave as-is on URL parse failure */ }
    }

    function escapeHtml( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function loadTopic( drawer, slug ) {
        setBody( drawer, '<p class="tt-docs-drawer__loading">' + escapeHtml( I18N.loading || 'Loading…' ) + '</p>' );
        var url = REST_URL.replace( /\/$/, '' ) + '/' + encodeURIComponent( slug );
        fetch( url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json' },
        } )
        .then( function ( r ) {
            if ( ! r.ok && r.status === 403 ) {
                // Not authorised — fall back to the default topic once.
                if ( slug !== DEFAULT ) return loadTopic( drawer, DEFAULT );
                throw new Error( 'forbidden' );
            }
            if ( ! r.ok ) throw new Error( 'fetch failed: ' + r.status );
            return r.json();
        } )
        .then( function ( data ) {
            if ( ! data || ! data.html ) return;
            setTitle( drawer, data.title || 'Help' );
            setBody( drawer, data.html );
            setExpand( drawer, data.slug || slug );
        } )
        .catch( function () {
            setBody( drawer, '<p class="tt-docs-drawer__error">' + escapeHtml( I18N.failed || 'Could not load this topic.' ) + '</p>' );
        } );
    }

    document.addEventListener( 'click', function ( e ) {
        var t = e.target;
        var openTrig = t.closest && t.closest( '[data-tt-docs-drawer-open]' );
        if ( openTrig ) {
            // Allow middle-click / cmd-click / ctrl-click to fall through to
            // the link's href (full Help & Docs page in a new tab).
            if ( e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey ) return;
            e.preventDefault();
            var d = $( '[data-tt-docs-drawer]' );
            if ( d ) openDrawer( d );
            return;
        }
        var closeTrig = t.closest && t.closest( '[data-tt-docs-drawer-close]' );
        if ( closeTrig ) {
            e.preventDefault();
            var d2 = closeTrig.closest( '[data-tt-docs-drawer]' );
            if ( d2 ) closeDrawer( d2 );
        }
    } );

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Escape' ) return;
        var d = $( '[data-tt-docs-drawer]' );
        if ( d && ! d.hidden ) closeDrawer( d );
    } );
})();
