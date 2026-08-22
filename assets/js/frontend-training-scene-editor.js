/*
 * frontend-training-scene-editor.js (#2501, epic #2493) — the canvas a
 * coach draws a drill on.
 *
 * ## It edits through the renderer, not beside it
 *
 * Every mark on this canvas is built by `TTTrainingScene.mount()` — the
 * same function the read-only scene uses. The editor adds selection,
 * dragging, a timeline and an inspector on top; it never draws a pitch
 * or a token of its own. That is what makes "renders identically in the
 * exercise detail, the sideline view and the A4 print" true by
 * construction rather than by remembering to keep four files in step.
 *
 * ## The core gesture
 *
 * Drag a marker: that writes a keyframe at the current time. Nothing
 * else. A coach who never opens the timeline still gets a working
 * animation by scrubbing to a moment and moving people — which is how
 * anyone describes a drill out loud, so it is how the editor works.
 *
 * ## Undo is a whole-scene stack (epic decision D19)
 *
 * Not a command log. A scene is small enough to snapshot outright, and
 * a per-operation inverse would need one carefully-written undo per
 * gesture — the kind of thing that is right for four operations and
 * subtly wrong by the tenth. The stack is bounded so a long authoring
 * session cannot grow without limit.
 *
 * ## The server has the last word
 *
 * Saving adopts what comes back rather than keeping the local copy. The
 * repository clamps coordinates, sorts and dedupes keyframes and drops
 * links to deleted actors; if the canvas kept its own version, the first
 * reload would silently disagree with what the coach last saw. Adopting
 * makes the correction visible in the moment it happens.
 *
 * Vanilla, no dependencies. All user-facing text arrives from PHP via
 * TT_SCENE_EDITOR.i18n.
 */
