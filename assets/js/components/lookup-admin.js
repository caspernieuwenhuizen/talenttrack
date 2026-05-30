/*
 * lookup-admin.js — list-first lookup category editor.
 *
 * Replaces the inline IIFE that used to live in
 * `FrontendConfigurationView::renderLookupCategoryEditor()`. Extracting
 * it has two benefits:
 *   1. The save handler can be properly diagnosed under devtools (the
 *      v3.110.203 inline script ran in the rendered page's global scope
 *      and could be silently shadowed by other inline scripts, which
 *      was the suspected root cause of issue #985's "Save does nothing"
 *      symptom).
 *   2. Tests and review can reason about one self-contained module
 *      rather than a pile of esc_js() templated globals.
 *
 * Contract:
 *   - The root element carries `data-tt-lkp-admin` plus a JSON config
 *     blob on `data-tt-lkp-config` with: rest_base, nonce, lookup_type,
 *     show_desc, show_color, locales[], site_locale, source_lang, i18n.
 *   - The list view, add view, and edit view all live inside the root.
 *     `data-state` on the root controls which is shown (CSS-only).
 *   - One form `[data-tt-lkp-form]` is rendered once and switched
 *     between add / edit by rewriting its input values.
 *
 * Player-centric — no, this is admin chrome.
 */
