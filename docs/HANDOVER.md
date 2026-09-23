# Infinite Icons: handover

This document lets someone (or Claude Code) pick the project up with no other context. Read it top to bottom once, then keep `ROADMAP.md` and the GitHub issues as the working reference. Suggested location in the repo: `CLAUDE.md` at the root, or `docs/HANDOVER.md` with a pointer from `CLAUDE.md`.

## 1. What this is

Infinite Icons is a free, open source WordPress plugin by Abhinav (github.com/CoderAbhinav). Install it once, choose an icon pack (Material Symbols, Lucide, and so on), and that pack becomes available everywhere icons are chosen: the core Icon block, Elementor, ACF / SCF fields and Gravity Forms.

It is the first product under the "Infinite" umbrella. A Gutenberg only theme and other plugins will follow later in separate repositories. This repo is only the icons plugin.

Repository: https://github.com/CoderAbhinav/infinite-icons (empty at the time of writing).

## 2. Non negotiables

- Zero hosting cost. GitHub for code, Releases as the pack CDN, Actions for builds, Pages for docs, jsDelivr for the catalog, WordPress.org for distribution.
- Works on every host including WordPress VIP. No writes outside `wp-content/uploads`, no reliance on a local filesystem, everything cached.
- One repository for the plugin, the pack builder and the docs. The plugin lives at the repo root.
- Store icon names in content (`pack-slug/icon-name`), never SVG markup. Render through core's `wp_get_icon()`.
- One pack format shared by every integration.
- Only permissively licensed icon sets, with every required notice carried.
- Writing style for everything public (issues, docs, commit messages, UI copy): plain punctuation. No em dashes, no en dashes, no fancy typography. Use commas, full stops, colons and hyphens.

## 3. Platform facts the design depends on

Verified against the WordPress 7.1 dev note "Registering and rendering SVG icons in WordPress 7.1" (make.wordpress.org/core, 24 July 2026). Re-read it before writing registration code.

- `wp_register_icon_collection( $slug, [ 'label', 'description' ] )` and `wp_register_icon( 'collection/name', [ 'label', 'content' | 'file_path' ] )` are public API in WordPress 7.1. Conventionally called on `init`.
- Slugs and icon names: lowercase letters, digits, hyphens, underscores, must start and end with a letter or digit.
- Core sanitizes registered SVGs with `wp_kses` against a small allowlist. Only `svg`, `path` and `polygon` elements survive. `fill` is kept on `path` and `polygon` only. `stroke` is not allowed anywhere. Stroke based sets (Lucide, Feather, Tabler, Heroicons outline, Phosphor) will not render unless converted to filled outlines at build time. This is the biggest technical risk and the first thing to prove.
- `file_path` is read lazily, at REST or render time, not at registration.
- `wp_get_icon( $name, [ 'size', 'class', 'label' ] )` renders on the server. Put `fill="currentColor"` on shapes so icons follow text colour outside the Icon block.
- Read only REST endpoints exist: `GET /wp/v2/icon-collections`, `GET /wp/v2/icons`, `GET /wp/v2/icons/<collection>`, `GET /wp/v2/icons/<collection>/<name>`, with `search` and `collection` parameters. Editor capability required.
- The Icon block picker groups icons by collection with a tab per collection and an All tab. Keyword search is not in core yet (being considered upstream), so our own picker should search keywords itself.

WordPress VIP specifics (docs.wpvip.com):
- Application containers are read only. Only `/wp-content/uploads/` (and `/tmp`) is writable, through a PHP stream wrapper and `WP_Filesystem`.
- Every filesystem call is an HTTP request costing at least 200ms. Avoid `file_exists` loops, directory scans, `mkdir`, `is_dir`, `glob`, `chmod`.
- The object store has no real directories.
- Files written without an attachment post cannot be deleted without VIP support. Create an attachment record for every pack file.
- Whether a `.json` file written through the wrapper is accepted by VIP's file type rules is unverified. The storage abstraction exists so the database store can be the default on VIP if needed.

