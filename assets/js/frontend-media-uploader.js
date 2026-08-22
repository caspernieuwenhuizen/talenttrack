/**
 * TalentTrack media uploader (#2593, epic #2589).
 *
 * Drives every `.tt-media-uploader` on the page: the wizard's source step
 * and, from #2594, the inline uploader on a record's media tab.
 *
 * Three things here are deliberate rather than incidental:
 *
 *   - **XHR, not fetch.** `fetch()` still cannot report upload progress.
 *     A coach uploading a match clip over mobile data needs to see that
 *     something is happening, and needs to be able to stop it.
 *   - **The poster frame is grabbed here.** Seeking a video element to
 *     the first frame and drawing it to a canvas gives the server a
 *     thumbnail without any transcoder installed. That is what makes
 *     self-hosted video viable on ordinary WordPress hosting.
 *   - **Size is checked before the request starts.** Discovering a file
 *     is too big after uploading it over a phone connection is the worst
 *     possible time to find out.
 */
( function () {
	'use strict';

	var CFG = window.TT_Media || {};
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

	/**
	 * First frame of a video as a JPEG blob.
	 *
	 * Resolves with null on any failure — a missing poster costs a tile
	 * its preview, never the upload itself, so nothing here is allowed to
	 * reject.
	 */
	function posterFor( file ) {
		return new Promise( function ( resolve ) {
			if ( ! file.type || file.type.indexOf( 'video/' ) !== 0 ) {
				resolve( null );
				return;
			}

			var url = URL.createObjectURL( file );
			var video = document.createElement( 'video' );
			var settled = false;

			function finish( blob ) {
				if ( settled ) return;
				settled = true;
				URL.revokeObjectURL( url );
				resolve( blob );
			}

			video.preload = 'metadata';
			video.muted = true;
			video.playsInline = true;
			video.src = url;

			// Some encoders give a black first frame; a fraction of a
			// second in is a better thumbnail and still effectively the
			// start of the clip.
			video.addEventListener( 'loadeddata', function () {
				try {
					video.currentTime = Math.min( 0.2, ( video.duration || 1 ) / 2 );
				} catch ( e ) {
					finish( null );
				}
			} );

			video.addEventListener( 'seeked', function () {
				try {
					var canvas = document.createElement( 'canvas' );
					canvas.width = video.videoWidth || 640;
					canvas.height = video.videoHeight || 360;
					canvas.getContext( '2d' ).drawImage( video, 0, 0, canvas.width, canvas.height );
					canvas.toBlob( function ( blob ) { finish( blob ); }, 'image/jpeg', 0.8 );
				} catch ( e ) {
					finish( null );
				}
			} );

			video.addEventListener( 'error', function () { finish( null ); } );

			// A file the browser cannot decode must not hang the queue.
			setTimeout( function () { finish( null ); }, 5000 );
		} );
	}

	function Uploader( root ) {
		this.root = root;
		this.entityType = root.getAttribute( 'data-entity-type' );
		this.entityId = root.getAttribute( 'data-entity-id' );
		this.maxBytes = parseInt( root.getAttribute( 'data-max-bytes' ), 10 ) || 0;
		this.queue = root.querySelector( '[data-role="queue"]' );
		this.status = root.querySelector( '[data-role="status"]' );
		this.stateField = root.querySelector( '[data-role="state"]' );
		this.added = [];
		this.bind();
	}

	Uploader.prototype.bind = function () {
		var self = this;

		var input = this.root.querySelector( '[data-role="file"]' );
		if ( input ) {
			input.addEventListener( 'change', function () {
				self.accept( input.files );
				// Reset so picking the same file twice still fires change.
				input.value = '';
			} );
		}

		var zone = this.root.querySelector( '[data-role="dropzone"]' );
		if ( zone ) {
			[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
				zone.addEventListener( evt, function ( e ) {
					e.preventDefault();
					zone.classList.add( 'is-dragging' );
				} );
			} );
			[ 'dragleave', 'drop' ].forEach( function ( evt ) {
				zone.addEventListener( evt, function ( e ) {
					e.preventDefault();
					zone.classList.remove( 'is-dragging' );
				} );
			} );
			zone.addEventListener( 'drop', function ( e ) {
				if ( e.dataTransfer && e.dataTransfer.files ) self.accept( e.dataTransfer.files );
			} );
		}

		var linkBtn = this.root.querySelector( '[data-role="link-add"]' );
		var linkUrl = this.root.querySelector( '[data-role="link-url"]' );
		if ( linkBtn && linkUrl ) {
			linkBtn.addEventListener( 'click', function () { self.addLink( linkUrl ); } );
			linkUrl.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					self.addLink( linkUrl );
				}
			} );
		}
	};

	Uploader.prototype.accept = function ( files ) {
		for ( var i = 0; i < files.length; i++ ) this.upload( files[ i ] );
	};

	Uploader.prototype.row = function ( label ) {
		var li = el( 'li', 'tt-media-queue__item' );
		var name = el( 'span', 'tt-media-queue__name', label );
		var bar = el( 'span', 'tt-media-queue__bar' );
		var fill = el( 'span', 'tt-media-queue__fill' );
		var state = el( 'span', 'tt-media-queue__state', t( 'uploading', 'Uploading…' ) );
		var action = el( 'button', 'tt-media-queue__action', t( 'cancel', 'Cancel' ) );

		action.type = 'button';
		bar.appendChild( fill );
		li.appendChild( name );
		li.appendChild( bar );
		li.appendChild( state );
		li.appendChild( action );
		this.queue.appendChild( li );

		return { li: li, fill: fill, state: state, action: action };
	};

	Uploader.prototype.upload = function ( file ) {
		var self = this;
		var row = this.row( file.name );

		if ( this.maxBytes && file.size > this.maxBytes ) {
			this.fail( row, t( 'tooLarge', 'This file is larger than the server accepts.' ) );
			return;
		}

		posterFor( file ).then( function ( poster ) {
			var form = new FormData();
			form.append( 'file', file );
			form.append( 'entity_type', self.entityType );
			form.append( 'entity_id', self.entityId );
			if ( poster ) form.append( 'poster', poster, 'poster.jpg' );

			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', CFG.root + '/media', true );
			xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );

			xhr.upload.addEventListener( 'progress', function ( e ) {
				if ( ! e.lengthComputable ) return;
				var pct = Math.round( ( e.loaded / e.total ) * 100 );
				row.fill.style.width = pct + '%'; /* tt-inline-ok */
				row.state.textContent = pct + '%';
			} );

			row.action.addEventListener( 'click', function () {
				xhr.abort();
				row.li.classList.add( 'is-cancelled' );
				row.state.textContent = t( 'cancelled', 'Cancelled' );
				row.action.remove();
			} );

			xhr.addEventListener( 'load', function () {
				var body = null;
				try { body = JSON.parse( xhr.responseText ); } catch ( e ) { body = null; }

				if ( xhr.status >= 200 && xhr.status < 300 && body && body.data ) {
					self.succeed( row, body.data );
					return;
				}

				var message = t( 'failed', 'Could not be added' );
				if ( body && body.errors && body.errors[ 0 ] && body.errors[ 0 ].message ) {
					message = body.errors[ 0 ].message;
				}
				self.fail( row, message );
			} );

			xhr.addEventListener( 'error', function () {
				self.fail( row, t( 'networkError', 'The upload failed.' ) );
			} );

			xhr.send( form );
		} );
	};

	Uploader.prototype.addLink = function ( input ) {
		var self = this;
		var url = ( input.value || '' ).trim();

		if ( ! url ) {
			this.say( t( 'linkNeeded', 'Paste a web address first.' ) );
			input.focus();
			return;
		}

		var row = this.row( url );
		row.action.remove();

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', CFG.root + '/media', true );
		xhr.setRequestHeader( 'X-WP-Nonce', CFG.nonce );
		xhr.setRequestHeader( 'Content-Type', 'application/json' );

		xhr.addEventListener( 'load', function () {
			var body = null;
			try { body = JSON.parse( xhr.responseText ); } catch ( e ) { body = null; }

			if ( xhr.status >= 200 && xhr.status < 300 && body && body.data ) {
				input.value = '';
				self.succeed( row, body.data );
				return;
			}

			var message = t( 'failed', 'Could not be added' );
			if ( body && body.errors && body.errors[ 0 ] && body.errors[ 0 ].message ) {
				message = body.errors[ 0 ].message;
			}
			self.fail( row, message );
		} );

		xhr.addEventListener( 'error', function () {
			self.fail( row, t( 'networkError', 'The upload failed.' ) );
		} );

		xhr.send( JSON.stringify( {
			entity_type: this.entityType,
			entity_id: this.entityId,
			external_url: url
		} ) );
	};

	Uploader.prototype.succeed = function ( row, media ) {
		row.li.classList.add( 'is-done' );
		row.fill.style.width = '100%'; /* tt-inline-ok */
		row.state.textContent = t( 'done', 'Added' );
		if ( row.action ) row.action.remove();

		if ( media.title ) row.li.querySelector( '.tt-media-queue__name' ).textContent = media.title;

		this.added.push( media.uuid );
		this.sync();

		this.root.dispatchEvent( new CustomEvent( 'tt-media:added', {
			bubbles: true,
			detail: { media: media }
		} ) );
	};

	Uploader.prototype.fail = function ( row, message ) {
		row.li.classList.add( 'is-failed' );
		row.state.textContent = message;
		if ( row.action ) row.action.remove();
		this.say( message );
	};

	Uploader.prototype.say = function ( message ) {
		if ( this.status ) this.status.textContent = message;
	};

	/**
	 * Publish what has been added into the wizard's hidden field, so the
	 * next step knows which items to describe. Without JS there is simply
	 * nothing to carry, which is the correct degraded behaviour.
	 */
	Uploader.prototype.sync = function () {
		if ( this.stateField ) this.stateField.value = this.added.join( ',' );

		var count = this.added.length;
		if ( count > 0 ) {
			this.say( ( t( 'addedCount', '%d added' ) ).replace( '%d', count ) );
		}
	};

	function init( scope ) {
		var nodes = ( scope || document ).querySelectorAll( '.tt-media-uploader' );
		Array.prototype.forEach.call( nodes, function ( node ) {
			if ( node.getAttribute( 'data-tt-bound' ) === '1' ) return;
			node.setAttribute( 'data-tt-bound', '1' );
			new Uploader( node );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () { init(); } );
	} else {
		init();
	}

	window.TT = window.TT || {};
	window.TT.mediaUploader = { init: init };
} )();
