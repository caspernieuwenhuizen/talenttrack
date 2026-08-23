/*
 * tt-offline-queue.js (#2552) — writes that survive losing signal.
 *
 * A coach runs a training on a pitch. Signal there is unreliable, and
 * before this existed a lost connection meant a lost session: block
 * timings and observations typed into a form that then failed to save.
 * That is the exact failure that sends people back to paper.
 *
 * ## IndexedDB, in the page — not the service worker
 *
 * #2552 framed the choice as "localStorage, or extend `tt-sw.js` with
 * IndexedDB so a cold load works offline". Both halves of that need
 * correcting:
 *
 *   - IndexedDB needs no service worker. Any page can open it.
 *   - `tt-sw.js` is scoped to the plugin's assets directory, on purpose,
 *     to stay out of theme pages that never opted into TalentTrack. It
 *     therefore cannot intercept a navigation, so extending it would not
 *     make a cold load work either. That would need the scope widened to
 *     the site root plus navigation caching — a bigger, separate change.
 *
 * So: IndexedDB, opened directly. It survives a reload, it has room for
 * the photographs #2502 will queue (localStorage is ~5 MB per origin in
 * total, and base64 inflates an image by a third — one photo could
 * exhaust it), and it needs no new service-worker surface.
 *
 * **A cold load still needs signal.** Queued writes survive a reload of
 * an already-loaded page; loading the page itself from nothing does not
 * work offline. Nothing here claims otherwise.
 *
 * ## Replaying is safe by construction, not by hope
 *
 * The queue can only promise at-least-once delivery: a request whose
 * response is lost in transit succeeded on the server and looks failed
 * here. So every write it carries has to be safe to repeat.
 *
 *   - `PATCH` on a run or a block sets absolute values, so replaying
 *     writes the same state twice and lands in the same place.
 *   - `POST /observations` would otherwise insert twice. It carries a
 *     `client_uuid` generated here, once, when the coach saves; the
 *     server returns the existing row instead of a second one.
 *
 * That matters more than it sounds: the run and its blocks are what
 * wave 7 computes per-player exposure from, so a double-applied
 * duration becomes a wrong number on a child's development record.
 *
 * Vanilla, no dependencies. Exposed as `window.TTOfflineQueue`.
 */
