#!/usr/bin/env bash
# Creates labels, milestones and the initial planning issues for Infinite Icons.
# Requires the GitHub CLI (gh) authenticated with access to the repository.
#
# Usage:
#   ./scripts/bootstrap-issues.sh                 # creates everything
#   DRY_RUN=1 ./scripts/bootstrap-issues.sh       # prints what would be created
#
# Safe to re-run: labels and milestones are upserted, issues are skipped if an
# open or closed issue with the same title already exists.

set -euo pipefail

REPO="${REPO:-CoderAbhinav/infinite-icons}"
DRY_RUN="${DRY_RUN:-0}"

run() {
  if [ "$DRY_RUN" = "1" ]; then
    echo "+ $*"
  else
    "$@"
  fi
}

# ---------------------------------------------------------------- labels

label() {
  # label <name> <color> <description>
  run gh label create "$1" --repo "$REPO" --color "$2" --description "$3" --force
}

echo "Creating labels..."
label "area: plugin"        "1D76DB" "Plugin runtime, PHP, storage, registration"
label "area: packs"         "0E8A16" "Pack format, builder, catalog, icon sets"
label "area: admin-ui"      "5319E7" "Admin screens, picker, React code"
label "area: integrations"  "FBCA04" "ACF, SCF, Gravity Forms, Elementor"
label "area: ci"            "BFD4F2" "GitHub Actions, linting, tests, releases"
label "area: docs"          "C2E0C6" "Docs site, readme, developer guides"
label "type: epic"          "3E4B9E" "Groups a milestone's tasks"
label "type: task"          "EDEDED" "A unit of work"
label "type: decision"      "D93F0B" "A recorded design decision with options and reasoning"
label "type: design"        "F9D0C4" "Visual or interaction design work"
label "type: spike"         "FEF2C0" "Time boxed investigation, outcome is knowledge"
label "hosting: vip"        "B60205" "WordPress VIP compatibility"
label "good first issue"    "7057FF" "Good for newcomers"
label "help wanted"         "008672" "Extra attention is needed"

# ------------------------------------------------------------ milestones

milestone() {
  # milestone <title> <description>
  if [ "$DRY_RUN" = "1" ]; then
    echo "+ milestone: $1"
    return
  fi
  local existing
  existing=$(gh api "repos/$REPO/milestones?state=all&per_page=100" --jq ".[] | select(.title == \"$1\") | .number" || true)
  if [ -z "$existing" ]; then
    gh api "repos/$REPO/milestones" -f title="$1" -f description="$2" >/dev/null
    echo "  created milestone: $1"
  else
    echo "  milestone exists: $1"
  fi
}

echo "Creating milestones..."
milestone "0.1 Foundations"   "Repo scaffold, CI, contributing docs, first decisions recorded."
milestone "0.2 Pack pipeline" "Pack format spec and builder. Lucide and Material Symbols published as releases."
milestone "0.3 Core runtime"  "Storage, installer, loader, registration, custom icons, public API."
milestone "0.4 Admin UI"      "Brand, designs, Packs / My Icons / Settings screens, shared picker."
milestone "1.0 Launch"        "ACF / SCF field, WordPress.org listing, Playground demo, docs, announcement."
milestone "1.1 Gravity Forms" "Icon field for Gravity Forms."
milestone "1.2 Elementor"     "Icon library tab for the Elementor editor."

# ---------------------------------------------------------------- issues

issue() {
  # issue <title> <labels> <milestone>   body on stdin
  local title="$1" labels="$2" ms="$3" body
  body=$(cat)
  if [ "$DRY_RUN" = "1" ]; then
    echo "+ issue [$ms] ($labels): $title"
    return
  fi
  local existing
  existing=$(gh issue list --repo "$REPO" --state all --search "in:title \"$title\"" --json title --jq ".[] | select(.title == \"$title\") | .title" || true)
  if [ -n "$existing" ]; then
    echo "  skip (exists): $title"
    return
  fi
  printf '%s\n' "$body" | gh issue create --repo "$REPO" --title "$title" --label "$labels" --milestone "$ms" --body-file - >/dev/null
  echo "  created: $title"
}

echo "Creating issues..."

# ======================================================= 0.1 Foundations

issue "Epic: Project foundations" "type: epic,area: ci,area: docs" "0.1 Foundations" <<'EOF'
## Goal
Have a repository that a stranger can clone, understand and contribute to before any feature code exists.

## Scope
- Repository scaffold with the plugin at the root
- CI that enforces coding standards and runs tests
- Contributing guide, code of conduct, templates
- The first design decisions recorded in the open

## Done when
Every task in the 0.1 milestone is closed and the README explains what the project is, how to run it locally and how to contribute.
EOF

issue "Scaffold the repository" "type: task,area: plugin,area: ci" "0.1 Foundations" <<'EOF'
## Goal
Create the initial file layout so the plugin, the pack builder and the docs all live in this one repository.

