Vikus Viewer Embed WordPress Plugin — Third-party notices
=====================================================

Git/development copy only (`docs/NOTICE.md`). WordPress.org Plugin Check
disallows unexpected markdown in the plugin root. Production attribution
ships in `readme.txt` under `== License ==`; this file is excluded from
the WordPress.org zip.

This WordPress plugin is licensed under GPL-3.0-or-later (see `LICENSE`).

It vendors [VIKUS Viewer](https://github.com/cpietsch/vikus-viewer) under
`vendor-vikus/` (MIT License, Copyright Christopher Pietsch and contributors).
Full MIT text: `vendor-vikus/LICENSE.md`.

The `vendor-vikus/` tree is kept unmodified on disk. WordPress-specific
behavior lives in `includes/Frontend/` and `assets/js/viewer-compat.js`:

* Asset URL rewriting and CSS `url()` rebasing
* Serve-time strip of remote html5shiv / remote script tags
* Serve-time neutralization of `eval` in `js/sidebars.js` (detail type
  `"function"` is disabled; this plugin never emits that type)
* Detail sidebar helpers and iframe sneak handling
* Lodash remapped to WordPress core (`wp-includes/js/dist/vendor/lodash.js`)

Bundled third-party libraries inside `vendor-vikus/` (GPL-compatible)
-------------------------------------------------------------------

These ship as part of the upstream Vikus Viewer release. SPDX identifiers
and upstream projects:

| Library | Path (under `vendor-vikus/`) | License | Upstream |
| --- | --- | --- | --- |
| D3.js v3.5.12 | `js/d3.v3.min.js` | BSD-3-Clause | https://github.com/d3/d3 (v3) |
| PixiJS v5.2.2 | `js/pixi.min.js` | MIT | https://github.com/pixijs/pixijs |
| Vue.js v2.5.11 | `js/vue.min.js` | MIT | https://github.com/vuejs/vue |
| marked | `js/marked.min.js` | MIT | https://github.com/markedjs/marked |
| Modernizr 3.3.1 (custom WebGL build) | `js/modernizr-custom.js` | MIT | https://modernizr.com / https://github.com/Modernizr/Modernizr |
| Lato font family | `font/Lato/` | SIL Open Font License 1.1 | `font/Lato/OFL.txt` |

Notes:

* **`js/crossfilter.js`** is Vikus Viewer application code (filter UI named
  “crossfilter”), not Square’s Crossfilter library. It is covered by the
  Vikus MIT license in `vendor-vikus/LICENSE.md`.
* **`js/pixi-packer-parser.js`** and other first-party Vikus scripts under
  `js/` are likewise covered by that MIT license.
* Lodash is referenced by upstream `index.html` but excluded from the
  WordPress.org zip; at runtime the asset endpoint serves core Lodash
  (MIT; https://lodash.com/license).
* Unused upstream copies are excluded from the release zip:
  `js/d3.v3.js` (unminified), `js/pixi-legacy.js` / `.mjs`,
  `js/pixi.8.min.js` / `.mjs` (not loaded by `index.html`).

All licenses above are GPL-compatible for distribution with this plugin.
Full license texts for D3 (BSD-3-Clause) and the MIT libraries appear in
their upstream repositories and, where present, in file header banners.
The SIL OFL text for Lato is shipped at `vendor-vikus/font/Lato/OFL.txt`.
