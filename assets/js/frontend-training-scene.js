/*
 * frontend-training-scene.js (#2501, epic #2493) — playback for exercise
 * scenes authored in TalentTrack.
 *
 * A sibling of `methodology-tactical-scene.js` rather than an extension
 * of it. That module renders the shipped Speelwijze scenes and its
 * payload is a different shape: three actor arrays, normalised 0–1
 * keyframe time, and arrows carrying literal coordinates. This one reads
 * the authored `scene_json` contract — one `actors` array keyed by
 * `kind`, absolute millisecond keyframes, and links that name actors by
 * id so a pass survives its receiver being repositioned.
 *
 * Teaching the shipped renderer both shapes would put a working surface
 * behind a refactor for the benefit of one that did not exist yet, so
 * the two share the STYLESHEET (`frontend-methodology-scene.css`) and
 * the pitch geometry instead. What is duplicated is the interpolation
 * helper — about thirty lines, against coupling two features that have
 * no reason to change together.
 *
 * Behaviour inherited deliberately, because it is right:
 *   - **Nothing ever autoplays.** On load the scene renders its first
 *     frame and waits. A drill diagram that starts moving while a coach
 *     is reading the text above it is worse than a static image.
 *   - `prefers-reduced-motion` renders the FINAL frame instead, so the
 *     scene still reads as a diagram, with Play still offered rather
 *     than removed.
 *
 * Vanilla, no dependencies. All geometry is set as SVG attributes; the
 * only inline style is a token's transform, which is data.
 */