## Scope
- Plugin at the repo root: `infinite-icons.php`, `src/` (PSR-4, namespace `Infinite\Icons`), `assets/src/`, `tests/`, `readme.txt`
- `packs/` for the builder, source configs, `index.json` and license templates
- `docs/` for the GitHub Pages site
- `.distignore` so `packs/`, `docs/`, `tests/`, `node_modules/` and dev config never ship in the WordPress.org zip
- `package.json` with npm workspaces for `assets` and `packs/builder`
- `composer.json` with autoloading and dev dependencies (PHPCS rulesets, PHPUnit)
- `.wp-env.json` for local development
- `.editorconfig`, `.gitattributes`, `.nvmrc`

## Acceptance criteria
- `npm install && npm run build` produces the plugin assets
- `wp-env start` boots a site with the plugin active
- Cloning the repo into `wp-content/plugins/` works as a plugin without a build step for PHP
EOF

issue "Decision: minimum WordPress and PHP versions" "type: decision,area: plugin" "0.1 Foundations" <<'EOF'
## Context
The plugin depends on `wp_register_icon_collection()` and `wp_register_icon()` which are public API in WordPress 7.1. There is no point supporting anything older.

## Options
1. WordPress 7.1+, PHP 8.1+
2. WordPress 7.1+, PHP 7.4+ to match core's floor

## Proposed
Option 1. PHP 8.1 gives us enums, readonly properties and typed code that is easier to maintain. Hosts that care about a new icon plugin are on modern PHP.

## Done when
`Requires at least`, `Requires PHP` and the CI matrix reflect the decision and it is written in `docs/decisions/0001-minimum-versions.md`.
EOF

issue "CI: coding standards, Plugin Check and tests" "type: task,area: ci" "0.1 Foundations" <<'EOF'
## Goal
Every pull request is checked automatically. Nothing merges that would fail WordPress.org review or WordPress VIP review.

## Scope
- PHPCS with the WordPress Coding Standards and the WordPress VIP Go ruleset (`WordPress-VIP-Go` plus `WordPressVIPMinimum`)
- PHPStan at a sensible level with the WordPress stubs
- WordPress Plugin Check action
- PHPUnit through `wp-env`
- ESLint and Stylelint via `@wordpress/scripts`
- Playwright smoke test that loads the editor and inserts an Icon block (can start as a placeholder)
- Matrix: minimum and latest supported WordPress and PHP

## Acceptance criteria
- A `plugin-ci.yml` workflow runs on pull requests and pushes to `main`
- The README shows the CI badge
- Local commands mirror CI: `composer lint`, `composer test`, `npm run lint`
EOF

issue "Contributing guide, code of conduct and templates" "type: task,area: docs,good first issue" "0.1 Foundations" <<'EOF'
## Goal
Make it obvious how to contribute, report bugs and propose packs.

## Scope
- `CONTRIBUTING.md`: local setup, branch naming, commit style, how to add a pack, how to run tests
- `CODE_OF_CONDUCT.md` (Contributor Covenant)
- Issue templates: bug report, feature request, new pack request, decision proposal
- Pull request template with a checklist (tests, docs, changelog entry)
- `docs/decisions/` folder with a short README explaining the decision record format
- `SECURITY.md` with how to report vulnerabilities privately

## Acceptance criteria
- Opening a new issue on GitHub offers the templates
- The decision record template is used by the existing decision issues
EOF

issue "Decision: collection slug naming" "type: decision,area: packs,area: plugin" "0.1 Foundations" <<'EOF'
## Context
Every icon is stored in content as `collection/icon-name`. The collection slug is part of the user's data forever. Other plugins may also register popular sets. The WordPress 7.1 dev note discussion already raises the risk of the same library registered under several slugs.

## Options
1. Canonical, unprefixed slugs: `lucide`, `material-symbols`, `heroicons`
2. Prefixed slugs: `infinite-lucide`, `infinite-material-symbols`

## Proposed
Option 1. Content stays portable if a site later swaps this plugin for another that uses the same canonical slug. Before registering a collection we check whether it already exists and skip if so, so we never clash with another plugin that got there first. We document the canonical slug for every pack and invite other plugin authors to use the same ones.

## Done when
Documented in `docs/decisions/0002-collection-slugs.md` and the pack format spec references it.
EOF

issue "Decision: licensing policy for the plugin and for packs" "type: decision,area: packs,area: docs" "0.1 Foundations" <<'EOF'
## Context
The plugin is distributed through WordPress.org and must be GPL compatible. Icon sets come under a mix of licenses and we redistribute modified copies (strokes outlined to fills, shapes flattened).