( function () {
    'use strict';

    var root = document.querySelector( '[data-tt-lkp-admin]' );
    if ( ! root ) return;

    var cfg = {};
    try {
        cfg = JSON.parse( root.getAttribute( 'data-tt-lkp-config' ) || '{}' );
    } catch ( e ) {
        cfg = {};
    }

    var i18n = cfg.i18n || {};
    var nonce = cfg.nonce || '';
    var restBase = ( cfg.rest_base || '/wp-json/talenttrack/v1/' ).replace( /\/+$/, '/' );
    var lookupType = cfg.lookup_type || '';
    var siteLocale = cfg.site_locale || 'en_US';
    var sourceLang = cfg.source_lang || 'en';
    var locales = Array.isArray( cfg.locales ) && cfg.locales.length > 0
        ? cfg.locales
        : [ 'en_US' ];

    var form = root.querySelector( '[data-tt-lkp-form]' );
    var msgSlot = root.querySelector( '[data-tt-lkp-msg]' );
    var txMsgSlot = root.querySelector( '[data-tt-lkp-tx-msg]' );

    /* ------------------------------------------------------------------
     * State + view toggling
     * ------------------------------------------------------------------ */
    function setState( state ) {
        if ( state !== 'list' && state !== 'add' && state !== 'edit' ) {
            state = 'list';
        }
        root.setAttribute( 'data-state', state );

        // URL hygiene: keep ?edit=N when editing, drop it otherwise so
        // a reload lands on the same view the operator was in.
        try {
            var url = new URL( window.location.href );
            if ( state === 'edit' ) {
                var idInput = form && form.querySelector( '[data-tt-lkp-id]' );
                var id = idInput ? parseInt( idInput.value || '0', 10 ) : 0;
                if ( id > 0 ) {
                    url.searchParams.set( 'edit', String( id ) );
                } else {
                    url.searchParams.delete( 'edit' );
                }
            } else {
                url.searchParams.delete( 'edit' );
            }
            window.history.replaceState( {}, '', url.toString() );
        } catch ( e ) { /* IE / old browsers — non-fatal */ }

        // Scroll to top of the editor so the new view is visible.
        try {
            root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        } catch ( e ) { /* old browsers — non-fatal */ }
    }

    /* ------------------------------------------------------------------
     * Form population (add / edit)
     * ------------------------------------------------------------------ */
    function clearForm() {
        if ( ! form ) return;
        var id = form.querySelector( '[data-tt-lkp-id]' );
        var name = form.querySelector( 'input[name="name"]' );
        var sort = form.querySelector( 'input[name="sort_order"]' );
        var desc = form.querySelector( 'input[name="description"]' );
        var color = form.querySelector( 'input[name="meta[color]"]' );
        var title = form.querySelector( '[data-tt-lkp-form-title]' );
        var hint = form.querySelector( '[data-tt-lkp-name-hint]' );

        if ( id ) id.value = '0';
        if ( name ) {
            name.value = '';
            name.removeAttribute( 'readonly' );
            name.removeAttribute( 'disabled' );
        }
        if ( sort ) sort.value = '0';
        if ( desc ) desc.value = '';
        if ( color ) color.value = '#5b6e75';
        if ( title ) title.textContent = i18n.title_add || 'Add new value';
        if ( hint ) hint.textContent = i18n.hint_add || '';

        form.querySelectorAll( '[data-tt-tx-locale]' ).forEach( function ( inp ) {
            inp.value = '';
        } );
        updateCoverageInForm();

        var save = form.querySelector( '.tt-save-btn' );
        var label = save && save.querySelector( '.tt-save-btn-label' );
        if ( save ) save.setAttribute( 'data-label-idle', i18n.add || 'Add value' );
        if ( label ) label.textContent = i18n.add || 'Add value';

        if ( msgSlot ) msgSlot.textContent = '';
        if ( txMsgSlot ) txMsgSlot.textContent = '';
    }

    function populateFromRow( row ) {
        if ( ! form || ! row ) return;
        var id = form.querySelector( '[data-tt-lkp-id]' );
        var name = form.querySelector( 'input[name="name"]' );
        var sort = form.querySelector( 'input[name="sort_order"]' );
        var desc = form.querySelector( 'input[name="description"]' );
        var color = form.querySelector( 'input[name="meta[color]"]' );
        var title = form.querySelector( '[data-tt-lkp-form-title]' );
        var hint = form.querySelector( '[data-tt-lkp-name-hint]' );

        if ( id ) id.value = String( row.getAttribute( 'data-id' ) || '0' );
        if ( name ) {
            name.value = String( row.getAttribute( 'data-row-name' ) || '' );
            // Q4 (#985): internal key is immutable on existing rows. The
            // operator-visible label comes from `tt_translations`, not
            // from the `name` column. Edit is disabled outright — no
            // confirm modal needed because the affordance is gone.
            name.setAttribute( 'readonly', 'readonly' );
            name.setAttribute( 'disabled', 'disabled' );
        }
        if ( sort ) sort.value = String( row.getAttribute( 'data-row-sort' ) || '0' );
        if ( desc ) desc.value = String( row.getAttribute( 'data-row-desc' ) || '' );
        if ( color ) color.value = String( row.getAttribute( 'data-row-color' ) || '' ) || '#5b6e75';
        if ( title ) title.textContent = i18n.title_edit || 'Edit value';
        if ( hint ) hint.textContent = i18n.hint_edit || '';

        // Translations: parse the JSON blob baked onto the row server-side.
        var tx = {};
        var raw = row.getAttribute( 'data-row-tx' ) || '';
        if ( raw !== '' ) {
            try { tx = JSON.parse( raw ); } catch ( e ) { tx = {}; }
        }
        form.querySelectorAll( '[data-tt-tx-locale]' ).forEach( function ( inp ) {
            var loc = inp.getAttribute( 'data-tt-tx-locale' );
            var field = inp.getAttribute( 'data-tt-tx-field' ) || 'name';
            var v = ( tx && tx[ loc ] && tx[ loc ][ field ] ) || '';
            inp.value = String( v );
        } );
        updateCoverageInForm();

        var save = form.querySelector( '.tt-save-btn' );
        var label = save && save.querySelector( '.tt-save-btn-label' );
        if ( save ) save.setAttribute( 'data-label-idle', i18n.save || 'Save changes' );
        if ( label ) label.textContent = i18n.save || 'Save changes';

        if ( msgSlot ) msgSlot.textContent = '';
        if ( txMsgSlot ) txMsgSlot.textContent = '';
    }

    /* ------------------------------------------------------------------
     * Coverage dots — Q5: a locale is "covered" when its name input
     * carries a non-empty value. Description is optional and doesn't
     * gate the dot. Surfaces in the list rail (server-rendered) and
     * inside the edit form (mirrors changes as the operator types).
     * ------------------------------------------------------------------ */
    function updateCoverageInForm() {
        if ( ! form ) return;
        // Find the row we're editing in the list and re-paint its dots
        // so the operator sees the impact of a name-field change without
        // a full reload.
        var idInput = form.querySelector( '[data-tt-lkp-id]' );
        var id = idInput ? parseInt( idInput.value || '0', 10 ) : 0;
        if ( id <= 0 ) return;
        var row = root.querySelector( '[data-tt-lkp-row][data-id="' + id + '"]' );
        if ( ! row ) return;

        locales.forEach( function ( loc ) {
            var dot = row.querySelector( '.tt-lkp-dot[data-locale="' + loc + '"]' );
            if ( ! dot ) return;
            var inp = form.querySelector(
                '[data-tt-tx-locale="' + loc + '"][data-tt-tx-field="name"]'
            );
            var val = inp ? String( inp.value || '' ).trim() : '';
            dot.classList.remove( 'is-set', 'is-missing' );
            dot.classList.add( val !== '' ? 'is-set' : 'is-missing' );
        } );
    }

    /* ------------------------------------------------------------------
     * Save handler — POST on add, PUT on edit. Q4: do NOT send `name`
     * on edit so the controller's `buildRow()` leaves the immutable
     * internal-key column alone.
     * ------------------------------------------------------------------ */
    function handleSave( e ) {
        if ( e ) e.preventDefault();
        if ( ! form ) return;

        var idInput = form.querySelector( '[data-tt-lkp-id]' );
        var id = idInput ? parseInt( idInput.value || '0', 10 ) : 0;

        var nameInput = form.querySelector( 'input[name="name"]' );
        var sortInput = form.querySelector( 'input[name="sort_order"]' );
        var descInput = form.querySelector( 'input[name="description"]' );
        var colorInput = form.querySelector( 'input[name="meta[color]"]' );

        var body = {};
        if ( id <= 0 ) {
            // Create — the internal key is required.
            var rawName = nameInput ? String( nameInput.value || '' ).trim() : '';
            if ( rawName === '' ) {
                if ( msgSlot ) msgSlot.textContent = i18n.err_name_required || 'Internal key is required.';
                return;
            }
            body.name = rawName;
        }
        if ( sortInput ) body.sort_order = parseInt( sortInput.value || '0', 10 );
        if ( descInput ) body.description = String( descInput.value || '' );
        if ( colorInput && colorInput.value ) {
            body.meta = { color: String( colorInput.value || '' ) };
        }

        // Collect per-locale translations including en_US (which now
        // carries the canonical English display value — see #985 spec
        // item 1: "rename to Internal key and add an en_US row to the
        // translations grid").
        var translations = {};
        form.querySelectorAll( '[data-tt-tx-locale]' ).forEach( function ( inp ) {
            var loc = inp.getAttribute( 'data-tt-tx-locale' );
            var field = inp.getAttribute( 'data-tt-tx-field' ) || 'name';
            var val = String( inp.value || '' ).trim();
            if ( ! loc ) return;
            if ( ! translations[ loc ] ) translations[ loc ] = {};
            translations[ loc ][ field ] = val;
        } );
        if ( Object.keys( translations ).length > 0 ) {
            body.translations = translations;
        }

        var url = restBase + 'lookups/' + encodeURIComponent( lookupType );
        var method = 'POST';
        if ( id > 0 ) {
            url += '/' + id;
            method = 'PUT';
        }

        if ( msgSlot ) msgSlot.textContent = i18n.saving || 'Saving…';

        fetch( url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
                'Accept': 'application/json'
            },
            body: JSON.stringify( body )
        } )
            .then( function ( r ) {
                return r.json().then( function ( j ) {
                    return { ok: r.ok, status: r.status, json: j };
                } );
            } )
            .then( function ( r ) {
                if ( r.ok ) {
                    // Reload reflects the new ordering + coverage state
                    // without us having to reimplement the list-render
                    // logic in JS. The server is the source of truth.
                    window.location.reload();
                    return;
                }
                var first = r.json && r.json.errors && r.json.errors[ 0 ] && r.json.errors[ 0 ].message;
                if ( msgSlot ) {
                    msgSlot.textContent = first || ( ( i18n.error || 'Error' ) + ' ' + r.status );
                }
            } )
            .catch( function () {
                if ( msgSlot ) msgSlot.textContent = i18n.network_error || 'Network error.';
            } );
    }

    if ( form ) {
        form.addEventListener( 'submit', handleSave );
    }

    /* ------------------------------------------------------------------
     * Translate-from-source — POSTs the source-language value to
     * /translations/preview which returns translations for the other
     * installed locales in a single bulk response. (Verified the
     * existing endpoint shape can satisfy Q3 without fan-out.)
     * ------------------------------------------------------------------ */
    var txBtn = root.querySelector( '[data-tt-lkp-translate]' );
    if ( txBtn ) {
        txBtn.addEventListener( 'click', function () {
            if ( ! form ) return;

            // Source for the translation is the en_US row first (canonical
            // English authored by the operator); fall back to the name
            // input (internal key) only when en_US is empty.
            var sourceInput = form.querySelector(
                '[data-tt-tx-locale="en_US"][data-tt-tx-field="name"]'
            );
            var nameInput = form.querySelector( 'input[name="name"]' );
            var text = '';
            if ( sourceInput && sourceInput.value && sourceInput.value.trim() !== '' ) {
                text = sourceInput.value.trim();
            } else if ( nameInput ) {
                text = String( nameInput.value || '' ).trim();
            }

            if ( text === '' ) {
                if ( txMsgSlot ) txMsgSlot.textContent = i18n.err_enter_name || 'Enter a name first.';
                return;
            }

            if ( txMsgSlot ) txMsgSlot.textContent = i18n.translating || 'Translating…';
            txBtn.disabled = true;

            fetch( restBase + 'translations/preview', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                    'Accept': 'application/json'
                },
                body: JSON.stringify( { text: text, source_lang: sourceLang } )
            } )
                .then( function ( r ) {
                    return r.json().then( function ( j ) {
                        return { ok: r.ok, status: r.status, json: j };
                    } );
                } )
                .then( function ( r ) {
                    txBtn.disabled = false;
                    if ( r.ok && r.json && r.json.success ) {
                        var data = ( r.json.data && r.json.data.translations ) || {};
                        Object.keys( data ).forEach( function ( loc ) {
                            var inp = form.querySelector(
                                '[data-tt-tx-locale="' + loc + '"][data-tt-tx-field="name"]'
                            );
                            if ( inp && ( ! inp.value || inp.value.trim() === '' ) ) {
                                inp.value = data[ loc ];
                            }
                        } );
                        updateCoverageInForm();
                        if ( txMsgSlot ) {
                            txMsgSlot.textContent = i18n.translated || 'Translated. Review and edit before saving.';
                        }
                    } else {
                        var first = r.json && r.json.errors && r.json.errors[ 0 ] && r.json.errors[ 0 ].message;
                        if ( txMsgSlot ) {
                            txMsgSlot.textContent = first || ( ( i18n.error || 'Error' ) + ' ' + r.status );
                        }
                    }
                } )
                .catch( function () {
                    txBtn.disabled = false;
                    if ( txMsgSlot ) txMsgSlot.textContent = i18n.network_error || 'Network error.';
                } );
        } );
    }

    /* ------------------------------------------------------------------
     * Delete (per-row).
     * ------------------------------------------------------------------ */
    root.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest && e.target.closest( '[data-tt-lkp-delete]' );
        if ( ! btn ) return;
        e.preventDefault();
        e.stopPropagation();

        var id = parseInt( btn.getAttribute( 'data-tt-lkp-delete' ), 10 );
        if ( ! id ) return;

        var prompt = i18n.confirm_delete || 'Delete this row?';
        if ( ! window.confirm( prompt ) ) return;

        btn.disabled = true;
        fetch( restBase + 'lookups/' + encodeURIComponent( lookupType ) + '/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' }
        } )
            .then( function ( r ) {
                if ( r.ok ) {
                    window.location.reload();
                } else {
                    btn.disabled = false;
                    window.alert( ( i18n.error || 'Error' ) + ' ' + r.status );
                }
            } )
            .catch( function () {
                btn.disabled = false;
                window.alert( i18n.network_error || 'Network error.' );
            } );
    } );

    /* ------------------------------------------------------------------
     * Row click → edit view. Delete and grip skip the navigation.
     * ------------------------------------------------------------------ */
    root.addEventListener( 'click', function ( e ) {
        if ( e.target.closest && e.target.closest( '[data-tt-lkp-delete]' ) ) return;
        if ( e.target.closest && e.target.closest( '.tt-lkp-row-grip' ) ) return;
        var row = e.target.closest && e.target.closest( '[data-tt-lkp-row]' );
        if ( ! row ) return;
        populateFromRow( row );
        setState( 'edit' );
    } );

    root.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Enter' && e.key !== ' ' ) return;
        var row = e.target.closest && e.target.closest( '[data-tt-lkp-row]' );
        if ( ! row ) return;
        if ( e.target.closest && e.target.closest( '[data-tt-lkp-delete]' ) ) return;
        e.preventDefault();
        populateFromRow( row );
        setState( 'edit' );
    } );

    /* ------------------------------------------------------------------
     * View-switch buttons (+ Add, Back to list, Cancel).
     * ------------------------------------------------------------------ */
    root.querySelectorAll( '[data-tt-lkp-go="add"]' ).forEach( function ( el ) {
        el.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            clearForm();
            setState( 'add' );
            var nameInput = form && form.querySelector( 'input[name="name"]' );
            if ( nameInput ) nameInput.focus();
        } );
    } );
    root.querySelectorAll( '[data-tt-lkp-go="list"]' ).forEach( function ( el ) {
        el.addEventListener( 'click', function ( e ) {
            e.preventDefault();
            setState( 'list' );
        } );
    } );

    /* ------------------------------------------------------------------
     * Translation-name inputs paint coverage dots live so the operator
     * sees the impact of typing without saving.
     * ------------------------------------------------------------------ */
    if ( form ) {
        form.addEventListener( 'input', function ( e ) {
            var t = e.target;
            if ( ! t || ! t.hasAttribute( 'data-tt-tx-locale' ) ) return;
            if ( t.getAttribute( 'data-tt-tx-field' ) !== 'name' ) return;
            updateCoverageInForm();
        } );
    }

    /* ------------------------------------------------------------------
     * Initial state — if the server-rendered markup landed us in edit
     * mode (i.e. ?edit=N matched a row), the form already has the row's
     * values populated. Otherwise default to list.
     * ------------------------------------------------------------------ */
    var initial = root.getAttribute( 'data-state' ) || 'list';
    setState( initial );

    // Suppress site_locale lint
    void siteLocale;
} )();
