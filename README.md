# Infinite Icons

A free WordPress plugin that makes popular open-source icon packs available as **native
WordPress icons**: in the core Icon block, in a shortcode, and in your own PHP.

Requires WordPress 7.1 (for the Icons API) and PHP 7.4.

```
[infinite_icon name="lucide/heart" size="32" color="#c00"]
```

```php
echo infinite_icons_get( 'lucide/star', array( 'size' => 32, 'label' => 'Featured' ) );
```

- **[docs/usage.md](docs/usage.md)** — the shortcode, the PHP API and the CSS classes.
- **[docs/hooks.md](docs/hooks.md)** — every filter and action.

## How it works

WordPress core sanitizes every registered icon with `wp_kses()` and keeps only `<svg>`,
`<path>` and `<polygon>`; all `stroke` attributes are removed. Stroke-drawn outline sets
(Lucide, Tabler outline, Heroicons outline) would therefore render as nothing.

Icons are converted ahead of time instead, by a separate pipeline in
[infinite-icons-packs](https://github.com/CoderAbhinav/infinite-icons-packs): strokes are
expanded to filled outlines, all shapes are boolean-united into one path, and each result is
compared against a render of the original before it ships. What the plugin registers is
already in the exact form the sanitizer preserves:

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="…"/></svg>
```

Icons register with `file_path`, so core reads and sanitizes each SVG only when it is first
rendered: 1,815 registered icons cost no file reads on a request that renders none.

## Packs

Lucide is bundled with the plugin. Packs are directories, either in this plugin's `packs/`
directory or in `uploads/infinite-icons/packs`; a pack in uploads supersedes a bundled pack
with the same slug.

```
packs/lucide/
├── manifest.json      slug, label, version, licence, variants, icon list with keywords
├── LICENSE
├── ATTRIBUTION.md
├── icons/<name>.svg
└── elementor/         CSS and JSON for Elementor's icon library
```

## Development

```sh
composer install
composer lint          # PHPCS: WordPress-Extra + WordPress-Docs
composer analyse       # PHPStan level 6
composer test          # PHPUnit
```

### Running the tests

The suite needs the WordPress test library and a throwaway database:

```sh
# 1. A database the tests may DROP tables in.
mysql -e "CREATE DATABASE wordpress_test"

# 2. The test library, matching the WordPress version under test.
curl -sSL https://github.com/WordPress/wordpress-develop/archive/refs/tags/7.1.0.tar.gz \
  | tar -xz --strip-components=3 -C /tmp/wordpress-tests-lib \
      wordpress-develop-7.1.0/tests/phpunit/includes \
      wordpress-develop-7.1.0/tests/phpunit/data

# 3. Point the suite at both, plus a wp-tests-config.php.
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
export WP_TESTS_CONFIG_FILE_PATH=/path/to/wp-tests-config.php
composer test
```

`WP_TESTS_CONFIG_FILE_PATH` must point at a `wp-tests-config.php` whose `ABSPATH` is a
WordPress 7.1 install. Start from `wp-tests-config-sample.php` in the same tarball.

## Licence

GPL-3.0-or-later. Each icon pack keeps its upstream licence; see the `LICENSE` and
`ATTRIBUTION.md` inside every pack directory.
