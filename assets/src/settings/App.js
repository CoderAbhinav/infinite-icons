/**
 * Appearance → Icons.
 */
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { Notice, Spinner, TabPanel } from '@wordpress/components';
import {
	errorMessage,
	getPacks,
	installPack,
	refreshIndex,
	removePack,
	saveSettings,
} from './api';
import PacksTab from './PacksTab';
import BrowseTab from './BrowseTab';

/**
 * The settings screen.
 *
 * Every mutation returns the whole state, so the screen never has to guess what
 * changed: enabling a pack changes which icons are registered, which changes
 * what Browse can show.
 *
 * @return {JSX.Element} The app.
 */
export default function App() {
	const [ state, setState ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ busySlug, setBusySlug ] = useState( '' );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			setState( await getPacks() );
			setError( '' );
		} catch ( caught ) {
			setError(
				errorMessage( caught ) ||
					__( 'The icon packs could not be loaded.', 'infinite-icons' )
			);
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	/**
	 * Runs a mutation, showing progress against one pack and surfacing failures.
	 *
	 * @param {string}   slug    Pack the work belongs to.
	 * @param {Function} work    Returns a promise resolving to the new state.
	 * @param {string}   success Message to show when it worked.
	 */
	const run = async ( slug, work, success = '' ) => {
		setBusySlug( slug );
		setError( '' );
		setNotice( '' );
		try {
			setState( await work() );
			if ( success ) {
				setNotice( success );
			}
		} catch ( caught ) {
			setError(
				errorMessage( caught ) ||
					__( 'Something went wrong.', 'infinite-icons' )
			);
		} finally {
			setBusySlug( '' );
		}
	};

	const onInstall = ( slug ) =>
		run(
			slug,
			() => installPack( slug ),
			__( 'Pack installed. Its icons are now available in the editor.', 'infinite-icons' )
		);

	const onRemove = ( slug ) =>
		run( slug, () => removePack( slug ), __( 'Pack removed.', 'infinite-icons' ) );

	const onToggle = ( slug, enabled ) =>
		run( slug, () =>
			saveSettings( {
				enabled_packs: { ...state.settings.enabled_packs, [ slug ]: enabled },
			} )
		);

	const onVariant = ( slug, key, enabled ) => {
		const pack = state.installed.find( ( item ) => item.slug === slug );
		const current = pack.variants
			.filter( ( item ) => item.enabled )
			.map( ( item ) => item.key );
		const next = enabled
			? [ ...current, key ]
			: current.filter( ( item ) => item !== key );

		// An empty list would fall back to the default variant, which reads as
		// the checkbox refusing to switch off. Keep at least one style on.
		if ( ! next.length ) {
			setError(
				__( 'At least one style has to stay enabled.', 'infinite-icons' )
			);
			return;
		}

		return run( slug, () =>
			saveSettings( {
				enabled_variants: {
					...state.settings.enabled_variants,
					[ slug ]: next,
				},
			} )
		);
	};

	const onRefresh = async () => {
		setRefreshing( true );
		setError( '' );
		setNotice( '' );
		try {
			setState( await refreshIndex() );
			setNotice( __( 'The list of packs is up to date.', 'infinite-icons' ) );
		} catch ( caught ) {
			setError(
				errorMessage( caught ) ||
					__( 'The list of packs could not be refreshed.', 'infinite-icons' )
			);
		} finally {
			setRefreshing( false );
		}
	};

	if ( loading && ! state ) {
		return <Spinner />;
	}

	return (
		<div className="ii-settings">
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			{ notice && (
				<Notice status="success" onRemove={ () => setNotice( '' ) }>
					{ notice }
				</Notice>
			) }

			{ ! state ? null : (
				<TabPanel
					className="ii-tabs"
					tabs={ [
						{ name: 'packs', title: __( 'Packs', 'infinite-icons' ) },
						{ name: 'browse', title: __( 'Browse', 'infinite-icons' ) },
					] }
				>
					{ ( tab ) =>
						tab.name === 'packs' ? (
							<PacksTab
								state={ state }
								busySlug={ busySlug }
								refreshing={ refreshing }
								onInstall={ onInstall }
								onRemove={ onRemove }
								onToggle={ onToggle }
								onVariant={ onVariant }
								onRefresh={ onRefresh }
							/>
						) : (
							<BrowseTab packs={ state.installed } />
						)
					}
				</TabPanel>
			) }
		</div>
	);
}