## Proposed policy
- Plugin code: GPL-2.0-or-later
- Packs are built from permissively licensed sets only: MIT, ISC, Apache-2.0, CC BY 4.0, CC0
- No pack is bundled inside the WordPress.org zip. Packs are downloaded from GitHub Releases at runtime or committed by the site owner. This avoids mixing Apache-2.0 assets into the GPL-2.0 distribution.
- Every pack zip carries `LICENSE`, `NOTICE` where the upstream has one, and a generated `MODIFICATIONS.md` that states what was changed and the exact upstream version
- The Packs screen shows license and attribution for every pack
- Brand and logo sets (Font Awesome brands, Simple Icons) ship with a note that trademarks belong to their owners

## Initial allow list
Material Symbols (Apache-2.0), Lucide (ISC), Heroicons, Tabler, Phosphor, Feather, Bootstrap Icons, Ionicons, Iconoir, Octicons, Radix, Fluent UI System Icons (MIT), Remix Icon (Apache-2.0), Font Awesome Free icons (CC BY 4.0), Simple Icons (CC0)

## Done when
Documented in `docs/decisions/0003-licensing.md` and linked from `CONTRIBUTING.md` under "adding a pack".
EOF

issue "Docs site skeleton on GitHub Pages" "type: task,area: docs" "0.1 Foundations" <<'EOF'
## Goal
A place for user and developer documentation that costs nothing and builds from the repo.

## Scope
- Static site in `docs/` (VitePress or Astro, whichever is simpler to maintain)
- Sections: Getting started, Packs, Integrations, Developer API, Decisions, Contributing
- Workflow that deploys `docs/` to GitHub Pages on push to `main`
- The decision records in `docs/decisions/` render on the site

## Acceptance criteria
- The site is live at the GitHub Pages URL and linked from the README
EOF

# ===================================================== 0.2 Pack pipeline

issue "Epic: Pack format and build pipeline" "type: epic,area: packs" "0.2 Pack pipeline" <<'EOF'
## Goal
A single pack format that every integration consumes, and a builder that turns any upstream icon set into a pack that renders correctly through WordPress core's sanitizer.

## Why this comes before the plugin
Core's SVG sanitizer keeps only `svg`, `path` and `polygon` elements and does not allow `stroke`. Stroke based sets (Lucide, Feather, Tabler, Heroicons outline, Phosphor) must be converted to filled outlines at build time or they will not render. If this step works, the rest of the plugin is plumbing. If it does not, the product changes.

## Done when
Lucide and Material Symbols are published as GitHub Releases, `packs/index.json` lists them, and a spike confirms `wp_get_icon()` renders both correctly.
EOF

issue "Spec: pack format v1" "type: task,area: packs,area: docs" "0.2 Pack pipeline" <<'EOF'
## Goal
Write down the exact contract for a pack so the builder, the plugin and third parties agree.

## Proposed format
```
<slug>-<version>.zip
  pack.json          slug, label, version, upstream, upstream_version, license, license_url, attribution, icon_count, styles[], built_at, format_version
  icons.json         [{ name, label, keywords[], categories[], style, content }]
  LICENSE
  NOTICE             only when the upstream has one
  MODIFICATIONS.md   generated
```

- `content` is inline, pre-sanitized, fill only SVG with `viewBox="0 0 24 24"` and `fill="currentColor"` on shapes
- Icons carry inline content rather than per file SVGs so a pack is one read and one cache entry
- `name` follows core's rule: lowercase letters, digits, hyphens, underscores, must start and end with a letter or digit
- `format_version` allows the plugin to refuse or migrate packs it does not understand
- A JSON Schema for both files lives in `packs/schema/`

## Acceptance criteria
- `docs/packs/format.md` describes every field with examples
- JSON Schemas exist and the builder validates its output against them
EOF

issue "Builder: normalize SVGs to core's allowlist" "type: task,area: packs" "0.2 Pack pipeline" <<'EOF'
## Goal
Take any upstream SVG and produce one that survives `wp_kses` in core unchanged.

## Scope
- Node builder in `packs/builder/` using svgo plus custom plugins
- Convert `circle`, `rect`, `ellipse`, `line`, `polyline` to `path`
- Flatten `g` and apply transforms
- Normalize `viewBox` to `0 0 24 24` and drop width and height
- Strip every attribute not on the core allowlist, keep `fill`, set `fill="currentColor"` on shapes
- Remove `style`, `class`, ids, comments, metadata
- Deterministic output so rebuilding the same upstream version gives an identical file

## Acceptance criteria
- Unit tests with fixture SVGs for each shape conversion
- A verification step runs the output through a PHP script that calls core's sanitizer and asserts the markup is unchanged
EOF

issue "Builder: convert strokes to outlined fills" "type: task,area: packs,type: spike" "0.2 Pack pipeline" <<'EOF'
## Goal
Stroke based icon sets must render through core, which does not allow `stroke`. Outline every stroke into a filled path.

## Approach to evaluate
- Path outlining libraries (for example paper.js style offsetting, or the outlining used by font pipelines) at the upstream stroke width, with round or square caps and joins as the set specifies
- Compare rendered output against the original at 24px, 48px and 96px
- Handle overlapping sub paths so fill rules do not create holes

