/**
 * Consistent card chrome for edit-screen sections.
 *
 * Title typography matches `@wordpress/ui` Card.Title (`heading-lg`:
 * 15px / 20px / medium) rather than components Heading level 3.
 *
 * @see https://wordpress.github.io/gutenberg/?path=/docs/design-system-components-card--docs
 */
import { Card, CardBody, CardHeader, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * @param {Object}      props
 * @param {string}      props.title
 * @param {string}      [props.description]
 * @param {string}      [props.illustration] Decorative image URL (aria-hidden).
 * @param {import('react').ReactNode} props.children
 */
export function SettingsCard( {
	title,
	description,
	illustration,
	children,
} ) {
	return (
		<Card
			className={
				illustration
					? 'vikus-admin-app__card vikus-admin-app__card--illustrated'
					: 'vikus-admin-app__card'
			}
			size="medium"
		>
			<CardHeader className="vikus-admin-app__card-header">
				<VStack spacing={ 1 } className="vikus-admin-app__card-header-text">
					<h3 className="vikus-admin-app__card-title">{ title }</h3>
					{ description ? (
						<p className="vikus-admin-app__card-description">
							{ description }
						</p>
					) : null }
				</VStack>
				{ illustration ? (
					<img
						className="vikus-admin-app__card-illustration"
						src={ illustration }
						alt=""
						aria-hidden="true"
						width={ 150 }
						height={ 62 }
						decoding="async"
					/>
				) : null }
			</CardHeader>
			<CardBody>{ children }</CardBody>
		</Card>
	);
}
