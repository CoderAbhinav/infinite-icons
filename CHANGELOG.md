# Changelog

All notable changes to this project are documented here. This project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Appearance -> Icons settings screen, with a Packs tab for installing, enabling and
  removing packs and a Browse tab for searching every registered icon and copying it as a
  name, shortcode, PHP call or block markup.
- Pack downloader: fetches the published index, refuses any host outside an allowlist,
  verifies the SHA-256 of every download, and validates the unpacked tree (file names,
  sizes, manifest and a sample of icons) before moving it into place. Guard files are
  written into the uploads directory so nothing there can be executed.
- REST API under `infinite-icons/v1`: `packs` (list, install, remove, refresh-index,
  settings) behind `manage_options`, and `icons` (paginated search over names, labels and
  keywords) behind `edit_posts`.
- Hooks `infinite_icons_index_url`, `infinite_icons_allowed_download_hosts`,
  `infinite_icons_downloads_supported`, `infinite_icons_pack_installed` and
  `infinite_icons_pack_removed`.
- Runtime downloads are turned off automatically on hosts with a read-only or remote
  filesystem, such as WordPress VIP, where packs are expected to arrive via deploy.

### Changed

- Registering a pack no longer checks every icon file for readability. That was a
  filesystem stat per icon on every request -- 165 ms with three large packs enabled --
  and is now one probe per style, bringing the registrar to 28 ms and the page to 55 ms.

### Fixed

- The plugin passes the WordPress VIP coding standards (`WordPressVIPMinimum` and
  `WordPress-VIP-Go`), so it can be deployed on managed platforms that enforce them.

- WordPress Icons API integration: enabled packs register as icon collections on `init`,
  and their icons register lazily by file path.
- Bundled Lucide pack (1,815 icons, ISC), converted to sanitizer-safe single-path SVG.
- `[infinite_icon]` shortcode and its `[ii_icon]` alias, with `name`, `size`, `color`,
  `class`, `label`, `rotate`, `flip`, `link`, `target` and `align`.
- `infinite_icons_get()`, `infinite_icons_the_icon()` and `infinite_icons_exists()`.
- `assets/frontend.css`, enqueued only when an icon is rendered.
- Settings stored in a single `infinite_icons_settings` option, covering enabled packs,
  enabled variants and the uninstall behaviour.
- Pack discovery in the plugin's `packs/` directory and in `uploads/infinite-icons/packs`,
  where a downloaded pack supersedes a bundled one with the same slug.
- Filters `infinite_icons_packs`, `infinite_icons_register_icon_args`,
  `infinite_icons_default_enabled_variants` and `infinite_icons_render`; actions
  `infinite_icons_loaded` and `infinite_icons_registered`. See `docs/hooks.md`.
- PHPUnit suite (122 tests), PHPCS (WordPress-Extra + WordPress-Docs) and PHPStan level 6.