( function () {
    'use strict';

    var cfg = window.TT_SCENE_EDITOR || {};
    var i18n = cfg.i18n || {};

    /** D19 — deep enough to cover a mistake, shallow enough to bound memory. */
    var UNDO_DEPTH = 40;

    /** Two keyframes closer together than this are the same moment. */
    var KF_EPSILON_MS = 60;

    var NS = 'http://www.w3.org/2000/svg';

    var state = {
        scene: null,
        sceneId: Number( cfg.sceneId ) || 0,
        name: cfg.name || '',
        t: 0,
        selected: null,
        tool: null,
        linkKind: null,
        linkFrom: null,
        dirty: false,
        undo: [],
        playing: false,
        raf: null,
        startedAt: 0
    };

    var el = {};
    var built = null;

    // ── small helpers ───────────────────────────────────────────────

    function clone( value ) {
        return JSON.parse( JSON.stringify( value ) );
    }

    function fmt( template, value ) {
        return String( template || '' ).replace( '%s', value ).replace( '%d', value );
    }

    /**
     * Positional placeholders, because a sentence naming three things
     * has to be reorderable by a translator — Dutch does not put the
     * pass, the passer and the receiver in the English order.
     */
    function fmtArgs( template, values ) {
        var out = String( template || '' );
        for ( var i = 0; i < values.length; i++ ) {
            out = out.split( '%' + ( i + 1 ) + '$s' ).join( values[ i ] );
        }
        return out;
    }

    function seconds( ms ) {
        var text = ( ms / 1000 ).toFixed( 1 );
        if ( cfg.decimalPoint && cfg.decimalPoint !== '.' ) {
            text = text.replace( '.', cfg.decimalPoint );
        }
        return fmt( i18n.seconds || '%s s', text );
    }

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

    function announce( message ) {
        if ( el.live && message ) { el.live.textContent = message; }
    }

    function actorById( id ) {
        var actors = state.scene.actors;
        for ( var i = 0; i < actors.length; i++ ) {
            if ( actors[ i ].id === id ) { return actors[ i ]; }
        }
        return null;
    }

    function selectedActor() {
        return state.selected ? actorById( state.selected ) : null;
    }

    /**
     * A readable name for an actor, for the timeline and announcements.
     * A cone has no label, so fall back to what kind of thing it is.
     */
    function actorName( actor ) {
        if ( actor.label ) { return actor.label; }
        var tool = toolFor( actor.kind );
        return tool ? tool.label : actor.kind;
    }

    function toolFor( kind ) {
        var tools = cfg.actorTools || [];
        for ( var i = 0; i < tools.length; i++ ) {
            if ( tools[ i ].kind === kind ) { return tools[ i ]; }
        }
        return null;
    }

    // ── undo (D19) ──────────────────────────────────────────────────

    /**
     * Snapshot BEFORE a change, so undo restores what was on screen when
     * the gesture started. Called once per gesture, not per pointer move
     * — otherwise dragging a player across the pitch would fill the
     * whole stack with one movement.
     */
    function checkpoint() {
        state.undo.push( clone( state.scene ) );
        if ( state.undo.length > UNDO_DEPTH ) { state.undo.shift(); }
        state.dirty = true;
        syncUndoButton();
    }

    function undo() {
        if ( !state.undo.length ) { return; }
        state.scene = state.undo.pop();
        if ( state.selected && !actorById( state.selected ) ) { state.selected = null; }
        state.dirty = true;
        syncUndoButton();
        rebuild();
        announce( i18n.undone || '' );
    }

    function syncUndoButton() {
        if ( el.undo ) { el.undo.disabled = state.undo.length === 0; }
    }

    // ── the scene ───────────────────────────────────────────────────

    function duration() {
        return Number( state.scene.duration_ms ) || 6000;
    }

    function positionAt( actor, ms ) {
        return window.TTTrainingScene.positionAt( actor.keyframes, ms );
    }

    /**
     * Write a position at a moment. Replacing anything already within
     * the epsilon rather than adding beside it: two keyframes a
     * millisecond apart read as a teleport, and nobody ever means one.
     */
    function setKeyframe( actor, ms, x, y ) {
        var t = Math.max( 0, Math.min( duration(), Math.round( ms ) ) );
        var kept = [];
        for ( var i = 0; i < actor.keyframes.length; i++ ) {
            if ( Math.abs( actor.keyframes[ i ].t - t ) > KF_EPSILON_MS ) {
                kept.push( actor.keyframes[ i ] );
            }
        }
        kept.push( { t: t, x: Math.round( x * 10 ) / 10, y: Math.round( y * 10 ) / 10 } );
        kept.sort( function ( a, b ) { return a.t - b.t; } );
        actor.keyframes = kept;
    }

    function nextActorId( prefix ) {
        var n = 1;
        while ( actorById( prefix + n ) ) { n++; }
        return prefix + n;
    }

    /** The next shirt number for a side, so placing a team is not typing. */
    function nextLabel( kind ) {
        if ( kind === 'ball' || kind === 'cone' || kind === 'goal' ) { return ''; }
        if ( kind === 'keeper' ) { return '1'; }

        var used = {};
        for ( var i = 0; i < state.scene.actors.length; i++ ) {
            var a = state.scene.actors[ i ];
            if ( a.kind === kind ) { used[ a.label ] = true; }
        }
        var n = 2;
        while ( used[ String( n ) ] && n < 30 ) { n++; }
        return String( n );
    }

    function addActor( kind, x, y ) {
        var tool = toolFor( kind ) || { kind: kind, side: 'own' };
        checkpoint();

        var actor = {
            id: nextActorId( kind.slice( 0, 4 ) ),
            kind: kind,
            label: nextLabel( kind ),
            side: tool.side === 'opp' ? 'opp' : 'own',
            // Placed at t=0, not at the playhead: an actor that only
            // exists from halfway through would pop into the picture,
            // and a drill diagram has everyone on the pitch to begin
            // with. Move them later by dragging at a later moment.
            keyframes: [ { t: 0, x: Math.round( x * 10 ) / 10, y: Math.round( y * 10 ) / 10 } ]
        };

        state.scene.actors.push( actor );
        state.selected = actor.id;
        rebuild();
        announce( fmt( i18n.added, actorName( actor ) ) );
    }

    function removeActor( id ) {
        var actor = actorById( id );
        if ( !actor ) { return; }
        checkpoint();

        state.scene.actors = state.scene.actors.filter( function ( a ) { return a.id !== id; } );
        // A pass drawn to someone who is no longer in the scene is a line
        // to nowhere. The repository drops these too; doing it here means
        // the canvas does not show one until the next save.
        state.scene.links = state.scene.links.filter( function ( l ) {
            return l.from !== id && l.to !== id;
        } );

        if ( state.selected === id ) { state.selected = null; }
        rebuild();
        announce( fmt( i18n.removed, actorName( actor ) ) );
    }

    function duplicateActor( id ) {
        var actor = actorById( id );
        if ( !actor ) { return; }
        checkpoint();

        var copy = clone( actor );
        copy.id = nextActorId( actor.kind.slice( 0, 4 ) );
        copy.label = nextLabel( actor.kind );
        for ( var i = 0; i < copy.keyframes.length; i++ ) {
            copy.keyframes[ i ].x = Math.min( 100, copy.keyframes[ i ].x + 6 );
        }

        state.scene.actors.push( copy );
        state.selected = copy.id;
        rebuild();
    }

    function addLink( from, to, kind ) {
        if ( from === to ) { return; }
        checkpoint();
        state.scene.links.push( { from: from, to: to, kind: kind, t: Math.round( state.t ) } );
        rebuild();
        announce( i18n.linkAdded || '' );
    }

    function removeLink( index ) {
        checkpoint();
        state.scene.links.splice( index, 1 );
        rebuild();
    }

    // ── the stage ───────────────────────────────────────────────────

    /**
     * Pointer to pitch space, through the SVG's own transform.
     *
     * `getScreenCTM()` rather than arithmetic on the bounding box: the
     * viewBox is letterboxed by `preserveAspectRatio`, so on any
     * container that is not exactly 100:140 the naive version puts the
     * marker a few units away from the finger — worst at the extremes,
     * which is where a coach draws the touchline.
     */
    function toSceneCoords( event ) {
        var svg = built.svg;
        var ctm = svg.getScreenCTM();
        if ( !ctm ) { return null; }

        var point = svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        var local = point.matrixTransform( ctm.inverse() );

        return {
            x: window.TTTrainingScene.unmapX( local.x ),
            y: window.TTTrainingScene.unmapY( local.y )
        };
    }

    function buildStage() {
        el.stage.textContent = '';

        built = window.TTTrainingScene.mount( state.scene );
        built.svg.classList.add( 'tt-sced__svg' );

        // The path the selected actor walks, dashed, with a dot per
        // keyframe. Inserted under the tokens so a marker is never
        // hidden by its own route.
        el.ghost = document.createElementNS( NS, 'g' );
        el.ghost.setAttribute( 'class', 'tt-sced__ghost' );
        if ( built.actorNodes.length ) {
            built.svg.insertBefore( el.ghost, built.actorNodes[ 0 ].node );
        } else {
            built.svg.appendChild( el.ghost );
        }

        for ( var i = 0; i < built.actorNodes.length; i++ ) {
            prepareActorNode( built.actorNodes[ i ] );
        }

        el.stage.appendChild( built.svg );
        bindStagePointer();
    }

    /**
     * Make a rendered token reachable and grabbable.
     *
     * The renderer already gives every actor a 7-unit invisible hit
     * circle — about 25px at 360px wide — so the drag target is a thumb
     * target without the visible marker having to be one. Here it also
     * becomes a tab stop: a coach who cannot use a pointer selects with
     * Tab and positions with the arrow keys.
     */
    function prepareActorNode( entry ) {
        var group = entry.node;
        var actor = entry.actor;

        group.setAttribute( 'tabindex', '0' );
        group.setAttribute( 'role', 'button' );
        group.setAttribute( 'aria-label', fmt( i18n.actorLabel, actorName( actor ) ) );

        group.addEventListener( 'keydown', function ( event ) {
            onActorKey( event, actor );
        } );

        group.addEventListener( 'focus', function () {
            if ( state.selected !== actor.id ) {
                state.selected = actor.id;
                paint();
            }
        } );
    }

    /** Arrow keys nudge; Shift makes the step coarse. Enter selects. */
    function onActorKey( event, actor ) {
        var step = event.shiftKey ? 5 : 1;
        var dx = 0;
        var dy = 0;

        if ( event.key === 'ArrowLeft' ) { dx = -step; }
        else if ( event.key === 'ArrowRight' ) { dx = step; }
        else if ( event.key === 'ArrowUp' ) { dy = -step; }
        else if ( event.key === 'ArrowDown' ) { dy = step; }
        else if ( event.key === 'Delete' || event.key === 'Backspace' ) {
            event.preventDefault();
            removeActor( actor.id );
            return;
        } else { return; }

        event.preventDefault();
        var p = positionAt( actor, state.t );
        if ( !p ) { return; }

        checkpoint();
        setKeyframe( actor, state.t, p.x + dx, p.y + dy );
        state.selected = actor.id;
        paint();
        renderTracks();
    }

    function bindStagePointer() {
        var dragging = null;

        built.svg.addEventListener( 'pointerdown', function ( event ) {
            var group = event.target.closest ? event.target.closest( '[data-actor-id]' ) : null;
            var point = toSceneCoords( event );
            if ( !point ) { return; }

            // Drawing a link: two taps, from and to. Nothing is created
            // until the second, and Escape abandons the first.
            if ( state.linkKind ) {
                if ( !group ) { return; }
                var id = group.getAttribute( 'data-actor-id' );
                if ( !state.linkFrom ) {
                    state.linkFrom = id;
                    announce( fmt( i18n.linkFrom, actorName( actorById( id ) ) ) );
                    paint();
                } else {
                    addLink( state.linkFrom, id, state.linkKind );
                    state.linkFrom = null;
                }
                event.preventDefault();
                return;
            }

            if ( state.tool && !group ) {
                addActor( state.tool, point.x, point.y );
                event.preventDefault();
                return;
            }

            if ( !group ) { return; }

            event.preventDefault();
            state.selected = group.getAttribute( 'data-actor-id' );
            stopPlayback();
            checkpoint();
            dragging = { id: state.selected, moved: false };
            try { built.svg.setPointerCapture( event.pointerId ); } catch ( e ) {}
            paint();
        } );

        built.svg.addEventListener( 'pointermove', function ( event ) {
            if ( !dragging ) { return; }
            var actor = actorById( dragging.id );
            var point = toSceneCoords( event );
            if ( !actor || !point ) { return; }

            dragging.moved = true;
            setKeyframe( actor, state.t, point.x, point.y );
            paint();
        } );

        function endDrag( event ) {
            if ( !dragging ) { return; }
            var actor = actorById( dragging.id );
            if ( dragging.moved && actor ) {
                renderTracks();
                announce( fmt( i18n.keyframeAt, seconds( state.t ) ) );
            } else {
                // A tap that moved nothing is a selection, not an edit.
                state.undo.pop();
                syncUndoButton();
            }
            dragging = null;
            try { built.svg.releasePointerCapture( event.pointerId ); } catch ( e ) {}
        }

        built.svg.addEventListener( 'pointerup', endDrag );
        built.svg.addEventListener( 'pointercancel', endDrag );
    }

    // ── painting ────────────────────────────────────────────────────

    /** Positions and selection only. Cheap enough to run on every move. */
    function paint() {
        built.renderAt( state.t );

        for ( var i = 0; i < built.actorNodes.length; i++ ) {
            var entry = built.actorNodes[ i ];
            var isSelected = entry.actor.id === state.selected;
            entry.node.classList.toggle( 'tt-sced__actor--selected', isSelected );
            entry.node.classList.toggle(
                'tt-sced__actor--linking',
                state.linkFrom === entry.actor.id
            );
        }

        paintGhost();
        paintTransport();

        // Rebuild the inspector only when it is showing something else.
        // Re-creating its inputs on every pointer move would throw away
        // whatever a coach was halfway through typing, and a drag fires
        // this sixty times a second.
        if ( el.inspectorFor === state.selected ) {
            syncInspectorCoords();
        } else {
            renderInspector();
        }
    }

    function syncInspectorCoords() {
        var actor = selectedActor();
        if ( !actor || !el.inputX || !el.inputY ) { return; }

        var p = positionAt( actor, state.t );
        if ( !p ) { return; }

        if ( document.activeElement !== el.inputX ) { el.inputX.value = round1( p.x ); }
        if ( document.activeElement !== el.inputY ) { el.inputY.value = round1( p.y ); }
    }

    function round1( value ) {
        return Math.round( value * 10 ) / 10;
    }

    function paintGhost() {
        el.ghost.textContent = '';

        var actor = selectedActor();
        if ( !actor || actor.keyframes.length < 2 ) { return; }

        var map = window.TTTrainingScene;
        var d = '';
        for ( var i = 0; i < actor.keyframes.length; i++ ) {
            var kf = actor.keyframes[ i ];
            d += ( i ? ' L' : 'M' ) + map.mapX( kf.x ) + ' ' + map.mapY( kf.y );
        }

        var path = document.createElementNS( NS, 'path' );
        path.setAttribute( 'class', 'tt-sced__ghost-path' );
        path.setAttribute( 'd', d );
        el.ghost.appendChild( path );

        for ( i = 0; i < actor.keyframes.length; i++ ) {
            var dot = document.createElementNS( NS, 'circle' );
            dot.setAttribute( 'class', 'tt-sced__ghost-dot' );
            dot.setAttribute( 'cx', map.mapX( actor.keyframes[ i ].x ) );
            dot.setAttribute( 'cy', map.mapY( actor.keyframes[ i ].y ) );
            dot.setAttribute( 'r', '1' );
            el.ghost.appendChild( dot );
        }
    }

    function paintTransport() {
        el.scrub.max = String( duration() );
        el.scrub.value = String( Math.round( state.t ) );
        el.time.textContent = seconds( state.t );

        var pct = ( state.t / duration() ) * 100;
        var heads = el.tracks.querySelectorAll( '.tt-sced__playhead' );
        for ( var i = 0; i < heads.length; i++ ) {
            heads[ i ].style.left = pct + '%'; /* tt-inline-ok — playhead position is data */
        }
    }

    /** A structural change: rebuild everything the DOM mirrors. */
    function rebuild() {
        el.inspectorFor = undefined;
        buildStage();
        renderTracks();
        renderLinks();
        paint();
    }

    // ── timeline ────────────────────────────────────────────────────

    function renderTracks() {
        el.tracks.textContent = '';

        if ( !state.scene.actors.length ) {
            el.tracks.appendChild( node( 'p', 'tt-small tt-muted', i18n.emptyScene || '' ) );
            return;
        }

        for ( var i = 0; i < state.scene.actors.length; i++ ) {
            el.tracks.appendChild( trackRow( state.scene.actors[ i ] ) );
        }
    }

    function trackRow( actor ) {
        var row = node( 'div', 'tt-sced__row' );
        if ( actor.id === state.selected ) { row.classList.add( 'tt-sced__row--selected' ); }

        var name = button( actorName( actor ), 'tt-sced__row-name', function () {
            state.selected = actor.id;
            renderTracks();
            paint();
        } );
        name.setAttribute( 'aria-label', fmt( i18n.selectActor, actorName( actor ) ) );
        row.appendChild( name );

        var track = node( 'div', 'tt-sced__track' );
        track.addEventListener( 'pointerdown', function ( event ) {
            if ( event.target !== track ) { return; }
            var rect = track.getBoundingClientRect();
            state.selected = actor.id;
            setTime( ( ( event.clientX - rect.left ) / rect.width ) * duration() );
            renderTracks();
        } );

        for ( var i = 0; i < actor.keyframes.length; i++ ) {
            track.appendChild( keyframeHandle( actor, i, track ) );
        }

        var head = node( 'div', 'tt-sced__playhead' );
        head.style.left = ( ( state.t / duration() ) * 100 ) + '%'; /* tt-inline-ok — playhead position is data */
        track.appendChild( head );

        row.appendChild( track );
        return row;
    }

    /**
     * One keyframe. Click to travel to it; drag to retime it.
     *
     * Retiming by dragging matters more than it looks: without it the
     * only way to fix a run that starts half a second too early is to
     * delete the keyframe and place the player again from scratch.
     */
    function keyframeHandle( actor, index, track ) {
        var kf = actor.keyframes[ index ];
        var handle = node( 'button', 'tt-sced__kf' );
        handle.type = 'button';
        handle.style.left = ( ( kf.t / duration() ) * 100 ) + '%'; /* tt-inline-ok — keyframe time is data */
        handle.setAttribute( 'aria-label', fmt( i18n.keyframeAt, seconds( kf.t ) ) );
        handle.title = actorName( actor ) + ' — ' + seconds( kf.t );

        if ( actor.id === state.selected && Math.abs( kf.t - state.t ) <= KF_EPSILON_MS ) {
            handle.classList.add( 'tt-sced__kf--current' );
        }

        var drag = null;

        handle.addEventListener( 'pointerdown', function ( event ) {
            event.preventDefault();
            state.selected = actor.id;
            stopPlayback();
            checkpoint();
            drag = { moved: false };
            try { handle.setPointerCapture( event.pointerId ); } catch ( e ) {}
        } );

        handle.addEventListener( 'pointermove', function ( event ) {
            if ( !drag ) { return; }
            var rect = track.getBoundingClientRect();
            var t = Math.max( 0, Math.min( duration(),
                ( ( event.clientX - rect.left ) / rect.width ) * duration() ) );

            drag.moved = true;
            kf.t = Math.round( t );
            // Only the node moves during the gesture — rebuilding the
            // row here would destroy the element the pointer is
            // captured on and the drag would die on the first move.
            handle.style.left = ( ( kf.t / duration() ) * 100 ) + '%'; /* tt-inline-ok — keyframe time is data */
            setTime( kf.t );
        } );

        function end( event ) {
            if ( !drag ) { return; }
            if ( drag.moved ) {
                actor.keyframes.sort( function ( a, b ) { return a.t - b.t; } );
                announce( fmt( i18n.keyframeMoved, seconds( kf.t ) ) );
            } else {
                state.undo.pop();
                syncUndoButton();
                setTime( kf.t );
            }
            drag = null;
            try { handle.releasePointerCapture( event.pointerId ); } catch ( e ) {}
            renderTracks();
            paint();
        }

        handle.addEventListener( 'pointerup', end );
        handle.addEventListener( 'pointercancel', end );

        return handle;
    }

    function addKeyframeHere() {
        var actor = selectedActor();
        if ( !actor ) { announce( i18n.selectFirst || '' ); return; }

        var p = positionAt( actor, state.t );
        if ( !p ) { return; }

        checkpoint();
        setKeyframe( actor, state.t, p.x, p.y );
        renderTracks();
        paint();
        announce( fmt( i18n.keyframeAt, seconds( state.t ) ) );
    }

    function removeKeyframeHere() {
        var actor = selectedActor();
        if ( !actor || actor.keyframes.length < 2 ) {
            // The last keyframe is the actor's only position. Removing
            // it would leave something with nowhere to be, which the
            // repository would silently drop on save.
            announce( i18n.lastKeyframe || '' );
            return;
        }

        var kept = actor.keyframes.filter( function ( kf ) {
            return Math.abs( kf.t - state.t ) > KF_EPSILON_MS;
        } );
        if ( kept.length === actor.keyframes.length ) {
            announce( i18n.noKeyframeHere || '' );
            return;
        }

        checkpoint();
        actor.keyframes = kept;
        renderTracks();
        paint();
    }

    // ── transport ───────────────────────────────────────────────────

    function setTime( ms ) {
        state.t = Math.max( 0, Math.min( duration(), ms ) );
        paint();
    }

    function now() {
        return ( window.performance && window.performance.now )
            ? window.performance.now() : Date.now();
    }

    function stopPlayback() {
        if ( state.raf !== null ) { window.cancelAnimationFrame( state.raf ); state.raf = null; }
        state.playing = false;
        if ( el.play ) { el.play.textContent = i18n.play || ''; }
    }

    function togglePlayback() {
        if ( state.playing ) { stopPlayback(); return; }

        if ( state.t >= duration() ) { state.t = 0; }
        state.playing = true;
        el.play.textContent = i18n.pause || '';
        state.startedAt = now() - state.t;

        var tick = function () {
            var ms = now() - state.startedAt;
            if ( ms >= duration() ) { setTime( duration() ); stopPlayback(); return; }
            setTime( ms );
            state.raf = window.requestAnimationFrame( tick );
        };
        state.raf = window.requestAnimationFrame( tick );
    }

    // ── inspector ───────────────────────────────────────────────────

    function renderInspector() {
        var actor = selectedActor();

        el.inspector.textContent = '';
        el.inspectorFor = state.selected;
        el.inputX = null;
        el.inputY = null;

        if ( !actor ) {
            el.inspector.appendChild( node( 'p', 'tt-small tt-muted', i18n.nothingSelected || '' ) );
            return;
        }

        el.inspector.appendChild( labelledInput(
            i18n.markerLabel,
            'text',
            actor.label,
            function ( value ) {
                checkpoint();
                actor.label = value.slice( 0, 4 );
                rebuild();
            }
        ) );

        var p = positionAt( actor, state.t );
        var coords = node( 'div', 'tt-sced__coords' );

        var fieldX = labelledInput( i18n.acrossPitch, 'number', p ? round1( p.x ) : 50, function ( value ) {
            checkpoint();
            setKeyframe( actor, state.t, Number( value ), positionAt( actor, state.t ).y );
            renderTracks();
            paint();
        } );
        var fieldY = labelledInput( i18n.alongPitch, 'number', p ? round1( p.y ) : 50, function ( value ) {
            checkpoint();
            setKeyframe( actor, state.t, positionAt( actor, state.t ).x, Number( value ) );
            renderTracks();
            paint();
        } );

        el.inputX = fieldX.querySelector( 'input' );
        el.inputY = fieldY.querySelector( 'input' );
        coords.appendChild( fieldX );
        coords.appendChild( fieldY );
        el.inspector.appendChild( coords );

        el.inspector.appendChild( node( 'p', 'tt-small tt-muted', i18n.dragHint || '' ) );

        var actions = node( 'div', 'tt-form-actions tt-sced__actor-actions' );
        actions.appendChild( button( i18n.addKeyframe, 'tt-btn tt-btn-secondary', addKeyframeHere ) );
        actions.appendChild( button( i18n.removeKeyframe, 'tt-btn tt-btn-secondary', removeKeyframeHere ) );
        el.inspector.appendChild( actions );

        var manage = node( 'div', 'tt-form-actions tt-sced__actor-actions' );
        manage.appendChild( button( i18n.duplicate, 'tt-btn tt-btn-secondary', function () {
            duplicateActor( actor.id );
        } ) );
        manage.appendChild( button( i18n.removeMarker, 'tt-btn tt-btn-secondary', function () {
            removeActor( actor.id );
        } ) );
        el.inspector.appendChild( manage );
    }

    function labelledInput( labelText, type, value, onChange ) {
        var wrap = node( 'label', 'tt-field tt-sced__field' );
        wrap.appendChild( node( 'span', 'tt-field__label', labelText || '' ) );

        var input = node( 'input', 'tt-input' );
        input.type = type;
        input.value = value;
        if ( type === 'number' ) {
            input.setAttribute( 'inputmode', 'decimal' );
            input.min = '0';
            input.max = '100';
            input.step = '0.5';
        }
        input.addEventListener( 'change', function () { onChange( input.value ); } );

        wrap.appendChild( input );
        return wrap;
    }

    function renderLinks() {
        el.links.textContent = '';

        if ( !state.scene.links.length ) {
            el.links.appendChild( node( 'p', 'tt-small tt-muted', i18n.noLinks || '' ) );
            return;
        }

        var list = node( 'ul', 'tt-sced__link-list' );
        for ( var i = 0; i < state.scene.links.length; i++ ) {
            list.appendChild( linkRow( state.scene.links[ i ], i ) );
        }
        el.links.appendChild( list );
    }

    function linkRow( link, index ) {
        var from = actorById( link.from );
        var to = actorById( link.to );

        var item = node( 'li', 'tt-sced__link' );
        item.appendChild( node( 'span', 'tt-sced__link-text', fmtArgs( i18n.linkSummary, [
            linkKindLabel( link.kind ),
            from ? actorName( from ) : '?',
            to ? actorName( to ) : '?'
        ] ) ) );

        item.appendChild( node( 'span', 'tt-sced__link-time', seconds( link.t ) ) );
        item.appendChild( button( i18n.removeLink, 'tt-btn tt-btn-secondary', function () {
            removeLink( index );
        } ) );

        return item;
    }

    function linkKindLabel( kind ) {
        var kinds = cfg.linkKinds || [];
        for ( var i = 0; i < kinds.length; i++ ) {
            if ( kinds[ i ].value === kind ) { return kinds[ i ].label; }
        }
        return kind;
    }

    // ── tools ───────────────────────────────────────────────────────

    function setTool( kind, linkKind ) {
        state.tool = kind;
        state.linkKind = linkKind || null;
        state.linkFrom = null;

        var buttons = el.tools.querySelectorAll( '[data-tool]' );
        for ( var i = 0; i < buttons.length; i++ ) {
            var value = buttons[ i ].getAttribute( 'data-tool' );
            var active = ( kind && value === 'actor:' + kind )
                || ( linkKind && value === 'link:' + linkKind );
            buttons[ i ].setAttribute( 'aria-pressed', active ? 'true' : 'false' );
        }

        el.stage.classList.toggle( 'tt-sced__stage--placing', !!kind || !!linkKind );
        announce( kind || linkKind ? ( i18n.toolArmed || '' ) : ( i18n.toolCleared || '' ) );
        paint();
    }

    function buildTools() {
        var tools = cfg.actorTools || [];
        for ( var i = 0; i < tools.length; i++ ) {
            el.tools.appendChild( toolButton( tools[ i ].label, 'actor:' + tools[ i ].kind, tools[ i ].kind, null ) );
        }

        var kinds = cfg.linkKinds || [];
        for ( i = 0; i < kinds.length; i++ ) {
            el.tools.appendChild( toolButton( kinds[ i ].label, 'link:' + kinds[ i ].value, null, kinds[ i ].value ) );
        }
    }

    function toolButton( label, value, kind, linkKind ) {
        var b = button( label, 'tt-sced__tool', function () {
            var pressed = b.getAttribute( 'aria-pressed' ) === 'true';
            if ( pressed ) { setTool( null, null ); return; }
            setTool( kind, linkKind );
        } );
        b.setAttribute( 'data-tool', value );
        b.setAttribute( 'aria-pressed', 'false' );
        return b;
    }

    // ── scene settings ──────────────────────────────────────────────

    function buildSettings() {
        el.settings.appendChild( labelledInput( i18n.sceneName, 'text', state.name, function ( value ) {
            state.name = value;
            state.dirty = true;
        } ) );

        el.settings.appendChild( labelledSelect(
            i18n.pitchView,
            cfg.pitchOptions || [],
            state.scene.pitch,
            function ( value ) {
                checkpoint();
                state.scene.pitch = value;
                rebuild();
            }
        ) );

        el.settings.appendChild( labelledSelect(
            i18n.sceneLength,
            cfg.durationOptions || [],
            String( duration() ),
            function ( value ) {
                checkpoint();
                state.scene.duration_ms = Number( value );
                // Shortening pulls the keyframes in with it rather than
                // leaving them past the end where nothing would play
                // them. The repository does the same on save; doing it
                // here means the timeline agrees straight away.
                clampToLength();
                if ( state.t > duration() ) { state.t = duration(); }
                rebuild();
            }
        ) );
    }

    function clampToLength() {
        var max = duration();
        for ( var i = 0; i < state.scene.actors.length; i++ ) {
            var actor = state.scene.actors[ i ];
            for ( var j = 0; j < actor.keyframes.length; j++ ) {
                actor.keyframes[ j ].t = Math.min( max, actor.keyframes[ j ].t );
            }
            actor.keyframes.sort( function ( a, b ) { return a.t - b.t; } );
        }
        for ( i = 0; i < state.scene.links.length; i++ ) {
            state.scene.links[ i ].t = Math.min( max, state.scene.links[ i ].t );
        }
    }

    function labelledSelect( labelText, options, value, onChange ) {
        var wrap = node( 'label', 'tt-field tt-sced__field' );
        wrap.appendChild( node( 'span', 'tt-field__label', labelText || '' ) );

        var select = node( 'select', 'tt-input' );
        for ( var i = 0; i < options.length; i++ ) {
            var option = node( 'option', null, options[ i ].label );
            option.value = options[ i ].value;
            if ( String( options[ i ].value ) === String( value ) ) { option.selected = true; }
            select.appendChild( option );
        }
        select.addEventListener( 'change', function () { onChange( select.value ); } );

        wrap.appendChild( select );
        return wrap;
    }

    // ── saving ──────────────────────────────────────────────────────

    function save() {
        el.save.disabled = true;
        announce( i18n.saving || '' );

        var creating = state.sceneId === 0;
        var url = creating
            ? cfg.restBase + '/exercises/' + cfg.exerciseId + '/scenes'
            : cfg.restBase + '/exercise-scenes/' + state.sceneId;

        window.fetch( url, {
            method: creating ? 'POST' : 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify( {
                name: state.name,
                pitch_preset: state.scene.pitch,
                duration_ms: duration(),
                scene: { actors: state.scene.actors, links: state.scene.links }
            } )
        } ).then( function ( response ) {
            return response.json();
        } ).then( function ( body ) {
            el.save.disabled = false;

            var saved = body && body.data && body.data.scene;
            if ( !body || !body.success || !saved ) {
                announce( i18n.saveFailed || '' );
                return;
            }

            // Adopt the stored version. What is on the canvas after a
            // save is what is in the database, including any clamping.
            state.sceneId = saved.id;
            state.name = saved.name || '';
            state.scene = saved.scene;
            state.dirty = false;
            state.undo = [];
            syncUndoButton();
            if ( state.selected && !actorById( state.selected ) ) { state.selected = null; }
            rebuild();
            announce( i18n.saved || '' );
        } )[ 'catch' ]( function () {
            el.save.disabled = false;
            announce( i18n.saveFailed || '' );
        } );
    }

    // ── shell ───────────────────────────────────────────────────────

    function buildShell( root ) {
        root.textContent = '';

        var grid = node( 'div', 'tt-sced' );

        // Left: what to add, and what the scene is.
        var left = node( 'aside', 'tt-sced__panel tt-sced__panel--tools' );
        left.appendChild( node( 'h2', 'tt-sced__panel-title', i18n.addMarker || '' ) );
        el.tools = node( 'div', 'tt-sced__tools' );
        left.appendChild( el.tools );
        left.appendChild( node( 'h2', 'tt-sced__panel-title', i18n.sceneSettings || '' ) );
        el.settings = node( 'div', 'tt-sced__settings' );
        left.appendChild( el.settings );
        grid.appendChild( left );

        // Middle: the pitch and the timeline.
        var middle = node( 'div', 'tt-sced__canvas' );
        el.stage = node( 'div', 'tt-sced__stage' );
        middle.appendChild( el.stage );

        var timeline = node( 'section', 'tt-sced__timeline' );
        timeline.appendChild( node( 'h2', 'tt-sced__panel-title', i18n.timeline || '' ) );

        var transport = node( 'div', 'tt-sced__transport' );
        el.play = button( i18n.play, 'tt-btn tt-btn-secondary', togglePlayback );
        transport.appendChild( el.play );

        el.scrub = node( 'input', 'tt-sced__scrub' );
        el.scrub.type = 'range';
        el.scrub.min = '0';
        el.scrub.step = '50';
        el.scrub.setAttribute( 'aria-label', i18n.timeInScene || '' );
        el.scrub.addEventListener( 'input', function () {
            stopPlayback();
            setTime( Number( el.scrub.value ) );
        } );
        transport.appendChild( el.scrub );

        el.time = node( 'output', 'tt-sced__time' );
        transport.appendChild( el.time );

        el.undo = button( i18n.undo, 'tt-btn tt-btn-secondary', undo );
        el.undo.disabled = true;
        transport.appendChild( el.undo );

        timeline.appendChild( transport );
        el.tracks = node( 'div', 'tt-sced__tracks' );
        timeline.appendChild( el.tracks );
        middle.appendChild( timeline );
        grid.appendChild( middle );

        // Right: the selected marker, and the lines between markers.
        var right = node( 'aside', 'tt-sced__panel tt-sced__panel--inspector' );
        right.appendChild( node( 'h2', 'tt-sced__panel-title', i18n.selectedMarker || '' ) );
        el.inspector = node( 'div', 'tt-sced__inspector' );
        right.appendChild( el.inspector );
        right.appendChild( node( 'h2', 'tt-sced__panel-title', i18n.lines || '' ) );
        el.links = node( 'div', 'tt-sced__links' );
        right.appendChild( el.links );
        grid.appendChild( right );

        root.appendChild( grid );

        // Cancel then Save, Save rendered right by the wrapper's flex
        // order — CLAUDE.md §6.
        var actions = node( 'div', 'tt-form-actions tt-sced__save' );
        var cancel = node( 'a', 'tt-btn tt-btn-secondary', i18n.cancel || '' );
        cancel.href = cfg.cancelUrl || '#';
        actions.appendChild( cancel );
        el.save = button( i18n.saveScene, 'tt-btn tt-btn-primary', save );
        actions.appendChild( el.save );
        root.appendChild( actions );

        el.live = node( 'div', 'tt-sr-only' );
        el.live.setAttribute( 'aria-live', 'polite' );
        root.appendChild( el.live );
    }

    function bindGlobalKeys() {
        document.addEventListener( 'keydown', function ( event ) {
            var tag = event.target && event.target.tagName;
            var typing = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA';

            if ( event.key === 'Escape' ) {
                if ( state.linkFrom ) { state.linkFrom = null; paint(); return; }
                if ( state.tool || state.linkKind ) { setTool( null, null ); }
                return;
            }

            if ( typing ) { return; }

            if ( ( event.ctrlKey || event.metaKey ) && event.key.toLowerCase() === 'z' ) {
                event.preventDefault();
                undo();
            }
        } );

        window.addEventListener( 'beforeunload', function ( event ) {
            if ( !state.dirty ) { return; }
            event.preventDefault();
            event.returnValue = '';
        } );
    }

    function init() {
        var root = document.querySelector( '[data-tt-scene-editor]' );
        if ( !root || !window.TTTrainingScene || !window.TTTrainingScene.mount ) { return; }

        var scene = cfg.scene || {};
        state.scene = {
            pitch: scene.pitch || 'full',
            duration_ms: Number( scene.duration_ms ) || 6000,
            actors: Array.isArray( scene.actors ) ? clone( scene.actors ) : [],
            links: Array.isArray( scene.links ) ? clone( scene.links ) : []
        };

        buildShell( root );
        buildTools();
        buildSettings();
        rebuild();
        bindGlobalKeys();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
