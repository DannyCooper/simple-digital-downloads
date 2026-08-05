( function ( $ ) {
	'use strict';

	var frame;
	var zeroDecimalCurrencies = ( window.sddMetaBoxes && window.sddMetaBoxes.zeroDecimalCurrencies ) || [];
	var currency = ( window.sddMetaBoxes && window.sddMetaBoxes.currency ) || 'USD';

	function getTemplate() {
		var el = document.getElementById( 'tmpl-sdd-file-row' );
		return el ? el.textContent : '';
	}

	function parsePriceToMinor( price ) {
		var normalized = String( price ).trim().replace( ',', '.' );
		if ( ! normalized || ! /^[0-9]*\.?[0-9]+$/.test( normalized ) ) {
			return 0;
		}
		var amount = parseFloat( normalized );
		if ( isNaN( amount ) ) {
			return 0;
		}
		if ( zeroDecimalCurrencies.indexOf( currency.toUpperCase() ) !== -1 ) {
			return Math.round( amount );
		}
		return Math.round( amount * 100 );
	}

	function collectDownloadMeta() {
		var fileIds = [];
		$( '#sdd-files-list input[name="sdd_files[]"]' ).each( function () {
			var id = parseInt( this.value, 10 );
			if ( id ) {
				fileIds.push( id );
			}
		} );

		return {
			price: $( '#sdd_price' ).val() || '',
			files: fileIds,
			text_content: $( '#sdd_text_content' ).val() || '',
		};
	}

	function notifyDownloadMetaChange() {
		document.dispatchEvent( new CustomEvent( 'sdd-download-meta-change' ) );
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
				notifyDownloadMetaChange();
			} );

			frame.open();
		} );

		$row.find( '.sdd-remove-file' ).on( 'click', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.sdd-file-row' ).remove();
			notifyDownloadMetaChange();
		} );
	}

	if ( window.wp && wp.hooks ) {
		wp.hooks.addFilter( 'editor.PreSavePost', 'sdd/sync-download-meta', function ( edits ) {
			if ( ! document.getElementById( 'sdd_price' ) ) {
				return edits;
			}

			var meta = collectDownloadMeta();
			edits.meta = Object.assign( {}, edits.meta || {}, {
				_sdd_price: parsePriceToMinor( meta.price ),
				_sdd_files: meta.files,
				_sdd_text_content: meta.text_content,
			} );

			return edits;
		} );

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
			update: notifyDownloadMetaChange,
		} );

		$( '#sdd-add-file' ).on( 'click', function ( e ) {
			e.preventDefault();
			var html = getTemplate().replace( /\{\{ data\.id \}\}/g, '' ).replace( /\{\{ data\.filename \}\}/g, '' );
			var $row = $( html );
			$( '#sdd-files-list' ).append( $row );
			bindRow( $row );
			$row.find( '.sdd-select-file' ).trigger( 'click' );
		} );

		$( '#sdd_price, #sdd_text_content' ).on( 'input change', notifyDownloadMetaChange );
	} );
}( jQuery ) );