## Acceptance criteria
- Lucide builds with no visible difference at 24px against the upstream rendering
- Known imperfect icons are listed in the pack's `MODIFICATIONS.md`
- The outlining step is optional per source config so fill based sets skip it
EOF

issue "Builder: source configs for Lucide and Material Symbols" "type: task,area: packs" "0.2 Pack pipeline" <<'EOF'
## Goal
The first two packs, chosen because one is stroke based and one is fill based.

## Scope
- `packs/sources/lucide.json` and `packs/sources/material-symbols.json`
- Each config states the upstream package or repo, the version, how to map file names to icon names, where keywords and categories come from, license info and whether outlining is needed
- Material Symbols is large. Decide which styles ship in v1 (outlined only is a reasonable start) and record it in the config

## Acceptance criteria
- `npm run build -- lucide` and `npm run build -- material-symbols` produce valid pack zips that pass schema validation
EOF

issue "Builder: generate LICENSE, NOTICE and MODIFICATIONS.md" "type: task,area: packs" "0.2 Pack pipeline" <<'EOF'
## Goal
Every pack carries the notices its license requires without anyone doing it by hand.

## Scope
- Copy the upstream LICENSE text and NOTICE when present
- Generate `MODIFICATIONS.md` from a template: upstream name, version, source URL, list of transformations applied, build date
- Fail the build if a source config has no license or the license is not on the allow list from the licensing decision

## Acceptance criteria
- Unit test that a build without a license field fails
- Both first packs contain the three files
EOF

issue "CI: build packs and publish them as GitHub Releases" "type: task,area: ci,area: packs" "0.2 Pack pipeline" <<'EOF'
## Goal
Packs are built by Actions and hosted on GitHub Releases, which is our free CDN.

## Scope
- `packs-build.yml` runs on changes to `packs/**` and on a weekly schedule
- Builds only the packs whose source config changed (or all on manual dispatch)
- Tags `pack/<slug>@<version>`, creates a release, attaches the zip and a `.sha256` file
- Updates `packs/index.json` with the new version, size, hash and download URL and opens a pull request with the change

## Acceptance criteria
- A release exists for `pack/lucide@<version>` and `pack/material-symbols@<version>`
EOF

issue "Pack catalog: packs/index.json served via jsDelivr" "type: task,area: packs,area: plugin" "0.2 Pack pipeline" <<'EOF'
## Goal
The plugin needs one URL that lists every available pack.

## Scope
- `packs/index.json` schema: catalog version, generated date, packs[] with slug, label, description, version, icon_count, styles, license, size, sha256, download_url, preview icon names
- Served from `https://cdn.jsdelivr.net/gh/CoderAbhinav/infinite-icons@main/packs/index.json`
- A snapshot of the file is copied into the plugin at build time so the Packs screen works with no network
- The plugin fetches the live catalog at most once a day and only from the admin

## Acceptance criteria
- The URL returns the catalog and the plugin build includes the snapshot
EOF

issue "Renovate: track upstream icon set releases" "type: task,area: ci,area: packs" "0.2 Pack pipeline" <<'EOF'
## Goal
Pack updates should cost one click.

## Scope
- Renovate config that watches the upstream npm packages or GitHub releases referenced in `packs/sources/*.json`
- A version bump PR triggers the packs build workflow so the PR shows whether the new upstream still builds cleanly

## Acceptance criteria
- A test bump of Lucide produces a PR with a passing build
EOF

issue "Add more packs" "type: task,area: packs,help wanted,good first issue" "0.2 Pack pipeline" <<'EOF'
## Goal
Grow the catalog once the pipeline is stable. Each of these is a new source config plus a build. Open a separate pull request per pack.

- [ ] Heroicons (MIT)
- [ ] Tabler Icons (MIT)
- [ ] Phosphor (MIT)
- [ ] Feather (MIT)
- [ ] Bootstrap Icons (MIT)
- [ ] Ionicons (MIT)
- [ ] Iconoir (MIT)
- [ ] Octicons (MIT)
- [ ] Radix Icons (MIT)
- [ ] Fluent UI System Icons (MIT)
- [ ] Remix Icon (Apache-2.0)
- [ ] Font Awesome Free (CC BY 4.0, brands are trademarks)
- [ ] Simple Icons (CC0, all logos are trademarks)

## How
Read `docs/packs/adding-a-pack.md`, copy an existing source config, run the build locally, check a few icons visually, open a PR.
EOF

# ====================================================== 0.3 Core runtime

issue "Epic: Core plugin runtime" "type: epic,area: plugin" "0.3 Core runtime" <<'EOF'
## Goal
The plugin can install a pack, load it, register it with WordPress core's icon registry and render icons on the front end, with no admin UI yet.

