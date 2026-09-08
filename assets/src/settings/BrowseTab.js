/**
 * Browse tab: search every enabled icon and copy it in the form you need.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useDebounce, useCopyToClipboard } from '@wordpress/compose';
import { errorMessage, searchIcons } from './api';
import { formatCount, variantLabel } from './format';

const PER_PAGE = 60;

/**
 * A button that copies one string.
 *
 * @param {Object} props
 * @param {string} props.text  Value to copy.
 * @param {string} props.label Button label.
 * @return {JSX.Element} The button.
 */
function CopyButton( { text, label } ) {
	const [ copied, setCopied ] = useState( false );
	const timer = useRef( null );

	const ref = useCopyToClipboard( text, () => {
		setCopied( true );
		window.clearTimeout( timer.current );
		timer.current = window.setTimeout( () => setCopied( false ), 1500 );
	} );

	useEffect( () => () => window.clearTimeout( timer.current ), [] );

	return (
		<Button ref={ ref } variant="tertiary" size="small">
			{ copied ? __( 'Copied', 'infinite-icons' ) : label }
		</Button>
	);
}

/**
 * The selected icon and the four ways to use it.
 *
 * @param {Object} props
 * @param {Object} props.icon Selected icon.
 * @return {JSX.Element} The detail panel.
 */
function IconDetail( { icon } ) {
	const snippets = [
		{ label: __( 'Name', 'infinite-icons' ), value: icon.name },
		{
			label: __( 'Shortcode', 'infinite-icons' ),
			value: `[infinite_icon name="${ icon.name }"]`,
		},
		{
			label: __( 'PHP', 'infinite-icons' ),
			value: `<?php infinite_icons_the_icon( '${ icon.name }' ); ?>`,
		},
		{
			label: __( 'Block', 'infinite-icons' ),
			value: `<!-- wp:icon {"icon":"${ icon.name }"} /-->`,
		},
	];

	return (
		<div className="ii-detail">
			<div
				className="ii-detail__preview"
				/* eslint-disable-next-line react/no-danger -- Markup comes from wp_get_icon(), already sanitized by core. */
				dangerouslySetInnerHTML={ { __html: icon.content } }
			/>
			<div className="ii-detail__body">
				<h3>{ icon.label }</h3>
				<p className="ii-detail__name">
					<code>{ icon.name }</code>
				</p>
				<div className="ii-detail__copies">
					{ snippets.map( ( snippet ) => (
						<CopyButton
							key={ snippet.label }
							text={ snippet.value }
							label={ sprintf(
								/* translators: %s: What is being copied, e.g. "Shortcode". */
								__( 'Copy %s', 'infinite-icons' ),
								snippet.label
							) }
						/>
					) ) }
				</div>
			</div>
		</div>
	);
}

/**
 * The Browse tab.
 *
 * @param {Object} props
 * @param {Array}  props.packs Installed packs, used to build the filters.
 * @return {JSX.Element} The tab.
 */
export default function BrowseTab( { packs } ) {
	const [ search, setSearch ] = useState( '' );
	const [ collection, setCollection ] = useState( '' );
	const [ variant, setVariant ] = useState( '' );
	const [ results, setResults ] = useState( { items: [], total: 0, pages: 0 } );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState( null );

	// Only a pack that is switched on has registered icons to search.
	const enabled = packs.filter( ( pack ) => pack.enabled );
	const activePack = enabled.find( ( pack ) => pack.slug === collection );

	const load = useCallback(
		async ( nextPage, append ) => {
			setLoading( true );
			setError( '' );
			try {
				const data = await searchIcons( {
					search,
					collection,
					variant: activePack ? variant : '',
					page: nextPage,
					per_page: PER_PAGE,
				} );
				setResults( ( previous ) => ( {
					total: data.total,
					pages: data.pages,
					items: append
						? [ ...previous.items, ...data.items ]
						: data.items,
				} ) );
				setPage( nextPage );
			} catch ( caught ) {
				setError(
					errorMessage( caught ) ||
						__( 'The icons could not be loaded.', 'infinite-icons' )
				);
			} finally {
				setLoading( false );
			}
		},
		[ search, collection, variant, activePack ]
	);

	const debouncedLoad = useDebounce( load, 250 );

	useEffect( () => {
		debouncedLoad( 1, false );
	}, [ search, collection, variant, debouncedLoad ] );

	if ( ! enabled.length ) {
		return (
			<div className="ii-tab">
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No packs are enabled yet. Turn one on in the Packs tab to browse its icons.',
						'infinite-icons'
					) }
				</Notice>
			</div>
		);
	}

	return (
		<div className="ii-tab">
			<div className="ii-filters">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __( 'Search icons', 'infinite-icons' ) }
					placeholder={ __( 'Search icons…', 'infinite-icons' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Pack', 'infinite-icons' ) }
					value={ collection }
					options={ [
						{ value: '', label: __( 'All packs', 'infinite-icons' ) },
						...enabled.map( ( pack ) => ( {
							value: pack.slug,
							label: pack.label,
						} ) ),
					] }
					onChange={ ( value ) => {
						setCollection( value );
						setVariant( '' );
					} }
				/>
				{ activePack && activePack.variants.length > 1 && (
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Style', 'infinite-icons' ) }
						value={ variant }
						options={ activePack.variants
							.filter( ( item ) => item.enabled )
							.map( ( item ) => ( {
								value: item.key,
								label: variantLabel( item.key, item.label ),
							} ) ) }
						onChange={ setVariant }
					/>
				) }
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ selected && <IconDetail icon={ selected } /> }

			<p className="ii-results-count">
				{ loading && ! results.items.length
					? __( 'Searching…', 'infinite-icons' )
					: formatCount( results.total ) }
			</p>

			<div className="ii-icon-grid">
				{ results.items.map( ( icon ) => (
					<button
						type="button"
						key={ icon.name }
						className={
							'ii-icon-grid__item' +
							( selected && selected.name === icon.name
								? ' is-selected'
								: '' )
						}
						title={ `${ icon.label } — ${ icon.name }` }
						aria-label={ icon.name }
						onClick={ () => setSelected( icon ) }
						/* eslint-disable-next-line react/no-danger -- Markup comes from wp_get_icon(), already sanitized by core. */
						dangerouslySetInnerHTML={ { __html: icon.content } }
					/>
				) ) }
			</div>

			{ loading && <Spinner /> }

			{ page < results.pages && ! loading && (
				<Button variant="secondary" onClick={ () => load( page + 1, true ) }>
					{ __( 'Load more', 'infinite-icons' ) }
				</Button>
			) }
		</div>
	);
}
