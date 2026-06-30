/*
 * frontend-measurement-status-select.js (#2144)
 *
 * Progressive enhancement for the Record-measurements roster status picker.
 * Each status row server-renders a native <select data-tt-status-select>
 * carrying one <option data-token="…"> per level. That select stays in the
 * DOM as the form value source AND as the no-JS fallback — this script only
 * builds an accessible coloured listbox on top of it and keeps the underlying
 * select in sync (set .value + dispatch 'change'), so the existing POST is
 * unchanged. With JS off, the native select is fully usable.
 *
 * The custom control follows the ARIA listbox pattern: a button
 * (aria-haspopup="listbox", aria-expanded) showing swatch + label, and a
 * ul[role="listbox"] of li[role="option"] (aria-selected) each rendering the
 * shared .tt-mlvl-swatch <token-class> + label. Keyboard: Enter/Space/Up/Down
 * open; Up/Down/Home/End move; Enter/Space select; Esc close; type-ahead.
 * Click-outside and Esc close. No globals beyond the TT namespace; the only
 * string (aria-label) comes from TT_ME_STATUS, localized in PHP.
 */
( function () {
	'use strict';

	var TT = ( window.TT = window.TT || {} );
	TT.i18n = TT.i18n || {};
	var cfg = window.TT_ME_STATUS || {};
	if ( cfg.i18n ) {
		for ( var k in cfg.i18n ) {
			if ( Object.prototype.hasOwnProperty.call( cfg.i18n, k ) && ! TT.i18n[ k ] ) {
				TT.i18n[ k ] = cfg.i18n[ k ];
			}
		}
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	var idSeq = 0;

	function chooseLabel() {
		return ( TT.i18n && TT.i18n.choose ) || 'Choose status';
	}

	/* Read {value,label,token,isSkip} list from the native options. */
	function readOptions( select ) {
		var out = [];
		var opts = select.options;
		for ( var i = 0; i < opts.length; i++ ) {
			var o = opts[ i ];
			out.push( {
				value: o.value,
				label: o.textContent.trim(),
				token: o.getAttribute( 'data-token' ) || '',
				isSkip: o.value === ''
			} );
		}
		return out;
	}

	function makeSwatch( token ) {
		var sw = document.createElement( 'span' );
		sw.className = 'tt-mlvl-swatch tt-statussel__swatch';
		if ( token ) {
			sw.className += ' tt-mlvl-swatch--' + token;
		} else {
			sw.className += ' tt-statussel__swatch--none';
		}
		sw.setAttribute( 'aria-hidden', 'true' );
		return sw;
	}

	function enhance( select ) {
		if ( select.getAttribute( 'data-tt-enhanced' ) === '1' ) {
			return;
		}
		select.setAttribute( 'data-tt-enhanced', '1' );

		var options = readOptions( select );
		var uid = 'tt-statussel-' + ( ++idSeq );
		var listId = uid + '-list';

		var wrap = document.createElement( 'div' );
		wrap.className = 'tt-statussel';

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'tt-statussel__button tt-input';
		button.id = uid + '-btn';
		button.setAttribute( 'aria-haspopup', 'listbox' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.setAttribute( 'aria-label', chooseLabel() );
		button.setAttribute( 'aria-controls', listId );

		var btnSwatch = document.createElement( 'span' );
		btnSwatch.className = 'tt-statussel__btn-swatch';
		var btnLabel = document.createElement( 'span' );
		btnLabel.className = 'tt-statussel__btn-label';
		var caret = document.createElement( 'span' );
		caret.className = 'tt-statussel__caret';
		caret.setAttribute( 'aria-hidden', 'true' );
		button.appendChild( btnSwatch );
		button.appendChild( btnLabel );
		button.appendChild( caret );

		var list = document.createElement( 'ul' );
		list.className = 'tt-statussel__list';
		list.id = listId;
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'tabindex', '-1' );
		list.hidden = true;

		var items = [];
		options.forEach( function ( opt, idx ) {
			var li = document.createElement( 'li' );
			li.className = 'tt-statussel__option';
			li.setAttribute( 'role', 'option' );
			li.id = uid + '-opt-' + idx;
			li.setAttribute( 'data-value', opt.value );
			li.setAttribute( 'aria-selected', 'false' );

			li.appendChild( makeSwatch( opt.isSkip ? '' : opt.token ) );
			var txt = document.createElement( 'span' );
			txt.className = 'tt-statussel__option-label';
			txt.textContent = opt.label;
			li.appendChild( txt );
			list.appendChild( li );
			items.push( li );
		} );

		var activeIdx = select.selectedIndex < 0 ? 0 : select.selectedIndex;
		var open = false;

		/* Mirror the chosen option onto the button face. */
		function paintButton() {
			var idx = select.selectedIndex < 0 ? 0 : select.selectedIndex;
			var opt = options[ idx ];
			btnSwatch.innerHTML = '';
			if ( opt && ! opt.isSkip && opt.token ) {
				btnSwatch.appendChild( makeSwatch( opt.token ) );
				btnSwatch.classList.remove( 'tt-statussel__btn-swatch--empty' );
			} else {
				btnSwatch.classList.add( 'tt-statussel__btn-swatch--empty' );
			}
			btnLabel.textContent = opt ? opt.label : '';
		}

		function markSelected( idx ) {
			items.forEach( function ( li, i ) {
				li.setAttribute( 'aria-selected', i === idx ? 'true' : 'false' );
			} );
		}

		function setActive( idx ) {
			if ( idx < 0 ) idx = 0;
			if ( idx > items.length - 1 ) idx = items.length - 1;
			activeIdx = idx;
			items.forEach( function ( li, i ) {
				li.classList.toggle( 'is-active', i === idx );
			} );
			list.setAttribute( 'aria-activedescendant', items[ idx ].id );
			items[ idx ].scrollIntoView( { block: 'nearest' } );
		}

		function commit( idx ) {
			if ( idx < 0 || idx > options.length - 1 ) return;
			if ( select.selectedIndex !== idx ) {
				select.selectedIndex = idx;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
			markSelected( idx );
			paintButton();
		}

		function onDocClick( e ) {
			if ( ! wrap.contains( e.target ) ) {
				closeList( false );
			}
		}

		function openList() {
			if ( open ) return;
			open = true;
			list.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
			setActive( select.selectedIndex < 0 ? 0 : select.selectedIndex );
			document.addEventListener( 'click', onDocClick, true );
			list.focus();
		}

		function closeList( refocus ) {
			if ( ! open ) return;
			open = false;
			list.hidden = true;
			button.setAttribute( 'aria-expanded', 'false' );
			document.removeEventListener( 'click', onDocClick, true );
			if ( refocus ) button.focus();
		}

		/* Type-ahead: first label that starts with the typed buffer. */
		var typeBuf = '';
		var typeTimer = null;
		function typeAhead( ch ) {
			typeBuf += ch.toLowerCase();
			if ( typeTimer ) clearTimeout( typeTimer );
			typeTimer = setTimeout( function () { typeBuf = ''; }, 600 );
			for ( var i = 0; i < options.length; i++ ) {
				if ( options[ i ].label.toLowerCase().indexOf( typeBuf ) === 0 ) {
					if ( open ) { setActive( i ); } else { commit( i ); }
					return;
				}
			}
		}

		button.addEventListener( 'click', function () {
			if ( open ) { closeList( false ); } else { openList(); }
		} );

		button.addEventListener( 'keydown', function ( e ) {
			switch ( e.key ) {
				case 'ArrowDown':
				case 'ArrowUp':
				case 'Enter':
				case ' ':
				case 'Spacebar':
					e.preventDefault();
					openList();
					break;
				default:
					if ( e.key && e.key.length === 1 ) {
						typeAhead( e.key );
					}
			}
		} );

		list.addEventListener( 'keydown', function ( e ) {
			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					setActive( activeIdx + 1 );
					break;
				case 'ArrowUp':
					e.preventDefault();
					setActive( activeIdx - 1 );
					break;
				case 'Home':
					e.preventDefault();
					setActive( 0 );
					break;
				case 'End':
					e.preventDefault();
					setActive( items.length - 1 );
					break;
				case 'Enter':
				case ' ':
				case 'Spacebar':
					e.preventDefault();
					commit( activeIdx );
					closeList( true );
					break;
				case 'Escape':
					e.preventDefault();
					closeList( true );
					break;
				case 'Tab':
					closeList( false );
					break;
				default:
					if ( e.key && e.key.length === 1 ) {
						e.preventDefault();
						typeAhead( e.key );
					}
			}
		} );

		items.forEach( function ( li, i ) {
			li.addEventListener( 'click', function () {
				commit( i );
				closeList( true );
			} );
			li.addEventListener( 'mousemove', function () {
				setActive( i );
			} );
		} );

		/*
		 * Insert the custom control right after the native select, then hide
		 * the native one visually (it stays in the DOM as the value holder /
		 * no-JS fallback).
		 */
		select.parentNode.insertBefore( wrap, select.nextSibling );
		wrap.appendChild( button );
		wrap.appendChild( list );
		select.classList.add( 'tt-statussel__native' );
		select.setAttribute( 'tabindex', '-1' );
		select.setAttribute( 'aria-hidden', 'true' );

		markSelected( select.selectedIndex < 0 ? 0 : select.selectedIndex );
		paintButton();

		/* If something else changes the native select, reflect it. */
		select.addEventListener( 'change', function () {
			markSelected( select.selectedIndex );
			paintButton();
		} );
	}

	ready( function () {
		var selects = document.querySelectorAll( '[data-tt-status-select]' );
		for ( var i = 0; i < selects.length; i++ ) {
			enhance( selects[ i ] );
		}
	} );
} )();
