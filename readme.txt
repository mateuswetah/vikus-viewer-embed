=== Vikus Viewer Embed ===
Contributors: wetah
Tags: visualization, gallery, collection, vikus, cultural heritage
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.3.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Map WordPress posts into a Vikus visualization and embed it with a block or shortcode.

== Description ==

This plugin is a WordPress integration for [VIKUS Viewer](https://vikusviewer.fh-potsdam.de/) (Visualisierung kultureller Sammlungen). It maps posts, taxonomies, and featured images into Vikus data packages and embeds the interactive canvas via shortcode, block, or a public viewer URL.

It is not the upstream Vikus Viewer application itself.

= Features =

* Collections managed in a dedicated admin app (WordPress 7.0+ DataViews / components)
* Map post types, taxonomies, and post meta to Vikus fields (id, keywords, year, detail sidebar)
* Hierarchical taxonomy keywords (Parent:Child:Term)
* PHP texture/spritesheet generation (Imagick preferred, GD fallback) — no Node required on the host
* Background rebuild queue + WP-CLI for large collections
* Shortcode, Gutenberg block, and public viewer route

= Third-party =

This plugin includes [VIKUS Viewer](https://github.com/cpietsch/vikus-viewer) (MIT License, Copyright Christopher Pietsch and contributors) under `vendor-vikus/`. See vendor-vikus/LICENSE.md for the full MIT text. Bundled libraries and licenses are listed under License below.

== License ==

This WordPress plugin is licensed under GPLv3 or later (see LICENSE in the plugin root).

Bundled Vikus Viewer (`vendor-vikus/`) is MIT — vendor-vikus/LICENSE.md.

Bundled libraries inside that tree (all GPL-compatible):

* D3.js v3.5.12 (`js/d3.v3.min.js`) — BSD-3-Clause — https://github.com/d3/d3
* PixiJS v5.2.2 (`js/pixi.min.js`) — MIT — https://github.com/pixijs/pixijs
* Vue.js v2.5.11 (`js/vue.min.js`) — MIT — https://github.com/vuejs/vue
* marked (`js/marked.min.js`) — MIT — https://github.com/markedjs/marked
* Modernizr 3.3.1 (`js/modernizr-custom.js`) — MIT — https://modernizr.com
* Lato font (`font/Lato/`) — SIL Open Font License 1.1 — vendor-vikus/font/Lato/OFL.txt

Notes:

* `js/crossfilter.js` is Vikus Viewer application code (not Square Crossfilter) and is covered by the Vikus MIT license.
* Lodash is not shipped; at runtime the plugin loads WordPress core Lodash (MIT).
* Unused upstream copies (unminified d3.v3.js, pixi-legacy, pixi.8) are excluded from the WordPress.org zip.
* Upstream detail type "function" (eval) is disabled when the viewer is served through WordPress.

= WP-CLI =

`wp vikus build <id> [--force] [--step=csv|textures|sprites]`
`wp vikus status <id>`
`wp vikus cancel <id>`

= Shortcode =

`[vikus_viewer id="123" height="80vh"]`

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/vikus-viewer-embed` (or install from the directory when available)
2. Activate the plugin through the **Plugins** screen
3. Open **Vikus Viewer Embed** in the admin menu, create a collection, map fields, and queue a rebuild
4. Embed with the block or shortcode

Imagick is recommended for large collections.

== Development ==

Source code (including unminified admin/block JS under `src/`), build steps, and contribution notes:
https://github.com/mateuswetah/vikus-viewer-embed

The WordPress.org zip ships compiled assets in `build/`. To rebuild from source:

`npm install && npm run build`

== Changelog ==

= 0.3.0 =
* Dedicated React admin app (DataViews list, create wizard, field builders) requiring WordPress 7.0+
* Classic collection CPT screens redirect into the app; settings via REST
* Renamed to Vikus Viewer Embed (slug `vikus-viewer-embed`)

= 0.2.1 =
* WP image size reuse for medium/large textures, build cleanup, Plugin Check hardening

= 0.1.0 =
* Initial release
