/* TalentTrack — Methodology media picker (#0027 expansion).
 *
 * Hooks every "Afbeelding kiezen…" button in a methodology edit form
 * up to the WordPress media library. On selection, appends a hidden
 * tt_assets_add[] input + a thumbnail preview so the form submits the
 * new attachment IDs alongside the rest of the entity save. */
( function ( $ ) {
    'use strict';

    function open_media_modal( $button ) {
        var $picker = $button.closest( '.tt-methodology-media' );
        if ( ! $picker.length ) return;

        var frame = wp.media( {
            title:    ( window.TT_MethodologyMedia && TT_MethodologyMedia.modalTitle )  || 'Pick image',
            button:   { text: ( window.TT_MethodologyMedia && TT_MethodologyMedia.modalButton ) || 'Add' },
            multiple: 'add',
            library:  { type: 'image' },
        } );

        frame.on( 'select', function () {
            var attachments = frame.state().get( 'selection' ).toJSON();
            var $staged     = $picker.find( '[data-tt-methodology-media-staged]' );
            attachments.forEach( function ( att ) {
                var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                var $card = $(
                    '<div class="tt-methodology-media-staged-card" style="border:1px dashed #1a4a8a; border-radius:6px; padding:6px; background:#f0f6ff; display:flex; gap:8px; align-items:center; max-width:260px;">' +
                        '<img src="' + thumb + '" style="width:48px; height:48px; object-fit:cover; border-radius:3px;" alt="" />' +
                        '<div style="flex:1; min-width:0;">' +
                            '<div style="font-size:12px; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + ( att.title || ( window.TT_MethodologyMedia && TT_MethodologyMedia.imageAlt ) || 'Image' ) + '</div>' +
                            '<div style="font-size:11px; color:#5b6470;">' + ( ( window.TT_MethodologyMedia && TT_MethodologyMedia.pendingLabel ) || 'Will be added on save' ) + '</div>' +
                        '</div>' +
                        '<button type="button" class="button button-small button-link-delete" style="align-self:flex-start;" data-remove>×</button>' +
                        '<input type="hidden" name="tt_assets_add[]" value="' + att.id + '" />' +
                    '</div>'
                );
                $staged.append( $card );
            } );
        } );

        frame.open();
    }

    $( document ).on( 'click', '[data-tt-methodology-media-add]', function ( e ) {
        e.preventDefault();
        open_media_modal( $( this ) );
    } );

    $( document ).on( 'click', '.tt-methodology-media-staged-card [data-remove]', function ( e ) {
        e.preventDefault();
        $( this ).closest( '.tt-methodology-media-staged-card' ).remove();
    } );
} )( jQuery );
