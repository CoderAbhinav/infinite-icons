<?php
/**
 * Uninstall handler.
 *
 * Options and transients always go. Downloaded packs are only deleted when the
 * user ticked "remove all data on uninstall", because re-downloading hundreds
 * of megabytes of icons is not something to do by surprise.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes a directory, preferring WP_Filesystem.
 *
 * @since 1.0.0
 *
 * @param string $dir  Absolute path to delete.
 * @param string $base Directory the path must stay inside.
 * @return bool True when the directory was handled.
 */
function infinite_icons_delete_dir( string $dir, string $base ): bool {
	$real      = realpath( $dir );
	$real_base = realpath( $base );
	if ( false === $real || false === $real_base || 0 !== strpos( $real, $real_base ) ) {
		return true;
	}

	// WP_Filesystem is the API managed hosts expect, and it is usually
	// available during uninstall. The manual walk below is the fallback.
	if ( ! function_exists( 'WP_Filesystem' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( function_exists( 'WP_Filesystem' ) && WP_Filesystem() ) {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			return (bool) $wp_filesystem->delete( $real, true );
		}
	}

	infinite_icons_rmdir( $real, $real_base );
	return true;
}

/**
 * Recursively deletes a directory without WP_Filesystem.
 *
 * @since 1.0.0
 *
 * @param string $dir  Absolute path to delete.
 * @param string $base Directory the path must stay inside.
 * @return void
 */
function infinite_icons_rmdir( string $dir, string $base ): void {
	$real      = realpath( $dir );
	$real_base = realpath( $base );
	if ( false === $real || false === $real_base || 0 !== strpos( $real, $real_base ) ) {
		return;
	}
	$items = scandir( $real );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $real . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			infinite_icons_rmdir( $path, $real_base );
		} else {
			wp_delete_file( $path );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPressVIPMinimum.Functions.RestrictedFunctions.directory_rmdir -- Fallback for when WP_Filesystem cannot be loaded during uninstall; infinite_icons_delete_dir() prefers it. A non-empty directory is left alone deliberately.
	rmdir( $real );
}

/**
 * Removes the plugin's data for one site.
 *
 * @since 1.0.0
 *
 * @return void
 */
function infinite_icons_uninstall_site(): void {
	$settings = get_option( 'infinite_icons_settings', array() );
	$remove   = is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );

	delete_option( 'infinite_icons_settings' );
	delete_transient( 'infinite_icons_index' );

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients are deleted by prefix; there is no core API for that.
	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_infinite\_icons\_%' OR option_name LIKE '\_transient\_timeout\_infinite\_icons\_%'" );
	foreach ( (array) $names as $name ) {
		delete_option( (string) $name );
	}

	delete_metadata( 'user', 0, 'infinite_icons_recent', '', true );

	if ( $remove ) {
		$uploads = wp_get_upload_dir();
		$dir     = untrailingslashit( $uploads['basedir'] ) . '/infinite-icons';
		if ( is_dir( $dir ) ) {
			infinite_icons_delete_dir( $dir, untrailingslashit( $uploads['basedir'] ) );
		}
	}
}

/**
 * Removes the plugin's data everywhere it is stored.
 *
 * @since 1.0.0
 *
 * @return void
 */
function infinite_icons_uninstall(): void {
	if ( ! is_multisite() ) {
		infinite_icons_uninstall_site();
		return;
	}

	$sites = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);
	foreach ( $sites as $site ) {
		// Only the database context is needed: this deletes options, transients
		// and user meta, none of which depend on the site's plugins or theme.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog -- Uninstall must reach every site's data.
		switch_to_blog( (int) $site );
		infinite_icons_uninstall_site();
		restore_current_blog();
	}
}

infinite_icons_uninstall();
