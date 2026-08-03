import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';

registerBlockType( 'simple-digital-downloads/buy-button', {
	edit: Edit,
} );
