# Server Backgrounds (Blueprint Addon)
Adds configurable background images behind server cards on the Pterodactyl dashboard using the Blueprint Framework.

## What This Addon Does
- Applies background images to server cards on the dashboard.
- Supports per-server overrides (by server UUID) and per-egg defaults.
- Supports per-background transparency (opacity).
- (Performance) Optional setting to disable backgrounds for admin users.

## Compatibility
- Blueprint Framework on Pterodactyl Panel
- Target: `beta-2024-12` (see `conf.yml`)

## Installation / Development Guides
Follow the official Blueprint guides for installing addons and developing extensions:
`https://blueprint.zip/guides`

Uninstall:
`blueprint -remove serverbackgrounds`

## Configuration (Admin)
In the Blueprint admin area for this addon you can:
- Bulk set backgrounds for a server UUID or an egg.
- Edit/delete applied backgrounds.
- Adjust background transparency.
- Disable backgrounds for admin users (performance option).

## How It Works (Repo Layout)
- `conf.yml`: Blueprint addon manifest (metadata, target version, entrypoints).
- `client/wrapper.blade.php`: Dashboard wrapper that includes the background injector.
- `src/views/wrapper/styles/import.blade.php`: Client-side script + CSS that applies backgrounds.
- `routes/web.php`: Web routes (admin actions + API endpoints used by the wrapper script).
- `admin/Controller.php`: Stores settings in Blueprint's key/value store and serves API data.
- `admin/view.blade.php`: Admin configuration page.

## Contributing
This repo is shared so the community can help improve and extend the addon, not because it's abandoned.
Where it helps, the code includes comments explaining non-obvious behavior; keep comments high-signal.

### Pull Request Requirements
- Clearly state what was added/updated and why.
- Include images or a short video of the change working/in action (especially for UI changes).
- Keep changes focused and avoid unrelated formatting-only churn.
- Keep credits/attribution intact (see `LICENSE`).

### Helpful Contribution Ideas
- Improve performance for large server lists.
- Make selector matching more resilient across panel versions.
- Styling improvements to better fit different themes.

## License
Source-available. Redistribution and resale (original or modified) are not permitted, and original credits must be kept within the addon.
See `LICENSE` for the full terms.