Competitor to be aware of: Aculect Icon Library (github.com/mehul0810/aculect-icon-library) already enhances the core Icon block with libraries and exposes WordPress Abilities for AI agents. Our differentiation: one pack format across Gutenberg, Elementor, ACF / SCF and Gravity Forms, stroke to fill conversion so packs actually render, and a much better admin UI.

## 4. Decisions already taken

Each of these has a `type: decision` issue and should end up as a file in `docs/decisions/`.

| # | Decision | Choice |
|---|---|---|
| 0001 | Minimum versions | WordPress 7.1+, PHP 8.1+ |
| 0002 | Collection slugs | Canonical, unprefixed (`lucide`, `material-symbols`). Skip registration if the slug already exists. |
| 0003 | Licensing | Plugin GPL-2.0-or-later. Packs from MIT, ISC, Apache-2.0, CC BY 4.0, CC0 sets only. No packs bundled in the WordPress.org zip. Every pack ships LICENSE, NOTICE where present, and a generated MODIFICATIONS.md. |
| 0004 | Menu placement | Appearance > Icons, mirroring Appearance > Fonts. |
| (open) | Elementor approach | Spike required: icon font per pack vs render interception vs dedicated widget. |
| (open) | Runtime pack install | Leaning towards enabled by default, disabled automatically under `DISALLOW_FILE_MODS`. Confirm with Abhinav before building the Packs screen. |

## 5. Architecture summary

Pack format (one compiled file per pack):
```
<slug>-<version>.zip
  pack.json        slug, label, version, upstream, upstream_version, license, license_url, attribution, icon_count, styles[], built_at, format_version
  icons.json       [{ name, label, keywords[], categories[], style, content }]
  LICENSE, NOTICE (if upstream has one), MODIFICATIONS.md (generated)
```
`content` is inline, pre-sanitized, fill only SVG, `viewBox="0 0 24 24"`, `fill="currentColor"` on shapes.

Build pipeline (Node, in `packs/builder/`): fetch upstream by version, flatten shapes to `path`, outline strokes to fills where the source config says so, normalize viewBox, strip to the core allowlist, validate against JSON Schema, verify through core's sanitizer, emit zip plus sha256. GitHub Actions tags `pack/<slug>@<version>`, attaches the zip to a Release and updates `packs/index.json` (served via jsDelivr, snapshot copied into the plugin build). Renovate tracks upstream versions.

Plugin runtime (PHP, PSR-4, namespace `Infinite\Icons`):
- `PackStore` interface with `FilesystemStore` (single JSON file per pack in uploads, attachment record created) and `DatabaseStore` (`ii_pack` post). Installed state lives in an option, never discovered from disk. Store selection filterable.
- `Installer`: catalog fetch, download with `wp_remote_get`, size and sha256 check, schema validation, re-sanitize, hand to store. Respects `DISALLOW_FILE_MODS`. Code managed packs picked up from `wp-content/infinite-icons-packs/`. WP-CLI commands.
- `PackLoader`: static cache, object cache (transient fallback), keys include version. Icon index kept in the option.
- Registration: admin, REST and editor requests register every enabled pack in full on `init`. Front end registers lazily per rendered icon via `render_block_data` on `core/icon`. A spike must confirm registration after `init` works; if not, fall back to full registration with caching.
- Custom icons: `ii_icon` post type, SVG in `post_content`, own sanitizer, collection slug `custom`, REST CRUD.
- Own REST namespace `infinite-icons/v1` for packs and settings, `manage_options`.
- Public API: `infinite_icons_get()`, `infinite_icons_register_pack()`, `infinite_icons_packs()`, hooks prefixed `infinite_icons_`, semver on documented surface.

Admin UI (React, `@wordpress/scripts`, `@wordpress/components`, `@wordpress/data`): three screens (Packs, My Icons, Settings) and one shared picker component reused by every integration. No onboarding wizard, the empty Packs state is the onboarding. Target: install to first icon in under thirty seconds.