## Scope
- Storage abstraction with filesystem and database implementations
- Installer that pulls packs from the catalog
- Loader with caching
- Registration into the core registry, full in admin, lazy on the front end
- Custom icons stored in the database
- Public PHP API and hooks
- Own REST endpoints for the admin UI to use later

## Done when
On a fresh site: `wp infinite-icons install lucide` then an Icon block using `lucide/home` renders on the front end, and the same works on a WordPress VIP sandbox.
EOF

issue "Spike: registration timing and front end rendering" "type: spike,area: plugin" "0.3 Core runtime" <<'EOF'
## Goal
Confirm two assumptions before building on them.

1. `wp_register_icon()` works when called after `init`, for example inside a `render_block_data` filter for `core/icon`. The dev note shows registration on `init` by convention only.
2. `wp_get_icon()` output for a Lucide icon converted by our builder is visually identical to the upstream icon.

## Method
Throwaway code in a test plugin on wp-env. Record findings in this issue and, if registration must happen on `init`, design the front end strategy differently (for example a per page usage index).

## Outcome
A short write up and a decision on the front end registration approach.
EOF

issue "Storage: PackStore interface with filesystem and database implementations" "type: task,area: plugin,hosting: vip" "0.3 Core runtime" <<'EOF'
## Goal
Where a pack lives on a given host is an implementation detail the rest of the plugin never sees.

## Design
```php
interface PackStore {
    public function write( string $slug, string $version, string $json ): void;
    public function read( string $slug ): ?string;
    public function delete( string $slug ): void;
    public function exists( string $slug ): bool;
}
```
- `FilesystemStore`: one file per pack at `wp_upload_dir()['basedir'] . '/infinite-icons/<slug>-<version>.json'`, written through `WP_Filesystem`. Registers a hidden attachment post for each file so it can be deleted on hosts where orphan files cannot be removed.
- `DatabaseStore`: one `ii_pack` post per pack with the JSON in `post_content`.
- The list of installed packs and their versions lives in an option. `exists()` reads the option, never the disk.
- Store choice is filterable: `infinite_icons_pack_store`.

## WordPress VIP notes
- Every filesystem call is an HTTP request on VIP and costs at least 200ms, so one file per pack and no directory scans
- No `mkdir`, `is_dir` or `glob`, the object store has no real directories
- Always derive paths from `wp_upload_dir()`

## Acceptance criteria
- Unit tests for both stores
- A pack can be written, read back and deleted through either store
EOF

issue "Installer: download, verify and install packs" "type: task,area: plugin" "0.3 Core runtime" <<'EOF'
## Goal
Install a pack from the catalog safely.

## Scope
- Fetch the catalog (see the catalog issue), cache it for a day
- Download the release zip with `wp_remote_get`, verify size and sha256 before doing anything with it
- Extract `pack.json` and `icons.json` in memory, validate against the schema, sanitize every icon again with the same rules core uses
- Hand the compiled JSON to the `PackStore`
- Record slug, version, hash and enabled state in the installed packs option
- Update and uninstall paths
- Respect `DISALLOW_FILE_MODS`: when set, installing is disabled and the code managed directory is the only route
- Code managed packs: any pack zip or extracted folder placed in `wp-content/infinite-icons-packs/` is picked up without a runtime write. Path filterable.
- WP-CLI commands: `wp infinite-icons list|install|update|uninstall|enable|disable`

## Acceptance criteria
- Installing Lucide on wp-env through WP-CLI works and the pack renders
- A tampered zip (wrong hash) is rejected with a clear error
EOF

issue "Loader and caching" "type: task,area: plugin,hosting: vip" "0.3 Core runtime" <<'EOF'
## Goal
Reading a pack is almost never a disk or database hit.

## Scope
- `PackLoader::get( $slug )` returns a `Pack` object
- Order: in request static cache, `wp_cache_get`, then store read and `wp_cache_set`. On hosts without a persistent object cache fall back to a transient.
- Cache keys include the pack version so updates invalidate automatically
- An icon index (name to pack) kept in the option so a single icon lookup does not load every pack
- Filters: `infinite_icons_pack_data`, `infinite_icons_cache_ttl`

## Acceptance criteria
- A front end request that renders one icon performs at most one store read on a cold cache and zero on a warm cache, verified with Query Monitor
EOF

issue "Registration into the core icon registry" "type: task,area: plugin" "0.3 Core runtime" <<'EOF'
## Goal
Icons from enabled packs appear in the core Icon block picker and render through `wp_get_icon()`.

## Scope
- Admin, REST and editor requests: on `init`, register every enabled pack in full with `wp_register_icon_collection()` and `wp_register_icon()` using inline `content`
- Front end requests: register only what is about to render. Hook `render_block_data` for `core/icon`, read the icon name, register that single icon. Depends on the registration timing spike.
- Skip registering a collection whose slug already exists (see the slug decision) and log it in a site health test
- Filter to force full registration everywhere for edge cases: `infinite_icons_register_all`

