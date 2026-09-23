# Where things stand

Written 23 September 2026. This file records what has been verified against a real
install, what the environment can and cannot do, and what to pick up next. Read it after
`docs/HANDOVER.md`, which is the full brief and does not change often. This file changes
as work lands, so keep it current.

## 1. Repository and issue state

- 47 issues open, 0 closed. Numbering matches `scripts/bootstrap-issues.sh` exactly, so
  nothing was skipped or duplicated. The script has been run and then removed from the
  repo, see commit 6836648.
- 7 milestones, all open: 0.1 Foundations (#1 to #8), 0.2 Pack pipeline (#9 to #18),
  0.3 Core runtime (#19 to #29), 0.4 Admin UI (#30 to #38), 1.0 Launch (#39 to #44),
  1.1 Gravity Forms (#45), 1.2 Elementor (#46 to #47).
- 22 labels: the 14 from the bootstrap script, GitHub's 7 defaults, plus a custom
  `accessibility` label.
- Committed so far: `.gitignore`, `CLAUDE.md`, `ROADMAP.md`, `docs/HANDOVER.md`. No code
  exists yet. Issue #2, the scaffold, has not been started.

## 2. Verified: core's SVG sanitizer allowlist

This is the fact the whole product depends on, so it was read from the running install
rather than taken from the dev note. Source: `wp-includes/class-wp-icons-registry.php`,
method `sanitize_icon_content()`, WordPress 7.1.2.

```php
$allowed_tags = array(
    'svg'     => array(
        'class' => true, 'xmlns' => true, 'width' => true, 'height' => true,
        'viewbox' => true, 'aria-hidden' => true, 'role' => true, 'focusable' => true,
    ),
    'path'    => array(
        'fill' => true, 'fill-rule' => true, 'd' => true, 'transform' => true,
    ),
    'polygon' => array(
        'fill' => true, 'fill-rule' => true, 'points' => true, 'transform' => true,
        'focusable' => true,
    ),
);
return wp_kses( $icon_content, $allowed_tags );
```

What this means in practice:

- Only `svg`, `path` and `polygon` survive. `g`, `circle`, `rect`, `line`, `polyline`,
  `ellipse`, `defs`, `mask`, `clipPath`, `use`, `style` and `title` are all stripped.
- `stroke` is not allowed anywhere. Stroke based sets (Lucide, Feather, Tabler,
  Heroicons outline, Phosphor) will not render unless outlined to fills at build time.
  This confirms the assumption behind issue #12.
- `clip-rule` is NOT on the `path` allowlist, only `fill-rule`. This was not called out
  in the handover and it matters: several sets, Heroicons solid in particular, pair
  `clip-rule="evenodd"` with `fill-rule="evenodd"` on the same path. When `clip-rule` is
  stripped the rendering may change on those icons. Build a fixture test for it.
- `transform` IS allowed on both `path` and `polygon`, so the builder does not strictly
  have to flatten transforms. Flattening is still worth doing for deterministic output
  and smaller files, but it is an optimisation, not a requirement.
- Note `viewbox` is lowercase in the allowlist. `wp_kses` lowercases attribute names, so
  a `viewBox` in source survives, but do not rely on case being preserved downstream.

Core's icon API lives in three files, all present on the test install:
`wp-includes/icons.php`, `class-wp-icons-registry.php`,
`class-wp-icon-collections-registry.php`.

## 3. Local environment

The plugin is checked out at `wp-content/plugins/infinite-icons` inside a Local site.

- Site root: `~/Local Sites/infinite/app/public`
- WordPress 7.1.2
- PHP 8.2.27, WP-CLI 2.11.0, Composer 2.8.6, MySQL 8.0.35, all provided by Local

To get PHP, WP-CLI and Composer on your PATH, open Local, right click the site and
choose "Open site shell". The binaries are macOS builds under
`~/Library/Application Support/Local/lightning-services/`. They are not on the system
PATH and they will not run anywhere other than macOS.

Docker is deliberately not being used for testing. The Local site is the test
environment. Do not modify the WordPress install itself, it is needed for testing.

## 4. What to pick up next

Two spikes decide the shape of the product. Neither needs the scaffold from #2 first,
because both are throwaway code. Do not build CI, the docs site or the contributing
guide before these two are answered, because the answers can change the roadmap.

**#12, stroke to fill outlining.** The highest value task. If outlining Lucide gives
acceptable results the roadmap stands. If it does not, the catalog is limited to fill
based sets (Material Symbols, Font Awesome, Simple Icons, Remix Icon) and the
differentiation story loses one of its three legs. Pure Node plus a PHP verification
step, no WordPress boot needed.

Open question that should be settled before calling this done: what is the quality bar?
"Pixel identical or it does not ship" is probably not achievable, because some icons in
any stroke set have self intersecting paths or joins that outline imperfectly.
"Indistinguishable at 24px, known exceptions listed in MODIFICATIONS.md" is achievable.
Abhinav to decide.

**#20, registration timing.** Needs a booted WordPress, so it has to run on the Local
site. Confirm that `wp_register_icon()` works when called after `init`, for example
inside a `render_block_data` filter on `core/icon`. If it does not, the lazy front end
registration strategy in #24 collapses and the fallback is full registration with
aggressive caching, which changes the performance story on large packs.

After both spikes land, #2 (scaffold) against real answers, then the rest of 0.2.

## 5. Verification approach for the builder

The acceptance criterion in #11 is that builder output survives `wp_kses` unchanged. The
cheapest way to check that is a PHP script that boots WordPress through WP-CLI on the
Local site and runs candidate SVGs through the same allowlist, comparing input to
output. Something like:

```
wp eval-file tools/verify-sanitize.php
```

Write that script early. It turns "we think this renders" into a test.
