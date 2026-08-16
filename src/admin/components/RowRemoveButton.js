/**
 * Remove action aligned with BaseControl inputs (label spacer + 40px control).
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}   props
 * @param {Function} props.onClick
 * @param {boolean}  [props.disabled]
 */
export function RowRemoveButton( { onClick, disabled = false } ) {
	return (
		<div className="vikus-admin-app__row-action">
			<span
				className="vikus-admin-app__row-action-label"
				aria-hidden="true"
			>
				{ __( 'Remove', 'vikus-viewer-embed' ) }
			</span>
			<Button
				isDestructive
				variant="tertiary"
				disabled={ disabled }
				onClick={ onClick }
			>
				{ __( 'Remove', 'vikus-viewer-embed' ) }
			</Button>
		</div>
	);
}
