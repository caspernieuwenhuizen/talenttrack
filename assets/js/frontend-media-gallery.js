/**
 * TalentTrack media gallery (#2594, epic #2589).
 *
 * The lightbox and the remove action for `.tt-media-gallery`.
 *
 * The dialog is a native `<dialog>`, so the focus trap, the Escape key
 * and the inertness of the page behind all come from the browser rather
 * than from code here that would be subtly wrong. What is added is arrow
 * navigation between items and making sure a video actually stops when
 * the viewer closes — a `<video>` left in the DOM keeps playing, and a
 * clip talking from an invisible dialog is a genuinely startling bug.
 */
( function () {
	'use strict';

	var CFG = window.TT_MediaGallery || {};
	var I18N = CFG.i18n || {};

	function t( key, fallback ) {
		return I18N[ key ] || fallback || '';
	}

	function Gallery( root ) {
		this.root = root;
		this.dialog = document.querySelector( '[data-role="lightbox"]' );
		this.stage = this.dialog ? this.dialog.querySelector( '[data-role="stage"]' ) : null;
		this.caption = this.dialog ? this.dialog.querySelector( '[data-role="caption"]' ) : null;
		this.index = -1;
		this.bind();
	}

	Gallery.prototype.openers = function () {
		return Array.prototype.slice.call( this.root.querySelectorAll( '[data-role="open"]' ) );
	};

	Gallery.prototype.bind = function () {
		var self = this;

		this.root.addEventListener( 'click', function ( e ) {
			var open = e.target.closest( '[data-role="open"]' );
			if ( open && open.tagName === 'BUTTON' ) {
				e.preventDefault();
				self.show( self.openers().indexOf( open ) );
				return;
			}

			var del = e.target.closest( '[data-role="delete"]' );
			if ( del ) {
				e.preventDefault();
				self.remove( del );
			}
		} );

		// Tagging is a change event, not a click: a checkbox toggled by
		// keyboard has to save too.
		this.root.addEventListener( 'change', function ( e ) {
			var box = e.target.closest( '[data-role="tag-player"]' );
			if ( box ) self.tag( box );
		} );

		if ( ! this.dialog ) return;

		this.dialog.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-role="close"]' ) ) { self.close(); return; }
			if ( e.target.closest( '[data-role="prev"]' ) ) { self.step( -1 ); return; }
			if ( e.target.closest( '[data-role="next"]' ) ) { self.step( 1 ); return; }
			// A click on the backdrop itself (not on the content) closes.
			if ( e.target === self.dialog ) self.close();
		} );

		this.dialog.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowLeft' ) { e.preventDefault(); self.step( -1 ); }
			if ( e.key === 'ArrowRight' ) { e.preventDefault(); self.step( 1 ); }
		} );

		// `close` fires for Escape too, so cleanup lives in one place.
		this.dialog.addEventListener( 'close', function () { self.clearStage(); } );
	};

	Gallery.prototype.show = function ( index ) {
		var items = this.openers();
		if ( index < 0 || index >= items.length || ! this.dialog ) return;

		this.index = index;
		var node = items[ index ];

		this.clearStage();

		var kind = node.getAttribute( 'data-kind' );
		var src = node.getAttribute( 'data-src' );
		var title = node.getAttribute( 'data-title' ) || '';
		var when = node.getAttribute( 'data-when' ) || '';

		if ( kind === 'video' ) {
			var video = document.createElement( 'video' );
			video.src = src;
			video.controls = true;
			video.playsInline = true;
			// metadata, not auto: opening the viewer should not commit the
			// coach's data plan to the whole clip before they press play.
			video.preload = 'metadata';
			video.className = 'tt-media-lightbox__media';
			this.stage.appendChild( video );
		} else {
			var img = document.createElement( 'img' );
			img.src = src;
			img.alt = title;
			img.className = 'tt-media-lightbox__media';
			this.stage.appendChild( img );
		}

		if ( this.caption ) {
			this.caption.textContent = when ? title + ' — ' + when : title;
		}

		if ( ! this.dialog.open ) {
			if ( typeof this.dialog.showModal === 'function' ) {
				this.dialog.showModal();
			} else {
				this.dialog.setAttribute( 'open', 'open' );
			}
		}
	};

	Gallery.prototype.step = function ( delta ) {
		var items = this.openers();
		if ( ! items.length ) return;
		var next = ( this.index + delta + items.length ) % items.length;
		this.show( next );
	};

	Gallery.prototype.close = function () {
		if ( ! this.dialog ) return;
		if ( typeof this.dialog.close === 'function' && this.dialog.open ) {
			this.dialog.close();
		} else {
			this.dialog.removeAttribute( 'open' );
			this.clearStage();
		}
	};

	/**
	 * Emptying the stage is what actually stops playback — pausing is not
	 * enough if the element stays around, and a hidden video that keeps
	 * talking is worse than one that never opened.
	 */
	Gallery.prototype.clearStage = function () {
		if ( ! this.stage ) return;
		var video = this.stage.querySelector( 'video' );
		if ( video ) {
			try { video.pause(); } catch ( e ) {}
			video.removeAttribute( 'src' );
			try { video.load(); } catch ( e ) {}
		}
		this.stage.innerHTML = '';
	};

	Gallery.prototype.remove = function ( button ) {
		var uuid = button.getAttribute( 'data-uuid' );
		if ( ! uuid ) return;

		if ( ! window.confirm( t( 'confirmDelete', 'Remove this permanently?' ) ) ) return;

		var tile = button.closest( '.tt-media-tile' );
		button.disabled = true;

		var xhr = new XMLHttpRequest();
		xhr.open( 'DELETE', CFG.root + '/media/' + uuid + '?hard=1', true );
		xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );

		xhr.addEventListener( 'load', function () {
			if ( xhr.status >= 200 && xhr.status < 300 ) {
				if ( tile ) tile.remove();
				return;
			}
			button.disabled = false;
			window.alert( t( 'deleteFailed', 'It could not be removed.' ) );
		} );

		xhr.addEventListener( 'error', function () {
			button.disabled = false;
			window.alert( t( 'deleteFailed', 'It could not be removed.' ) );
		} );

		xhr.send();
	};

	/**
	 * Attach or detach one player, immediately.
	 *
	 * No Save button: a checkbox that needs confirming elsewhere is a
	 * checkbox people forget to confirm. The box reverts on failure, so
	 * what is on screen is always what the server holds.
	 */
	Gallery.prototype.tag = function ( box ) {
		var details = box.closest( '[data-role="tag"]' );
		if ( ! details ) return;

		var uuid = details.getAttribute( 'data-uuid' );
		var playerId = box.getAttribute( 'data-player-id' );
		var linkId = box.getAttribute( 'data-link-id' );
		var wanted = box.checked;
		var self = this;

		box.disabled = true;

		var xhr = new XMLHttpRequest();
		if ( wanted ) {
			xhr.open( 'POST', CFG.root + '/media/' + uuid + '/links', true );
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
		} else {
			xhr.open( 'DELETE', CFG.root + '/media/' + uuid + '/links/' + linkId, true );
		}
		xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );

		xhr.addEventListener( 'load', function () {
			box.disabled = false;

			if ( xhr.status < 200 || xhr.status >= 300 ) {
				box.checked = ! wanted; // the server did not agree; say so
				window.alert( t( 'tagFailed', 'That tag could not be saved.' ) );
				return;
			}

			if ( wanted ) {
				var body = null;
				try { body = JSON.parse( xhr.responseText ); } catch ( e ) { body = null; }
				var links = body && body.data && body.data.links ? body.data.links : [];
				for ( var i = 0; i < links.length; i++ ) {
					if ( String( links[ i ].entity_id ) === String( playerId ) && links[ i ].entity_type === 'player' ) {
						box.setAttribute( 'data-link-id', links[ i ].id );
					}
				}
			} else {
				box.setAttribute( 'data-link-id', '0' );
			}

			self.refreshTagSummary( details );
		} );

		xhr.addEventListener( 'error', function () {
			box.disabled = false;
			box.checked = ! wanted;
			window.alert( t( 'tagFailed', 'That tag could not be saved.' ) );
		} );

		xhr.send( wanted ? JSON.stringify( { entity_type: 'player', entity_id: parseInt( playerId, 10 ) } ) : null );
	};

	Gallery.prototype.refreshTagSummary = function ( details ) {
		var summary = details.querySelector( '.tt-media-tag__summary' );
		if ( ! summary ) return;

		var checked = details.querySelectorAll( '[data-role="tag-player"]:checked' ).length;

		if ( checked === 0 ) {
			summary.textContent = t( 'tagNone', 'Tag players' );
		} else if ( checked === 1 ) {
			summary.textContent = t( 'tagOne', '1 player tagged' );
		} else {
			summary.textContent = ( t( 'tagCount', '%d players tagged' ) ).replace( '%d', checked );
		}
	};

	/**
	 * Show an upload the moment it lands, instead of after a reload (#2742).
	 *
	 * The markup comes from the server as `tile_html`. Building it here from
	 * the JSON payload was the obvious alternative and is a trap: the
	 * payload's `_links` deliberately carry no nonce, so an `<img src>` set
	 * from one is answered 401 — the very bug #2715 fixed.
	 *
	 * Listening on `document` rather than on the gallery: the uploader is
	 * rendered as a sibling of `.tt-media-gallery`, so the event bubbles
	 * past the grid and never reaches it. The target is matched from the
	 * event instead.
	 */
	function insertAdded( e ) {
		var detail = e.detail || {};
		var media = detail.media;

		if ( ! media || ! media.tile_html ) return;

		// Both values are server-rendered data attributes, but they are
		// going into a selector — keep them to the shapes we expect.
		var type = String( detail.entityType || '' );
		var id = parseInt( detail.entityId, 10 );

		if ( ! /^[a-z_]+$/.test( type ) || ! ( id > 0 ) ) return;

		var gallery = document.querySelector(
			'.tt-media-gallery[data-entity-type="' + type + '"][data-entity-id="' + id + '"]'
		);
		if ( ! gallery ) return;

		var grid = gallery.querySelector( '[data-role="grid"]' );
		if ( ! grid ) return;

		// Newest first, matching the server's ordering.
		grid.insertAdjacentHTML( 'afterbegin', media.tile_html );

		var empty = gallery.querySelector( '[data-role="empty"]' );
		if ( empty ) empty.remove();
	}

	document.addEventListener( 'tt-media:added', insertAdded );

	function init( scope ) {
		var nodes = ( scope || document ).querySelectorAll( '.tt-media-gallery' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			if ( node.getAttribute( 'data-tt-bound' ) === '1' ) return;
			node.setAttribute( 'data-tt-bound', '1' );
			new Gallery( node );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { init(); } );
	} else {
		init();
	}

	window.TT = window.TT || {};
	window.TT.mediaGallery = { init: init };
} )();
