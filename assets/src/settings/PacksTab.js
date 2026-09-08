/**
 * Packs tab: what is installed, and what can be downloaded.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	Spinner,
	ToggleControl,
	__experimentalConfirmDialog as ConfirmDialog,
} from '@wordpress/components';
import { formatBytes, formatCount, variantLabel } from './format';

/**
 * Eight preview icons for a pack that is not installed yet.
 *
 * The SVGs ship with the plugin, so nothing is fetched from the network.
 *
 * @param {Object} props
 * @param {string} props.base  Base URL of the bundled previews.
 * @param {string} props.slug  Pack slug.
 * @param {Array}  props.names Icon names to show.
 * @return {JSX.Element|null} The preview strip.
 */
function Previews( { base, slug, names } ) {
	if ( ! names || ! names.length ) {
		return null;
	}
	return (
		<div className="ii-previews" aria-hidden="true">
			{ names.slice( 0, 8 ).map( ( name ) => (
				<img
					key={ name }
					src={ `${ base }${ slug }/${ name }.svg` }
					alt=""
					width="24"
					height="24"
					loading="lazy"
				/>
			) ) }
		</div>
	);
}

/**
 * One installed pack.
 *
 * @param {Object}   props
 * @param {Object}   props.pack     Pack payload.
 * @param {boolean}  props.busy     Whether a request for this pack is in flight.
 * @param {Function} props.onToggle Enable or disable the pack.
 * @param {Function} props.onVariant Enable or disable one variant.
 * @param {Function} props.onRemove Remove the pack.
 * @param {Object}   [props.update] Index entry when a newer version exists.
 * @return {JSX.Element} The card.
 */
function InstalledPack( { pack, busy, onToggle, onVariant, onRemove, update } ) {
	const [ confirming, setConfirming ] = useState( false );

	return (
		<Card className="ii-card">
			<CardBody>
				<Flex align="flex-start" justify="space-between" gap={ 4 }>
					<FlexItem isBlock>
						<h3 className="ii-card__title">
							{ pack.label }
							{ pack.bundled && (
								<span className="ii-badge ii-badge--muted">
									{ __( 'Bundled', 'infinite-icons' ) }
								</span>
							) }
							{ update && (
								<span className="ii-badge ii-badge--notice">
									{ sprintf(
										/* translators: %s: Pack version. */
										__( 'Update to %s', 'infinite-icons' ),
										update.version
									) }
								</span>
							) }
						</h3>
						<p className="ii-card__meta">
							{ [
								formatCount( pack.icon_count ),
								pack.version,
								pack.license.spdx,
							]
								.filter( Boolean )
								.join( ' · ' ) }
						</p>
						{ pack.description && (
							<p className="ii-card__desc">{ pack.description }</p>
						) }
					</FlexItem>
					<FlexItem>
						{ busy ? (
							<Spinner />
						) : (
							<ToggleControl
								__nextHasNoMarginBottom
								label={ __( 'Enabled', 'infinite-icons' ) }
								checked={ pack.enabled }
								onChange={ ( value ) => onToggle( pack.slug, value ) }
							/>
						) }
					</FlexItem>
				</Flex>

				{ pack.enabled && pack.variants.length > 1 && (
					<div className="ii-variants">
						<span className="ii-variants__label">
							{ __( 'Styles', 'infinite-icons' ) }
						</span>
						{ pack.variants.map( ( variant ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ variant.key || 'default' }
								label={ `${ variantLabel( variant.key, variant.label ) } (${ variant.count.toLocaleString() })` }
								checked={ variant.enabled }
								disabled={ busy }
								onChange={ ( value ) =>
									onVariant( pack.slug, variant.key, value )
								}
							/>
						) ) }
					</div>
				) }

				{ pack.enabled && (
					<p className="ii-card__note">
						{ sprintf(
							/* translators: %s: Number of icons, already formatted. */
							__( '%s available in the editor.', 'infinite-icons' ),
							formatCount( pack.active_count )
						) }
					</p>
				) }

				{ ! pack.bundled && (
					<p>
						<Button
							variant="link"
							isDestructive
							disabled={ busy }
							onClick={ () => setConfirming( true ) }
						>
							{ __( 'Remove', 'infinite-icons' ) }
						</Button>
					</p>
				) }

				<ConfirmDialog
					isOpen={ confirming }
					confirmButtonText={ __( 'Remove', 'infinite-icons' ) }
					onConfirm={ () => {
						setConfirming( false );
						onRemove( pack.slug );
					} }
					onCancel={ () => setConfirming( false ) }
				>
					{ sprintf(
						/* translators: %s: Pack label. */
						__(
							'Remove %s? Its files are deleted from your uploads folder. Any icons from this pack already used in your content will stop rendering.',
							'infinite-icons'
						),
						pack.label
					) }
				</ConfirmDialog>
			</CardBody>
		</Card>
	);
}