( function () {
    'use strict';

    var DB_NAME = 'tt-offline';
    var DB_VERSION = 1;
    var STORE = 'writes';

    /**
     * Statuses that mean "this will never succeed", so the entry is
     * dropped rather than left blocking everything behind it. A stuck
     * queue is worse than a lost write, because it also loses everything
     * queued after it.
     */
    var PERMANENT = [ 400, 404, 409, 410, 422 ];

    /**
     * Statuses that mean "stop, but keep everything". 401/403 is usually
     * an expired nonce after a long spell offline — the writes are still
     * good, they just need a fresh page. Dropping them would throw away
     * a session's work to a recoverable problem.
     */
    var PAUSE = [ 401, 403 ];

    var listeners = [];
    var dbPromise = null;
    var flushing = false;

    /**
     * What to do with a response, as a pure decision so it can be
     * tested without a database or a network.
     *
     *   'sent' — it landed; forget it.
     *   'drop' — it will never land; forget it, or it blocks the queue
     *            forever and takes the rest of the session with it.
     *   'stop' — it might land later; keep it and stop here, so the
     *            order the coach did things in is preserved.
     */
    function classify( status ) {
        if ( status >= 200 && status < 300 ) { return 'sent'; }
        if ( PERMANENT.indexOf( status ) !== -1 ) { return 'drop'; }
        return 'stop';
    }

    // ── storage ─────────────────────────────────────────────────────

    function openDb() {
        if ( dbPromise ) { return dbPromise; }

        dbPromise = new Promise( function ( resolve, reject ) {
            if ( !window.indexedDB ) { reject( new Error( 'no-indexeddb' ) ); return; }

            var request = window.indexedDB.open( DB_NAME, DB_VERSION );

            request.onupgradeneeded = function () {
                var db = request.result;
                if ( !db.objectStoreNames.contains( STORE ) ) {
                    // autoIncrement, so the key order IS the order the
                    // coach did things in. The queue must replay in that
                    // order or a block's duration can land before the
                    // block exists.
                    db.createObjectStore( STORE, { keyPath: 'id', autoIncrement: true } );
                }
            };
            request.onsuccess = function () { resolve( request.result ); };
            request.onerror = function () { reject( request.error ); };
        } );

        return dbPromise;
    }

    function tx( mode, run ) {
        return openDb().then( function ( db ) {
            return new Promise( function ( resolve, reject ) {
                var transaction = db.transaction( STORE, mode );
                var store = transaction.objectStore( STORE );
                var result;

                try { result = run( store ); } catch ( e ) { reject( e ); return; }

                transaction.oncomplete = function () { resolve( result && result.result !== undefined ? result.result : result ); };
                transaction.onerror = function () { reject( transaction.error ); };
                transaction.onabort = function () { reject( transaction.error ); };
            } );
        } );
    }

    function all() {
        return tx( 'readonly', function ( store ) {
            return store.getAll ? store.getAll() : null;
        } ).then( function ( rows ) {
            return Array.isArray( rows ) ? rows : [];
        } );
    }

    // ── the queue ───────────────────────────────────────────────────

    function notify() {
        pending().then( function ( count ) {
            for ( var i = 0; i < listeners.length; i++ ) {
                try { listeners[ i ]( count ); } catch ( e ) {}
            }
        } );
    }

    function pending() {
        return tx( 'readonly', function ( store ) {
            return store.count();
        } ).then( function ( n ) {
            return Number( n ) || 0;
        } )[ 'catch' ]( function () { return 0; } );
    }

    /**
     * Put a write in the queue. Returns a promise for its key, or null
     * when there is no IndexedDB to put it in — a caller that gets null
     * knows the write is genuinely lost and can say so, rather than
     * showing a reassuring "saved offline" that was not true.
     */
    function enqueue( entry ) {
        return tx( 'readwrite', function ( store ) {
            return store.add( {
                method: entry.method,
                url: entry.url,
                body: entry.body === undefined ? null : entry.body,
                label: entry.label || '',
                createdAt: Date.now()
            } );
        } ).then( function ( key ) {
            notify();
            return key;
        } )[ 'catch' ]( function () { return null; } );
    }

    function remove( id ) {
        return tx( 'readwrite', function ( store ) {
            return store [ 'delete' ]( id );
        } );
    }

    /**
     * Replay everything, oldest first, stopping at the first entry that
     * cannot go through yet.
     *
     * Sequential on purpose. These writes are about one run and often
     * about the same block, so sending them in parallel would let a
     * later write land before an earlier one.
     */
    function flush( fetchImpl ) {
        var idle = { sent: 0, dropped: 0, paused: false };

        var send = fetchImpl
            || ( typeof window.fetch === 'function' ? window.fetch.bind( window ) : null );

        // Nothing to flush with, or nowhere to flush from. Checked
        // before anything else because `flush()` runs on load: throwing
        // here would take the module down on the browsers least able to
        // afford it, and the run view would then have no queue at all
        // rather than a degraded one.
        if ( flushing || !send || !window.indexedDB ) { return Promise.resolve( idle ); }

        flushing = true;
        var sent = 0;
        var dropped = 0;
        var paused = false;

        return all().then( function ( rows ) {
            rows.sort( function ( a, b ) { return a.id - b.id; } );

            return rows.reduce( function ( chain, row ) {
                return chain.then( function ( stop ) {
                    if ( stop ) { return true; }

                    var init = {
                        method: row.method,
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': ( window.TTOfflineQueue && window.TTOfflineQueue.nonce ) || ''
                        }
                    };
                    if ( row.body !== null && row.body !== undefined ) {
                        init.body = JSON.stringify( row.body );
                    }

                    return send( row.url, init ).then( function ( response ) {
                        var verdict = classify( response.status );

                        if ( verdict === 'sent' ) {
                            sent++;
                            return remove( row.id ).then( function () { return false; } );
                        }
                        if ( verdict === 'drop' ) {
                            dropped++;
                            return remove( row.id ).then( function () { return false; } );
                        }
                        // 5xx, or an expired nonce: keep it, stop here.
                        if ( PAUSE.indexOf( response.status ) !== -1 ) { paused = true; }
                        return true;
                    } )[ 'catch' ]( function () {
                        // Still offline. Everything stays.
                        return true;
                    } );
                } );
            }, Promise.resolve( false ) );
        } ).then( function () {
            flushing = false;
            notify();
            return { sent: sent, dropped: dropped, paused: paused };
        } )[ 'catch' ]( function () {
            flushing = false;
            return { sent: sent, dropped: dropped, paused: paused };
        } );
    }

    /** A v4 uuid, for writes that must not apply twice. */
    function uuid() {
        if ( window.crypto && window.crypto.randomUUID ) {
            return window.crypto.randomUUID();
        }
        if ( window.crypto && window.crypto.getRandomValues ) {
            var bytes = new Uint8Array( 16 );
            window.crypto.getRandomValues( bytes );
            bytes[ 6 ] = ( bytes[ 6 ] & 0x0f ) | 0x40;
            bytes[ 8 ] = ( bytes[ 8 ] & 0x3f ) | 0x80;
            var hex = [];
            for ( var i = 0; i < bytes.length; i++ ) {
                hex.push( ( bytes[ i ] + 0x100 ).toString( 16 ).slice( 1 ) );
            }
            return hex.slice( 0, 4 ).join( '' ) + '-' + hex.slice( 4, 6 ).join( '' ) + '-'
                + hex.slice( 6, 8 ).join( '' ) + '-' + hex.slice( 8, 10 ).join( '' ) + '-'
                + hex.slice( 10, 16 ).join( '' );
        }
        // No crypto at all. Still v4-SHAPED, deliberately: the server
        // validates the shape before using it as an idempotency key, so
        // anything else would be silently discarded and the write would
        // quietly become duplicable again. Math.random is weaker than
        // crypto, but this only has to be unique among one coach's
        // queued writes, not unguessable.
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
            var r = Math.random() * 16 | 0;
            return ( c === 'x' ? r : ( r & 0x3 | 0x8 ) ).toString( 16 );
        } );
    }

    function onChange( callback ) {
        if ( typeof callback === 'function' ) {
            listeners.push( callback );
            pending().then( callback );
        }
    }

    window.TTOfflineQueue = {
        enqueue: enqueue,
        pending: pending,
        flush: flush,
        onChange: onChange,
        uuid: uuid,
        classify: classify,
        nonce: '',
        supported: !!window.indexedDB
    };

    // Flushing on `online` is the whole point; flushing on load catches
    // the coach who closed the tab in the car park and reopened it at
    // home, where `online` never fires because it never went offline.
    window.addEventListener( 'online', function () { flush(); } );

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function () { flush(); } );
    } else {
        flush();
    }
}() );
