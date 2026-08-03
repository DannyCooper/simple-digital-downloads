( function ( $ ) {
	'use strict';

	var frame;

	function getTemplate() {
		return $( '#tmpl-sdd-file-row' ).html();
	}

	function bindRow( $row ) {
		$row.find( '.sdd-select-file' ).on( 'click', function ( e ) {
			e.preventDefault();
			var $btn = $( this );
			var $li  = $btn.closest( '.sdd-file-row' );

			if ( frame ) {
				frame.close();
			}

			frame = wp.media( {
				title: sddMetaBoxes.selectFile,
				button: { text: sddMetaBoxes.selectFile },
				multiple: false,
			} );

			frame.on( 'open', function () {
				window.sdd_upload_to_protected = true;
			} );

			frame.on( 'close', function () {
				window.sdd_upload_to_protected = false;
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				$li.find( 'input[type="hidden"]' ).val( attachment.id );
				$li.find( '.sdd-file-name' ).text( attachment.filename || attachment.title );
			} );

			frame.open();
		} );

		$row.find( '.sdd-remove-file' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.sdd-file-row' ).remove();
		} );
	}

	if ( window.wp && wp.hooks ) {
		wp.hooks.addFilter( 'plupload_default_params', 'sdd/protected-upload', function ( params ) {
			if ( window.sdd_upload_to_protected ) {
				params.sdd_protected = '1';
			}
			return params;
		} );
	}

	$( function () {
		$( '#sdd-files-list .sdd-file-row' ).each( function () {
			bindRow( $( this ) );
		} );

		$( '#sdd-files-list' ).sortable( {
			handle: '.sdd-drag-handle',
		} );

		$( '#sdd-add-file' ).on( 'click', function ( e ) {
			e.preventDefault();
			var html = getTemplate().replace( /\{\{ data\.id \}\}/g, '' ).replace( /\{\{ data\.filename \}\}/g, '' );
			var $row = $( html );
			$( '#sdd-files-list' ).append( $row );
			bindRow( $row );
		} );
	} );
}( jQuery ) );
