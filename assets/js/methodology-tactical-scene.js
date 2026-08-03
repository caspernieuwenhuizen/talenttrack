/*
 * methodology-tactical-scene.js (#2323) — animated per-phase tactical
 * scenes for the methodology "Speelwijze" tab.
 *
 * Renders an SVG pitch (mirroring FormationDiagram's geometry / viewBox)
 * into every `.tt-tactical-scene` container from a JSON payload carried
 * in a sibling `<script type="application/json">`. Players, opponents and
 * the ball animate along their keyframes with requestAnimationFrame and
 * linear interpolation across `duration_ms`; movement-intent arrows draw
 * statically.
 *
 * Vanilla JS, no dependencies. Coordinates are normalized 0–100 in both
 * axes (0,0 = top-left, y grows toward our own goal at the bottom) and
 * mapped onto the shared pitch viewBox of 100×140.
 *
 * Mobile-first + a11y (CLAUDE.md §2): respects prefers-reduced-motion by
 * NOT autoplaying — it renders the final frame statically and lets the
 * user opt into playback via the Play button. Controls are real <button>s
 * with discernible names, ≥48px touch targets (from the stylesheet) and
 * keyboard support. All DOM geometry is set in JS (SVG attributes /
 * transforms), never as PHP inline style.
 */
