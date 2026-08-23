/*
 * tt-photo-hold.js (#2735, follow-up to #2502) — a photograph waits on the
 * phone until there is signal to read it.
 *
 * ## Why this is not the offline write queue
 *
 * `tt-offline-queue.js` holds *writes*: fire-and-forget, replayed blindly,
 * nobody is waiting for the result. A photograph produces an extraction a
 * coach has to check, so it cannot be replayed and forgotten — there is a
 * person at the other end of it. Two lifecycles in one store would mean one
 * of them was being handled by rules written for the other, so this has its
 * own database.
 *
 * ## What it promises
 *
 * A photo taken out of range survives a reload and a browser restart, and is
 * gone seven days later whether or not anybody looked at it. The expiry is a
 * ceiling rather than a target: a held photo is deleted the moment it has
 * been read and reviewed.
 *
 * The seven days are told to the coach, not enforced silently. A photograph
 * that vanishes without a word is worse than one that expires loudly — the
 * coach believes their training is safe and finds out weeks later that it
 * was not.
 *
 * Vanilla, no dependencies. All user-facing text arrives from PHP.
 */
( function () {
    'use strict';

    var DB_NAME = 'tt_photo_hold';
    var DB_VERSION = 1;
    var STORE = 'photos';

    var cfg = window.TT_PHOTO_HOLD || {};
    var i18n = cfg.i18n || {};

    /** Seven days, unless PHP says otherwise — it owns the number. */
    function maxAgeMs() {
        var days = Number( cfg.holdDays );
        if ( !isFinite( days ) || days <= 0 ) { days = 7; }
        return days * 24 * 60 * 60 * 1000;
    }

    function open() {
        return new Promise( function ( resolve, reject ) {
            if ( !window.indexedDB ) { reject( new Error( 'no indexeddb' ) ); return; }

            var request = window.indexedDB.open( DB_NAME, DB_VERSION );

            request.onupgradeneeded = function () {
                var db = request.result;
                if ( !db.objectStoreNames.contains( STORE ) ) {
                    db.createObjectStore( STORE, { keyPath: 'id', autoIncrement: true } );
                }
            };
            request.onsuccess = function () { resolve( request.result ); };
            request.onerror = function () { reject( request.error ); };
        } );
    }

    function withStore( mode, run ) {
        return open().then( function ( db ) {
            return new Promise( function ( resolve, reject ) {
                var tx = db.transaction( STORE, mode );
                var result = run( tx.objectStore( STORE ) );

                tx.oncomplete = function () { db.close(); resolve( result.value ); };
                tx.onerror = function () { db.close(); reject( tx.error ); };
            } );
        } );
    }

    /**
     * Hold one photograph. `capturedAt` is when the coach took it, not when
     * it was stored, because the seven days are counted from the moment
     * that matters to them.
     */
    function put( base64, teamId ) {
        var record = {
            base64: String( base64 || '' ),
            teamId: teamId ? Number( teamId ) : null,
            capturedAt: Date.now()
        };

        return withStore( 'readwrite', function ( store ) {
            var out = {};
            var request = store.add( record );
            request.onsuccess = function () { out.value = request.result; };
            return out;
        } );
    }

    /** @return Promise<Array> oldest first — the coach's own order. */
    function list() {
        return withStore( 'readonly', function ( store ) {
            var out = { value: [] };
            var request = store.getAll();
            request.onsuccess = function () {
                out.value = ( request.result || [] ).sort( function ( a, b ) {
                    return ( a.capturedAt || 0 ) - ( b.capturedAt || 0 );
                } );
            };
            return out;
        } );
    }

    function remove( id ) {
        return withStore( 'readwrite', function ( store ) {
            var out = {};
            store[ 'delete' ]( id );
            return out;
        } );
    }

    function count() {
        return list().then( function ( rows ) { return rows.length; } );
    }

    /**
     * Drop anything older than the retention window.
     *
     * Called on load as well as on a timer: a phone that was closed for a
     * fortnight would otherwise resurrect a two-week-old photograph the
     * moment it opened, which is the opposite of what the retention
     * promises.
     *
     * @return Promise<number> how many were dropped, so the caller can say so
     */
    function sweep() {
        var cutoff = Date.now() - maxAgeMs();

        return list().then( function ( rows ) {
            var stale = rows.filter( function ( row ) {
                return ( row.capturedAt || 0 ) < cutoff;
            } );
            if ( !stale.length ) { return 0; }

            return Promise.all( stale.map( function ( row ) { return remove( row.id ); } ) )
                .then( function () { return stale.length; } );
        } );
    }

    /**
     * Paint every `[data-tt-photo-hold]` element with the pending count, so
     * a coach who navigated away can still find the photo that is waiting.
     * Empty renders nothing at all rather than a zero — a badge reading "0"
     * is noise on every other page load.
     */
    function paint() {
        var targets = document.querySelectorAll( '[data-tt-photo-hold]' );
        if ( !targets.length ) { return Promise.resolve( 0 ); }

        return count().then( function ( n ) {
            Array.prototype.forEach.call( targets, function ( el ) {
                if ( !n ) {
                    el.textContent = '';
                    el.hidden = true;
                    return;
                }
                var text = n === 1
                    ? ( i18n.pendingOne || 'A photo is waiting to be read' )
                    : ( i18n.pendingMany || '%d photos are waiting to be read' );
                el.textContent = text.replace( '%d', String( n ) );
                el.hidden = false;
            } );
            return n;
        } )[ 'catch' ]( function () { return 0; } );
    }

    window.TT = window.TT || {};
    window.TT.photoHold = {
        put: put,
        list: list,
        remove: remove,
        count: count,
        sweep: sweep,
        paint: paint
    };

    function init() {
        // Sweep first, then paint, so the count never includes a photo that
        // is about to be dropped.
        sweep().then( paint )[ 'catch' ]( function () {} );

        // Hourly, for a tab left open across the expiry.
        window.setInterval( function () {
            sweep().then( paint )[ 'catch' ]( function () {} );
        }, 60 * 60 * 1000 );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
