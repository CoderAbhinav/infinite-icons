# Where things stand

Written 23 September 2026, updated the same day after both spikes landed. This file records what has been verified against a real
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
- On `main`: `.gitignore`, `CLAUDE.md`, `ROADMAP.md`, `docs/HANDOVER.md`, `docs/STATE.md`.
  No plugin code exists yet. Issue #2, the scaffold, has not been started.
- Spike branches, pushed and not meant to be merged: `spike/12-stroke-to-fill` and
  `spike/20-registration-timing`. Results are in section 4 and in comments on #12 and #20.
  Both issues stay open until Abhinav has read the findings.

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
- `viewbox` is lowercase in the allowlist and `wp_kses` rewrites the attribute name, so
  core stores `viewBox` as `viewbox`. This happens to core's own icons too. HTML parsers
  restore the case for inline SVG, so pages render correctly, but the stored markup is not
  valid as a standalone `.svg` file, `<img src>` or data URI. Any integration that uses
  icons outside inline HTML must restore `viewBox` itself or use the pack source.
- `clip-rule` being stripped is harmless: it only affects shapes inside a `clipPath`,
  which core strips anyway. Verified with a fixture in the #12 spike.
- `content` is sanitized inside `register()`, on every request. `file_path` defers it, but
  needs one `.svg` file per icon and uses `realpath()`, which does not work with the VIP
  uploads stream wrapper. So packs register through `content`.
- There is no timing guard: `register()` works at any point in the request.
- `GET /wp/v2/icons` and `GET /wp/v2/icons/<collection>` do not paginate. `per_page` is
  ignored and every icon's SVG is included in the response.

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

WP-CLI also works from an ordinary shell while the site is running, by pointing Local's
PHP at the site's MySQL socket:

```
"~/Library/Application Support/Local/lightning-services/php-8.2.27+1/bin/darwin/bin/php" \
  -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/8Ksmp7EQ9/mysql/mysqld.sock" \
  /Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar <command>
```

Run it from the site root. `8Ksmp7EQ9` is this site's id in Local's `sites.json`.

Docker is deliberately not being used for testing. The Local site is the test
environment. Do not modify the WordPress install itself, it is needed for testing.

## 4. Spike results and what to pick up next

**#12, stroke to fill: works, the roadmap stands.** All 1,848 Lucide icons outline to a
single filled path with Skia (`canvaskit-wasm`: `makeStroked` with `precision: 16`, then a
PathOps union, 2 decimals). All 1,848 meet the agreed bar, "same look at 24px": at most 2
pixels whose alpha differs by more than 16/255. In practice 1,835 have a worst pixel within
4/255. They still pass after going through core's sanitizer and `wp_get_icon()`. Two
lessons for the builder: the stroker's default precision is too coarse for a 24 unit box,
and the comparison needs a supersampled reference (32x), because a direct 24px render of
upstream is itself approximate. Cost: outlined Lucide is 4.3 MB raw, 1.1 MB gzip, against
0.9 MB upstream.

**#20, registration timing: late registration works.** Registering inside
`render_block_data` works on a real front end request with no notices, and so does
`rest_pre_dispatch` for the single icon REST route. Full registration costs about 0.12 ms
per icon on every request (226 ms for Lucide, 616 ms for 5,000 icons), almost all of it
`wp_kses`, and caching cannot remove it. Decoding a whole pack JSON costs 20 ms and 5.4 MB.
Proposed for #24: lazy per icon on the front end and single icon REST routes, full
registration only for the icons list REST routes, not on every admin page. The loader (#23)
must serve single icons without decoding the whole pack.

**Open question for Abhinav:** the core list endpoint does not paginate, so each enabled
pack adds its full size to the Icon block picker's download (4.5 MB of JSON for outlined
Lucide). Shrink the builder output, expose a curated subset to the core picker, or accept
it? This affects #11, #24 and #36.

Next, once Abhinav has read the findings: #2 (scaffold), then the rest of 0.2, porting the
outliner and `verify-sanitize.php` from the #12 spike into `packs/builder/` under #11 and
#12.

## 5. Verification approach for the builder

The acceptance criterion in #11 is that builder output survives `wp_kses` unchanged. The
cheapest way to check that is a PHP script that boots WordPress through WP-CLI on the
Local site and runs candidate SVGs through the same allowlist, comparing input to
output. Something like:

```
wp eval-file tools/verify-sanitize.php
```

A working version is on the `spike/12-stroke-to-fill` branch at
`spikes/stroke-to-fill/tools/verify-sanitize.php`. It calls core's own
`sanitize_icon_content()` through reflection, allows only the `viewBox` case change, and
writes what `wp_get_icon()` returns so it can be compared against upstream. Promote it into
the builder's test suite.
