=== Infinite Icons ===
Contributors: coderabhinav
Tags: icons, svg, icon block, lucide, block editor
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Popular open-source icon packs as native WordPress icons, in the Icon block, a shortcode and your templates.

== Description ==

Infinite Icons registers open-source icon sets with the WordPress Icons API, so they behave
exactly like the icons that ship with WordPress: pick one in the Icon block, colour it with
your theme palette, and it renders as inline SVG with no extra font or stylesheet.

The plugin ships with **Lucide** (1,815 icons). More packs can be added later from the
Appearance → Icons screen.

= Where the icons work =

* The core **Icon** block: every pack appears as its own tab in the picker.
* The `[infinite_icon]` shortcode (alias `[ii_icon]`), anywhere shortcodes run.
* `infinite_icons_get()` and `infinite_icons_the_icon()` in themes and plugins.

= Why the icons are rebuilt =

WordPress sanitizes every registered icon and keeps only `<svg>`, `<path>` and `<polygon>`,
dropping all `stroke` attributes. Outline icon sets drawn with strokes would render as nothing.
Every icon in every pack is therefore converted ahead of time into a single filled path that
survives sanitizing untouched, and each conversion is checked against the original image
before it ships. The conversion pipeline is open source; see the link below.

= Privacy =

The plugin makes no network requests of its own and collects no data. The bundled Lucide pack
is included in the download and works offline.

= Where the icon packs come from =

Lucide ships inside the plugin, so Infinite Icons is fully functional the moment it is
activated, with no network access at all.

The other five packs are downloaded only when you ask for them, from
**github.com**, on the Appearance -> Icons screen: opening it or pressing "Check for
updates" fetches a small JSON list of packs, and pressing "Install" downloads that
pack's zip. Each download is checked against the SHA-256 checksum published in the list
before it is unpacked, and the unpacked files are validated to be SVG and JSON only.

No information about your site is ever sent anywhere, and nothing is downloaded in the
background or on a schedule.

On hosts with a read-only or remote filesystem (WordPress VIP, for instance) downloading
is turned off automatically; add the packs you want to the plugin's `packs/` directory in
your deploy instead.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/infinite-icons`, or install it through Plugins → Add New.
2. Activate it.
3. Insert an Icon block and choose the Lucide tab, or use `[infinite_icon name="lucide/heart"]`.

== Frequently Asked Questions ==

= How do I find an icon's name? =

Names are `collection/icon-name`, for example `lucide/heart`. The Icon block picker shows the
name when you hover an icon.

= Can I change an icon's size and colour? =

Yes. `[infinite_icon name="lucide/heart" size="32" color="#c00"]`. Icons inherit `color` from
their surroundings, so setting `color` on a parent element works too. Use `size="inherit"` to
make an icon scale with the surrounding text.

= Are the icons accessible? =

Icons are decorative by default (`aria-hidden="true"`). Add a `label` to give an icon an
accessible name, and it gets `role="img"` and `aria-label` instead.

= Which licences apply to the icons? =

Each pack keeps its upstream licence, included as `LICENSE` and `ATTRIBUTION.md` inside the
pack directory. Lucide is ISC.

= Does this add a webfont or extra requests? =

No. Icons are inlined as SVG. A single small stylesheet is enqueued, and only on pages that
actually render an icon.

== Screenshots ==

1. Choosing a Lucide icon in the core Icon block.
2. Icons rendered at different sizes and colours.

== Changelog ==

= 1.0.0 =
* First release: the Icons API integration, the bundled Lucide pack, the `[infinite_icon]`
  shortcode and the `infinite_icons_get()` template function.

== Upgrade Notice ==

= 1.0.0 =
First release.
