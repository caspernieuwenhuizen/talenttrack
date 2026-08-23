/*
 * frontend-exercise-tagging.js (#2753) — classify the exercise library.
 *
 * An exercise with no principle is never suggested by the generator and
 * contributes nothing to player training exposure. On the pilot install
 * that is 69 of 123 exercises, and the reason it stayed that way is
 * arithmetic: a tagged exercise carries 7.8 principles on average, so
 * doing it one at a time is roughly 550 interactions through a form that
 * opens and saves once per row.
 *
 * So this screen is one interaction — **select many, apply once** — and
 * everything else is arranged around not getting in its way.
 *
 * ## "None apply" is a real answer
 *
 * Nearly half the unclassified rows are warm-ups, cool-downs and
 * conditioning work that should never carry a tactical principle. If the
 * only way out of the list were tagging, those would sit there forever
 * and the count would never reach zero — so applying an empty set is a
 * first-class action, and it marks the row looked-at.
 *
 * Vanilla, no dependencies. All user-facing text arrives from PHP.
 */
( function () {
    'use strict';

    var cfg = window.TT_EXERCISE_TAGGING || {};
    var i18n = cfg.i18n || {};

    var state = {
        rows: [],
        progress: { reviewed: 0, total: 0 },
        selected: {},
        principles: {},
        mode: 'add',
        busy: false
    };

    var root = null;
    var el = {};

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
        if ( el.msg ) { el.msg.textContent = message || ''; }
    }

    function selectedIds() {
        return Object.keys( state.selected ).filter( function ( id ) {
            return state.selected[ id ];
        } ).map( Number );
    }

    function chosenPrincipleIds() {
        return Object.keys( state.principles ).filter( function ( id ) {
            return state.principles[ id ];
        } ).map( Number );
    }

    // ── data ────────────────────────────────────────────────────────

    function load() {
        return window.fetch( cfg.restBase + '/exercises/awaiting-review', {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        } ).then( function ( response ) {
            return response.json();
        } ).then( function ( envelope ) {
            if ( !envelope || envelope.success === false ) { throw new Error( 'rest' ); }
            state.rows = envelope.data.rows || [];
            state.progress = envelope.data.progress || state.progress;
            state.selected = {};
            render();
        } )[ 'catch' ]( function () {
            render();
            say( i18n.loadFailed );
        } );
    }

    function apply( principleIds ) {
        var ids = selectedIds();
        if ( !ids.length ) { say( i18n.pickSome ); return; }
        if ( state.busy ) { return; }

        if ( state.mode === 'replace' && principleIds.length
            && !window.confirm( i18n.replaceWarn ) ) { return; }

        state.busy = true;
        say( i18n.saving );

        window.fetch( cfg.restBase + '/exercises/principles/bulk', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
            body: JSON.stringify( {
                exercise_ids: ids,
                principle_ids: principleIds,
                // An empty set with `replace` would clear an exercise and
                // then mark it done, which is never what "none apply"
                // means. It means "leave it alone and stop asking".
                mode: principleIds.length ? state.mode : 'add',
                mark_reviewed: true
            } )
        } ).then( function ( response ) {
            return response.json();
        } ).then( function ( envelope ) {
            state.busy = false;
            if ( !envelope || envelope.success === false ) { say( i18n.saveFailed ); return; }

            var changed = envelope.data.changed || 0;
            state.progress = envelope.data.progress || state.progress;
            state.principles = {};
            return load().then( function () { say( fmt( i18n.saved, changed ) ); } );
        } )[ 'catch' ]( function () {
            state.busy = false;
            say( i18n.saveFailed );
        } );
    }

    // ── the list ────────────────────────────────────────────────────

    function renderRows() {
        el.rows.textContent = '';

        if ( !state.rows.length ) {
            el.rows.appendChild( node( 'p', 'tt-extag__done', i18n.allDone ) );
            return;
        }

        var currentGroup = null;

        state.rows.forEach( function ( row ) {
            // Grouped by category, because exercises of a kind usually
            // train the same principles — which is what makes selecting
            // a whole group and applying once the fast path.
            if ( row.category_label !== currentGroup ) {
                currentGroup = row.category_label;
                var head = node( 'div', 'tt-extag__group' );
                head.appendChild( node( 'h3', 'tt-extag__group-title', currentGroup || '—' ) );
                head.appendChild( button( i18n.selectAll, 'tt-btn tt-btn-secondary tt-extag__group-all', function () {
                    state.rows.forEach( function ( r ) {
                        if ( r.category_label === currentGroup ) { state.selected[ r.id ] = true; }
                    } );
                    render();
                } ) );
                el.rows.appendChild( head );
            }

            el.rows.appendChild( rowEl( row ) );
        } );
    }

    function rowEl( row ) {
        var label = node( 'label', 'tt-extag__row' );
        if ( state.selected[ row.id ] ) { label.classList.add( 'tt-extag__row--on' ); }

        var box = node( 'input', 'tt-extag__check' );
        box.type = 'checkbox';
        box.checked = !!state.selected[ row.id ];
        box.addEventListener( 'change', function () {
            state.selected[ row.id ] = box.checked;
            label.classList.toggle( 'tt-extag__row--on', box.checked );
            paintCount();
        } );
        label.appendChild( box );

        var body = node( 'span', 'tt-extag__body' );
        body.appendChild( node( 'span', 'tt-extag__name', row.name ) );

        if ( row.description ) {
            body.appendChild( node( 'span', 'tt-extag__desc', row.description ) );
        }

        body.appendChild( node(
            'span',
            'tt-extag__tags' + ( row.principle_ids.length ? '' : ' tt-extag__tags--none' ),
            row.principle_ids.length
                ? fmt( i18n.alreadyTagged, row.principle_ids.length )
                : i18n.untagged
        ) );

        label.appendChild( body );
        return label;
    }

    // ── the picker ──────────────────────────────────────────────────

    function renderPicker() {
        el.picker.textContent = '';

        ( cfg.groups || [] ).forEach( function ( group ) {
            var block = node( 'div', 'tt-extag__pgroup' );
            block.appendChild( node( 'h4', 'tt-extag__pgroup-title', group.label ) );

            group.principles.forEach( function ( principle ) {
                var item = node( 'label', 'tt-extag__principle' );

                var box = node( 'input' );
                box.type = 'checkbox';
                box.checked = !!state.principles[ principle.id ];
                box.addEventListener( 'change', function () {
                    state.principles[ principle.id ] = box.checked;
                    item.classList.toggle( 'tt-extag__principle--on', box.checked );
                } );
                if ( box.checked ) { item.classList.add( 'tt-extag__principle--on' ); }

                item.appendChild( box );
                item.appendChild( node( 'span', 'tt-extag__pcode', principle.code ) );
                item.appendChild( node( 'span', 'tt-extag__ptitle', principle.title ) );
                block.appendChild( item );
            } );

            el.picker.appendChild( block );
        } );
    }

    function paintCount() {
        var n = selectedIds().length;
        if ( el.count ) { el.count.textContent = fmt( i18n.selected, n ); }
        if ( el.applyBtn ) { el.applyBtn.disabled = n === 0; }
        if ( el.noneBtn ) { el.noneBtn.disabled = n === 0; }
        if ( el.clearBtn ) { el.clearBtn.disabled = n === 0; }
    }

    // ── shell ───────────────────────────────────────────────────────

    function render() {
        root.textContent = '';

        var head = node( 'div', 'tt-extag__head' );
        head.appendChild( node( 'p', 'tt-extag__progress',
            fmt( i18n.progress, state.progress.reviewed, state.progress.total ) ) );
        if ( cfg.methodology ) {
            head.appendChild( node( 'p', 'tt-extag__method tt-muted', fmt( i18n.writingTo, cfg.methodology ) ) );
        }
        root.appendChild( head );

        el.rows = node( 'div', 'tt-extag__rows' );
        root.appendChild( el.rows );
        renderRows();

        if ( state.rows.length ) {
            var panel = node( 'div', 'tt-extag__panel' );

            var bar = node( 'div', 'tt-extag__bar' );
            el.count = node( 'span', 'tt-extag__count' );
            bar.appendChild( el.count );
            el.clearBtn = button( i18n.clearSelection, 'tt-btn tt-btn-secondary', function () {
                state.selected = {};
                render();
            } );
            bar.appendChild( el.clearBtn );
            panel.appendChild( bar );

            panel.appendChild( node( 'h3', 'tt-extag__panel-title', i18n.choosePrinciples ) );
            el.picker = node( 'div', 'tt-extag__picker' );
            panel.appendChild( el.picker );
            renderPicker();

            var modes = node( 'div', 'tt-extag__modes' );
            [ [ 'add', i18n.modeAdd ], [ 'replace', i18n.modeReplace ] ].forEach( function ( pair ) {
                var item = node( 'label', 'tt-extag__mode' );
                var radio = node( 'input' );
                radio.type = 'radio';
                radio.name = 'tt-extag-mode';
                radio.checked = state.mode === pair[ 0 ];
                radio.addEventListener( 'change', function () { state.mode = pair[ 0 ]; } );
                item.appendChild( radio );
                item.appendChild( node( 'span', null, pair[ 1 ] ) );
                modes.appendChild( item );
            } );
            panel.appendChild( modes );

            var actions = node( 'div', 'tt-form-actions tt-extag__actions' );
            el.noneBtn = button( i18n.noneApply, 'tt-btn tt-btn-secondary', function () {
                apply( [] );
            } );
            actions.appendChild( el.noneBtn );
            el.applyBtn = button( i18n.apply, 'tt-btn tt-btn-primary', function () {
                var chosen = chosenPrincipleIds();
                if ( !chosen.length ) { say( i18n.pickPrinciples ); return; }
                apply( chosen );
            } );
            actions.appendChild( el.applyBtn );
            panel.appendChild( actions );

            root.appendChild( panel );
        }

        el.msg = node( 'p', 'tt-extag__msg tt-muted' );
        el.msg.setAttribute( 'role', 'status' );
        el.msg.setAttribute( 'aria-live', 'polite' );
        root.appendChild( el.msg );

        paintCount();
    }

    function init() {
        root = document.querySelector( '[data-tt-extag]' );
        if ( !root ) { return; }

        render();
        load();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
