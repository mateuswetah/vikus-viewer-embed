/**
 * Breadcrumb trail matching `@wordpress/admin-ui` Breadcrumbs.
 *
 * Preceding items are navigable; the last item is the current page title (h1).
 * Uses onClick/href instead of router `to` until admin-ui is a safe dependency.
 *
 * @see https://wordpress.github.io/gutenberg/?path=/story/admin-ui-page--with-breadcrumbs-and-subtitle
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/admin-ui/src/breadcrumbs
 */
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * @typedef {Object} BreadcrumbItem
 * @property {string}    label     Visible label.
 * @property {string}    [href]    Optional URL for preceding items.
 * @property {Function}  [onClick] Optional click handler (SPA back, etc.).
 */

/**
 * @param {Object}            props
 * @param {BreadcrumbItem[]}  props.items
 * @param {1|2|3|4|5|6}       [props.headingLevel=1]
 */
export function Breadcrumbs( { items, headingLevel = 1 } ) {
	if ( ! items?.length ) {
		return null;
	}

	const preceding = items.slice( 0, -1 );
	const current = items[ items.length - 1 ];

	return (
		<nav
			className="vikus-admin-ui-breadcrumbs"
			aria-label={ __( 'Breadcrumbs', 'vikus-viewer-embed' ) }
		>
			<ol className="vikus-admin-ui-breadcrumbs__list">
				{ preceding.map( ( item, index ) => (
					<li key={ `${ item.label }-${ index }` }>
						{ item.href || item.onClick ? (
							<Button
								variant="link"
								href={ item.href }
								onClick={ item.onClick }
							>
								{ item.label }
							</Button>
						) : (
							<span>{ item.label }</span>
						) }
						<span
							className="vikus-admin-ui-breadcrumbs__separator"
							aria-hidden="true"
						>
							/
						</span>
					</li>
				) ) }
				<li>
					{ current.href || current.onClick
						? (
								<Button
									variant="link"
									href={ current.href }
									onClick={ current.onClick }
								>
									{ current.label }
								</Button>
						  )
						: createElement(
								`h${ headingLevel }`,
								{
									className:
										'vikus-admin-ui-breadcrumbs__current',
								},
								current.label
						  ) }
				</li>
			</ol>
		</nav>
	);
}
