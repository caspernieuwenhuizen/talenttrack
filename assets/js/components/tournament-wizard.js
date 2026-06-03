/*
 * tournament-wizard.js — #975 ship.
 *
 * Vanilla JS behaviours for the new-tournament wizard's Squad + Matches
 * + Review steps, and the post-creation Add-match standalone surface.
 *
 *   - Formation radio-cards: toggle .is-checked on the parent label.
 *   - Squad search filter + per-row inclusion toggle + position chip
 *     toggling + Mark-all-present bulk action + live count.
 *   - Match card: live-updating headline as opponent / label inputs
 *     change; chip editor for substitution windows (Enter / comma to
 *     add, Backspace pops, x removes); live-update max-value hint from
 *     duration_min; "+ Add another match" clones; remove + renumber.
 *   - Review step: Edit links jump to the named step via the
 *     framework's Back button (which writes the step pointer in
 *     wizard state).
 *
 * No globals beyond `window.TT` (per CLAUDE.md §4 front-end coupling).
 * Strings come from `TT_TournamentWizard` localised via
 * wp_localize_script.
 */
( function () {
    'use strict';

    var I18N = ( window.TT_TournamentWizard && window.TT_TournamentWizard.i18n ) || {};

    function t( key, fallback ) {
        return ( typeof I18N[ key ] === 'string' && I18N[ key ] ) ? I18N[ key ] : fallback;
    }

    /* -------------------------------------------------------------
     * Formation radio cards
     * ------------------------------------------------------------- */
    function wireFormationCards() {
        var cards = document.querySelectorAll( '.ttw-formation-card' );
        if ( ! cards.length ) return;
        cards.forEach( function ( card ) {
            var input = card.querySelector( 'input[type=radio]' );
            if ( ! input ) return;
            if ( input.checked ) card.classList.add( 'is-checked' );
            input.addEventListener( 'change', function () {
                if ( ! input.name ) return;
                document.querySelectorAll( '.ttw-formation-card input[name="' + input.name + '"]' ).forEach( function ( other ) {
                    var label = other.closest( '.ttw-formation-card' );
                    if ( label ) label.classList.toggle( 'is-checked', other.checked );
                } );
            } );
        } );
    }

    /* -------------------------------------------------------------
     * Squad step
     * ------------------------------------------------------------- */
    function recountSquad( root ) {
        var rows = root.querySelectorAll( '.ttw-squad-row' );
        var inCount = 0;
        var outCount = 0;
        rows.forEach( function ( row ) {
            var check = row.querySelector( '.ttw-row-check input[type=checkbox]' );
            if ( check && check.checked ) inCount++;
            else outCount++;
        } );
        var countEl = root.querySelector( '[data-ttw-squad-count]' );
        if ( ! countEl ) return;
        var inLabel  = ( t( 'squad_in', '%d in squad' ) ).replace( '%d', inCount );
        var outLabel = ( t( 'squad_out', '%d not picked' ) ).replace( '%d', outCount );
        countEl.innerHTML = '<strong>' + inCount + '</strong> ' + escapeHtml( inLabel.replace( /^\d+\s*/, '' ) ) + ' &middot; ' + escapeHtml( outLabel );
    }

    function wireSquad() {
        var root = document.querySelector( '[data-ttw-squad]' );
        if ( ! root ) return;

        // Search filter — narrows visible rows by name (case-insensitive).
        var search = root.querySelector( '[data-ttw-squad-search]' );
        if ( search ) {
            search.addEventListener( 'input', function () {
                var q = ( search.value || '' ).trim().toLowerCase();
                root.querySelectorAll( '.ttw-squad-row' ).forEach( function ( row ) {
                    var name = ( row.getAttribute( 'data-name' ) || '' ).toLowerCase();
                    row.style.display = ( q === '' || name.indexOf( q ) >= 0 ) ? '' : 'none';
                } );
            } );
        }

        // Position chip toggling. Each chip is a span with a hidden
        // checkbox sibling so the form submits the set.
        root.querySelectorAll( '.ttw-pos-chip' ).forEach( function ( chip ) {
            chip.setAttribute( 'role', 'button' );
            chip.setAttribute( 'tabindex', '0' );
            var target = chip.getAttribute( 'data-target' );
            var hidden = target ? root.querySelector( '#' + target ) : null;
            var sync = function () {
                if ( hidden ) chip.classList.toggle( 'is-active', !! hidden.checked );
            };
            sync();
            var toggle = function () {
                if ( ! hidden ) return;
                hidden.checked = ! hidden.checked;
                sync();
            };
            chip.addEventListener( 'click', toggle );
            chip.addEventListener( 'keydown', function ( e ) {
                if ( e.key === ' ' || e.key === 'Enter' ) {
                    e.preventDefault();
                    toggle();
                }
            } );
        } );

        // Roster checkboxes drive the in-squad count.
        root.querySelectorAll( '.ttw-row-check input[type=checkbox]' ).forEach( function ( c ) {
            c.addEventListener( 'change', function () { recountSquad( root ); } );
        } );

        // Mark-all-present (= mark-all-in for the in-squad checkbox set).
        var markAll = root.querySelector( '[data-ttw-mark-all]' );
        if ( markAll ) {
            markAll.addEventListener( 'click', function () {
                root.querySelectorAll( '.ttw-row-check input[type=checkbox]' ).forEach( function ( c ) {
                    c.checked = true;
                } );
                recountSquad( root );
            } );
        }

        recountSquad( root );
    }

    /* -------------------------------------------------------------
     * Match cards — headline live update + chip editor + add / remove
     * ------------------------------------------------------------- */
    function escapeHtml( s ) {
        return String( s ).replace( /[&<>"'`]/g, function ( c ) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' }[ c ];
        } );
    }

    function updateHeadline( card ) {
        var headline = card.querySelector( '[data-ttw-headline]' );
        if ( ! headline ) return;
        var label = ( card.querySelector( '[data-ttw-field="label"]' ) || {} ).value || '';
        var opp   = ( card.querySelector( '[data-ttw-field="opponent_name"]' ) || {} ).value || '';
        label = label.trim();
        opp   = opp.trim();
        if ( label !== '' ) {
            headline.textContent = label;
            headline.classList.remove( 'is-empty' );
        } else if ( opp !== '' ) {
            headline.textContent = ( t( 'vs_opponent', 'vs %s' ) ).replace( '%s', opp );
            headline.classList.remove( 'is-empty' );
        } else {
            headline.textContent = t( 'new_match_placeholder', 'New match — fill in opponent below' );
            headline.classList.add( 'is-empty' );
        }
    }

    function syncDurationHint( card ) {
        var duration = parseInt( ( card.querySelector( '[data-ttw-field="duration_min"]' ) || {} ).value || '0', 10 );
        if ( ! ( duration > 0 ) ) duration = 20;
        var maxAllowed = Math.max( 0, duration - 1 );
        card.querySelectorAll( '[data-ttw-dur-max]' ).forEach( function ( el ) {
            el.textContent = String( maxAllowed );
        } );
        card.querySelectorAll( '[data-ttw-chip-editor] input' ).forEach( function ( input ) {
            input.setAttribute( 'data-max', String( maxAllowed ) );
        } );
    }

    function rebuildChipHidden( editor ) {
        // Walk chips left-to-right and write a comma-separated CSV into
        // the hidden field. The validator on the server parses the
        // same CSV ("10, 20, 30") that the v1 wizard accepted.
        var hidden = editor.querySelector( 'input[type=hidden]' );
        if ( ! hidden ) return;
        var values = [];
        editor.querySelectorAll( '.ttw-chip' ).forEach( function ( c ) {
            var v = parseInt( c.getAttribute( 'data-value' ) || '0', 10 );
            if ( v > 0 ) values.push( v );
        } );
        hidden.value = values.join( ',' );
        // v4.20.28 (#1186) — fire a bubbling `change` so wizard-autosave
        // (and any other form-level listener) picks up the new CSV.
        // JS-driven `.value =` assignments don't trigger native events,
        // so the autosave silently never serialised the chips. Mirrors
        // the v4.20.7 PlayerSearchPicker fix (#1157).
        try {
            hidden.dispatchEvent( new Event( 'change', { bubbles: true } ) );
        } catch ( e ) {
            var ev = document.createEvent( 'Event' );
            ev.initEvent( 'change', true, false );
            hidden.dispatchEvent( ev );
        }
    }

    function makeChip( value ) {
        var chip = document.createElement( 'span' );
        chip.className = 'ttw-chip';
        chip.setAttribute( 'data-value', String( value ) );
        chip.innerHTML = escapeHtml( String( value ) ) + "' <button type=\"button\" class=\"ttw-chip-x\" aria-label=\"" +
            escapeHtml( ( t( 'remove_chip', 'Remove %s' ) ).replace( '%s', String( value ) ) ) + "\">&times;</button>";
        return chip;
    }

    function wireChipEditor( editor ) {
        if ( editor.getAttribute( 'data-ttw-wired' ) === '1' ) return;
        editor.setAttribute( 'data-ttw-wired', '1' );
        var input = editor.querySelector( 'input[type=text]' );
        if ( ! input ) return;
        var card = editor.closest( '.ttw-match-card' ) || editor.closest( '.ttw-add-match' );
        function commit( raw ) {
            var v = parseInt( String( raw ).replace( /[^0-9]/g, '' ), 10 );
            if ( ! ( v > 0 ) ) return false;
            var max = parseInt( input.getAttribute( 'data-max' ) || '0', 10 );
            if ( max > 0 && v > max ) return false;
            // Avoid duplicates.
            var existing = editor.querySelectorAll( '.ttw-chip' );
            for ( var i = 0; i < existing.length; i++ ) {
                if ( parseInt( existing[ i ].getAttribute( 'data-value' ) || '0', 10 ) === v ) {
                    return false;
                }
            }
            editor.insertBefore( makeChip( v ), input );
            rebuildChipHidden( editor );
            return true;
        }
        input.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' || e.key === ',' ) {
                e.preventDefault();
                if ( commit( input.value ) ) input.value = '';
            } else if ( e.key === 'Backspace' && input.value === '' ) {
                var chips = editor.querySelectorAll( '.ttw-chip' );
                if ( chips.length ) {
                    chips[ chips.length - 1 ].remove();
                    rebuildChipHidden( editor );
                }
            }
        } );
        editor.addEventListener( 'click', function ( e ) {
            var target = e.target;
            if ( target && target.classList && target.classList.contains( 'ttw-chip-x' ) ) {
                var chip = target.closest( '.ttw-chip' );
                if ( chip ) chip.remove();
                rebuildChipHidden( editor );
            } else if ( target === editor || ( target.classList && target.classList.contains( 'ttw-hint' ) ) ) {
                input.focus();
            }
        } );
        if ( card ) syncDurationHint( card );
    }

    function wireMatchCard( card ) {
        if ( card.getAttribute( 'data-ttw-wired' ) === '1' ) return;
        card.setAttribute( 'data-ttw-wired', '1' );

        [ 'label', 'opponent_name' ].forEach( function ( field ) {
            var el = card.querySelector( '[data-ttw-field="' + field + '"]' );
            if ( el ) {
                el.addEventListener( 'input', function () { updateHeadline( card ); } );
            }
        } );
        var dur = card.querySelector( '[data-ttw-field="duration_min"]' );
        if ( dur ) {
            dur.addEventListener( 'input', function () { syncDurationHint( card ); } );
        }
        card.querySelectorAll( '[data-ttw-chip-editor]' ).forEach( wireChipEditor );

        var removeBtn = card.querySelector( '[data-ttw-match-remove]' );
        if ( removeBtn ) {
            removeBtn.addEventListener( 'click', function () {
                var list = card.parentElement;
                card.remove();
                if ( list ) renumberMatchCards( list );
            } );
        }

        updateHeadline( card );
        syncDurationHint( card );
    }

    function renumberMatchCards( list ) {
        var cards = list.querySelectorAll( '.ttw-match-card' );
        cards.forEach( function ( card, i ) {
            var seq = card.querySelector( '.ttw-seq' );
            if ( seq ) seq.textContent = String( i + 1 );
            // Rename input names so PHP receives a clean indexed array.
            card.setAttribute( 'data-row', String( i ) );
            card.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
                if ( ! el.name ) return;
                el.name = el.name.replace( /matches\[\d+\]/, 'matches[' + i + ']' );
            } );
        } );
    }

    function cloneBlankMatchCard( list ) {
        var template = list.querySelector( '[data-ttw-match-template]' );
        if ( ! template ) return;
        var fresh = template.content ? template.content.firstElementChild.cloneNode( true )
                                     : template.cloneNode( true );
        if ( ! fresh ) return;
        fresh.removeAttribute( 'data-ttw-wired' );
        // Blank every field; clear chip editor content.
        fresh.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
            if ( el.type === 'hidden' ) { el.value = ''; return; }
            if ( el.tagName === 'SELECT' ) { el.selectedIndex = 0; return; }
            el.value = '';
        } );
        fresh.querySelectorAll( '.ttw-chip' ).forEach( function ( c ) { c.remove(); } );
        fresh.querySelectorAll( '.ttw-field--invalid' ).forEach( function ( f ) { f.classList.remove( 'ttw-field--invalid' ); } );
        fresh.querySelectorAll( '.ttw-error' ).forEach( function ( e ) { e.remove(); } );
        var headline = fresh.querySelector( '[data-ttw-headline]' );
        if ( headline ) {
            headline.classList.add( 'is-empty' );
            headline.textContent = t( 'new_match_placeholder', 'New match — fill in opponent below' );
        }
        // Insert before the add-button (which lives outside the list)
        // so cards stack at the bottom of the OL.
        list.appendChild( fresh );
        renumberMatchCards( list );
        wireMatchCard( fresh );
    }

    function wireMatches() {
        var list = document.querySelector( '[data-ttw-match-list]' );
        if ( ! list ) return;
        list.querySelectorAll( '.ttw-match-card' ).forEach( wireMatchCard );

        var add = document.querySelector( '[data-ttw-match-add]' );
        if ( add ) {
            add.addEventListener( 'click', function () { cloneBlankMatchCard( list ); } );
        }
    }

    /* -------------------------------------------------------------
     * Standalone Add-match (post-creation) surface
     * ------------------------------------------------------------- */
    function wireStandaloneAddMatch() {
        var root = document.querySelector( '.ttw-add-match' );
        if ( ! root ) return;
        root.querySelectorAll( '[data-ttw-chip-editor]' ).forEach( wireChipEditor );
        var dur = root.querySelector( '[data-ttw-field="duration_min"]' );
        if ( dur ) {
            dur.addEventListener( 'input', function () { syncDurationHint( root ); } );
        }
        syncDurationHint( root );
    }

    /* -------------------------------------------------------------
     * Review step — Edit links resubmit the wizard with a step jump.
     * ------------------------------------------------------------- */
    function wireReviewEdit() {
        document.querySelectorAll( '[data-ttw-jump]' ).forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                var form = document.querySelector( '.tt-wizard-form' );
                if ( ! form ) return; // fall through to href (URL fallback)
                e.preventDefault();
                var target = link.getAttribute( 'data-ttw-jump' );
                var hidden = form.querySelector( 'input[name="tt_wizard_jump_to"]' );
                if ( ! hidden ) {
                    hidden = document.createElement( 'input' );
                    hidden.type = 'hidden';
                    hidden.name = 'tt_wizard_jump_to';
                    form.appendChild( hidden );
                }
                hidden.value = target;
                var actionField = document.createElement( 'input' );
                actionField.type = 'hidden';
                actionField.name = 'tt_wizard_action';
                actionField.value = 'back';
                form.appendChild( actionField );
                form.submit();
            } );
        } );
    }

    function init() {
        wireFormationCards();
        wireSquad();
        wireMatches();
        wireStandaloneAddMatch();
        wireReviewEdit();
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
