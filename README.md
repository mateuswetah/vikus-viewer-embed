# Vikus Viewer Embed

WordPress plugin that maps posts, taxonomies, and featured images into [VIKUS Viewer](https://github.com/cpietsch/vikus-viewer) data packages and embeds the viewer via shortcode, block, or a public route.

**Repository:** https://github.com/mateuswetah/vikus-viewer-embed

This repository is the **WordPress integration**. Upstream Vikus lives under `vendor-vikus/` and is kept unmodified; WordPress-specific behavior is in `includes/`, `assets/js/viewer-compat.js`, and the React admin under `src/admin/`.

WordPress.org listing copy lives in [`readme.txt`](./readme.txt). This file is for developers working from Git.

## Requirements

- WordPress 7.0+
- PHP 8.0+
- Node.js 20+ (admin / block build only — not required on the production host after assets are built)
- Imagick recommended for large collections (GD fallback)

## Quick start

1. Clone into a WordPress site:

   ```bash
   git clone https://github.com/mateuswetah/vikus-viewer-embed.git wp-content/plugins/vikus-viewer-embed
   ```

2. Install JS dependencies and build admin + block assets:

   ```bash
   cd wp-content/plugins/vikus-viewer-embed
   npm install
   npm run build
   ```

3. Activate **Vikus Viewer Embed** in wp-admin.
4. Open the admin menu entry, create a collection, map fields, queue a rebuild.
5. Embed with the block, shortcode, or the public viewer URL.

## npm scripts

| Command | Purpose |
| --- | --- |
| `npm run build` | Production build (`wp-scripts`) + copy block assets |
| `npm start` | Watch mode for admin / block development |
| `npm run lint:js` | ESLint on `src/` |
| `npm run plugin-zip` | Zip for distribution (uses `package.json` `files`) |
| `npm run packages-update` | Update `@wordpress/*` packages via wp-scripts |

Built output goes to `build/`. Source is under `src/` (`admin/`, `block/`).

## Layout

```
vikus-viewer-embed.php          # Bootstrap
includes/                 # PHP (CPT, REST, export, pipeline, admin mount)
src/admin/                # React admin app
src/block/                # Gutenberg block
assets/js/viewer-compat.js # Viewer shell patches (no vendor edits)
vendor-vikus/             # Upstream Vikus (MIT) — do not modify for product features
build/                    # Compiled assets (commit or generate before zip)
readme.txt                # WordPress.org readme
docs/NOTICE.md                 # Third-party attribution (Git only; not in .org zip — see readme.txt License)
```

### Vendor policy

- Do **not** edit `vendor-vikus/` for WordPress features.
- Prefer `assets/js/viewer-compat.js` and `includes/Frontend/` for shell / behavior overlays.
- Lodash is remapped to WordPress core when served (`includes/Frontend/Assets.php`).

## WP-CLI

```bash
wp vikus build <id> [--force] [--step=csv|textures|sprites]
wp vikus status <id>
wp vikus cancel <id>
```

Useful for large collections or when the web build worker is constrained.

## Shortcode / block

```
[vikus_viewer id="123" height="80vh"]
```

Or insert the **Vikus Viewer Embed** block and select a collection.

Public viewer route (after a successful build): `/vikus/{collection_id}/?config=…`

## Packaging

- `.distignore` documents files excluded from SVN / manual zips.
- `npm run plugin-zip` packs according to `package.json` `files` (includes `build/`, excludes `src/`, `node_modules`, etc.).
- Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) against the built zip before a directory submission.
- This `README.md` is development-only and is listed in `.distignore`.

## License

- Plugin code: [GPL-3.0-or-later](./LICENSE)
- Bundled Vikus Viewer (`vendor-vikus/`): MIT — see [`vendor-vikus/LICENSE.md`](./vendor-vikus/LICENSE.md). Production attribution lives in [`readme.txt`](./readme.txt) `== License ==` (Plugin Check disallows root `docs/NOTICE.md` in the zip).
