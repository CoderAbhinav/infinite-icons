# Infinite Icons

A free, open source WordPress plugin. Install it once, pick an icon pack, and that pack
shows up everywhere icons are chosen: the core Icon block, Elementor, ACF / SCF fields
and Gravity Forms.

## Start here

- `docs/STATE.md` is where things stand right now: verified facts, environment notes and
  what to pick up next. Read this first, it is the shortest path into the work.
- `docs/HANDOVER.md` is the full brief. Read it top to bottom before writing any code.
- `ROADMAP.md` is the human readable overview of milestones and conventions.
- The GitHub issues and milestones are the source of truth for status.

## Rules that are not up for negotiation

Read section 2 and section 4 of `docs/HANDOVER.md`. In short:

- Zero hosting cost. GitHub for code, Releases as the pack CDN, Actions for builds,
  Pages for docs, WordPress.org for distribution.
- Works on every host including WordPress VIP. No writes outside `wp-content/uploads`,
  no reliance on a local filesystem, everything cached.
- Store icon names in content (`pack-slug/icon-name`), never SVG markup. Render through
  core's `wp_get_icon()`.
- One pack format shared by every integration.
- Only permissively licensed icon sets, with every required notice carried.
- WordPress 7.1+, PHP 8.1+.

## Writing style for everything public

Issues, docs, commit messages and UI copy use plain punctuation. No em dashes, no en
dashes, no fancy typography. Use commas, full stops, colons and hyphens.

## Working conventions

- One pull request per issue, reference the issue number, keep a changelog entry.
- Run `composer lint`, `composer test`, `npm run lint` before pushing. CI mirrors these.
- PHPCS rulesets: WordPress Coding Standards plus `WordPress-VIP-Go`. No exclusions
  without a comment explaining why.
- Prefer `@wordpress/*` packages and components over third party UI libraries.
- Ask Abhinav before changing anything in section 2 or section 4 of the handover.

## Local development

This repository is checked out at `wp-content/plugins/infinite-icons` inside a Local
site running WordPress 7.1.2, which is where the icons API landed.

PHP, WP-CLI and Composer are provided by Local and are not on the system PATH. Open
Local, right click the site and choose "Open site shell" to get them. Docker is not used
for testing. Do not modify the WordPress install itself, it is needed for testing.

See `docs/STATE.md` section 2 for core's SVG sanitizer allowlist, read from this
install. It is the constraint every pack and every icon has to satisfy.
