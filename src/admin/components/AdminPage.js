/**
 * Temporary stand-in for `@wordpress/admin-ui` `Page`.
 *
 * API and DOM roles mirror packages/admin-ui/src/page (Page + Header).
 * When the package is a safe public dependency, replace this module with a
 * re-export of that Page and delete the matching CSS block in style.scss.
 *
 * @see https://wordpress.github.io/gutenberg/?path=/story/admin-ui-page--with-breadcrumbs-and-subtitle
 * @see https://wordpress.github.io/gutenberg/?path=/story/admin-ui-page--full-header
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/admin-ui/src/page/style.module.css
 */
import { createElement } from '@wordpress/element';

/**
 * @param {Object}          props
 * @param {1|2|3|4|5|6}     [props.headingLevel=1]
 * @param {import('react').ReactNode} [props.breadcrumbs]
 * @param {import('react').ReactNode} [props.badges]
 * @param {import('react').ReactNode} [props.visual]
 * @param {import('react').ReactNode} [props.title]
 * @param {import('react').ReactNode} [props.subTitle]
 * @param {import('react').ReactNode} props.children
 * @param {string}          [props.className]
 * @param {import('react').ReactNode} [props.actions]
 * @param {string}          [props.ariaLabel]
 * @param {boolean}         [props.hasPadding=false]
 * @param {boolean}         [props.showSidebarToggle] Unused until real Page; kept for API parity.
 */
export function AdminPage( {
	headingLevel = 1,
	breadcrumbs,
	badges,
	visual,
	title,
	subTitle,
	children,
	className = '',
	actions,
	ariaLabel,
	hasPadding = false,
	showSidebarToggle: _showSidebarToggle = false,
} ) {
	const effectiveAriaLabel =
		ariaLabel ?? ( typeof title === 'string' ? title : '' );
	const showHeader = !!(
		title ||
		breadcrumbs ||
		badges ||
		actions ||
		visual
	);

	return (
		<div
			className={ [ 'vikus-admin-ui-page', className ]
				.filter( Boolean )
				.join( ' ' ) }
			role="region"
			aria-label={ effectiveAriaLabel || undefined }
		>
			{ showHeader ? (
				<header className="vikus-admin-ui-page__header">
					<div className="vikus-admin-ui-page__header-content">
						<div className="vikus-admin-ui-page__header-start">
							{ visual ? (
								<div
									className="vikus-admin-ui-page__header-visual"
									aria-hidden="true"
								>
									{ visual }
								</div>
							) : null }
							{ title
								? createElement(
										`h${ headingLevel }`,
										{
											className:
												'vikus-admin-ui-page__header-title',
										},
										title
								  )
								: null }
							{ breadcrumbs }
							{ badges }
						</div>
						{ actions ? (
							<div className="vikus-admin-ui-page__header-actions">
								{ actions }
							</div>
						) : null }
					</div>
					{ subTitle ? (
						<p className="vikus-admin-ui-page__header-subtitle">
							{ subTitle }
						</p>
					) : null }
				</header>
			) : null }
			<div
				className={ [
					'vikus-admin-ui-page__content',
					hasPadding ? 'vikus-admin-ui-page__content--has-padding' : '',
				]
					.filter( Boolean )
					.join( ' ' ) }
			>
				{ children }
			</div>
		</div>
	);
}