## Acceptance criteria
- Icons from an enabled pack show up as a tab in the Icon block picker
- A page with one Icon block registers exactly one icon on the front end
- Disabling a pack removes its tab without touching content
EOF

issue "Custom icons: post type, sanitizer and REST" "type: task,area: plugin" "0.3 Core runtime" <<'EOF'
## Goal
Users can upload their own SVGs and use them like any pack icon.

## Scope
- `ii_icon` post type, not public, SVG stored in `post_content`, label in `post_title`, keywords in meta
- Server side SVG sanitizer that matches core's allowlist and additionally rejects scripts, external references, `foreignObject` and event handlers
- Automatically run the same normalization the builder does (shapes to paths, viewBox) in PHP where feasible, otherwise reject with a helpful message
- Registered as a collection with slug `custom`
- REST routes under `infinite-icons/v1/icons` for create, update, delete, list, restricted to users who can `upload_files` and `edit_posts`
- Bulk import from a zip of SVGs

## Acceptance criteria
- An uploaded icon renders through `wp_get_icon( 'custom/<name>' )`
- A malicious SVG fixture set is rejected in tests
EOF

issue "Public PHP API, hooks and semver policy" "type: task,area: plugin,area: docs" "0.3 Core runtime" <<'EOF'
## Goal
Theme and plugin developers can rely on Infinite Icons without touching internals.

## Scope
- Functions: `infinite_icons()` service locator, `infinite_icons_get( $name, $args )` wrapper around `wp_get_icon()` that also handles lazy registration, `infinite_icons_register_pack( $path_or_array )`, `infinite_icons_packs()`
- Documented actions and filters, all prefixed `infinite_icons_`
- A versioning policy: anything documented in `docs/developer/` follows semver, internals may change in minor releases
- Deprecation helper that logs through `_deprecated_function()`

## Acceptance criteria
- `docs/developer/api.md` lists every public function and hook with an example
EOF

issue "REST endpoints for the admin UI" "type: task,area: plugin" "0.3 Core runtime" <<'EOF'
## Goal
The React admin talks to the plugin through a small, permission checked API.

## Scope
- Namespace `infinite-icons/v1`
- `GET packs/catalog`, `GET packs`, `POST packs/<slug>/install`, `POST packs/<slug>/update`, `DELETE packs/<slug>`, `PATCH packs/<slug>` for enable and disable
- `GET settings`, `PATCH settings`
- Capability `manage_options` for pack and settings routes
- Long operations (install) return progress friendly responses, no PHP timeouts on large packs

## Acceptance criteria
- REST tests for every route including permission failures
EOF

issue "Uninstall and data cleanup" "type: task,area: plugin,good first issue" "0.3 Core runtime" <<'EOF'
## Goal
Deleting the plugin leaves nothing behind unless the user asked to keep data.

## Scope
- `uninstall.php` removes options, transients, `ii_pack` and `ii_icon` posts and pack files through the store, including their attachment records
- A setting "keep data on uninstall" defaulting to off
- Site health test that reports orphaned pack files

## Acceptance criteria
- After uninstall the database and uploads contain no plugin data on wp-env
EOF

issue "WordPress VIP compatibility checklist and sandbox test" "type: task,area: plugin,hosting: vip" "0.3 Core runtime" <<'EOF'
## Goal
Prove the runtime works on WordPress VIP, not just believe it.

## Checklist
- No writes outside `wp-content/uploads`, all writes via `WP_Filesystem` or the stream wrapper
- No `mkdir`, `glob`, `is_dir`, `chmod`
- Minimal `file_exists` calls, state kept in options
- Every pack file has an attachment record so it can be deleted
- Confirm `.json` written through the wrapper is accepted by the VIP file type rules. If not, default to `DatabaseStore` on VIP.
- Object cache used for every pack read
- `DISALLOW_FILE_MODS` path documented for git deployed sites
- Passes `WordPress-VIP-Go` PHPCS ruleset with no exclusions

## Acceptance criteria
- Findings recorded in `docs/hosting/wordpress-vip.md` including which store is the default on VIP
EOF

# ======================================================= 0.4 Admin UI

issue "Epic: Admin UI" "type: epic,area: admin-ui" "0.4 Admin UI" <<'EOF'
## Goal
An admin experience where a new user installs a pack and uses an icon in under thirty seconds, and that looks like it belongs in wp-admin.

## Screens
1. Packs: the product for most users
2. My Icons: upload and manage custom SVGs
3. Settings: integrations, rendering, import and export

Plus one shared icon picker component reused by every integration.

## Done when
All three screens ship, the picker is used by the ACF field, and an accessibility review has been done.
EOF

issue "Design: Infinite brand and admin visual language" "type: design,area: admin-ui" "0.4 Admin UI" <<'EOF'
## Goal
Define the look of Infinite as a brand and of Infinite Icons inside wp-admin.

