import { useEffect } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, ComboboxControl, Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { downloadId, buttonText, showPrice } = attributes;
	const blockProps = useBlockProps( {
		className: 'sdd-buy-button',
	} );

	const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );

	useEffect( () => {
		if ( ! downloadId && postType === 'sdd_download' && postId ) {
			setAttributes( { downloadId: postId } );
		}
	}, [ downloadId, postId, postType, setAttributes ] );

	const downloads = useSelect( ( select ) => {
		return select( 'core' ).getEntityRecords( 'postType', 'sdd_download', { per_page: -1, status: 'publish' } );
	}, [] );

	const options = ( downloads || [] ).map( ( d ) => ( {
		value: String( d.id ),
		label: d.title?.rendered || `#${ d.id }`,
	} ) );

	const selected = useSelect(
		( select ) => {
			if ( ! downloadId ) {
				return null;
			}
			return select( 'core' ).getEntityRecord( 'postType', 'sdd_download', downloadId );
		},
		[ downloadId ]
	);

	const price = selected?.meta?._sdd_price || 0;
	const priceDisplay = selected?.sdd_price_display || '';
	const fileCount = selected?.sdd_file_count || 0;
	const isFree = price <= 0;

	let warning = '';
	if ( selected ) {
		if ( ! fileCount ) {
			warning = __( 'This download has no files attached.', 'simple-digital-downloads' );
		}
	}

	const defaultText = isFree
		? __( 'Download Free', 'simple-digital-downloads' )
		: __( 'Buy Now', 'simple-digital-downloads' );
	const displayText = buttonText || defaultText;

	return (
		<div className="sdd-buy-button-wrapper">
			<InspectorControls>
				<PanelBody title={ __( 'Buy Button Settings', 'simple-digital-downloads' ) }>
					<ComboboxControl
						label={ __( 'Download', 'simple-digital-downloads' ) }
						value={ downloadId ? String( downloadId ) : '' }
						options={ options }
						onChange={ ( val ) => setAttributes( { downloadId: val ? parseInt( val, 10 ) : 0 } ) }
					/>
					<TextControl
						label={ __( 'Button Text', 'simple-digital-downloads' ) }
						value={ buttonText }
						onChange={ ( val ) => setAttributes( { buttonText: val } ) }
						placeholder={ defaultText }
					/>
					<ToggleControl
						label={ __( 'Show Price', 'simple-digital-downloads' ) }
						checked={ showPrice }
						onChange={ ( val ) => setAttributes( { showPrice: val } ) }
					/>
				</PanelBody>
			</InspectorControls>

			{ warning && <Notice status="warning" isDismissible={ false }>{ warning }</Notice> }

			<button type="button" { ...blockProps } disabled>
				{ displayText }
				{ showPrice && ! isFree && priceDisplay ? ` — ${ priceDisplay }` : '' }
			</button>
		</div>
	);
}
