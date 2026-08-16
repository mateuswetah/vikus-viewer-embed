/**
 * Map build status to Design System Badge intent + label.
 *
 * @see https://wordpress.github.io/gutenberg/?path=/docs/design-system-components-badge--docs
 */
import { __ } from '@wordpress/i18n';

/**
 * @param {string} status Build status key.
 * @return {{ intent: 'high'|'medium'|'low'|'stable'|'informational'|'draft'|'none', label: string }}
 */
export function getBuildStatusBadge( status ) {
	switch ( status ) {
		case 'complete':
			return {
				intent: 'stable',
				label: __( 'Complete', 'vikus-viewer-embed' ),
			};
		case 'error':
			return {
				intent: 'high',
				label: __( 'Error', 'vikus-viewer-embed' ),
			};
		case 'queued':
			return {
				intent: 'low',
				label: __( 'Queued', 'vikus-viewer-embed' ),
			};
		case 'running':
			return {
				intent: 'informational',
				label: __( 'Running', 'vikus-viewer-embed' ),
			};
		case 'idle':
		default:
			return {
				intent: 'none',
				label: __( 'Idle', 'vikus-viewer-embed' ),
			};
	}
}