## Scope
- Logo mark: a lemniscate that reads well at 16px (plugin menu icon) and at large sizes
- One accent colour that works on wp-admin light and dark backgrounds, checked for contrast
- Typography and spacing follow `@wordpress/components` so the screens feel native
- Figma file with: brand sheet, the three admin screens, the picker, empty states, pack card states (available, installing, installed, update available, disabled), error states
- Export of the menu icon as SVG

## Acceptance criteria
- Figma link added to this issue and to `docs/design/`
- Components in Figma named after the `@wordpress/components` they map to
EOF

issue "Decision: admin menu placement" "type: decision,area: admin-ui" "0.4 Admin UI" <<'EOF'
## Options
1. Top level "Infinite" menu with "Icons" under it, ready for future Infinite products
2. Appearance > Icons, mirroring Appearance > Fonts in core

## Proposed
Option 2 for the plugin on its own. If a second Infinite product is installed later, a shared top level menu can be introduced by the products themselves. Users find icons where they expect to find fonts.

## Done when
Recorded in `docs/decisions/0004-menu-placement.md`.
EOF

issue "Packs screen" "type: task,area: admin-ui" "0.4 Admin UI" <<'EOF'
## Goal
Browse, install, enable and update packs.

## Scope
- React app built with `@wordpress/scripts`, `@wordpress/components`, `@wordpress/data`
- Grid of pack cards: preview of six icons, name, icon count, license badge, version, action button, update available badge
- Filters: installed, style (filled, outlined, duotone), license, search
- Card states: available, installing with progress, installed and enabled, installed and disabled, update available, error
- Pack detail panel: description, attribution, upstream link, full icon preview with search
- Install disabled with an explanation when `DISALLOW_FILE_MODS` is set, showing the code managed path instead

## Acceptance criteria
- Install to first icon in the block editor in under thirty seconds on a fresh site
- Playwright test covering install, disable, enable
EOF

issue "My Icons screen" "type: task,area: admin-ui" "0.4 Admin UI" <<'EOF'
## Goal
Manage custom icons without touching code.

## Scope
- Drag and drop upload of one or many SVGs, or a zip
- Inline preview, rename, keywords, delete
- Clear error messages when an SVG is rejected, with the reason (stroke based, unsupported element, script)
- Uses the custom icons REST routes

## Acceptance criteria
- Uploaded icons appear in the picker under the "Custom" tab immediately
EOF

issue "Settings screen" "type: task,area: admin-ui" "0.4 Admin UI" <<'EOF'
## Goal
Everything configurable in one place, and portable between sites.

## Scope
- Integrations: auto detected (Elementor, ACF, SCF, Gravity Forms), each toggleable
- Front end registration mode: lazy (default) or full
- Storage: which store is in use, code managed directory path
- Keep data on uninstall
- Export and import configuration as JSON (installed packs, enabled state, settings), useful for agencies and for staging to production

## Acceptance criteria
- Import on a fresh site reproduces the exported state, installing packs as needed
EOF

issue "Shared icon picker component" "type: task,area: admin-ui,area: integrations" "0.4 Admin UI" <<'EOF'
## Goal
One picker used by the ACF field, the Gravity Forms field, the Elementor tab and any future context.

## Scope
- Published from `assets/src/picker/` as a component and also exposed on `window.infiniteIcons.Picker` for non React contexts
- Search across packs using name, label and keywords
- Tabs per pack plus All and Recent
- Virtualized grid so ten thousand icons scroll smoothly
- Full keyboard navigation and screen reader labels
- Reads icons from core's `wp/v2/icons` endpoints so it stays consistent with the block picker
- Returns the `collection/name` string

## Acceptance criteria
- Storybook or a demo page for the picker
- Playwright test for search and keyboard selection
EOF

issue "Empty states and first run experience" "type: task,area: admin-ui,type: design" "0.4 Admin UI" <<'EOF'
## Goal
No wizard. The first visit to Packs is the onboarding.

## Scope
- Empty Packs state: short explanation and three recommended packs with one click install
- Empty My Icons state: drop zone with a one line explanation of what an SVG needs to look like
- A dismissible notice after the first install pointing to the Icon block
- No admin notices anywhere else, ever

## Acceptance criteria
- Reviewed against the Figma designs
EOF

issue "Accessibility review of the admin screens and picker" "type: task,area: admin-ui,help wanted" "0.4 Admin UI" <<'EOF'
## Goal
WCAG 2.1 AA for everything we ship in wp-admin.

## Scope
- Keyboard only walkthrough of all screens and the picker
- Screen reader pass with NVDA or VoiceOver
- Colour contrast check of the brand accent on light and dark admin schemes
- Focus management in modals and the picker
- Fixes filed as follow up issues

## Acceptance criteria
- A written report attached to this issue with no open AA blockers
EOF

