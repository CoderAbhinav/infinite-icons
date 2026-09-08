# Using Infinite Icons

## In the editor

Insert an **Icon** block and pick a collection tab. Every enabled pack appears there.

Block markup looks like this, so you can paste it into a template or a synced pattern:

```html
<!-- wp:icon {"icon":"lucide/heart"} /-->
```

## Shortcode

`[infinite_icon]`, or its shorter alias `[ii_icon]`. Self-closing; only `name` is required.

```
[infinite_icon name="lucide/heart"]
[infinite_icon name="lucide/github" size="32" color="#333" link="https://example.org" target="_blank" label="GitHub"]
[ii_icon name="lucide/house" size="inherit"]
```

| Attribute | Values | Default |
|---|---|---|
| `name` | `collection/icon-name` | required; an unknown icon renders nothing |
| `size` | pixels, or `inherit` to scale with the surrounding font size | `24` |
| `color` | hex, `rgb()`/`rgba()`/`hsl()`/`hsla()`, a colour keyword, or `var(--…)` | inherit |
| `class` | extra class names, space separated | — |
| `label` | accessible label; omit for a decorative icon | — |
| `rotate` | `90`, `180`, `270` | `0` |
| `flip` | `horizontal`, `vertical`, `both` | — |
| `link` | URL; wraps the icon in a link (`http`, `https`, `mailto`, `tel`) | — |
| `target` | `_blank`, which also adds `rel="noopener noreferrer"` | — |
| `align` | `left`, `center`, `right` | — |

Anything unrecognised is ignored rather than passed through, so a typo can never inject markup.

The shortcode also runs anywhere `do_shortcode()` does, including widgets, templates and
Gravity Forms HTML fields.

## PHP

```php
echo infinite_icons_get( 'lucide/heart', array(
	'size'   => 32,
	'class'  => 'my-icon',
	'label'  => __( 'Favourite', 'my-theme' ),
	'color'  => 'var(--wp--preset--color--primary)',
	'rotate' => 90,
	'flip'   => 'horizontal',
	'attrs'  => array( 'data-id' => '7' ),
) );

infinite_icons_the_icon( 'lucide/star' );          // echoes
infinite_icons_exists( 'lucide/star' );            // bool
```

`size => null` drops the `width`/`height` attributes and adds `ii-icon--inherit`, which sizes
the icon at `1em` so it follows the surrounding text.

Only `data-*`, `aria-*` and `id` are accepted in `attrs`; event handlers and everything else
are discarded. Colours that could break out of the `style` attribute are rejected outright.

## Styling

`assets/frontend.css` is enqueued only when an icon is actually rendered. It defines:

| Class | Effect |
|---|---|
| `.ii-icon` | `display:inline-block`, `fill:currentColor`, optical baseline alignment |
| `.ii-icon--inherit` | `1em` square, so the icon scales with the font |
| `.ii-rotate-90` / `-180` / `-270` | rotation |
| `.ii-flip-h` / `-v` / `-both` | mirroring, including combined with rotation |
| `.ii-align-left` / `-center` / `-right` | wrapper alignment |

Icons inherit `color`, so `color: red` on any ancestor recolours them.