( function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';

    // Pitch geometry — shared with FormationDiagram (viewBox 100×140).
    var VIEW_W = 100;
    var VIEW_H = 140;
    // Scene y 0..100 maps onto the playable band [Y_TOP, Y_BOTTOM] so the
    // markers clear the pitch border and goals.
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

    function mapX( x ) {
        return clamp( Number( x ), 0, 100 );
    }

    function mapY( y ) {
        var t = clamp( Number( y ), 0, 100 ) / 100;
        return Y_TOP + t * ( Y_BOTTOM - Y_TOP );
    }

    function clamp( v, lo, hi ) {
        if ( isNaN( v ) ) { return lo; }
        return v < lo ? lo : ( v > hi ? hi : v );
    }

    function i18n( container, key, fallback ) {
        var attr = container.getAttribute( 'data-i18n-' + key );
        return attr && attr !== '' ? attr : fallback;
    }

    /* ── keyframe interpolation ─────────────────────────────────────
     * Each animated entity carries keyframes [{t,x,y}] with t in 0..1.
     * position(kfs, t) linearly interpolates x/y at normalized time t,
     * clamping outside the authored range (hold first/last frame). */
    function position( kfs, t ) {
        if ( ! kfs || ! kfs.length ) { return null; }
        if ( kfs.length === 1 ) { return { x: kfs[ 0 ].x, y: kfs[ 0 ].y }; }
        if ( t <= kfs[ 0 ].t ) { return { x: kfs[ 0 ].x, y: kfs[ 0 ].y }; }
        var last = kfs[ kfs.length - 1 ];
        if ( t >= last.t ) { return { x: last.x, y: last.y }; }
        for ( var i = 0; i < kfs.length - 1; i++ ) {
            var a = kfs[ i ];
            var b = kfs[ i + 1 ];
            if ( t >= a.t && t <= b.t ) {
                var span = b.t - a.t;
                var f = span > 0 ? ( t - a.t ) / span : 0;
                return { x: a.x + ( b.x - a.x ) * f, y: a.y + ( b.y - a.y ) * f };
            }
        }
        return { x: last.x, y: last.y };
    }

    function buildPitch( svg ) {
        svg.appendChild( el( 'rect', { x: 2, y: 2, width: 96, height: 136, rx: 2, 'class': 'tt-tsc-pitch' } ) );
        svg.appendChild( el( 'line', { x1: 2, y1: 70, x2: 98, y2: 70, 'class': 'tt-tsc-line' } ) );
        svg.appendChild( el( 'circle', { cx: 50, cy: 70, r: 9, 'class': 'tt-tsc-line', fill: 'none' } ) );
        svg.appendChild( el( 'circle', { cx: 50, cy: 70, r: 0.6, 'class': 'tt-tsc-line', fill: 'currentColor' } ) );
        // Top (opponent) box.
        svg.appendChild( el( 'rect', { x: 22, y: 2, width: 56, height: 18, 'class': 'tt-tsc-line', fill: 'none' } ) );
        svg.appendChild( el( 'rect', { x: 36, y: 2, width: 28, height: 6, 'class': 'tt-tsc-line', fill: 'none' } ) );
        // Bottom (own) box.
        svg.appendChild( el( 'rect', { x: 22, y: 120, width: 56, height: 18, 'class': 'tt-tsc-line', fill: 'none' } ) );
        svg.appendChild( el( 'rect', { x: 36, y: 132, width: 28, height: 6, 'class': 'tt-tsc-line', fill: 'none' } ) );
    }

    function arrowMarker() {
        var defs = el( 'defs' );
        var marker = el( 'marker', {
            id: 'tt-tsc-arrow-head', viewBox: '0 0 10 10', refX: 9, refY: 5,
            markerWidth: 5, markerHeight: 5, orient: 'auto-start-reverse'
        } );
        marker.appendChild( el( 'path', { d: 'M 0 0 L 10 5 L 0 10 z' } ) );
        defs.appendChild( marker );
        return defs;
    }

    function buildArrows( svg, arrows ) {
        if ( ! arrows || ! arrows.length ) { return; }
        for ( var i = 0; i < arrows.length; i++ ) {
            var a = arrows[ i ];
            if ( ! a || ! a.from || ! a.to ) { continue; }
            var kind = a.kind ? String( a.kind ) : 'run';
            var line = el( 'line', {
                x1: mapX( a.from.x ), y1: mapY( a.from.y ),
                x2: mapX( a.to.x ), y2: mapY( a.to.y ),
                'class': 'tt-tsc-arrow tt-tsc-arrow--' + kind,
                'marker-end': 'url(#tt-tsc-arrow-head)'
            } );
            svg.appendChild( line );
        }
    }

    function buildToken( group, entity, teamClass ) {
        var label = entity.label != null ? String( entity.label ) : '';
        var g = el( 'g', { 'class': 'tt-tsc-token ' + teamClass } );
        g.appendChild( el( 'circle', { r: 4 } ) );
        if ( label !== '' ) {
            var text = el( 'text', { y: 1.4, 'text-anchor': 'middle', 'font-size': 4, 'font-weight': '700' } );
            text.textContent = label;
            g.appendChild( text );
        }
        group.appendChild( g );
        return g;
    }

    function place( node, kfs, t ) {
        var pos = position( kfs, t );
        if ( ! pos ) { return; }
        node.setAttribute( 'transform', 'translate(' + mapX( pos.x ) + ',' + mapY( pos.y ) + ')' );
    }

    function initScene( container ) {
        var payloadEl = container.querySelector( 'script[type="application/json"]' );
        if ( ! payloadEl ) { return; }
        var scene;
        try {
            scene = JSON.parse( payloadEl.textContent || '{}' );
        } catch ( e ) {
            return;
        }
        if ( ! scene || typeof scene !== 'object' ) { return; }

        var duration = Number( scene.duration_ms ) > 0 ? Number( scene.duration_ms ) : 5000;
        var players = Array.isArray( scene.players ) ? scene.players : [];
        var opponents = Array.isArray( scene.opponents ) ? scene.opponents : [];
        var ball = scene.ball && Array.isArray( scene.ball.keyframes ) ? scene.ball : null;

        var stage = document.createElement( 'div' );
        stage.className = 'tt-tsc-stage';

        var svg = el( 'svg', {
            'class': 'tt-tsc-svg', viewBox: '0 0 ' + VIEW_W + ' ' + VIEW_H,
            role: 'img', preserveAspectRatio: 'xMidYMid meet',
            'aria-label': i18n( container, 'label', 'Tactical scene' )
        } );
        buildPitch( svg );
        svg.appendChild( arrowMarker() );
        buildArrows( svg, scene.arrows );

        // Build the animated tokens.
        var animated = [];
        var i;
        for ( i = 0; i < opponents.length; i++ ) {
            var oppKfs = Array.isArray( opponents[ i ].keyframes ) ? opponents[ i ].keyframes : [];
            animated.push( { node: buildToken( svg, opponents[ i ], 'tt-tsc-token--opp' ), kfs: oppKfs } );
        }
        for ( i = 0; i < players.length; i++ ) {
            var plKfs = Array.isArray( players[ i ].keyframes ) ? players[ i ].keyframes : [];
            animated.push( { node: buildToken( svg, players[ i ], 'tt-tsc-token--own' ), kfs: plKfs } );
        }
        var ballNode = null;
        if ( ball ) {
            ballNode = el( 'circle', { 'class': 'tt-tsc-ball', r: 2.4 } );
            svg.appendChild( ballNode );
        }

        stage.appendChild( svg );
        container.appendChild( stage );

        function renderAt( t ) {
            for ( var j = 0; j < animated.length; j++ ) {
                place( animated[ j ].node, animated[ j ].kfs, t );
            }
            if ( ballNode ) {
                var bp = position( ball.keyframes, t );
                if ( bp ) {
                    ballNode.setAttribute( 'cx', mapX( bp.x ) );
                    ballNode.setAttribute( 'cy', mapY( bp.y ) );
                }
            }
        }

        // ── controls ────────────────────────────────────────────────
        var controls = document.createElement( 'div' );
        controls.className = 'tt-tsc-controls';

        var playBtn = makeButton( i18n( container, 'play', 'Play' ), 'tt-tsc-btn tt-tsc-btn--play' );
        var pauseBtn = makeButton( i18n( container, 'pause', 'Pause' ), 'tt-tsc-btn tt-tsc-btn--pause' );
        var restartBtn = makeButton( i18n( container, 'restart', 'Restart' ), 'tt-tsc-btn tt-tsc-btn--restart' );
        controls.appendChild( playBtn );
        controls.appendChild( pauseBtn );
        controls.appendChild( restartBtn );
        container.appendChild( controls );

        var raf = null;
        var startTs = 0;
        var pausedAt = 0; // normalized 0..1 progress when paused

        function stop() {
            if ( raf !== null ) {
                window.cancelAnimationFrame( raf );
                raf = null;
            }
        }

        function tick( now ) {
            var elapsed = now - startTs;
            var t = elapsed / duration;
            if ( t >= 1 ) {
                renderAt( 1 );
                pausedAt = 0;
                stop();
                return;
            }
            renderAt( t );
            raf = window.requestAnimationFrame( tick );
        }

        function play() {
            stop();
            startTs = ( window.performance && window.performance.now ? window.performance.now() : Date.now() ) - pausedAt * duration;
            raf = window.requestAnimationFrame( tick );
        }

        function pause() {
            if ( raf === null ) { return; }
            var now = ( window.performance && window.performance.now ? window.performance.now() : Date.now() );
            pausedAt = clamp( ( now - startTs ) / duration, 0, 1 );
            stop();
        }

        function restart() {
            stop();
            pausedAt = 0;
            play();
        }

        playBtn.addEventListener( 'click', play );
        pauseBtn.addEventListener( 'click', pause );
        restartBtn.addEventListener( 'click', restart );

        // Reduced-motion: do not autoplay. Render the final frame so the
        // scene reads statically; the user opts into playback via Play.
        var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
        if ( reduce ) {
            renderAt( 1 );
        } else {
            // Non-reduced: still start paused on the first frame; the user
            // presses Play. (No surprise motion on page load.)
            renderAt( 0 );
        }
    }

    function makeButton( label, cls ) {
        var b = document.createElement( 'button' );
        b.type = 'button';
        b.className = cls;
        b.textContent = label;
        b.setAttribute( 'aria-label', label );
        return b;
    }

    function init() {
        var scenes = document.querySelectorAll( '.tt-tactical-scene' );
        if ( ! scenes.length ) { return; }
        Array.prototype.forEach.call( scenes, initScene );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
