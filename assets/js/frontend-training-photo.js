/*
 * frontend-training-photo.js (#2502, wave 9 of epic #2493) — photograph a
 * hand-written training and get a draft back.
 *
 * Three states: capture, read, review. The whole extraction lives in
 * memory until the coach presses the button that creates something.
 * Closing the tab at the review step leaves no plan, no blocks and no
 * photograph anywhere, which is the wave's own acceptance criterion and
 * the reason nothing here writes as it goes.
 *
 * ## The review step is a checking task, not a choosing one
 *
 * The generator's step 4 shows blocks the system picked, and the coach
 * decides whether they like them. Here the coach decides whether a
 * machine read their handwriting correctly. So every row carries the
 * match's confidence, a sentence about why it might be wrong, and an
 * editable name and duration — and an unmatched row says what leaving it
 * unmatched costs, because "this will not count towards what your
 * players have been taught" is invisible otherwise.
 *
 * ## Thresholds come from PHP
 *
 * 0.85 and 0.6 arrive as numbers in the config rather than being written
 * here, because 0.6 is `ExerciseFuzzyMatcher`'s own threshold and a copy
 * of it in JavaScript would drift the day someone tuned the matcher. A
 * test reads both and fails if they part company.
 *
 * Vanilla, no dependencies. All user-facing text arrives from PHP.
 */