# ========================================================= 1.0 Launch

issue "Epic: 1.0 launch" "type: epic,area: integrations,area: docs" "1.0 Launch" <<'EOF'
## Goal
Ship on WordPress.org with the core Icon block and ACF / SCF support, and tell people about it.

## Done when
The plugin is live on WordPress.org, the Playground demo works, the docs cover users and developers, and the announcement is published.
EOF

issue "ACF and SCF icon field type" "type: task,area: integrations" "1.0 Launch" <<'EOF'
## Goal
An "Icon" field for Advanced Custom Fields and Secure Custom Fields.

## Scope
- Field class extending `acf_field`, registered only when ACF or SCF is active
- Uses the shared picker
- Stores the `collection/name` string
- Return format setting: name, SVG markup via `wp_get_icon()`, or array with both
- Settings: allowed packs, default icon, allow custom icons
- Works in the block editor, classic meta boxes, options pages and repeaters
- `acf/format_value` integration and a `get_field()` example in the docs

## Acceptance criteria
- PHPUnit tests for save and format, Playwright test for selecting an icon in a field group
EOF

issue "WordPress.org listing: readme.txt, assets and submission" "type: task,area: docs,area: ci" "1.0 Launch" <<'EOF'
## Goal
A listing that explains the plugin in one screen.

## Scope
- `readme.txt` with a clear description, FAQ (hosting, VIP, licensing, where packs come from), screenshots, changelog
- Banner and icon assets in `.wordpress-org/`
- `plugin-release.yml` that deploys tagged releases to SVN using the standard action, including assets
- Submit for review and track feedback in this issue

## Acceptance criteria
- Plugin approved and version 1.0.0 live
EOF

issue "WordPress Playground demo blueprint" "type: task,area: docs,good first issue" "1.0 Launch" <<'EOF'
## Goal
Try the plugin in the browser without installing anything.

## Scope
- `blueprint.json` that installs the plugin, installs Lucide from the catalog, enables it and lands on a post with a few Icon blocks
- Link in the README and the WordPress.org description

## Acceptance criteria
- The Playground link works from a fresh browser
EOF

issue "Documentation for users and developers" "type: task,area: docs" "1.0 Launch" <<'EOF'
## Goal
Nobody needs to read the code to use or extend the plugin.

## Scope
- User: installing packs, using icons in the block editor, custom icons, ACF field, hosting notes including WordPress VIP and `DISALLOW_FILE_MODS`
- Developer: pack format, adding a pack, public API, hooks, storage adapters, registration behaviour
- Decision records published

## Acceptance criteria
- Every screen and every public function is documented on the docs site
EOF

issue "Launch announcement and outreach" "type: task,area: docs" "1.0 Launch" <<'EOF'
## Goal
Reach the people who struggle with icons in WordPress.

## Scope
- Announcement post on the docs site
- Short demo video or GIF of install to first icon
- Posts on the usual WordPress community channels, Make WordPress Slack where appropriate, and the block editor community
- Reach out to a few theme and block authors about using the canonical slugs

## Acceptance criteria
- Announcement published, links collected in this issue
EOF

# =================================================== 1.1 Gravity Forms

issue "Gravity Forms icon field" "type: task,area: integrations" "1.1 Gravity Forms" <<'EOF'
## Goal
Icons inside Gravity Forms, both as a field and as choice decoration.

## Scope
- `GF_Field` subclass "Icon" that renders the shared picker in the form editor for a default value and a simple selector on the front end
- Per choice icons on radio, checkbox and select fields, rendered with `wp_get_icon()`
- Merge tag that outputs the SVG or the name
- Entry display and export show the icon name

## Acceptance criteria
- Works on the latest Gravity Forms with tests for save and render
EOF

# ====================================================== 1.2 Elementor

issue "Spike: how to add SVG based libraries to the Elementor icon picker" "type: spike,area: integrations" "1.2 Elementor" <<'EOF'
## Goal
Elementor's icon library tabs are built around icon fonts (CSS class prefix and a JSON list). We ship SVGs. Find the least invasive way in.

## Options to evaluate
1. Build an icon font per pack in the builder (woff2 and CSS) and register it as a tab through `elementor/icons_manager/additional_tabs`
2. Register a tab and intercept rendering so the icon is output as inline SVG through `wp_get_icon()`
3. A dedicated Infinite Icons widget instead of extending the native picker

## Outcome
A written comparison and a recommendation, recorded as a decision.
EOF

issue "Elementor icon library tab" "type: task,area: integrations" "1.2 Elementor" <<'EOF'
## Goal
Every enabled pack appears in the Elementor icon picker.

## Scope
Depends on the Elementor spike. Implement the recommended approach, keep the pack format unchanged, and add whatever build output is required (for example woff2) as an optional artifact.

## Acceptance criteria
- A Lucide icon can be picked in an Elementor Icon widget and renders on the front end
EOF

echo "Done."
