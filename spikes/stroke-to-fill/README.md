# Spike: stroke to fill (#12)

Throwaway code. It answers whether Lucide can be outlined into fill only SVGs that survive
core's icon sanitizer and still look the same. The parts worth keeping get ported into
`packs/builder/` under #11 and #12. Findings are on issue #12.

```
npm install
node outline.mjs                 # out/icons.json, one filled path per icon
node compare.mjs                 # out/compare.json and report/index.html
node fixtures.mjs                # edge cases: dots, self intersection, overlaps, clip-rule
wp eval-file tools/verify-sanitize.php out/icons.json out/core-icons.json
node compare.mjs --from-core     # same comparison on what wp_get_icon() returns
```

Knobs: `II_RES_SCALE` (Skia stroke precision, default 16) and `II_DECIMALS` (default 2)
for `outline.mjs`; `--tol`, `--max` and `--ss` (supersampling, default 32) for
`compare.mjs`.
