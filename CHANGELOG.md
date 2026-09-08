# Changelog

All notable changes to this project are documented here. This project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
