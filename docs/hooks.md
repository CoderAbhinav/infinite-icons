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
