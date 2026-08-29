/**
 * Methodology vocabulary (#2976).
 *
 * One client over nine differently-shaped vocabularies. The shape of the
 * open one arrives in `window.TT_METHODOLOGY_VOCAB.vocabulary` from
 * `VocabularyCatalog`; the CRUD is the REST controllers that already own
 * each entity, so nothing about an entity is decided twice.
 *
 * Three modes:
 *
 *   collection  list + add + edit + delete
 *   singleton   one row per club, edit only — the REST layer answers 405 to
 *               POST and DELETE, so no button offers them
 *   nested      a child of a parent the operator picks first (formation
 *               positions), so the list waits for a parent
 *
 * Every visible string comes from `i18n`; none is written here in English
 * (CLAUDE.md § 4, front-end coupling rules).
 */
( function () {
    'use strict';

    var CFG = window.TT_METHODOLOGY_VOCAB;
    if ( ! CFG || ! CFG.vocabulary ) return;

    var root = document.querySelector( '[data-tt-mv]' );
    if ( ! root ) return;

    var V       = CFG.vocabulary;
    var T       = CFG.i18n || {};
    var LOCALES = CFG.locales || { nl: 'nl', en: 'en' };

    var elStatus = root.querySelector( '[data-tt-mv-status]' );
    var elParent = root.querySelector( '[data-tt-mv-parent]' );
    var elList   = root.querySelector( '[data-tt-mv-list]' );
    var elEditor = root.querySelector( '[data-tt-mv-editor]' );

    var state = { rows: [], parentId: 0, parents: [] };

    // ── REST ─────────────────────────────────────────────────────────

    function restPath() {
        return String( V.rest ).replace( '{parent}', String( state.parentId ) );
    }

    function api( method, path, body ) {
        var opts = {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': CFG.rest_nonce, 'Content-Type': 'application/json' }
        };
        if ( body ) opts.body = JSON.stringify( body );

        return fetch( CFG.rest_url + path, opts ).then( function ( res ) {
            return res.json().then( function ( json ) {
                if ( ! res.ok || ! json || json.success === false ) {
                    var msg = json && json.errors && json.errors[ 0 ] && json.errors[ 0 ].message;
                    throw new Error( msg || '' );
                }
                return json.data;
            } );
        } );
    }

    // ── rendering helpers ────────────────────────────────────────────

    function el( tag, className, text ) {
        var node = document.createElement( tag );
        if ( className ) node.className = className;
        if ( text !== undefined && text !== null ) node.textContent = String( text );
        return node;
    }

    function status( message ) {
        if ( ! elStatus ) return;
        elStatus.textContent = message || '';
        elStatus.hidden = ! message;
    }

    function labelFor( row, field ) {
        var value = row[ field ];
        if ( Array.isArray( value ) ) value = value.join( ', ' );
        return value === undefined || value === null || value === '' ? '' : String( value );
    }

    function optionLabel( field, key ) {
        var options = field.options || {};
        return Object.prototype.hasOwnProperty.call( options, key ) ? options[ key ] : key;
    }

    // ── list ─────────────────────────────────────────────────────────

    function renderList() {
        elList.textContent = '';

        if ( V.mode !== 'singleton' && ! ( V.mode === 'nested' && ! state.parentId ) ) {
            var addWrap = el( 'div', 'tt-mv-listhead' );
            var add = el( 'button', 'tt-btn tt-btn-primary tt-mv-add', T.add );
            add.type = 'button';
            add.addEventListener( 'click', function () { openEditor( null ); } );
            addWrap.appendChild( add );
            elList.appendChild( addWrap );
        }

        if ( V.mode === 'nested' && ! state.parentId ) return;

        if ( ! state.rows.length ) {
            elList.appendChild( el( 'p', 'tt-mv-empty', V.mode === 'singleton' ? T.empty_singleton : T.empty ) );
            return;
        }

        var list = el( 'ul', 'tt-mv-rows' );
        state.rows.forEach( function ( row ) {
            list.appendChild( renderRow( row ) );
        } );
        elList.appendChild( list );
    }

    function renderRow( row ) {
        var item = el( 'li', 'tt-mv-row' );

        var main  = el( 'div', 'tt-mv-row-main' );
        var title = labelFor( row, V.title_field ) || T.untitled;
        main.appendChild( el( 'span', 'tt-mv-row-title', title ) );

        var sub = labelFor( row, V.subtitle_field );
        if ( sub ) main.appendChild( el( 'span', 'tt-mv-row-sub', sub ) );
        item.appendChild( main );

        var actions = el( 'div', 'tt-mv-row-actions' );
        if ( row.is_shipped ) {
            var badge = el( 'span', 'tt-pill tt-mv-shipped', T.shipped );
            badge.title = T.shipped_note;
            actions.appendChild( badge );
        } else {
            var edit = el( 'button', 'tt-btn tt-btn-secondary', T.edit );
            edit.type = 'button';
            edit.addEventListener( 'click', function () { openEditor( row ); } );
            actions.appendChild( edit );

            if ( V.mode !== 'singleton' ) {
                var del = el( 'button', 'tt-btn tt-btn-secondary tt-mv-delete', T.delete );
                del.type = 'button';
                del.addEventListener( 'click', function () { remove( row ); } );
                actions.appendChild( del );
            }
        }
        item.appendChild( actions );
        return item;
    }

    // ── editor ───────────────────────────────────────────────────────

    /**
     * A list response carries the localised strings resolved to the reader's
     * locale, not the raw per-locale values an author edits. Those arrive in
     * the `*_i18n` keys, which only the item route returns — so editing an
     * existing row fetches it first, and only "add" opens straight away.
     */
    function openEditor( row ) {
        if ( ! row ) {
            renderEditor( null );
            return;
        }

        status( T.loading );
        api( 'GET', restPath() + '/' + row.id ).then( function ( full ) {
            status( '' );
            renderEditor( full || row );
        } ).catch( function ( err ) {
            status( err.message || T.load_failed );
        } );
    }

    function renderEditor( row ) {
        elEditor.textContent = '';
        elEditor.hidden = false;

        var form = el( 'form', 'tt-mv-form' );
        form.appendChild( el( 'h3', 'tt-mv-form-title', row ? labelFor( row, V.title_field ) || T.untitled : T.new_entry ) );

        ( V.fields || [] ).forEach( function ( field ) {
            form.appendChild( renderField( field, row ) );
        } );

        var msg = el( 'div', 'tt-mv-form-msg' );
        form.appendChild( msg );

        // Cancel then Save in DOM order; the stylesheet puts Save on the
        // right, matching the shared FormSaveButton contract (CLAUDE.md §6).
        var actions = el( 'div', 'tt-form-actions tt-mv-form-actions' );
        var cancel = el( 'button', 'tt-btn tt-btn-secondary', T.cancel );
        cancel.type = 'button';
        cancel.addEventListener( 'click', closeEditor );
        actions.appendChild( cancel );

        var save = el( 'button', 'tt-btn tt-btn-primary', T.save );
        save.type = 'submit';
        actions.appendChild( save );
        form.appendChild( actions );

        form.addEventListener( 'submit', function ( e ) {
            e.preventDefault();
            submit( form, row, msg, save );
        } );

        elEditor.appendChild( form );
        var first = form.querySelector( 'input, select, textarea' );
        if ( first ) first.focus();
    }

    function closeEditor() {
        elEditor.hidden = true;
        elEditor.textContent = '';
    }

    function renderField( field, row ) {
        var wrap = el( 'div', 'tt-field tt-mv-field' );
        var id   = 'tt-mv-' + field.name;

        var label = el( 'label', 'tt-field-label', field.label );
        label.setAttribute( 'for', id );
        wrap.appendChild( label );

        if ( field.type === 'select' ) {
            wrap.appendChild( buildSelect( field, row, id ) );
        } else if ( field.type === 'number' ) {
            wrap.appendChild( buildNumber( field, row, id ) );
        } else if ( field.type === 'text' ) {
            wrap.appendChild( buildText( field, row, id ) );
        } else {
            wrap.appendChild( buildI18n( field, row, id ) );
        }

        if ( field.help ) {
            var help = el( 'span', 'tt-field-help', field.help );
            help.id = id + '-help';
            wrap.appendChild( help );
        }
        return wrap;
    }

    function buildText( field, row, id ) {
        var input = document.createElement( 'input' );
        input.type = 'text';
        input.id = id;
        input.className = 'tt-input';
        input.dataset.ttMvField = field.name;
        input.dataset.ttMvType = 'text';
        if ( field.required ) input.required = true;
        if ( field.help ) input.setAttribute( 'aria-describedby', id + '-help' );
        input.value = row && row[ field.name ] !== undefined && row[ field.name ] !== null ? String( row[ field.name ] ) : '';
        return input;
    }

    function buildNumber( field, row, id ) {
        var input = document.createElement( 'input' );
        input.type = 'number';
        input.inputMode = 'numeric';
        input.id = id;
        input.className = 'tt-input';
        input.min = String( field.min );
        input.max = String( field.max );
        input.step = '1';
        input.dataset.ttMvField = field.name;
        input.dataset.ttMvType = 'number';
        input.value = row && row[ field.name ] !== undefined && row[ field.name ] !== null ? String( row[ field.name ] ) : '';
        return input;
    }

    function buildSelect( field, row, id ) {
        var select = document.createElement( 'select' );
        select.id = id;
        select.className = 'tt-input';
        select.dataset.ttMvField = field.name;
        select.dataset.ttMvType = 'text';

        if ( field.optional ) {
            var blank = document.createElement( 'option' );
            blank.value = '';
            blank.textContent = T.none;
            select.appendChild( blank );
        } else if ( ! row ) {
            var choose = document.createElement( 'option' );
            choose.value = '';
            choose.textContent = T.choose;
            select.appendChild( choose );
        }

        var current = row && row[ field.name ] !== undefined && row[ field.name ] !== null ? String( row[ field.name ] ) : '';
        Object.keys( field.options || {} ).forEach( function ( key ) {
            var option = document.createElement( 'option' );
            option.value = key;
            option.textContent = optionLabel( field, key );
            if ( key === current ) option.selected = true;
            select.appendChild( option );
        } );
        return select;
    }

    /**
     * A multilingual field is one control per locale. Both are always shown:
     * an academy that fills in only one language should see which one it
     * filled in, not discover the gap later in a coach's own locale.
     */
    function buildI18n( field, row, id ) {
        var group = el( 'div', 'tt-mv-i18n' );
        var stored = row ? row[ field.name + '_i18n' ] : null;

        Object.keys( LOCALES ).forEach( function ( locale ) {
            var cell = el( 'div', 'tt-mv-i18n-cell' );
            var cellId = id + '-' + locale;

            var caption = el( 'label', 'tt-mv-i18n-locale', LOCALES[ locale ] );
            caption.setAttribute( 'for', cellId );
            cell.appendChild( caption );

            var control;
            if ( field.type === 'i18n_text' ) {
                control = document.createElement( 'input' );
                control.type = 'text';
            } else {
                control = document.createElement( 'textarea' );
                control.rows = field.type === 'i18n_list' ? 4 : 3;
            }
            control.id = cellId;
            control.className = 'tt-input';
            control.dataset.ttMvField = field.name;
            control.dataset.ttMvType = field.type;
            control.dataset.ttMvLocale = locale;

            var value = stored ? stored[ locale ] : null;
            if ( Array.isArray( value ) ) value = value.join( '\n' );
            control.value = value === undefined || value === null ? '' : String( value );

            cell.appendChild( control );
            group.appendChild( cell );
        } );

        return group;
    }

    // ── write ────────────────────────────────────────────────────────

    function collect( form ) {
        var payload = {};
        var controls = form.querySelectorAll( '[data-tt-mv-field]' );

        Array.prototype.forEach.call( controls, function ( control ) {
            var name = control.dataset.ttMvField;
            var type = control.dataset.ttMvType;
            var value = control.value;

            if ( type === 'number' ) {
                payload[ name ] = value === '' ? 0 : parseInt( value, 10 );
                return;
            }
            if ( type === 'text' ) {
                payload[ name ] = value;
                return;
            }

            var locale = control.dataset.ttMvLocale;
            if ( ! payload[ name ] ) payload[ name ] = {};
            if ( type === 'i18n_list' ) {
                payload[ name ][ locale ] = value.split( '\n' ).map( function ( line ) {
                    return line.trim();
                } ).filter( function ( line ) {
                    return line !== '';
                } );
            } else {
                payload[ name ][ locale ] = value;
            }
        } );

        return payload;
    }

    function submit( form, row, msg, save ) {
        var payload = collect( form );
        var isNew = ! row;
        var path = restPath() + ( isNew ? '' : '/' + row.id );

        save.disabled = true;
        msg.textContent = '';
        msg.className = 'tt-mv-form-msg';

        api( isNew ? 'POST' : 'PUT', path, payload ).then( function () {
            closeEditor();
            status( T.saved );
            return load();
        } ).catch( function ( err ) {
            save.disabled = false;
            msg.className = 'tt-mv-form-msg tt-mv-form-msg--error';
            msg.textContent = err.message || T.save_failed;
        } );
    }

    function remove( row ) {
        if ( ! window.confirm( T.confirm_delete ) ) return;

        api( 'DELETE', restPath() + '/' + row.id ).then( function () {
            status( T.deleted );
            return load();
        } ).catch( function ( err ) {
            status( err.message || T.delete_failed );
        } );
    }

    // ── load ─────────────────────────────────────────────────────────

    function load() {
        if ( V.mode === 'nested' && ! state.parentId ) {
            state.rows = [];
            renderList();
            status( '' );
            return Promise.resolve();
        }

        status( T.loading );
        return api( 'GET', restPath() ).then( function ( data ) {
            var payload = data ? data[ V.collection_key ] : null;
            if ( V.mode === 'singleton' ) {
                state.rows = payload ? [ payload ] : [];
            } else {
                state.rows = Array.isArray( payload ) ? payload : [];
            }
            status( '' );
            renderList();
        } ).catch( function ( err ) {
            status( err.message || T.load_failed );
        } );
    }

    function loadParents() {
        var parent = V.parent;
        status( T.loading );

        return api( 'GET', parent.rest ).then( function ( data ) {
            var rows = data ? data[ parent.collection_key ] : null;
            state.parents = Array.isArray( rows ) ? rows : [];
            renderParentPicker();
            status( '' );
        } ).catch( function ( err ) {
            status( err.message || T.load_failed );
        } );
    }

    function renderParentPicker() {
        var parent = V.parent;
        elParent.textContent = '';
        elParent.hidden = false;

        var wrap = el( 'div', 'tt-field tt-mv-field' );
        var id = 'tt-mv-parent';
        var label = el( 'label', 'tt-field-label', parent.label );
        label.setAttribute( 'for', id );
        wrap.appendChild( label );

        var select = document.createElement( 'select' );
        select.id = id;
        select.className = 'tt-input';

        var blank = document.createElement( 'option' );
        blank.value = '';
        blank.textContent = T.choose;
        select.appendChild( blank );

        state.parents.forEach( function ( row ) {
            var option = document.createElement( 'option' );
            option.value = String( row.id );
            option.textContent = labelFor( row, parent.title_field ) || T.untitled;
            select.appendChild( option );
        } );

        select.addEventListener( 'change', function () {
            state.parentId = parseInt( select.value, 10 ) || 0;
            closeEditor();
            load();
        } );

        wrap.appendChild( select );
        elParent.appendChild( wrap );
    }

    // ── boot ─────────────────────────────────────────────────────────

    if ( V.mode === 'nested' ) {
        loadParents().then( function () { renderList(); } );
    } else {
        load();
    }
} )();
