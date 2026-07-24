<?php

namespace AppLocalPlugins\TvLogos;

use App\Models\Channel;
use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\PluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Plugin implements ChannelProcessorPluginInterface, HookablePluginInterface, PluginInterface
{
    private const DEFAULT_GITHUB_REPO = 'tv-logo/tv-logos';

    private const DEFAULT_KYZU_REPO = 'K-yzu/Logos';

    private const CACHE_FILE = 'plugin-data/tv-logos/matches.json';

    private const LOG_BATCH_SIZE = 100;

    /**
     * Non-semantic filename tokens used by logo repositories for variants.
     * These are ignored only after exact filename matching has failed.
     *
     * @var array<int, string>
     */
    private const LOGO_VARIANT_TOKENS = ['custom', 'hd', 'fhd', 'uhd', 'sd', '4k', '8k'];

    private string $cdnBase;

    private string $indexApiBase;

    /**
     * Lowercase file extensions (without the dot) to index for the active source.
     *
     * @var array<int, string>
     */
    private array $indexExtensions = ['png'];

    /**
     * Maps ISO 3166-1 alpha-2 country codes to their top-level folder in the
     * K-yzu/Logos repo. Regional affiliate/supplemental folders (e.g. TV:US2)
     * are not indexed in this initial integration.
     *
     * @var array<string, string>
     */
    private const KYZU_COUNTRY_FOLDERS = [
        'us' => 'TV:US',
        'ca' => 'TV:CA',
        'gb' => 'TV:UK',
        'au' => 'TV:AU',
        'nz' => 'TV:NZ',
    ];

    /**
     * Maps ISO 3166-1 alpha-2 country codes to their folder names in the tv-logo/tv-logos repo.
     *
     * @var array<string, string>
     */
    private const COUNTRY_FOLDERS = [
        'al' => 'albania',
        'dz' => 'algeria',
        'ar' => 'argentina',
        'au' => 'australia',
        'at' => 'austria',
        'be' => 'belgium',
        'ba' => 'bosnia-and-herzegovina',
        'br' => 'brazil',
        'bg' => 'bulgaria',
        'ca' => 'canada',
        'cn' => 'china',
        'hr' => 'croatia',
        'cz' => 'czech-republic',
        'dk' => 'denmark',
        'eg' => 'egypt',
        'ee' => 'estonia',
        'fi' => 'finland',
        'fr' => 'france',
        'de' => 'germany',
        'gr' => 'greece',
        'hu' => 'hungary',
        'in' => 'india',
        'ie' => 'ireland',
        'is' => 'iceland',
        'il' => 'israel',
        'it' => 'italy',
        'jp' => 'japan',
        'xk' => 'kosovo',
        'lv' => 'latvia',
        'lt' => 'lithuania',
        'lu' => 'luxembourg',
        'mk' => 'north-macedonia',
        'me' => 'montenegro',
        'mx' => 'mexico',
        'nl' => 'netherlands',
        'nz' => 'new-zealand',
        'ng' => 'nigeria',
        'no' => 'norway',
        'pl' => 'poland',
        'pt' => 'portugal',
        'ro' => 'romania',
        'ru' => 'russia',
        'sa' => 'saudi-arabia',
        'rs' => 'serbia',
        'sk' => 'slovakia',
        'si' => 'slovenia',
        'za' => 'south-africa',
        'kr' => 'south-korea',
        'es' => 'spain',
        'se' => 'sweden',
        'ch' => 'switzerland',
        'tr' => 'turkey',
        'ua' => 'ukraine',
        'ae' => 'united-arab-emirates',
        'gb' => 'united-kingdom',
        'us' => 'united-states',
    ];

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'health_check' => $this->healthCheck($context),
            'enrich_logos' => $this->enrichFromAction($payload, $context),
            default => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'playlist.synced') {
            return PluginActionResult::success("Hook [{$hook}] not handled by TV Logos.");
        }

        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in hook payload.');
        }

        $configured = $context->settings['default_playlist_id'] ?? null;
        $watchedIds = array_map('intval', array_filter((array) $configured));

        if ($watchedIds === []) {
            return PluginActionResult::success('No default playlist(s) configured - skipping automatic enrichment.');
        }

        if (! in_array($playlistId, $watchedIds, true)) {
            return PluginActionResult::success("Playlist #{$playlistId} is not in the configured defaults - skipping.");
        }

        return $this->processPlaylist($playlistId, $context);
    }

    /**
     * Ping the CDN and report cache stats.
     */
    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $context->info('Checking logo source CDN reachability...');

        $settings = $context->settings;
        $source = (string) ($settings['logo_source'] ?? 'tv-logo');
        if (! in_array($source, ['tv-logo', 'kyzu'], true)) {
            $source = 'tv-logo';
        }

        if ($source === 'kyzu') {
            $repo = trim((string) ($settings['kyzu_github_repo'] ?? self::DEFAULT_KYZU_REPO)) ?: self::DEFAULT_KYZU_REPO;
            $cdnBase = "https://cdn.jsdelivr.net/gh/{$repo}@main";
            $pingUrl = $cdnBase.'/'.rawurlencode('TV:US').'/'.rawurlencode('ABC.png');
            $supportedCountries = array_keys(self::KYZU_COUNTRY_FOLDERS);
        } else {
            $repo = trim((string) ($settings['github_repo'] ?? self::DEFAULT_GITHUB_REPO)) ?: self::DEFAULT_GITHUB_REPO;
            $cdnBase = "https://cdn.jsdelivr.net/gh/{$repo}@main/countries";
            $pingUrl = $cdnBase.'/united-states/espn-us.png';
            $supportedCountries = array_keys(self::COUNTRY_FOLDERS);
        }

        $reachable = false;

        try {
            $reachable = Http::timeout(10)->head($pingUrl)->successful();
        } catch (Throwable) {
            // CDN unreachable
        }

        $cacheEntries = 0;

        try {
            $cache = $this->loadCache(0);
            $cacheEntries = count($cache['matches'] ?? []);
        } catch (Throwable) {
            // Cache unreadable
        }

        return PluginActionResult::success('Health check complete.', [
            'source' => $source,
            'cdn_reachable' => $reachable,
            'cdn_base' => $cdnBase,
            'cached_entries' => $cacheEntries,
            'supported_countries' => $supportedCountries,
        ]);
    }

    /**
     * Entry point for the manual enrich_logos action.
     *
     * Accepts optional overrides for overwrite_existing, skip_vod, and
     * ignore_cache so the user can control these per run without changing
     * the global plugin settings.
     */
    private function enrichFromAction(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = (int) ($payload['playlist_id'] ?? 0);

        if ($playlistId === 0) {
            return PluginActionResult::failure('Missing playlist_id in action payload.');
        }

        $overrides = [];

        if (array_key_exists('overwrite_existing', $payload)) {
            $overrides['overwrite_existing'] = (bool) $payload['overwrite_existing'];
        }

        if (array_key_exists('skip_vod', $payload)) {
            $overrides['skip_vod'] = (bool) $payload['skip_vod'];
        }

        if (array_key_exists('ignore_cache', $payload)) {
            $overrides['ignore_cache'] = (bool) $payload['ignore_cache'];
        }

        return $this->processPlaylist($playlistId, $context, $overrides);
    }

    /**
     * Core enrichment logic - queries channels for the given playlist and attempts
     * to match each one against a logo from the tv-logo/tv-logos CDN.
     *
     * @param  array{overwrite_existing?: bool, skip_vod?: bool, ignore_cache?: bool}  $overrides
     */
    private function processPlaylist(int $playlistId, PluginExecutionContext $context, array $overrides = []): PluginActionResult
    {
        $settings = $context->settings;
        $countryCode = strtolower(trim((string) ($settings['country_code'] ?? 'us')));
        $overwriteExisting = (bool) ($overrides['overwrite_existing'] ?? $settings['overwrite_existing'] ?? false);
        $skipVod = (bool) ($overrides['skip_vod'] ?? $settings['skip_vod'] ?? true);
        $ignoreCache = (bool) ($overrides['ignore_cache'] ?? false);
        $cacheTtlDays = (int) ($settings['cache_ttl_days'] ?? 7);
        $isDryRun = $context->dryRun;
        $normConfig = $this->buildNormalizationConfig($settings);

        $source = (string) ($settings['logo_source'] ?? 'tv-logo');
        if (! in_array($source, ['tv-logo', 'kyzu'], true)) {
            $source = 'tv-logo';
        }

        if ($source === 'kyzu') {
            $repo = trim((string) ($settings['kyzu_github_repo'] ?? self::DEFAULT_KYZU_REPO));
            if ($repo === '') {
                $repo = self::DEFAULT_KYZU_REPO;
            }
            $this->cdnBase = "https://cdn.jsdelivr.net/gh/{$repo}@main";
            $this->indexApiBase = "https://api.github.com/repos/{$repo}/contents";
            $this->indexExtensions = ['png', 'gif'];
            $countryFolders = self::KYZU_COUNTRY_FOLDERS;
        } else {
            $repo = trim((string) ($settings['github_repo'] ?? self::DEFAULT_GITHUB_REPO));
            if ($repo === '') {
                $repo = self::DEFAULT_GITHUB_REPO;
            }
            $this->cdnBase = "https://cdn.jsdelivr.net/gh/{$repo}@main/countries";
            $this->indexApiBase = "https://api.github.com/repos/{$repo}/contents/countries";
            $this->indexExtensions = ['png'];
            $countryFolders = self::COUNTRY_FOLDERS;
        }

        $repoCacheKey = $this->normalizeRepoCacheKey($repo);
        $countryFolder = $countryFolders[$countryCode] ?? null;

        if ($countryFolder === null) {
            return PluginActionResult::failure(sprintf(
                'Unknown country code [%s] for source [%s]. Supported codes: %s.',
                $countryCode,
                $source,
                implode(', ', array_keys($countryFolders))
            ));
        }

        $cache = $this->loadCache($cacheTtlDays);

        $cacheChanged = false;
        $index = $this->fetchCountryIndex($countryCode, $countryFolder, $repoCacheKey, $cache, $cacheChanged, $ignoreCache);

        if ($index !== []) {
            $context->info(sprintf('Loaded index of %d known logos for %s.', count($index), $countryFolder));
        } elseif ($source === 'kyzu') {
            $context->info('Logo index unavailable for the K-yzu/Logos source - no matches can be made this run.');
        } else {
            $context->info('Logo index unavailable - falling back to per-channel CDN HEAD checks (slower).');
        }

        $byBasename = $this->buildBasenameIndex($index);
        $kyzuCompactIndex = $source === 'kyzu' ? $this->buildKyzuCompactIndex($index) : [];

        $query = Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('enabled', true)
            ->select(['id', 'title', 'title_custom', 'name', 'name_custom', 'logo']);

        if ($skipVod) {
            $query->where('is_vod', false);
        }

        if (! $overwriteExisting) {
            $query->where(function ($q): void {
                $q->whereNull('logo')->orWhere('logo', '');
            });
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            return PluginActionResult::success('No channels require logo enrichment.', [
                'matched' => 0,
                'skipped' => 0,
                'total' => 0,
            ]);
        }

        $context->info(sprintf(
            'Processing %d channel(s) for playlist #%d [country=%s%s].',
            $total,
            $playlistId,
            $countryCode,
            $isDryRun ? ', dry_run' : ''
        ));

        $matched = 0;
        $unmatched = 0;
        $cacheHits = 0;
        $cacheMisses = 0;
        $processed = 0;
        $batchMatched = [];
        $batchUnmatched = [];
        $batchStart = 1;

        foreach ($channels as $channel) {
            $displayName = trim((string) ($channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name ?? ''));

            if ($displayName === '') {
                continue;
            }

            $processed++;
            $normalizedName = $this->normalizeChannelName($displayName, $normConfig);
            $cacheKey = $repoCacheKey.':'.$countryCode.':'.mb_strtolower($normalizedName, 'UTF-8');

            if (! $ignoreCache && array_key_exists($cacheKey, $cache['matches'])) {
                $logoUrl = $cache['matches'][$cacheKey] ?: null;
                $cacheHits++;
            } else {
                $logoUrl = $this->resolveLogoUrl($normalizedName, $countryCode, $countryFolder, $index, $byBasename, $source, $kyzuCompactIndex);
                $cache['matches'][$cacheKey] = $logoUrl ?? '';
                $cacheChanged = true;
                $cacheMisses++;
            }

            if ($logoUrl !== null) {
                $matched++;
                $batchMatched[$displayName] = $logoUrl;

                if (! $isDryRun && ($channel->logo ?? '') !== $logoUrl) {
                    Channel::where('id', $channel->id)->update(['logo' => $logoUrl]);
                }
            } else {
                $unmatched++;
                $batchUnmatched[] = $displayName;
            }

            if ($processed % self::LOG_BATCH_SIZE === 0) {
                $context->info(
                    sprintf('Channels %d-%d: %d matched, %d unmatched.', $batchStart, $processed, \count($batchMatched), \count($batchUnmatched)),
                    ['matched' => $batchMatched, 'unmatched' => $batchUnmatched],
                );
                $batchMatched = [];
                $batchUnmatched = [];
                $batchStart = $processed + 1;
                $context->heartbeat(progress: (int) (($processed / $total) * 100));
            }
        }

        // Flush the final partial batch.
        if ($batchMatched !== [] || $batchUnmatched !== []) {
            $context->info(
                sprintf('Channels %d-%d: %d matched, %d unmatched.', $batchStart, $processed, \count($batchMatched), \count($batchUnmatched)),
                ['matched' => $batchMatched, 'unmatched' => $batchUnmatched],
            );
        }

        if ($cacheChanged && ! $isDryRun) {
            $this->saveCache($cache);
        }

        $resultData = [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'total' => $total,
            'cache_hits' => $cacheHits,
            'cache_misses' => $cacheMisses,
            'country_code' => $countryCode,
            'dry_run' => $isDryRun,
            'ignore_cache' => $ignoreCache,
        ];

        return PluginActionResult::success(
            sprintf('%d of %d channel(s) matched%s.', $matched, $total, $isDryRun ? ' (dry run - no changes written)' : ''),
            $resultData
        );
    }

    /**
     * Attempt to resolve a CDN logo URL for the given channel name.
     *
     * When an index is available, performs a comprehensive filename-based search
     * across ALL subfolders (hd/, sky-sport/hd/, custom/, etc.), preferring
     * HD subfolders for HD-hinted channels.
     * Falls back to sequential CDN HEAD checks only when the index is unavailable.
     *
     * @param  array<string, true>  $index  Filename → true map; empty array triggers HEAD fallback.
     * @param  array<string, list<string>>  $byBasename  Pre-built basename lookup (built once per run).
     * @param  array<string, list<string>>  $kyzuCompactIndex  Pre-built compact-basename lookup for the K-yzu/Logos source.
     */
    private function resolveLogoUrl(string $channelName, string $countryCode, string $countryFolder, array $index, array $byBasename, string $source, array $kyzuCompactIndex): ?string
    {
        if ($source === 'kyzu') {
            return $index === [] ? null : $this->resolveKyzuLogoUrl($channelName, $countryFolder, $kyzuCompactIndex);
        }

        $slugs = array_values(array_unique(array_filter([
            $this->slugify($channelName, false),
            $this->slugify($channelName, true),
        ])));

        if ($slugs === []) {
            return null;
        }

        $filenames = $this->buildFilenamesForSlugs($slugs, $countryCode);

        // When an index is available, search all subfolders by basename for best match.
        if ($index !== []) {
            $result = $this->resolveFromIndex($filenames, $channelName, $countryFolder, $byBasename);

            if ($result !== null) {
                return $result;
            }

            return $this->compactIndexMatch($slugs, $countryCode, $countryFolder, $channelName, $index);
        }

        // HEAD fallback when index is unavailable.
        foreach ($this->preferredQualityFolders($channelName) as $folder) {
            foreach ($filenames as $filename) {
                $relativePath = $folder === '' ? $filename : "{$folder}/{$filename}";
                $url = $this->buildLogoUrl($countryFolder, $relativePath);

                if ($this->urlExists($url)) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Build the final CDN URL for a relative logo path, URL-encoding each path
     * segment individually. K-yzu/Logos folder names contain characters (e.g. `:`)
     * that are not valid unencoded in a URL path; tv-logo/tv-logos paths are already
     * URL-safe, so encoding them is a no-op.
     */
    private function buildLogoUrl(string $countryFolder, string $relativePath): string
    {
        $fullPath = trim($countryFolder, '/').'/'.$relativePath;

        return $this->cdnBase.'/'.implode('/', array_map('rawurlencode', explode('/', $fullPath)));
    }

    /**
     * Resolve a logo URL against the K-yzu/Logos index.
     *
     * Unlike tv-logo/tv-logos, K-yzu filenames are human-readable channel names
     * with no country-code suffix (e.g. "ABC HD.png"), so matching is done by
     * comparing compact (hyphen-stripped) slugs rather than constructing candidate
     * filenames.
     *
     * @param  array<string, list<string>>  $compactIndex  Pre-built compact-basename → paths map.
     */
    private function resolveKyzuLogoUrl(string $channelName, string $countryFolder, array $compactIndex): ?string
    {
        $keys = array_values(array_unique(array_filter([
            str_replace('-', '', $this->slugify($channelName, true)),
            str_replace('-', '', $this->slugify($channelName, false)),
        ])));

        if ($keys === []) {
            return null;
        }

        $hdPreferred = (bool) preg_match('/\b(hd|fhd|uhd|4k|8k|1080[pi]|720p)\b/iu', $channelName);

        foreach ($keys as $key) {
            if (! isset($compactIndex[$key])) {
                continue;
            }

            $paths = $compactIndex[$key];

            if (count($paths) === 1) {
                return $this->buildLogoUrl($countryFolder, $paths[0]);
            }

            // Prefer root-level files over nested affiliate/variant subfolders.
            $rootPaths = array_values(array_filter($paths, fn (string $p): bool => ! str_contains($p, '/')));
            $candidates = $rootPaths !== [] ? $rootPaths : $paths;

            foreach ($candidates as $path) {
                $isHd = (bool) preg_match('/\bhd\b/i', pathinfo($path, PATHINFO_FILENAME));

                if ($isHd === $hdPreferred) {
                    return $this->buildLogoUrl($countryFolder, $path);
                }
            }

            return $this->buildLogoUrl($countryFolder, $candidates[0]);
        }

        return null;
    }

    /**
     * Build a compact-basename (extension stripped, hyphens removed) → [relativePaths…]
     * lookup from the K-yzu/Logos index, so channel names can be matched against
     * filenames without a shared country-code/quality-suffix convention.
     *
     * @param  array<string, true>  $index
     * @return array<string, list<string>>
     */
    private function buildKyzuCompactIndex(array $index): array
    {
        $map = [];

        foreach ($index as $relativePath => $_) {
            $stem = pathinfo($relativePath, PATHINFO_FILENAME);

            foreach ([true, false] as $stripQualityTags) {
                $key = str_replace('-', '', $this->slugify($stem, $stripQualityTags));

                if ($key !== '') {
                    $map[$key][] = $relativePath;
                }
            }
        }

        foreach ($map as $key => $paths) {
            $map[$key] = array_values(array_unique($paths));
        }

        return $map;
    }

    /**
     * Build a basename → [relativePaths…] lookup from the index.
     *
     * Called once per run so that resolveFromIndex() can search by filename
     * without iterating the full index on every channel.
     *
     * @param  array<string, true>  $index
     * @return array<string, list<string>>
     */
    private function buildBasenameIndex(array $index): array
    {
        $byBasename = [];

        foreach ($index as $relativePath => $_) {
            $bn = strtolower(basename($relativePath));
            $byBasename[$bn][] = $relativePath;
        }

        return $byBasename;
    }

    /**
     * Resolve a logo URL by searching the pre-fetched index across ALL subfolders.
     *
     * Uses a pre-built basename lookup so that files in nested subfolders like
     * sky-sport/hd/ or custom/hd/ are found regardless of folder structure.
     * When multiple paths match the same filename, prefers HD subfolders for
     * HD-hinted channels.
     *
     * @param  array<int, string>  $filenames
     * @param  array<string, list<string>>  $byBasename  Pre-built basename → paths map.
     */
    private function resolveFromIndex(array $filenames, string $channelName, string $countryFolder, array $byBasename): ?string
    {
        $hdPreferred = (bool) preg_match('/\b(hd|fhd|uhd|4k|8k|1080[pi]|720p)\b/iu', $channelName);

        foreach ($filenames as $filename) {
            $lowFilename = strtolower($filename);

            if (! isset($byBasename[$lowFilename])) {
                continue;
            }

            $paths = $byBasename[$lowFilename];

            // Single match - return immediately.
            if (count($paths) === 1) {
                return $this->buildLogoUrl($countryFolder, $paths[0]);
            }

            // Multiple matches - pick the best based on quality preference.
            $hdMatch = null;
            $rootMatch = null;

            foreach ($paths as $path) {
                $inHd = str_contains($path, '/hd/') || str_starts_with($path, 'hd/');

                if ($inHd) {
                    $hdMatch ??= $path;
                } elseif (! str_contains($path, '/')) {
                    $rootMatch ??= $path;
                }
            }

            if ($hdPreferred && $hdMatch !== null) {
                return $this->buildLogoUrl($countryFolder, $hdMatch);
            }

            if ($rootMatch !== null) {
                return $this->buildLogoUrl($countryFolder, $rootMatch);
            }

            return $this->buildLogoUrl($countryFolder, $paths[0]);
        }

        return null;
    }

    /**
     * Build the ordered list of candidate filenames to probe for the given slugs.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    private function buildFilenamesForSlugs(array $slugs, string $countryCode): array
    {
        $filenames = [];

        foreach ($slugs as $slug) {
            $filenames[] = "{$slug}-{$countryCode}.png";
            $filenames[] = "{$slug}.png";

            $parts = explode('-', $slug);
            $lastPart = end($parts);
            $qualitySuffixes = ['hd', 'fhd', 'uhd', 'sd', '4k', '8k'];
            if (count($parts) > 1 && ! preg_match('/^\d+$/', $lastPart) && ! in_array($lastPart, $qualitySuffixes, true)) {
                $shortened = implode('-', array_slice($parts, 0, -1));
                if ($shortened !== '') {
                    $filenames[] = "{$shortened}-{$countryCode}.png";
                }
            }
        }

        return array_values(array_unique($filenames));
    }

    /**
     * @return array<int, string>
     */
    private function preferredQualityFolders(string $channelName): array
    {
        $hasHdHint = (bool) preg_match('/\b(hd|fhd|uhd|4k|8k|1080[pi]|720p)\b/iu', $channelName);

        return $hasHdHint ? ['hd', ''] : ['', 'hd'];
    }

    /**
     * Compact matching fallback - strips all hyphens from both the channel slug
     * and index filenames so minor hyphenation differences still match
     * (e.g. "sport1" vs "sport-1-de.png").
     *
     * @param  array<int, string>  $slugs
     * @param  array<string, true>  $index
     */
    private function compactIndexMatch(array $slugs, string $countryCode, string $countryFolder, string $channelName, array $index): ?string
    {
        $suffixes = ["-{$countryCode}.png", '.png'];
        $qualityFolders = $this->preferredQualityFolders($channelName);
        $channelSlugTiers = $this->buildCompactMatchCandidateTiers($slugs);

        foreach ($channelSlugTiers as $channelSlugs) {
            foreach ($qualityFolders as $preferredFolder) {
                foreach ($index as $relativePath => $_) {
                    $basename = basename($relativePath);
                    $suffixLen = 0;

                    foreach ($suffixes as $suffix) {
                        if (str_ends_with($basename, $suffix)) {
                            $suffixLen = strlen($suffix);

                            break;
                        }
                    }

                    if ($suffixLen === 0) {
                        continue;
                    }

                    $folder = dirname($relativePath);
                    $folder = $folder === '.' ? '' : $folder;

                    $isHdPath = $folder === 'hd' || str_ends_with($folder, '/hd');
                    $wantsHd = $preferredFolder === 'hd';

                    if ($wantsHd !== $isHdPath) {
                        continue;
                    }

                    $indexStem = substr($basename, 0, -$suffixLen);
                    $indexCandidates = $this->buildCompactMatchCandidates([$indexStem]);

                    foreach ($channelSlugs as $channelCandidate) {
                        foreach ($indexCandidates as $indexCandidate) {
                            if ($indexCandidate === $channelCandidate) {
                                return $this->buildLogoUrl($countryFolder, $relativePath);
                            }

                            if ($this->isSafeCompactSuffixMatch($indexCandidate, $channelCandidate)) {
                                return $this->buildLogoUrl($countryFolder, $relativePath);
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Build compact filename match candidates for fuzzy index matching.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, array<int, string>>
     */
    private function buildCompactMatchCandidateTiers(array $slugs): array
    {
        $primary = [];

        foreach ($slugs as $slug) {
            $slug = trim($slug, '-');

            if ($slug !== '') {
                $primary[] = str_replace('-', '', $slug);

                break;
            }
        }

        $all = $this->buildCompactMatchCandidates($slugs);
        $secondary = array_values(array_diff($all, $primary));

        return array_values(array_filter([
            array_values(array_unique(array_filter($primary))),
            array_values(array_unique(array_filter($secondary))),
        ]));
    }

    /**
     * Build compact filename match candidates for fuzzy index matching.
     *
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    private function buildCompactMatchCandidates(array $slugs): array
    {
        $candidates = [];

        foreach ($slugs as $slug) {
            $slug = trim($slug, '-');

            if ($slug === '') {
                continue;
            }

            $candidates[] = str_replace('-', '', $slug);
            $candidates[] = $this->stripVariantTokensForMatch($slug);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function stripVariantTokensForMatch(string $slug): string
    {
        $tokens = array_values(array_filter(
            explode('-', $slug),
            fn (string $token): bool => $token !== '' && ! in_array($token, self::LOGO_VARIANT_TOKENS, true)
        ));

        return implode('', $tokens);
    }

    private function isSafeCompactSuffixMatch(string $indexCandidate, string $channelCandidate): bool
    {
        if (strlen($channelCandidate) < 8 || strlen($indexCandidate) <= strlen($channelCandidate)) {
            return false;
        }

        return str_ends_with($indexCandidate, $channelCandidate);
    }

    /**
     * Fetch the set of known logo filenames for a country folder from the
     * GitHub Contents API and store it in the cache.
     *
     * Returns a map of lowercase relative path → true for O(1) lookups.
     * Returns an empty array on failure so callers can fall back to HEAD checks.
     *
     * @param  array<string, mixed>  $cache
     * @return array<string, true>
     */
    private function fetchCountryIndex(string $countryCode, string $countryFolder, string $repoCacheKey, array &$cache, bool &$cacheChanged, bool $ignoreCache = false): array
    {
        $cacheKey = "index:{$repoCacheKey}:{$countryCode}";

        if (! $ignoreCache && array_key_exists($cacheKey, $cache) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $index = $this->collectPngIndexEntries($countryFolder);

        if ($index !== []) {
            $cache[$cacheKey] = $index;
            $cacheChanged = true;
        }

        return $index;
    }

    /**
     * Recursively collect indexable logo files (per $indexExtensions) from a
     * country folder and its subfolders.
     *
     * @return array<string, true>
     */
    private function collectPngIndexEntries(string $path, string $prefix = ''): array
    {
        try {
            $requestPath = implode('/', array_map('rawurlencode', explode('/', $path)));

            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'tv-logos-plugin/1.0',
                ])
                ->get($this->indexApiBase.'/'.$requestPath);

            if (! $response->successful()) {
                return [];
            }

            $entries = [];

            foreach ((array) ($response->json() ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $type = (string) ($item['type'] ?? '');
                $name = (string) ($item['name'] ?? '');
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

                if ($type === 'file' && in_array($extension, $this->indexExtensions, true)) {
                    $entries[strtolower($prefix.$name)] = true;

                    continue;
                }

                if ($type === 'dir' && $name !== '') {
                    $childPath = trim($path.'/'.$name, '/');
                    $childPrefix = $prefix.$name.'/';
                    $entries = [...$entries, ...$this->collectPngIndexEntries($childPath, $childPrefix)];
                }
            }

            return $entries;
        } catch (Throwable) {
            return [];
        }
    }

    private function urlExists(string $url): bool
    {
        try {
            return Http::timeout(8)->head($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Normalise a channel name into a hyphenated slug suitable for tv-logo filenames.
     *
     * Steps: lowercase → strip quality tags and bracket content → normalise & → strip
     * non-alphanumeric → collapse whitespace → hyphenate.
     */
    private function slugify(string $name, bool $stripQualityTags = true): string
    {
        // Split camelCase / PascalCase boundaries BEFORE lowercasing
        // e.g. "ProSieben" → "Pro Sieben", "SportDeutschland" → "Sport Deutschland"
        $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name) ?? $name;

        $name = mb_strtolower($name, 'UTF-8');

        if ($stripQualityTags) {
            // Strip quality suffixes and optional trailing modifiers (raw, low, high)
            // e.g. "HDraw" → "", "FHD Low" → "", "HEVC" → ""
            $name = preg_replace('/\b(hd|fhd|uhd|4k|8k|sd|1080[pi]|720p|hevc|h\.?264|h\.?265)\s*(raw|low|high)?\b/iu', '', $name) ?? $name;
        }

        // Strip common IPTV transport / source terms (always, regardless of quality tag stripping)
        // Use negative lookahead so "sat" inside "SAT.1" is not stripped (sat followed by dot+digit).
        $name = preg_replace('/\b(cable|sat(?:ellite)?(?![.\s]*\d)|terrestrial|dvb[tcsh]?|iptv|ott|fta|stream|linear)\b/iu', '', $name) ?? $name;

        // Remove content inside any bracket type
        $name = preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/', '', $name) ?? $name;

        // Normalise ampersand early (before stripping non-alnum)
        $name = str_replace('&', ' and ', $name);

        // Treat dots as word separators (e.g. "SAT.1" → "SAT 1")
        $name = str_replace('.', ' ', $name);

        // Convert plus sign to word "plus" (e.g. "ANIXE+" → "ANIXE plus")
        $name = str_replace('+', ' plus ', $name);

        // Keep only unicode letters, digits, and spaces
        $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name) ?? $name;

        // Collapse whitespace
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? $name;

        // Hyphenate and collapse consecutive hyphens
        $name = str_replace(' ', '-', $name);
        $name = preg_replace('/-+/', '-', $name) ?? $name;

        return trim($name, '-');
    }

    /**
     * Load the match cache from storage.
     *
     * Returns an empty cache structure when the file is missing, malformed, or expired.
     *
     * @return array{version: int, cached_at: string, matches: array<string, string>}
     */
    private function loadCache(int $cacheTtlDays): array
    {
        $empty = ['version' => 5, 'cached_at' => now()->toIso8601String(), 'matches' => []];

        try {
            if (! Storage::disk('local')->exists(self::CACHE_FILE)) {
                return $empty;
            }

            $data = json_decode((string) Storage::disk('local')->get(self::CACHE_FILE), true);

            if (! is_array($data) || ! isset($data['matches']) || ($data['version'] ?? 1) < 5) {
                return $empty;
            }

            if ($cacheTtlDays > 0 && isset($data['cached_at'])) {
                if (Carbon::parse($data['cached_at'])->diffInDays(now()) >= $cacheTtlDays) {
                    return $empty;
                }
            }

            return $data;
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * Persist the match cache to storage.
     */
    private function saveCache(array $cache): void
    {
        try {
            Storage::disk('local')->put(
                self::CACHE_FILE,
                json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        } catch (Throwable) {
            // Non-fatal - a missing cache means the next run re-checks the CDN.
        }
    }

    private function normalizeRepoCacheKey(string $repo): string
    {
        $normalized = mb_strtolower(trim($repo), 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9_.\/-]+/', '_', $normalized) ?: '';

        return $normalized !== '' ? $normalized : self::DEFAULT_GITHUB_REPO;
    }

    /**
     * Build the normalization configuration array from plugin settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool, strip_unicode: bool, strip_raw: bool, strip_provider_info: bool, provider_terms: list<string>, strip_quality_extras: bool, custom_patterns: list<string>}
     */
    private function buildNormalizationConfig(array $settings): array
    {
        $enabled = (bool) ($settings['normalize_channel_names'] ?? false);

        $providerTerms = [];
        $rawProviderTerms = trim((string) ($settings['normalize_provider_terms'] ?? ''));

        if ($rawProviderTerms !== '') {
            foreach (explode("\n", $rawProviderTerms) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $providerTerms[] = $line;
                }
            }
        }

        $customPatterns = [];
        $rawPatterns = trim((string) ($settings['normalize_custom_patterns'] ?? ''));

        if ($rawPatterns !== '') {
            foreach (explode("\n", $rawPatterns) as $line) {
                $line = trim($line);
                if ($line !== '' && @preg_match($line, '') !== false) {
                    $customPatterns[] = $line;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'strip_unicode' => $enabled && (bool) ($settings['normalize_strip_unicode'] ?? true),
            'strip_raw' => $enabled && (bool) ($settings['normalize_strip_raw'] ?? true),
            'strip_provider_info' => $enabled && (bool) ($settings['normalize_strip_provider_info'] ?? true),
            'provider_terms' => $providerTerms,
            'strip_quality_extras' => $enabled && (bool) ($settings['normalize_strip_quality_extras'] ?? true),
            'custom_patterns' => $customPatterns,
        ];
    }

    /**
     * Normalize a channel display name before slug generation.
     *
     * Applies enabled normalization rules in a fixed order to produce
     * a cleaner name that maps more reliably to logo filenames.
     */
    private function normalizeChannelName(string $name, array $config): string
    {
        if (! $config['enabled']) {
            return $name;
        }

        // 1. Unicode → ASCII transliteration (superscripts, subscripts, small-caps)
        if ($config['strip_unicode']) {
            $unicodeMap = [
                // Superscripts
                '⁰' => '0', '¹' => '1', '²' => '2', '³' => '3', '⁴' => '4',
                '⁵' => '5', '⁶' => '6', '⁷' => '7', '⁸' => '8', '⁹' => '9',
                '⁺' => '+', '⁻' => '-',
                // Subscripts
                '₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4',
                '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9',
                // Small-caps Latin
                'ᴀ' => 'A', 'ʙ' => 'B', 'ᴄ' => 'C', 'ᴅ' => 'D', 'ᴇ' => 'E',
                'ꜰ' => 'F', 'ɢ' => 'G', 'ʜ' => 'H', 'ɪ' => 'I', 'ᴊ' => 'J',
                'ᴋ' => 'K', 'ʟ' => 'L', 'ᴍ' => 'M', 'ɴ' => 'N', 'ᴏ' => 'O',
                'ᴘ' => 'P', 'ꞯ' => 'Q', 'ʀ' => 'R', 'ꜱ' => 'S', 'ᴛ' => 'T',
                'ᴜ' => 'U', 'ᴠ' => 'V', 'ᴡ' => 'W', 'ʏ' => 'Y', 'ᴢ' => 'Z',
            ];

            $name = strtr($name, $unicodeMap);
        }

        // 2. Strip "raw" / "RAW" appended to quality tags (e.g. "HDraw" → "HD")
        if ($config['strip_raw']) {
            $name = (string) preg_replace('/\b(HD|FHD|UHD|SD)\s*raw\b/iu', '$1', $name);
        }

        // 3. Strip common IPTV transport / source terms that are never part of a channel name
        // Use negative lookahead so "Sat" inside "SAT.1" is not stripped (Sat followed by dot/space+digit).
        if ($config['strip_provider_info']) {
            $name = (string) preg_replace('/\b(Cable|Sat(?:ellite)?(?![.\s]*\d)|Terrestrial|DVB[TCSH]?|IPTV|OTT|FTA|Stream|Linear)\b/iu', '', $name);
        }

        // 4. Strip user-configured provider terms (one term per line in settings)
        if ($config['strip_provider_info'] && $config['provider_terms'] !== []) {
            $escapedTerms = array_map(fn (string $t): string => preg_quote($t, '/'), $config['provider_terms']);
            $name = (string) preg_replace('/\b('.implode('|', $escapedTerms).')\b/iu', '', $name);
        }

        // 5. Strip extra quality descriptors that follow a quality tag
        if ($config['strip_quality_extras']) {
            // "HD Low" → "HD", "HD High" → "HD"
            $name = (string) preg_replace('/\b(HD|FHD|UHD|SD)\s*(Low|High)\b/iu', '$1', $name);
        }

        // 6. Apply user-defined custom regex patterns (each pattern replaces match with empty string)
        foreach ($config['custom_patterns'] as $pattern) {
            $result = @preg_replace($pattern, '', $name);
            if ($result !== null) {
                $name = $result;
            }
        }

        // Final cleanup: collapse whitespace, trim
        $name = trim((string) preg_replace('/\s{2,}/', ' ', $name));

        return $name;
    }
}
