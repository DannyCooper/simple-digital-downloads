import { useEffect, useMemo, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';

const ZERO_DECIMAL_CURRENCIES = [
	'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
];

function parsePriceToMinor( price, currency ) {
	const normalized = String( price ).trim().replace( ',', '.' );
	if ( ! normalized || ! /^[0-9]*\.?[0-9]+$/.test( normalized ) ) {
		return 0;
	}
	const amount = parseFloat( normalized );
	if ( Number.isNaN( amount ) ) {
		return 0;
	}
	if ( ZERO_DECIMAL_CURRENCIES.includes( currency.toUpperCase() ) ) {
		return Math.round( amount );
	}
	return Math.round( amount * 100 );
}

function formatPriceDisplay( minor, currency ) {
	const code = currency.toUpperCase();
	if ( ZERO_DECIMAL_CURRENCIES.includes( code ) ) {
		return `${ code } ${ minor }`;
	}
	return `${ code } ${ ( minor / 100 ).toFixed( 2 ) }`;
}

function readMetaBoxPreview( currency ) {
	const priceInput = document.getElementById( 'sdd_price' );
	const priceMinor = parsePriceToMinor( priceInput?.value ?? '', currency );
	const fileCount = Array.from(
		document.querySelectorAll( '#sdd-files-list input[name="sdd_files[]"]' )
	).filter( ( input ) => input.value ).length;

	return {
		priceMinor,
		priceDisplay: priceMinor > 0 ? formatPriceDisplay( priceMinor, currency ) : '',
		fileCount,
	};
}

export default function useDownloadPreview( downloadId, postType, postId ) {
	const [ metaBoxTick, setMetaBoxTick ] = useState( 0 );
	const useMetaBoxPreview = postType === 'sdd_download' && downloadId === postId;
	const currency = window.sddBuyButtonEditor?.currency || 'USD';

	useEffect( () => {
		if ( postType !== 'sdd_download' || downloadId !== postId ) {
			return undefined;
		}

		const bump = () => setMetaBoxTick( ( tick ) => tick + 1 );
		document.addEventListener( 'sdd-download-meta-change', bump );

		return () => {
			document.removeEventListener( 'sdd-download-meta-change', bump );
		};
	}, [ downloadId, postId, postType ] );

	const selected = useSelect(
		( select ) => {
			if ( ! downloadId || useMetaBoxPreview ) {
				return null;
			}
			return select( 'core' ).getEntityRecord(
				'postType',
				'sdd_download',
				downloadId,
				{ context: 'edit' }
			);
		},
		[ downloadId, useMetaBoxPreview ]
	);

	return useMemo( () => {
		if ( useMetaBoxPreview ) {
			return readMetaBoxPreview( currency );
		}

		if ( selected ) {
			return {
				priceMinor: Number( selected.meta?._sdd_price ) || 0,
				priceDisplay: selected.sdd_price_display || '',
				fileCount: selected.sdd_file_count || 0,
			};
		}

		return {
			priceMinor: 0,
			priceDisplay: '',
			fileCount: 0,
		};
	}, [ currency, metaBoxTick, selected, useMetaBoxPreview ] );
}
