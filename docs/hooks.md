# Hooks

Every filter and action Infinite Icons provides. Names are stable within a major version.

## Actions

### `infinite_icons_loaded`

Fires once every service is wired up, at the end of the plugin's bootstrap.

```php
add_action( 'infinite_icons_loaded', function ( InfiniteIcons\Plugin $plugin ) {
	$locator = $plugin->get( 'locator' ); // options, locator, icon, registrar, shortcode
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$plugin` | `InfiniteIcons\Plugin` | The plugin instance. |

### `infinite_icons_pack_installed`

Fires after a pack has been downloaded, verified and moved into place.

```php
add_action( 'infinite_icons_pack_installed', function ( InfiniteIcons\Packs\Pack $pack ) {
	error_log( "Installed {$pack->slug} {$pack->version}" );
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$pack` | `InfiniteIcons\Packs\Pack` | The pack that was installed. |

### `infinite_icons_pack_removed`

Fires after a downloaded pack's files have been deleted.

```php
add_action( 'infinite_icons_pack_removed', function ( string $slug ) {
	// Clean up anything keyed by this pack.
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$slug` | `string` | Slug of the removed pack. |

### `infinite_icons_registered`

Fires after every enabled pack has been registered with the WordPress Icons API, on `init` priority 10.

```php
add_action( 'infinite_icons_registered', function ( InfiniteIcons\Packs\Registrar $registrar ) {
	$names = array_keys( $registrar->registered_icons() );
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$registrar` | `InfiniteIcons\Packs\Registrar` | The registrar, after registration. |

## Filters

### `infinite_icons_packs`

Filters the installed packs before they are registered. Use it to add a pack from your own
directory, or to hide one.

```php
add_filter( 'infinite_icons_packs', function ( array $packs ) {
	unset( $packs['lucide'] );
	return $packs;
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$packs` | `array<string, Pack>` | Packs keyed by slug, sorted by label. |
| `$locator` | `InfiniteIcons\Packs\PackLocator` | The locator. |

Values that are not a `Pack` are discarded.

### `infinite_icons_register_icon_args`

Filters the arguments passed to `wp_register_icon()` for each icon.

```php
add_filter( 'infinite_icons_register_icon_args', function ( array $args, string $name, $pack ) {
	if ( 'lucide/heart' === $name ) {
		$args['label'] = __( 'Favourite', 'my-plugin' );
	}
	return $args;
}, 10, 3 );
```

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array{label: string, file_path: string}` | Registration arguments. |
| `$name` | `string` | Qualified icon name, `collection/icon-name`. |
| `$pack` | `InfiniteIcons\Packs\Pack` | The pack the icon belongs to. |

Core rejects an icon that provides both `content` and `file_path`; replace one with the other,
never add to it.

### `infinite_icons_default_enabled_variants`

Filters which variants are registered for a pack the user has never configured.

```php
add_filter( 'infinite_icons_default_enabled_variants', function ( array $variants, $pack ) {
	return 'heroicons' === $pack->slug ? array( '', 'solid' ) : $variants;
}, 10, 2 );
```

| Parameter | Type | Description |
|---|---|---|
| `$variants` | `array<int, string>` | Variant keys; defaults to the pack's default variant. |
| `$pack` | `InfiniteIcons\Packs\Pack` | The pack. |

Variant keys the pack does not define are dropped.

### `infinite_icons_render`

Filters the markup returned by `infinite_icons_get()`.

```php
add_filter( 'infinite_icons_render', function ( string $html, string $name, array $args ) {
	return '<span class="icon-wrap">' . $html . '</span>';
}, 10, 3 );
```

| Parameter | Type | Description |
|---|---|---|
| `$html` | `string` | The SVG markup. |
| `$name` | `string` | Qualified icon name. |
| `$args` | `array<string, mixed>` | Rendering arguments. |

Anything you add here is output as-is. Escape your own additions.

### `infinite_icons_index_url`

Filters where the list of downloadable packs is fetched from. Must be an HTTPS URL returning
the `schema: 1` index document; anything else is refused before a request is made.

```php
add_filter( 'infinite_icons_index_url', function ( string $url ) {
	return 'https://icons.example.com/index.json';
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$url` | `string` | Absolute HTTPS URL. Defaults to the plugin's GitHub release. |

### `infinite_icons_allowed_download_hosts`

Filters the hosts a pack may be downloaded from. A URL whose host is not on this list is
refused before any request is made, so a tampered index cannot point the site elsewhere.

```php
add_filter( 'infinite_icons_allowed_download_hosts', function ( array $hosts ) {
	$hosts[] = 'icons.example.com';
	return $hosts;
} );
```

| Parameter | Type | Description |
|---|---|---|
| `$hosts` | `array<int, string>` | Lower-case host names. Defaults to GitHub's release hosts. |

### `infinite_icons_downloads_supported`

Filters whether packs may be downloaded and unpacked at runtime. Returns `false` automatically
on WordPress VIP, where the uploads directory is served from an object store rather than a local
disk. When downloads are off, the Packs screen explains that packs should be committed into the
plugin's `packs/` directory instead; everything else works unchanged.

```php
// Turn runtime installation off on a read-only filesystem.
add_filter( 'infinite_icons_downloads_supported', '__return_false' );
```

| Parameter | Type | Description |
|---|---|---|
| `$supported` | `bool` | Whether runtime installation is possible. |
