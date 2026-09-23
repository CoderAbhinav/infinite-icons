# Spike: registration timing (#20)

Throwaway code. It answers whether icons can be registered after `init`, per rendered
block, and what full registration costs. Findings are on issue #20.

All scripts take an `icons.json` mapping icon name to SVG, for example the output of
`spikes/stroke-to-fill` on branch `spike/12-stroke-to-fill`.

```
wp eval-file spikes/registration-timing/eval-late-register.php icons.json  # register inside render_block_data
wp eval-file spikes/registration-timing/eval-rest-lazy.php icons.json      # register inside rest_pre_dispatch
wp eval-file spikes/registration-timing/bench.php icons.json               # cost of full registration
```

`ii-spike-plugin.php` is the real front end check: copy it with `icons.json` into
`wp-content/plugins/ii-spike-registration/`, activate it, view a page containing
`<!-- wp:icon {"icon":"ii-spike/house"} /-->`, read the `ii-spike` HTML comment at the end
of the page, then deactivate and delete it.