( function () {
    'use strict';

    var cfg = window.TT_TRAINING_PHOTO || {};
    var i18n = cfg.i18n || {};

    /** Anthropic's Messages API refuses an image over 5 MB; so do we, first. */
    var MAX_BYTES = 5 * 1024 * 1024;

    var state = {
        step: 'capture',
        teamId: null,
        rows: [],
        title: '',
        stream: null,
        busy: false
    };

    var root = null;

    // ── helpers ─────────────────────────────────────────────────────

    function node( tag, className, text ) {
        var n = document.createElement( tag );
        if ( className ) { n.className = className; }
        if ( text !== undefined && text !== null ) { n.textContent = text; }
        return n;
    }

    function button( label, className, onClick ) {
        var b = node( 'button', className, label );
        b.type = 'button';
        b.addEventListener( 'click', onClick );
        return b;
    }

    function fmt( template, a, b ) {
        return String( template || '' )
            .replace( /%1\$[ds]/, String( a ) )
            .replace( /%2\$[ds]/, String( b ) )
            .replace( /%[ds]/, String( a ) );
    }

    function say( message ) {
        var live = root.querySelector( '[data-tt-photo-msg]' );
        if ( live ) { live.textContent = message || ''; }
    }

    /** Which band a match falls in. One place, so the tint and the copy agree. */
    function band( similarity ) {
        var value = Number( similarity );
        if ( !( value > 0 ) ) { return 'unsure'; }
        if ( value >= Number( cfg.sure ) ) { return 'sure'; }
        if ( value >= Number( cfg.maybe ) ) { return 'maybe'; }
        return 'unsure';
    }

    function go( step ) {
        state.step = step;
        render();
    }

    // ── capture ─────────────────────────────────────────────────────

    function renderCapture() {
        var wrap = node( 'div', 'tt-photo__capture' );

        // Team first. Asking after the extraction, with the coach already
        // reading rows, would be a question in the wrong place.
        var teams = cfg.teams || [];
        if ( teams.length ) {
            var field = node( 'label', 'tt-field tt-photo__team' );
            field.appendChild( node( 'span', 'tt-field__label', i18n.forTeam ) );

            var select = node( 'select', 'tt-input' );
            teams.forEach( function ( team ) {
                var option = node( 'option', null, team.label );
                option.value = String( team.value );
                select.appendChild( option );
            } );
            select.addEventListener( 'change', function () { state.teamId = Number( select.value ); } );
            state.teamId = Number( select.value || teams[ 0 ].value );

            field.appendChild( select );
            wrap.appendChild( field );
        }

        var finder = node( 'div', 'tt-photo__finder' );
        var video = node( 'video', 'tt-photo__video' );
        video.setAttribute( 'playsinline', '' );
        video.setAttribute( 'muted', '' );
        video.muted = true;
        finder.appendChild( video );
        finder.appendChild( node( 'div', 'tt-photo__guide' ) );
        finder.appendChild( node( 'p', 'tt-photo__hint', i18n.frameIt ) );
        wrap.appendChild( finder );

        var row = node( 'div', 'tt-photo__shutter-row' );

        // The gallery path is a real input, not a styled div, so it works
        // on a browser that refuses the camera and on a desktop that has
        // none. `capture` is a hint, not a requirement.
        var file = node( 'input', 'tt-sr-only-file' );
        file.type = 'file';
        file.accept = 'image/*';
        file.id = 'tt-photo-file';
        file.addEventListener( 'change', function () {
            if ( file.files && file.files[ 0 ] ) { useFile( file.files[ 0 ] ); }
        } );

        var pick = node( 'label', 'tt-photo__linkish', i18n.fromLibrary );
        pick.setAttribute( 'for', 'tt-photo-file' );

        var shutter = button( '', 'tt-photo__shutter', function () { snap( video ); } );
        shutter.setAttribute( 'aria-label', i18n.takePhoto );

        row.appendChild( pick );
        row.appendChild( shutter );
        row.appendChild( file );
        wrap.appendChild( row );

        if ( cfg.dataRegion ) {
            wrap.appendChild( node(
                'p',
                'tt-photo__destination',
                fmt( i18n.destination, cfg.dataRegion )
            ) );
        }

        root.appendChild( wrap );
        openCamera( video, shutter );
    }

    function openCamera( video, shutter ) {
        if ( !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia ) {
            say( i18n.noCamera );
            shutter.disabled = true;
            return;
        }

        navigator.mediaDevices.getUserMedia( {
            video: { facingMode: { ideal: 'environment' } },
            audio: false
        } ).then( function ( stream ) {
            state.stream = stream;
            video.srcObject = stream;
            video.play();
        } )[ 'catch' ]( function () {
            // Refused, or no camera. The gallery path still works, so this
            // is a redirection rather than a failure.
            say( i18n.cameraRefused );
            shutter.disabled = true;
        } );
    }

    function stopCamera() {
        if ( !state.stream ) { return; }
        state.stream.getTracks().forEach( function ( track ) { track.stop(); } );
        state.stream = null;
    }

    function snap( video ) {
        if ( !video.videoWidth ) { say( i18n.noCamera ); return; }

        var canvas = document.createElement( 'canvas' );
        // Cap the long edge. A modern phone shoots far more than the model
        // needs, and the 5 MB limit is easier to respect before encoding
        // than to apologise for after.
        var scale = Math.min( 1, 1600 / Math.max( video.videoWidth, video.videoHeight ) );
        canvas.width = Math.round( video.videoWidth * scale );
        canvas.height = Math.round( video.videoHeight * scale );
        canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );

        var dataUrl = canvas.toDataURL( 'image/jpeg', 0.85 );
        stopCamera();
        extract( dataUrl.split( ',' )[ 1 ] );
    }

    function useFile( file ) {
        if ( file.size > MAX_BYTES ) { say( i18n.tooBig ); return; }

        var reader = new FileReader();
        reader.onload = function () {
            stopCamera();
            extract( String( reader.result ).split( ',' )[ 1 ] );
        };
        reader.onerror = function () { say( i18n.readFailed ); };
        reader.readAsDataURL( file );
    }

    // ── read ────────────────────────────────────────────────────────

    function extract( base64 ) {
        if ( !base64 ) { say( i18n.readFailed ); return; }

        // Roughly: base64 inflates by a third. Checking here rather than
        // letting the server refuse saves sending several megabytes to be
        // told no, over the connection least able to spare them.
        if ( base64.length * 0.75 > MAX_BYTES ) { say( i18n.tooBig ); return; }

        go( 'reading' );

        window.fetch( cfg.restBase + '/vision/extract', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify( { photo_base64: base64, team_id: state.teamId } )
        } ).then( function ( response ) {
            return response.json().then( function ( envelope ) {
                return { ok: response.ok, envelope: envelope };
            } );
        } ).then( function ( result ) {
            if ( !result.ok || !result.envelope || result.envelope.success === false ) {
                go( 'capture' );
                say( i18n.readFailed );
                return;
            }

            state.rows = ( result.envelope.data && result.envelope.data.exercises ) || [];
            if ( !state.rows.length ) {
                go( 'capture' );
                say( i18n.nothingRead );
                return;
            }

            go( 'review' );
        } )[ 'catch' ]( function () {
            // A rejected fetch is "never reached the server", which for
            // this screen means offline. Nothing was sent, and saying so
            // matters more than the retry.
            go( 'capture' );
            say( navigator.onLine === false ? i18n.offline : i18n.readFailed );
        } );
    }

    function renderReading() {
        var wrap = node( 'div', 'tt-photo__reading' );
        wrap.appendChild( node( 'div', 'tt-photo__spinner' ) );
        wrap.appendChild( node( 'h2', 'tt-photo__reading-title', i18n.reading ) );
        wrap.appendChild( node( 'p', 'tt-muted', i18n.readingSub ) );
        wrap.appendChild( node( 'p', 'tt-photo__nothing', i18n.nothingSaved ) );
        root.appendChild( wrap );
    }

    // ── review ──────────────────────────────────────────────────────

    function totalMinutes() {
        return state.rows.reduce( function ( sum, row ) {
            return sum + ( Number( row.duration_minutes ) || 0 );
        }, 0 );
    }

    function renderReview() {
        var wrap = node( 'div', 'tt-photo__review' );

        wrap.appendChild( node( 'h2', 'tt-photo__review-title', i18n.checkThis ) );
        wrap.appendChild( node( 'p', 'tt-muted', fmt( i18n.readSummary, state.rows.length, totalMinutes() ) ) );

        var legend = node( 'div', 'tt-photo__legend' );
        [ [ 'sure', i18n.legendSure ], [ 'maybe', i18n.legendMaybe ], [ 'unsure', i18n.legendUnsure ] ]
            .forEach( function ( pair ) {
                var item = node( 'span', 'tt-photo__legend-item' );
                item.appendChild( node( 'i', 'tt-photo__swatch tt-photo__swatch--' + pair[ 0 ] ) );
                item.appendChild( node( 'span', null, pair[ 1 ] ) );
                legend.appendChild( item );
            } );
        wrap.appendChild( legend );

        var list = node( 'div', 'tt-photo__rows' );
        state.rows.forEach( function ( row, index ) {
            list.appendChild( reviewRow( row, index ) );
        } );
        wrap.appendChild( list );

        // The title. Prefilled from the team and today, because a coach
        // who just photographed a sheet has already decided what this is.
        var titleField = node( 'label', 'tt-field' );
        titleField.appendChild( node( 'span', 'tt-field__label', i18n.planTitle ) );
        var titleInput = node( 'input', 'tt-input' );
        titleInput.type = 'text';
        titleInput.value = state.title || defaultTitle();
        titleInput.addEventListener( 'input', function () { state.title = titleInput.value; } );
        state.title = titleInput.value;
        titleField.appendChild( titleInput );
        wrap.appendChild( titleField );

        var actions = node( 'div', 'tt-form-actions tt-photo__commit' );
        actions.appendChild( node( 'p', 'tt-photo__nothing', i18n.nothingSaved ) );
        actions.appendChild( button( i18n.discard, 'tt-btn tt-btn-secondary', function () {
            state.rows = [];
            go( 'capture' );
        } ) );
        var create = button( i18n.createDraft, 'tt-btn tt-btn-primary', commit );
        create.setAttribute( 'data-tt-photo-create', '' );
        actions.appendChild( create );
        wrap.appendChild( actions );

        root.appendChild( wrap );
    }

    function defaultTitle() {
        var team = ( cfg.teams || [] ).filter( function ( t ) {
            return Number( t.value ) === Number( state.teamId );
        } )[ 0 ];

        return team ? team.label : '';
    }

    function reviewRow( row, index ) {
        var kind = band( row.matched_similarity );
        var item = node( 'div', 'tt-photo__row tt-photo__row--' + kind );

        var top = node( 'div', 'tt-photo__row-top' );

        var name = node( 'input', 'tt-photo__row-name' );
        name.type = 'text';
        name.value = row.name || '';
        name.setAttribute( 'aria-label', i18n.nameLabel );
        name.addEventListener( 'input', function () { state.rows[ index ].name = name.value; } );
        top.appendChild( name );

        var mins = node( 'input', 'tt-photo__row-mins tt-input' );
        mins.type = 'number';
        mins.setAttribute( 'inputmode', 'numeric' );
        mins.min = '0';
        mins.value = String( Number( row.duration_minutes ) || 0 );
        mins.setAttribute( 'aria-label', i18n.minutesLabel );
        mins.addEventListener( 'input', function () {
            state.rows[ index ].duration_minutes = Number( mins.value ) || 0;
        } );
        top.appendChild( mins );

        item.appendChild( top );

        var match = node( 'div', 'tt-photo__row-match' );
        var matchedName = row.match_candidates && row.match_candidates.length
            ? row.match_candidates[ 0 ].name
            : null;

        match.appendChild( node(
            'span',
            'tt-photo__chip' + ( kind === 'sure' ? ' tt-photo__chip--sure' : '' ),
            row.matched_exercise_id && matchedName ? matchedName : i18n.noMatch
        ) );

        if ( row.matched_similarity ) {
            match.appendChild( node(
                'span',
                'tt-photo__conf',
                Number( row.matched_similarity ).toFixed( 2 )
            ) );
        }

        match.appendChild( button( i18n.removeRow, 'tt-btn tt-btn-secondary tt-photo__row-remove', function () {
            state.rows.splice( index, 1 );
            if ( !state.rows.length ) { go( 'capture' ); return; }
            render();
        } ) );

        item.appendChild( match );

        if ( kind !== 'sure' ) {
            item.appendChild( node(
                'p',
                'tt-photo__why',
                kind === 'maybe' ? i18n.whyMaybe : i18n.whyUnsure
            ) );
        }

        return item;
    }

    // ── commit ──────────────────────────────────────────────────────

    /**
     * The only thing on this screen that writes.
     *
     * Two calls, in order: create the plan, then replace its blocks. If
     * the second fails the coach is told nothing was saved — which is a
     * small lie about the empty plan row, and better than the truth being
     * an orphan they never find. Worth revisiting if it ever happens.
     */
    function commit() {
        if ( state.busy ) { return; }

        if ( !state.title.trim() ) { say( i18n.titleRequired ); return; }
        if ( !state.teamId ) { say( i18n.teamRequired ); return; }

        state.busy = true;
        var create = root.querySelector( '[data-tt-photo-create]' );
        if ( create ) { create.disabled = true; }
        say( i18n.creating );

        post( '/training/plans', {
            title: state.title.trim(),
            team_id: state.teamId,
            source: 'photo'
        } ).then( function ( plan ) {
            var planId = plan && plan.plan && plan.plan.id ? plan.plan.id : ( plan && plan.id );
            if ( !planId ) { throw new Error( 'no-plan' ); }

            return put( '/training/plans/' + planId + '/blocks', {
                blocks: state.rows.map( function ( row, index ) {
                    return {
                        order_index: index,
                        block_type: 'main',
                        exercise_id: row.matched_exercise_id || null,
                        title_override: row.name || '',
                        duration_minutes: Number( row.duration_minutes ) || 0,
                        coaching_points: row.notes || ''
                    };
                } )
            } ).then( function () { return planId; } );
        } ).then( function ( planId ) {
            say( i18n.created );
            window.location.href = cfg.plansUrl + '&id=' + planId;
        } )[ 'catch' ]( function () {
            state.busy = false;
            if ( create ) { create.disabled = false; }
            say( i18n.createFailed );
        } );
    }

    function post( path, body ) { return send( 'POST', path, body ); }
    function put( path, body ) { return send( 'PUT', path, body ); }

    function send( method, path, body ) {
        return window.fetch( cfg.restBase + path, {
            method: method,
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify( body )
        } ).then( function ( response ) {
            if ( !response.ok ) { throw new Error( String( response.status ) ); }
            return response.json();
        } ).then( function ( envelope ) {
            if ( !envelope || envelope.success === false ) { throw new Error( 'rest' ); }
            return envelope.data;
        } );
    }

    // ── shell ───────────────────────────────────────────────────────

    function render() {
        root.textContent = '';

        if ( state.step === 'capture' ) { renderCapture(); }
        else if ( state.step === 'reading' ) { renderReading(); }
        else { renderReview(); }

        var live = node( 'p', 'tt-photo__msg tt-muted' );
        live.setAttribute( 'data-tt-photo-msg', '' );
        live.setAttribute( 'role', 'status' );
        live.setAttribute( 'aria-live', 'polite' );
        root.appendChild( live );
    }

    function init() {
        root = document.querySelector( '[data-tt-photo]' );
        if ( !root ) { return; }

        render();

        // A camera left running because someone navigated away is a light
        // on a phone in a coach's pocket.
        window.addEventListener( 'pagehide', stopCamera );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