Integrations, in order: core Icon block (via registration alone), ACF / SCF field (1.0), Gravity Forms field (1.1), Elementor tab (1.2, after a spike).

## 6. Repository layout to create

```
infinite-icons/
  infinite-icons.php
  uninstall.php
  src/                       Infinite\Icons\...
    Packs/                   Store, FilesystemStore, DatabaseStore, Installer, Loader, Registrar, Catalog
    Icons/                   CustomIcons post type, Sanitizer
    Integrations/            CoreBlock, Acf, GravityForms, Elementor (each self guarding)
    Rest/                    Controllers for infinite-icons/v1
    Admin/                   Menu, screens, script registration
    Cli/                     WP-CLI commands
  assets/src/                admin app, picker, editor code
  tests/                     phpunit, e2e
  packs/
    builder/                 Node builder
    sources/                 <slug>.json per set
    schema/                  pack.json and icons.json schemas
    licenses/                templates for NOTICE and MODIFICATIONS.md
    index.json               catalog
  docs/                      GitHub Pages site, decisions/, packs/, developer/, hosting/
  .github/workflows/         plugin-ci.yml, plugin-release.yml, packs-build.yml
  .distignore  .wp-env.json  composer.json  package.json  phpcs.xml.dist  phpstan.neon.dist
```

## 7. State of the project right now

Done in planning:
- `ROADMAP.md`: milestones, principles, labels, layout.
- This handover.

Published in the repository:
- 14 labels, 7 milestones and 47 issues, every issue labelled and assigned to a milestone. The GitHub
  issues are the source of truth for status from here.

The first commit holds these documents and nothing else. No plugin code exists yet. The one off script
that created the labels, milestones and issues has been removed now that it has run, so that the issues
on GitHub and a stale copy of their bodies cannot drift apart. It is in the git history at the first
commit.

## 8. Immediate next steps, in order

1. Milestone 0.1: scaffold the repo (issue "Scaffold the repository"), set up CI, contributing docs, write the four decision records.
2. Milestone 0.2, start with the two spikes that decide everything:
   - Stroke to fill outlining for Lucide. Prove `wp_get_icon()` output matches upstream at 24px.
   - Confirm `wp_register_icon()` works after `init` inside `render_block_data`.
3. Only then build the pack builder properly, then the runtime, then the UI.
4. Do not start the admin UI before the Figma work. Abhinav will provide the Figma file. The brief is the issue "Design: Infinite brand and admin visual language".

## 9. Working conventions for whoever continues

- One pull request per issue, reference the issue number, keep a changelog entry.
- Run `composer lint`, `composer test`, `npm run lint` before pushing. CI mirrors these.
- PHPCS rulesets: WordPress Coding Standards plus `WordPress-VIP-Go`. No exclusions without a comment explaining why.
- Prefer `@wordpress/*` packages and components over third party UI libraries so the admin feels native.
- Never write files outside uploads. Never assume a local filesystem. Cache every pack read.
- Never store SVG markup in post content, meta or entries. Store the icon name.
- Ask Abhinav before changing anything in section 2 or section 4. Everything else is open to good judgement.

## 10. Reference links

- WordPress 7.1 icons dev note: https://make.wordpress.org/core/2026/07/24/registering-and-rendering-svg-icons-in-wordpress-7-1/
- Core registry changeset: https://core.trac.wordpress.org/changeset/62748
- Sanitizer allowlist follow up: https://github.com/WordPress/gutenberg/pull/75550
- Keyword search follow up: https://github.com/WordPress/gutenberg/pull/76481
- VIP file system docs: https://docs.wpvip.com/vip-file-system/media-uploads/
- VIP plugin incompatibilities: https://docs.wpvip.com/plugins/incompatibilities/
- Competitor: https://github.com/mehul0810/aculect-icon-library
