# TV Logos Plugin

An official plugin for [m3u-editor](https://github.com/m3ue/m3u-editor) that automatically enriches channel logos by matching channel names against an open-source logo repository - either [tv-logo/tv-logos](https://github.com/tv-logo/tv-logos) or [K-yzu/Logos](https://github.com/K-yzu/Logos) - via the jsDelivr CDN.

## Features

- Two supported logo sources, selectable per-playlist-run via the `logo_source` setting:
  - `tv-logo/tv-logos` (default) - country-folder layout with slugified, country-code-suffixed filenames (e.g. `espn-us.png`)
  - `K-yzu/Logos` - region-coded top folders with human-readable filenames (e.g. `ABC HD.png`); supports `us`, `ca`, `gb`, `au`, `nz` (regional-affiliate subfolders like `TV:US2` are not indexed yet)
- Matches channel names to logos using a two-pass strategy: a cached GitHub Contents API index (fast, O(1)) with a HEAD-check fallback for the tv-logo/tv-logos source
- Quality-aware folder/filename probing - channels with HD hints (`HD`, `FHD`, `4K`, etc.) prefer HD variants first
- CamelCase splitting, dot/plus normalisation, and compact hyphenation matching for resilient name resolution
- Per-run match cache with configurable TTL to avoid redundant API calls
- Supports 60+ country codes (ISO 3166-1 alpha-2) on tv-logo/tv-logos
- Dry-run support on all actions

## Requirements

- m3u-editor with plugin support enabled
- `network_egress` permission (CDN + GitHub Contents API)

## Installation

Install via the m3u-editor Plugins page using the latest GitHub release, or via Artisan:

```bash
php artisan plugins:stage-github-release \
  https://github.com/m3ue/tv-logos-plugin/releases/download/v1.0.2/tv-logos-v1.0.2.zip \
  --sha256=<checksum>
```

Once staged, approve the install review in the UI and enable the plugin.

## Settings

| Setting | Default | Description |
|---|---|---|
| `logo_source` | `tv-logo` | Which logo repository layout to match against: `tv-logo` (tv-logo/tv-logos) or `kyzu` (K-yzu/Logos). |
| `github_repo` | `tv-logo/tv-logos` | Source repository (`owner/repo`) used when `logo_source` is `tv-logo`. Point to a fork to use custom logos. |
| `kyzu_github_repo` | `K-yzu/Logos` | Source repository (`owner/repo`) used when `logo_source` is `kyzu`. |
| `default_playlist_id` | - | Playlists to enrich automatically after each sync. |
| `country_code` | `us` | ISO 3166-1 alpha-2 country code (e.g. `gb`, `de`, `fr`). Supported codes vary by `logo_source`. |
| `overwrite_existing` | `false` | Overwrite channels that already have a logo URL. |
| `skip_vod` | `true` | Skip VOD channels. |
| `cache_ttl_days` | `7` | Match cache lifetime in days. Set to `0` to never expire. |

## Actions

| Action | Description |
|---|---|
| `health_check` | Pings the CDN and reports cache stats. Dry-run safe. |
| `enrich_logos` | Enriches logos for a selected playlist. |

## Hook

Subscribes to `playlist.synced`. When a playlist sync completes, the plugin automatically enriches logos for any playlists configured in `default_playlist_id`.

## Supported Countries

### tv-logo/tv-logos

Albania, Algeria, Argentina, Australia, Austria, Belgium, Bosnia & Herzegovina, Brazil, Bulgaria, Canada, China, Croatia, Czech Republic, Denmark, Egypt, Estonia, Finland, France, Germany, Greece, Hungary, India, Ireland, Iceland, Israel, Italy, Japan, Kosovo, Latvia, Lithuania, Luxembourg, North Macedonia, Montenegro, Mexico, Netherlands, New Zealand, Nigeria, Norway, Poland, Portugal, Romania, Russia, Saudi Arabia, Serbia, Slovakia, Slovenia, South Africa, South Korea, Spain, Sweden, Switzerland, Turkey, Ukraine, United Arab Emirates, United Kingdom, United States.

### K-yzu/Logos

United States (`us`), Canada (`ca`), United Kingdom (`gb`), Australia (`au`), New Zealand (`nz`).

## Releasing

```bash
bash scripts/package-plugin.sh
```

Update the SHA-256 checksum in the release notes whenever the zip changes.

## License

MIT