( function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';

    // Shared with the Speelwijze renderer and FormationDiagram so a scene
    // looks the same wherever it is drawn.
    var VIEW_W = 100;
    var VIEW_H = 140;
    var Y_TOP = 6;
    var Y_BOTTOM = 134;

    function el( name, attrs ) {
        var node = document.createElementNS( NS, name );
        if ( attrs ) {
            for ( var k in attrs ) {
                if ( Object.prototype.hasOwnProperty.call( attrs, k ) ) {
                    node.setAttribute( k, attrs[ k ] );
                }
            }
        }
        return node;
    }

    function clamp( v, lo, hi ) {
        v = Number( v );
        if ( isNaN( v ) ) { return lo; }
        return v < lo ? lo : ( v > hi ? hi : v );
    }

    function mapX( x ) { return clamp( x, 0, 100 ); }

    function mapY( y ) { return Y_TOP + ( clamp( y, 0, 100 ) / 100 ) * ( Y_BOTTOM - Y_TOP ); }

    /* ── interpolation ──────────────────────────────────────────────
     * Keyframes are [{t,x,y}] with t in MILLISECONDS, absolute. Outside
     * the authored range the first/last frame holds, so an actor that
     * stops moving stays where it stopped rather than snapping home. */
    function positionAt( keyframes, ms ) {
        if ( !keyframes || !keyframes.length ) { return null; }
        if ( keyframes.length === 1 ) { return { x: keyframes[ 0 ].x, y: keyframes[ 0 ].y }; }

        var first = keyframes[ 0 ];
        var last = keyframes[ keyframes.length - 1 ];
        if ( ms <= first.t ) { return { x: first.x, y: first.y }; }
        if ( ms >= last.t ) { return { x: last.x, y: last.y }; }

        for ( var i = 0; i < keyframes.length - 1; i++ ) {
            var a = keyframes[ i ];
            var b = keyframes[ i + 1 ];
            if ( ms >= a.t && ms <= b.t ) {
                var span = b.t - a.t;
                var f = span > 0 ? ( ms - a.t ) / span : 0;
                return { x: a.x + ( b.x - a.x ) * f, y: a.y + ( b.y - a.y ) * f };
            }
        }
        return { x: last.x, y: last.y };
    }

    /* ── pitch presets ──────────────────────────────────────────────
     * The contract's reason for existing: a rondo drawn on a full pitch
     * is six players in a corner. `half` and `third` crop the markings
     * so the drill fills the frame at the size it is actually run. */
    function buildPitch( svg, preset ) {
        svg.appendChild( el( 'rect', {
            x: 2, y: 2, width: 96, height: 136, rx: 2, 'class': 'tt-tsc-pitch'
        } ) );

        if ( preset === 'blank' ) { return; }

        if ( preset === 'full' ) {
            svg.appendChild( el( 'line', { x1: 2, y1: 70, x2: 98, y2: 70, 'class': 'tt-tsc-line' } ) );
            svg.appendChild( el( 'circle', { cx: 50, cy: 70, r: 9, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'circle', { cx: 50, cy: 70, r: 0.6, 'class': 'tt-tsc-line', fill: 'currentColor' } ) );
            svg.appendChild( el( 'rect', { x: 22, y: 2, width: 56, height: 18, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'rect', { x: 36, y: 2, width: 28, height: 6, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'rect', { x: 22, y: 120, width: 56, height: 18, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'rect', { x: 36, y: 132, width: 28, height: 6, 'class': 'tt-tsc-line', fill: 'none' } ) );
            return;
        }

        if ( preset === 'half' ) {
            // One box at the top, the halfway line as the bottom edge —
            // the shape an attacking-phase drill is actually run in.
            svg.appendChild( el( 'rect', { x: 22, y: 2, width: 56, height: 30, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'rect', { x: 36, y: 2, width: 28, height: 10, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'circle', { cx: 50, cy: 44, r: 9, 'class': 'tt-tsc-line', fill: 'none' } ) );
            svg.appendChild( el( 'line', { x1: 2, y1: 136, x2: 98, y2: 136, 'class': 'tt-tsc-line' } ) );
            return;
        }

        // third — a grid box with no goal, for rondos and possession squares.
        svg.appendChild( el( 'line', { x1: 2, y1: 48, x2: 98, y2: 48, 'class': 'tt-tsc-line' } ) );
        svg.appendChild( el( 'line', { x1: 2, y1: 92, x2: 98, y2: 92, 'class': 'tt-tsc-line' } ) );
    }

    /* ── actors ─────────────────────────────────────────────────────
     * Every actor gets an invisible hit circle far larger than its
     * visible token. At 360px wide a 3.2-unit token is about 11px —
     * unhittable with a thumb — and the editor drags by the same nodes
     * this renderer builds. */
    function buildActor( svg, actor ) {
        var group = el( 'g', {
            'class': 'tt-tsc-token tt-scene-actor tt-scene-actor--' + actor.kind
                + ( actor.side === 'opp' ? ' tt-tsc-token--opp' : ' tt-tsc-token--own' ),
            'data-actor-id': actor.id
        } );

        if ( actor.kind === 'ball' ) {
            group.appendChild( el( 'circle', { r: 1.8, 'class': 'tt-tsc-ball' } ) );
        } else if ( actor.kind === 'cone' ) {
            group.appendChild( el( 'polygon', { points: '0,-2.4 2,2 -2,2', 'class': 'tt-scene-cone' } ) );
        } else if ( actor.kind === 'goal' ) {
            group.appendChild( el( 'rect', { x: -5, y: -1.2, width: 10, height: 2.4, 'class': 'tt-scene-goal' } ) );
        } else {
            group.appendChild( el( 'circle', { r: 3.2 } ) );
            if ( actor.label ) {
                var text = el( 'text', {
                    'text-anchor': 'middle', 'dominant-baseline': 'central',
                    'font-size': 3.4
                } );
                text.textContent = actor.label;
                group.appendChild( text );
            }
        }

        // The hit target. Transparent, generous, and last so it sits on
        // top of the visible marks.
        group.appendChild( el( 'circle', { r: 7, 'class': 'tt-scene-hit', fill: 'transparent' } ) );

        svg.appendChild( group );
        return group;
    }

    function placeActor( node, keyframes, ms ) {
        var p = positionAt( keyframes, ms );
        if ( !p ) { return null; }
        node.setAttribute( 'transform', 'translate(' + mapX( p.x ) + ' ' + mapY( p.y ) + ')' );
        return p;
    }

    /* ── links ──────────────────────────────────────────────────────
     * A link names two actors and a time. It is drawn between where
     * those actors ARE at that moment, and only once the moment has
     * arrived — a pass that is visible before it is played tells the
     * reader the wrong story about the drill. */
    function buildLink( svg, link ) {
        return svg.appendChild( el( 'line', {
            'class': 'tt-tsc-arrow tt-tsc-arrow--' + link.kind,
            'marker-end': 'url(#tt-scene-arrow-head)',
            'data-link-t': link.t
        } ) );
    }

    function placeLink( node, link, actorsById, ms ) {
        var from = actorsById[ link.from ];
        var to = actorsById[ link.to ];
        if ( !from || !to ) { node.setAttribute( 'opacity', '0' ); return; }

        var a = positionAt( from.keyframes, ms );
        var b = positionAt( to.keyframes, ms );
        if ( !a || !b ) { node.setAttribute( 'opacity', '0' ); return; }

        node.setAttribute( 'x1', mapX( a.x ) );
        node.setAttribute( 'y1', mapY( a.y ) );
        node.setAttribute( 'x2', mapX( b.x ) );
        node.setAttribute( 'y2', mapY( b.y ) );
        node.setAttribute( 'opacity', ms >= link.t ? '1' : '0' );
    }

    function arrowMarker() {
        var defs = el( 'defs' );
        var marker = el( 'marker', {
            id: 'tt-scene-arrow-head', viewBox: '0 0 10 10', refX: 9, refY: 5,
            markerWidth: 5, markerHeight: 5, orient: 'auto-start-reverse'
        } );
        marker.appendChild( el( 'path', { d: 'M 0 0 L 10 5 L 0 10 z' } ) );
        defs.appendChild( marker );
        return defs;
    }

    function label( container, key, fallback ) {
        var attr = container.getAttribute( 'data-i18n-' + key );
        return attr && attr !== '' ? attr : fallback;
    }

    function makeButton( text, cls ) {
        var b = document.createElement( 'button' );
        b.type = 'button';
        b.className = cls;
        b.textContent = text;
        b.setAttribute( 'aria-label', text );
        return b;
    }

    /**
     * Build one scene. Exposed so the editor can re-render after an edit
     * without duplicating the drawing code — one renderer, so what a
     * coach authors is exactly what every read surface shows.
     */
    function render( container ) {
        var payloadEl = container.querySelector( 'script[type="application/json"]' );
        if ( !payloadEl ) { return null; }

        var scene;
        try {
            scene = JSON.parse( payloadEl.textContent || '{}' );
        } catch ( e ) {
            return null;
        }

        var duration = Number( scene.duration_ms ) > 0 ? Number( scene.duration_ms ) : 6000;
        var actors = Array.isArray( scene.actors ) ? scene.actors : [];
        var links = Array.isArray( scene.links ) ? scene.links : [];

        container.textContent = '';

        var stage = document.createElement( 'div' );
        stage.className = 'tt-tsc-stage';

        var svg = el( 'svg', {
            'class': 'tt-tsc-svg',
            viewBox: '0 0 ' + VIEW_W + ' ' + VIEW_H,
            preserveAspectRatio: 'xMidYMid meet',
            role: 'img'
        } );

        svg.appendChild( arrowMarker() );
        buildPitch( svg, scene.pitch || 'full' );

        // Links first so tokens sit above their own arrows.
        var linkNodes = [];
        var actorsById = {};
        for ( var i = 0; i < links.length; i++ ) {
            linkNodes.push( { node: buildLink( svg, links[ i ] ), link: links[ i ] } );
        }

        var actorNodes = [];
        for ( i = 0; i < actors.length; i++ ) {
            var actor = actors[ i ];
            actorsById[ actor.id ] = actor;
            actorNodes.push( { node: buildActor( svg, actor ), actor: actor } );
        }

        stage.appendChild( svg );
        container.appendChild( stage );

        function renderAt( ms ) {
            for ( var j = 0; j < actorNodes.length; j++ ) {
                placeActor( actorNodes[ j ].node, actorNodes[ j ].actor.keyframes, ms );
            }
            for ( j = 0; j < linkNodes.length; j++ ) {
                placeLink( linkNodes[ j ].node, linkNodes[ j ].link, actorsById, ms );
            }
        }

        // ── controls ────────────────────────────────────────────────
        var controls = document.createElement( 'div' );
        controls.className = 'tt-tsc-controls';

        var playBtn = makeButton( label( container, 'play', 'Play' ), 'tt-tsc-btn tt-tsc-btn--play' );
        var pauseBtn = makeButton( label( container, 'pause', 'Pause' ), 'tt-tsc-btn tt-tsc-btn--pause' );
        var restartBtn = makeButton( label( container, 'restart', 'Restart' ), 'tt-tsc-btn tt-tsc-btn--restart' );
        controls.appendChild( playBtn );
        controls.appendChild( pauseBtn );
        controls.appendChild( restartBtn );
        container.appendChild( controls );

        var raf = null;
        var startedAt = 0;
        var pausedAtMs = 0;

        function now() {
            return ( window.performance && window.performance.now ) ? window.performance.now() : Date.now();
        }

        function stop() {
            if ( raf !== null ) { window.cancelAnimationFrame( raf ); raf = null; }
        }

        function tick() {
            var ms = now() - startedAt;
            if ( ms >= duration ) { renderAt( duration ); pausedAtMs = 0; stop(); return; }
            renderAt( ms );
            raf = window.requestAnimationFrame( tick );
        }

        function play() {
            stop();
            startedAt = now() - pausedAtMs;
            raf = window.requestAnimationFrame( tick );
        }

        function pause() {
            if ( raf === null ) { return; }
            pausedAtMs = clamp( now() - startedAt, 0, duration );
            stop();
        }

        function restart() { stop(); pausedAtMs = 0; play(); }

        playBtn.addEventListener( 'click', play );
        pauseBtn.addEventListener( 'click', pause );
        restartBtn.addEventListener( 'click', restart );

        // Reduced motion shows the finished picture, which is what a
        // diagram is for. Play stays available rather than being removed
        // — the preference is about surprise motion, not about capability.
        var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
        renderAt( reduce ? duration : 0 );

        return { svg: svg, renderAt: renderAt, duration: duration, scene: scene };
    }

    function init() {
        var nodes = document.querySelectorAll( '.tt-training-scene' );
        if ( !nodes.length ) { return; }
        Array.prototype.forEach.call( nodes, render );
    }

    // The editor drives its own rendering, so expose the builder.
    window.TTTrainingScene = { render: render, positionAt: positionAt, mapX: mapX, mapY: mapY };

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