/**
 * One pack that can be downloaded.
 *
 * @param {Object}   props
 * @param {Object}   props.pack        Index entry.
 * @param {string}   props.previewBase Base URL for bundled previews.
 * @param {boolean}  props.busy        Whether this pack is being installed.
 * @param {boolean}  props.canInstall  Whether the uploads folder is writable.
 * @param {Function} props.onInstall   Install handler.
 * @return {JSX.Element} The card.
 */
function AvailablePack( { pack, previewBase, busy, canInstall, onInstall } ) {
	return (
		<Card className="ii-card">
			<CardBody>
				<h3 className="ii-card__title">{ pack.label }</h3>
				<p className="ii-card__meta">
					{ [
						formatCount( pack.icon_count ),
						formatBytes( pack.size_bytes ),
						pack.license,
					]
						.filter( Boolean )
						.join( ' · ' ) }
				</p>
				{ pack.description && (
					<p className="ii-card__desc">{ pack.description }</p>
				) }
				<Previews
					base={ previewBase }
					slug={ pack.slug }
					names={ pack.preview }
				/>
				<Button
					variant="secondary"
					disabled={ busy || ! canInstall }
					onClick={ () => onInstall( pack.slug ) }
				>
					{ busy
						? __( 'Installing…', 'infinite-icons' )
						: pack.update
							? __( 'Update', 'infinite-icons' )
							: __( 'Install', 'infinite-icons' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * The Packs tab.
 *
 * @param {Object}   props
 * @param {Object}   props.state    Payload from GET /packs.
 * @param {string}   props.busySlug Slug currently being worked on.
 * @param {boolean}  props.refreshing Whether the index is being refetched.
 * @param {Function} props.onInstall Install handler.
 * @param {Function} props.onRemove  Remove handler.
 * @param {Function} props.onToggle  Enable/disable handler.
 * @param {Function} props.onVariant Variant handler.
 * @param {Function} props.onRefresh Refresh handler.
 * @return {JSX.Element} The tab.
 */
export default function PacksTab( {
	state,
	busySlug,
	refreshing,
	onInstall,
	onRemove,
	onToggle,
	onVariant,
	onRefresh,
} ) {
	const updates = {};
	state.available.forEach( ( entry ) => {
		if ( entry.update ) {
			updates[ entry.slug ] = entry;
		}
	} );
	const notInstalled = state.available.filter( ( entry ) => ! entry.installed );

	return (
		<div className="ii-tab">
			<h2>{ __( 'Installed', 'infinite-icons' ) }</h2>
			<div className="ii-grid">
				{ state.installed.map( ( pack ) => (
					<InstalledPack
						key={ pack.slug }
						pack={ pack }
						busy={ busySlug === pack.slug }
						update={ updates[ pack.slug ] }
						onToggle={ onToggle }
						onVariant={ onVariant }
						onRemove={ onRemove }
					/>
				) ) }
			</div>

			<h2>{ __( 'Available', 'infinite-icons' ) }</h2>
			<p className="ii-disclosure">
				{ sprintf(
					/* translators: %s: Host name packs are downloaded from. */
					__(
						'Packs, and the list of them, are downloaded from %s only when you press Install or Check for updates. No information about your site is sent anywhere.',
						'infinite-icons'
					),
					'github.com'
				) }
			</p>

			<p>
				<Button
					variant="secondary"
					onClick={ onRefresh }
					disabled={ refreshing }
				>
					{ refreshing
						? __( 'Checking…', 'infinite-icons' )
						: __( 'Check for updates', 'infinite-icons' ) }
				</Button>
			</p>

			{ state.index.error && (
				<Notice status="warning" isDismissible={ false }>
					{ state.index.error.message }
				</Notice>
			) }

			{ ! state.canInstall && (
				<Notice status="warning" isDismissible={ false }>
					{ state.downloadsSupported
						? __(
								'Your uploads folder is not writable, so packs cannot be installed.',
								'infinite-icons'
						  )
						: __(
								'This site does not allow packs to be downloaded at runtime. Add the packs you want to the plugin\'s packs directory in your deploy; everything else works as normal.',
								'infinite-icons'
						  ) }
				</Notice>
			) }

			{ ! state.index.error && ! notInstalled.length && (
				<p>{ __( 'Every available pack is installed.', 'infinite-icons' ) }</p>
			) }

			<div className="ii-grid">
				{ notInstalled.map( ( pack ) => (
					<AvailablePack
						key={ pack.slug }
						pack={ pack }
						previewBase={ state.previewBase }
						busy={ busySlug === pack.slug }
						canInstall={ state.canInstall }
						onInstall={ onInstall }
					/>
				) ) }
			</div>

			<h2>{ __( 'Licences', 'infinite-icons' ) }</h2>
			<ul className="ii-licences">
				{ state.installed.map( ( pack ) => (
					<li key={ pack.slug }>
						<strong>{ pack.label }</strong> — { pack.license.spdx }
						{ pack.license.attribution
							? ` · ${ pack.license.attribution }`
							: '' }
						{ pack.upstream.url && (
							<>
								{ ' · ' }
								<a
									href={ pack.upstream.url }
									target="_blank"
									rel="noreferrer noopener"
								>
									{ __( 'Project site', 'infinite-icons' ) }
								</a>
							</>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
