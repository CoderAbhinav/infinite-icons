# Infinite Icons Roadmap

Infinite Icons is a free, open source WordPress plugin. Install it once, pick an icon pack, and that pack shows up everywhere you choose icons: the core Icon block, Elementor, ACF / SCF fields and Gravity Forms.

This document is the human readable overview. The GitHub issues and milestones are the source of truth for status. Every decision that shapes the project is recorded as a `type: decision` issue so anyone can read why things are the way they are.

## Principles

1. Store icon names, never SVG markup. Content holds `pack-slug/icon-name` and rendering goes through `wp_get_icon()`.
2. One pack format for every integration. A pack is a single compiled JSON file plus license artifacts.
3. Works on any host, including WordPress VIP. No writes outside `wp-content/uploads`, no assumptions about a local filesystem, everything cached.
4. Zero hosting cost. GitHub for code, Releases as the pack CDN, Actions for builds, Pages for docs, WordPress.org for distribution.
5. Ship only permissively licensed icon sets and carry every required notice.
6. Boring, predictable engineering. Public PHP API with a semver promise, tests, coding standards enforced in CI.

## Milestones

| Milestone | Outcome |
|---|---|
| 0.1 Foundations | Repo scaffold, CI, contributing docs, first decisions recorded |
| 0.2 Pack pipeline | Pack format spec, builder that outlines strokes and sanitizes SVGs, Lucide and Material Symbols built and published as releases |
| 0.3 Core runtime | Storage, installer, loader, registration into the core icon registry, custom icons, public API |
| 0.4 Admin UI | Brand, Figma designs, Packs / My Icons / Settings screens, shared icon picker |
| 1.0 Launch | ACF / SCF field, WordPress.org listing, Playground demo, docs, announcement |
| 1.1 Gravity Forms | Icon field for Gravity Forms |
| 1.2 Elementor | Icon library tab for the Elementor editor |

## Repository layout

```
infinite-icons/            the plugin lives at the repo root
  infinite-icons.php
  src/                     PHP, PSR-4, namespace Infinite\Icons
  assets/src/              React admin and editor code
  tests/                   PHPUnit (wp-env) and Playwright
  packs/                   builder, per set source configs, index.json, license templates
  docs/                    GitHub Pages site
  .github/workflows/       plugin CI, plugin release, packs build
  .distignore              keeps packs/, docs/, tests/ out of the WordPress.org zip
```

## Labels

- `area: plugin`, `area: packs`, `area: admin-ui`, `area: integrations`, `area: ci`, `area: docs`
- `type: epic`, `type: task`, `type: decision`, `type: design`, `type: spike`
- `hosting: vip`
- `good first issue`, `help wanted`

## How the issues are organised

Each milestone has one epic issue that links to its tasks. Tasks carry a goal, scope and acceptance criteria. Decision issues describe the options, the choice and the reasoning, then get closed once the decision is documented in `docs/decisions/`.

The labels, milestones and issues are already published in this repository. They are the source of truth
from here, so add, edit and close them on GitHub directly. The one off script that created them has been
removed now that it has run. It is in the git history at the first commit if it is ever needed again.
