/**
 * TalentTrack player tag field (#3093).
 *
 * Drives every `.tt-tagfield`: the wizard's Details step, where the picks
 * are applied to the whole batch on submit, and a media tile, where each
 * pick is saved the moment it is made.
 *
 * Two things here are deliberate:
 *
 *   - **The chip list is the record; the text is display.** Typing `@` in
 *     the description opens the same list and inserts the name into the
 *     sentence, but what is stored is the chip. Editing that sentence
 *     afterwards never adds or removes a tag — a tag is a player relation,
 *     and diffing prose to decide whether a relation still holds is a
 *     coin toss that silently untags people.
 *   - **The roster is already here.** It arrives in the markup because it
 *     is one squad, not a directory. No request per keystroke, so the
 *     control works at the side of a pitch on one bar of signal.
 */
( function () {
	'use strict';

	var CFG = window.TT_MediaTags || {};
	var I18N = CFG.i18n || {};

	function t( key, fallback ) {
		return I18N[ key ] || fallback || '';
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) node.className = className;
		if ( text !== undefined ) node.textContent = text;
		return node;
	}

	function TagField( root ) {
		this.root = root;
		this.mode = root.getAttribute( 'data-mode' ) === 'tile' ? 'tile' : 'wizard';
		this.uuid = root.getAttribute( 'data-uuid' ) || '';
		this.chips = root.querySelector( '[data-role="chips"]' );
		this.input = root.querySelector( '[data-role="tagfield-input"]' );
		this.list = root.querySelector( '[data-role="tagfield-list"]' );
		this.value = root.querySelector( '[data-role="tagfield-value"]' );
		this.status = root.querySelector( '[data-role="tagfield-status"]' );
		this.roster = [];
		this.active = -1;
		this.mentionStart = -1;

		try {
			this.roster = JSON.parse( root.getAttribute( 'data-roster' ) || '[]' );
		} catch ( e ) {
			this.roster = [];
		}

		var selector = root.getAttribute( 'data-mentions' );
		this.mentions = selector ? document.querySelector( selector ) : null;

		this.bind();
		this.syncValue();
	}

	TagField.prototype.bind = function () {
		var self = this;

		this.root.addEventListener( 'click', function ( e ) {
			var remove = e.target.closest( '[data-role="chip-remove"]' );
			if ( ! remove ) return;
			e.preventDefault();
			self.remove( remove.closest( '[data-role="chip"]' ) );
		} );

		if ( this.input ) {
			this.input.addEventListener( 'input', function () {
				self.mentionStart = -1;
				self.open( self.input.value, self.input );
			} );
			this.input.addEventListener( 'focus', function () {
				self.mentionStart = -1;
				self.open( self.input.value, self.input );
			} );
			this.input.addEventListener( 'keydown', function ( e ) { self.key( e, self.input ); } );
			this.input.addEventListener( 'blur', function () {
				// After the click that picked an option, not before it.
				setTimeout( function () { self.close(); }, 150 );
			} );
		}

		if ( this.list ) {
			this.list.addEventListener( 'mousedown', function ( e ) {
				var option = e.target.closest( '[data-player-id]' );
				if ( ! option ) return;
				e.preventDefault();
				self.pick( parseInt( option.getAttribute( 'data-player-id' ), 10 ) );
			} );
		}

		// `@` in the description opens the same list against the caret.
		if ( this.mentions ) {
			this.mentions.addEventListener( 'input', function () { self.mention(); } );
			this.mentions.addEventListener( 'keydown', function ( e ) {
				if ( self.mentionStart === -1 ) return;
				self.key( e, self.mentions );
			} );
			this.mentions.addEventListener( 'blur', function () {
				setTimeout( function () { self.close(); }, 150 );
			} );
		}
	};

	/** Players not already tagged, matching what has been typed. */
	TagField.prototype.matches = function ( query ) {
		var taken = this.selected();
		var needle = ( query || '' ).trim().toLowerCase();

		return this.roster.filter( function ( player ) {
			if ( taken.indexOf( player.id ) !== -1 ) return false;
			if ( needle === '' ) return true;
			return player.name.toLowerCase().indexOf( needle ) !== -1;
		} );
	};

	TagField.prototype.open = function ( query, anchor ) {
		if ( ! this.list ) return;

		var options = this.matches( query );
		var self = this;

		this.list.innerHTML = '';
		this.active = -1;

		if ( options.length === 0 ) {
			var none = el( 'li', 'tt-tagfield__none', t( 'noMatches', 'No players match' ) );
			this.list.appendChild( none );
		}

		options.forEach( function ( player, index ) {
			var option = el( 'li', 'tt-tagfield__option', player.name );
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'data-player-id', player.id );
			option.id = self.list.id + '-' + index;
			self.list.appendChild( option );
		} );

		this.list.hidden = false;
		this.root.classList.add( 'is-open' );
		if ( this.input ) this.input.setAttribute( 'aria-expanded', 'true' );

		// Against the description the list belongs under that box, not
		// under an input the reader is not looking at.
		this.list.classList.toggle( 'tt-tagfield__list--mentions', anchor === this.mentions );
		if ( anchor === this.mentions && this.mentions ) {
			this.mentions.setAttribute( 'aria-expanded', 'true' );
		}
	};

	TagField.prototype.close = function () {
		if ( ! this.list ) return;
		this.list.hidden = true;
		this.active = -1;
		this.mentionStart = -1;
		this.root.classList.remove( 'is-open' );
		if ( this.input ) this.input.setAttribute( 'aria-expanded', 'false' );
		if ( this.mentions ) this.mentions.removeAttribute( 'aria-expanded' );
	};

	TagField.prototype.options = function () {
		return this.list ? this.list.querySelectorAll( '[data-player-id]' ) : [];
	};

	TagField.prototype.highlight = function ( step ) {
		var options = this.options();
		if ( options.length === 0 ) return;

		this.active = ( this.active + step + options.length ) % options.length;

		for ( var i = 0; i < options.length; i++ ) {
			options[ i ].classList.toggle( 'is-active', i === this.active );
		}

		var current = options[ this.active ];
		current.scrollIntoView( { block: 'nearest' } );

		var owner = this.mentionStart !== -1 && this.mentions ? this.mentions : this.input;
		if ( owner ) owner.setAttribute( 'aria-activedescendant', current.id );
	};

	TagField.prototype.key = function ( e, source ) {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			if ( this.list && this.list.hidden ) this.open( source.value, source );
			this.highlight( 1 );
			return;
		}

		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			this.highlight( -1 );
			return;
		}

		if ( e.key === 'Enter' ) {
			var options = this.options();
			if ( this.list && ! this.list.hidden && options.length > 0 ) {
				e.preventDefault();
				var index = this.active === -1 ? 0 : this.active;
				this.pick( parseInt( options[ index ].getAttribute( 'data-player-id' ), 10 ) );
			}
			return;
		}

		if ( e.key === 'Escape' ) {
			if ( this.list && ! this.list.hidden ) e.preventDefault();
			this.close();
			return;
		}

		// An empty box and one more Backspace takes back the last chip —
		// what every other chip field does.
		if ( e.key === 'Backspace' && source === this.input && source.value === '' ) {
			var chips = this.chips ? this.chips.querySelectorAll( '[data-role="chip"]' ) : [];
			if ( chips.length > 0 ) {
				e.preventDefault();
				this.remove( chips[ chips.length - 1 ] );
			}
		}
	};

	/**
	 * The `@token` immediately before the caret, if there is one. A token
	 * has to start a word, so an email address in the middle of a sentence
	 * does not open a player list.
	 */
	TagField.prototype.mention = function () {
		if ( ! this.mentions ) return;

		var caret = this.mentions.selectionStart;
		var before = this.mentions.value.slice( 0, caret );
		var match = /(^|\s)@([^\s@]{0,40})$/.exec( before );

		if ( ! match ) {
			if ( this.mentionStart !== -1 ) this.close();
			return;
		}

		this.mentionStart = caret - match[ 2 ].length - 1;
		this.open( match[ 2 ], this.mentions );
	};

	TagField.prototype.selected = function () {
		var out = [];
		var chips = this.chips ? this.chips.querySelectorAll( '[data-role="chip"]' ) : [];
		for ( var i = 0; i < chips.length; i++ ) {
			out.push( parseInt( chips[ i ].getAttribute( 'data-player-id' ), 10 ) );
		}
		return out;
	};

	TagField.prototype.player = function ( id ) {
		for ( var i = 0; i < this.roster.length; i++ ) {
			if ( this.roster[ i ].id === id ) return this.roster[ i ];
		}
		return null;
	};

	TagField.prototype.pick = function ( id ) {
		var player = this.player( id );
		if ( ! player || this.selected().indexOf( id ) !== -1 ) return;

		// Picked from the description: the name goes into the sentence in
		// place of what was typed, and the chip below is what is stored.
		if ( this.mentionStart !== -1 && this.mentions ) {
			var caret = this.mentions.selectionStart;
			var value = this.mentions.value;
			this.mentions.value = value.slice( 0, this.mentionStart ) + player.name + ' ' + value.slice( caret );
			var at = this.mentionStart + player.name.length + 1;
			this.mentions.setSelectionRange( at, at );
			this.mentions.focus();
		} else if ( this.input ) {
			this.input.value = '';
			this.input.focus();
		}

		this.close();
		this.add( player );
	};

	TagField.prototype.chip = function ( player, linkId ) {
		var li = el( 'li', 'tt-tagfield__chip' );
		li.setAttribute( 'data-role', 'chip' );
		li.setAttribute( 'data-player-id', player.id );
		li.setAttribute( 'data-link-id', linkId || 0 );

		var button = el( 'button', 'tt-tagfield__remove' );
		button.type = 'button';
		button.setAttribute( 'data-role', 'chip-remove' );
		button.setAttribute( 'aria-label', ( t( 'removeTag', 'Remove %s' ) ).replace( '%s', player.name ) );
		button.appendChild( el( 'span', null, '×' ) );
		button.firstChild.setAttribute( 'aria-hidden', 'true' );

		li.appendChild( el( 'span', 'tt-tagfield__chip-name', player.name ) );
		li.appendChild( button );

		return li;
	};

	TagField.prototype.add = function ( player ) {
		var self = this;
		var chip = this.chip( player, 0 );

		this.chips.appendChild( chip );
		this.syncValue();
		this.say( ( t( 'tagAdded', '%s tagged' ) ).replace( '%s', player.name ) );

		if ( this.mode !== 'tile' ) return;

		chip.classList.add( 'is-saving' );

		this.request( 'POST', '/media/' + this.uuid + '/links', { entity_type: 'player', entity_id: player.id }, function ( body ) {
			chip.classList.remove( 'is-saving' );

			var links = body && body.data && body.data.links ? body.data.links : [];
			for ( var i = 0; i < links.length; i++ ) {
				if ( links[ i ].entity_type === 'player' && String( links[ i ].entity_id ) === String( player.id ) ) {
					chip.setAttribute( 'data-link-id', links[ i ].id );
				}
			}

			self.syncValue();
		}, function () {
			// The server did not agree; what is on screen must not claim
			// a tag that was never stored.
			chip.remove();
			self.syncValue();
			window.alert( t( 'tagFailed', 'That tag could not be saved.' ) );
		} );
	};

	TagField.prototype.remove = function ( chip ) {
		if ( ! chip ) return;

		var self = this;
		var name = chip.querySelector( '.tt-tagfield__chip-name' );
		var label = name ? name.textContent : '';
		var linkId = parseInt( chip.getAttribute( 'data-link-id' ), 10 ) || 0;

		if ( this.mode !== 'tile' || linkId === 0 ) {
			chip.remove();
			this.syncValue();
			this.say( ( t( 'tagRemoved', '%s untagged' ) ).replace( '%s', label ) );
			return;
		}

		var player = this.player( parseInt( chip.getAttribute( 'data-player-id' ), 10 ) );
		var next = chip.nextElementSibling;
		var parent = chip.parentNode;

		chip.remove();
		this.syncValue();
		this.say( ( t( 'tagRemoved', '%s untagged' ) ).replace( '%s', label ) );

		this.request( 'DELETE', '/media/' + this.uuid + '/links/' + linkId, null, null, function () {
			// Put it back where it was rather than at the end, so a failed
			// removal does not quietly reorder the list.
			if ( player ) parent.insertBefore( self.chip( player, linkId ), next );
			self.syncValue();
			window.alert( t( 'tagFailed', 'That tag could not be saved.' ) );
		} );
	};

	TagField.prototype.request = function ( method, path, payload, done, failed ) {
		var xhr = new XMLHttpRequest();
		xhr.open( method, CFG.root + path, true );
		xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );
		if ( payload ) xhr.setRequestHeader( 'Content-Type', 'application/json' );

		xhr.addEventListener( 'load', function () {
			if ( xhr.status < 200 || xhr.status >= 300 ) {
				if ( failed ) failed();
				return;
			}

			var body = null;
			try { body = JSON.parse( xhr.responseText ); } catch ( e ) { body = null; }
			if ( done ) done( body );
		} );

		xhr.addEventListener( 'error', function () {
			if ( failed ) failed();
		} );

		xhr.send( payload ? JSON.stringify( payload ) : null );
	};

	/**
	 * Publish the current picks: into the wizard's hidden field, and into
	 * the tile's disclosure summary so a collapsed control still says how
	 * many players are tagged.
	 */
	TagField.prototype.syncValue = function () {
		var ids = this.selected();

		if ( this.value ) this.value.value = ids.join( ',' );

		var details = this.root.closest( '[data-role="tag"]' );
		var summary = details ? details.querySelector( '.tt-media-tag__summary' ) : null;

		if ( summary ) {
			if ( ids.length === 0 ) {
				summary.textContent = t( 'tagNone', 'Tag players' );
			} else if ( ids.length === 1 ) {
				summary.textContent = t( 'tagOne', '1 player tagged' );
			} else {
				summary.textContent = ( t( 'tagCount', '%d players tagged' ) ).replace( '%d', ids.length );
			}
		}
	};

	TagField.prototype.say = function ( message ) {
		if ( this.status ) this.status.textContent = message;
	};

	function init( scope ) {
		var nodes = ( scope || document ).querySelectorAll( '[data-role="tagfield"]' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			if ( node.getAttribute( 'data-tt-bound' ) === '1' ) return;
			node.setAttribute( 'data-tt-bound', '1' );
			new TagField( node );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { init(); } );
	} else {
		init();
	}

	// Tiles that arrive later — an upload, or another page of the grid —
	// are bound by the gallery once it has inserted them.
	window.TT = window.TT || {};
	window.TT.mediaTags = { init: init };
} )();
