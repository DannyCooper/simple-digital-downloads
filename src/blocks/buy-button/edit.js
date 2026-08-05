import { useEffect, useRef } from '@wordpress/element';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	ComboboxControl,
	Notice,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import useDownloadPreview from './use-download-preview';

const STYLE_DEFAULTS = metadata.attributes.style.default;

function mergeStyleDefaults( style ) {
	const current = style || {};

	return {
		...STYLE_DEFAULTS,
		...current,
		spacing: {
			...STYLE_DEFAULTS.spacing,
			...current.spacing,
			padding: {
				...STYLE_DEFAULTS.spacing?.padding,
				...current.spacing?.padding,
			},
		},
		typography: {
			...STYLE_DEFAULTS.typography,
			...current.typography,
		},
	};
}

export default function Edit( { attributes, setAttributes } ) {
	const { downloadId, buttonText, showPrice, style } = attributes;
	const blockProps = useBlockProps( {
		className: 'sdd-buy-button',
	} );

	const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
	const didAutoSelectDownload = useRef( false );
	const didApplyStyleDefaults = useRef( false );

	useEffect( () => {
		if ( didApplyStyleDefaults.current ) {
			return;
		}

		const merged = mergeStyleDefaults( style );
		if ( JSON.stringify( merged ) === JSON.stringify( style || {} ) ) {
			didApplyStyleDefaults.current = true;
			return;
		}

		didApplyStyleDefaults.current = true;
		setAttributes( { style: merged } );
	}, [ setAttributes, style ] );

	useEffect( () => {
		if ( didAutoSelectDownload.current || downloadId || postType !== 'sdd_download' || ! postId ) {
			return;
		}
		didAutoSelectDownload.current = true;
		setAttributes( { downloadId: postId } );
	}, [ downloadId, postId, postType, setAttributes ] );

	const downloads = useSelect( ( select ) => {
		return select( 'core' ).getEntityRecords( 'postType', 'sdd_download', {
			per_page: -1,
			status: [ 'publish', 'draft', 'pending', 'future', 'private' ],
			context: 'edit',
		} );
	}, [] );

	const options = ( downloads || [] ).map( ( d ) => ( {
		value: String( d.id ),
		label: d.title?.rendered || `#${ d.id }`,
	} ) );

	const { priceMinor, priceDisplay, fileCount } = useDownloadPreview( downloadId, postType, postId );
	const isFree = priceMinor <= 0;

	const warning = downloadId && ! fileCount
		? __( 'This download has no files attached.', 'simple-digital-downloads' )
		: '';

	const defaultText = isFree
		? __( 'Download Free', 'simple-digital-downloads' )
		: __( 'Buy Now', 'simple-digital-downloads' );
	const displayText = buttonText || defaultText;

	return (
		<div className="sdd-buy-button-wrapper">
			<InspectorControls>
				<PanelBody title={ __( 'Buy Button Settings', 'simple-digital-downloads' ) }>
					<VStack spacing={ 4 }>
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
					</VStack>
				</PanelBody>
			</InspectorControls>

			{ warning && <Notice status="warning" isDismissible={ false }>{ warning }</Notice> }

			<button type="button" { ...blockProps }>
				{ displayText }
				{ showPrice && ! isFree && priceDisplay ? ` — ${ priceDisplay }` : '' }
			</button>
		</div>
	);
}
