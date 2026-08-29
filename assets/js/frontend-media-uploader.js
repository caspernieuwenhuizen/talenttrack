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
 *   - **Paste is bound on the document, not on the drop zone** (#3092).
 *     A drop zone cannot hold focus, and someone who has just taken a
 *     screenshot expects Ctrl+V to land it without clicking first.
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

	// Every uploader on the page, so the document-level paste handler can
	// work out which one a pasted image belongs to (#3092).
	var INSTANCES = [];

	var EXTENSIONS = {
		'image/png': 'png',
		'image/jpeg': 'jpg',
		'image/webp': 'webp',
		'image/gif': 'gif'
	};

	function two( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	/**
	 * A clipboard image is named `image.png` or nothing at all, and a grid
	 * of tiles all reading "Photo" is no better. The moment it was pasted
	 * is the one thing that tells them apart, and the title stays editable
	 * afterwards like any other.
	 */
	function stamp() {
		var d = new Date();
		return d.getFullYear() + '-' + two( d.getMonth() + 1 ) + '-' + two( d.getDate() )
			+ ' ' + two( d.getHours() ) + '-' + two( d.getMinutes() ) + '-' + two( d.getSeconds() );
	}

	/**
	 * Wrap a clipboard blob so it behaves like a picked file — the queue
	 * row, the size check and the upload part all read `name`.
	 */
	function asFile( blob, name ) {
		try {
			return new File( [ blob ], name, { type: blob.type } );
		} catch ( e ) {
			// No File constructor: the blob still uploads, it just carries
			// its name on the form part rather than on itself.
			blob.name = name;
			return blob;
		}
	}

	/**
	 * A pasted image must clear the same policy a picked one does. The
	 * `accept` list is the policy's answer for this target — an explicit
	 * list of MIME types, no wildcards — so a screenshot cannot land on a
	 * documents-only target such as a course submission. The server checks
	 * again; this only spares the coach a round trip.
	 */
	function acceptsType( root, type ) {
		var input = root.querySelector( '[data-role="file"]' );
		var accept = input ? ( input.getAttribute( 'accept' ) || '' ) : '';
		if ( accept === '' ) return true;
		return accept.replace( /\s+/g, '' ).split( ',' ).indexOf( type ) !== -1;
	}

	function isTextEntry( node ) {
		if ( ! node || ! node.tagName ) return false;
		if ( node.isContentEditable ) return true;
		if ( node.tagName === 'TEXTAREA' ) return true;
		return node.tagName === 'INPUT' && node.type !== 'file';
	}

	/**
	 * Which uploader a paste belongs to: the one holding focus, or the
	 * only one on the page. Several uploaders and no focus is genuinely
	 * ambiguous, and guessing would drop a photo on the wrong record.
	 */
	function pasteTarget() {
		var active = document.activeElement;

		for ( var i = 0; i < INSTANCES.length; i++ ) {
			if ( active && INSTANCES[ i ].root.contains( active ) ) return INSTANCES[ i ];
		}

		return INSTANCES.length === 1 ? INSTANCES[ 0 ] : null;
	}

	function onPaste( e ) {
		if ( ! e.clipboardData ) return;

		// The link box, and any other field, keeps its ordinary paste.
		if ( isTextEntry( e.target ) ) return;

		var images = [];
		var seen = {};

		function consider( file ) {
			if ( ! file || ! file.type || file.type.indexOf( 'image/' ) !== 0 ) return;
			var key = ( file.name || '' ) + ':' + file.size + ':' + file.type;
			if ( seen[ key ] ) return;
			seen[ key ] = true;
			images.push( file );
		}

		var items = e.clipboardData.items || [];
		for ( var i = 0; i < items.length; i++ ) {
			if ( items[ i ].kind === 'file' ) consider( items[ i ].getAsFile() );
		}

		// A file copied in Explorer or Finder arrives here rather than as
		// an item, and the same image can appear in both.
		var files = e.clipboardData.files || [];
		for ( var j = 0; j < files.length; j++ ) consider( files[ j ] );

		// Nothing to upload: leave the paste alone rather than swallowing
		// it, so pasting text anywhere on the page still behaves.
		if ( images.length === 0 ) return;

		var uploader = pasteTarget();
		if ( ! uploader ) return;

		e.preventDefault();
		images.forEach( function ( image ) { uploader.acceptPasted( image ); } );
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
		this.refusal = root.getAttribute( 'data-refusal' ) || '';
		this.added = [];
		this.bind();

		INSTANCES.push( this );
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

	/**
	 * A screenshot straight off the clipboard (#3092). It goes through the
	 * same queue, size check and cancel button as anything else — the only
	 * difference is that it arrives without a name, so it is given one.
	 */
	Uploader.prototype.acceptPasted = function ( blob ) {
		if ( ! acceptsType( this.root, blob.type ) ) {
			this.say( this.refusal || t( 'pasteRefused', 'That kind of file cannot be attached here.' ) );
			return;
		}

		var when = stamp();
		var ext = EXTENSIONS[ blob.type ] || 'png';

		this.upload(
			asFile( blob, 'screenshot-' + when.replace( ' ', '-' ) + '.' + ext ),
			( t( 'pastedTitle', 'Screenshot %s' ) ).replace( '%s', when )
		);
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

	Uploader.prototype.upload = function ( file, title ) {
		var self = this;
		var row = this.row( title || file.name );

		if ( this.maxBytes && file.size > this.maxBytes ) {
			this.fail( row, t( 'tooLarge', 'This file is larger than the server accepts.' ) );
			return;
		}

		posterFor( file ).then( function ( poster ) {
			var form = new FormData();
			form.append( 'file', file, file.name );
			form.append( 'entity_type', self.entityType );
			form.append( 'entity_id', self.entityId );
			if ( title ) form.append( 'title', title );
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

		// #2611 — say what happened to the video's location metadata. The
		// server decides the wording; the uploader only decides whether it
		// reads as a confirmation or a warning.
		if ( media.location_notice ) {
			var note = el( 'p', 'tt-media-queue__note', media.location_notice );
			if ( media.location_metadata === 'unreadable' ) {
				note.classList.add( 'tt-media-queue__note--warn' );
			}
			row.li.appendChild( note );
			this.say( media.location_notice );
		}

		this.added.push( media.uuid );
		this.sync();

		// The target rides along because the uploader is a *sibling* of the
		// gallery, not a child of it — the event bubbles past the grid, so
		// the listener matches on these rather than on containment (#2742).
		this.root.dispatchEvent( new CustomEvent( 'tt-media:added', {
			bubbles: true,
			detail: {
				media: media,
				entityType: this.entityType,
				entityId: this.entityId
			}
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

	// Bound once, whatever `init` runs later finds: the handler resolves
	// its uploader at paste time and does nothing when there is none.
	document.addEventListener( 'paste', onPaste );

	window.TT = window.TT || {};
	window.TT.mediaUploader = { init: init };
} )();
