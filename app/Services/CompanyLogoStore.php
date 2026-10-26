<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CompanyLogoStore
{
    /**
     * Store a company logo from a public image, the company website, or Odoo.
     */
    public function store(int $partnerId, ?string $contents, ?string $url, ?string $website = null, string $directory = 'first-order-diamonds'): ?string
    {
        $stored = $this->storeFromUrl($partnerId, $url, $directory)
            ?? $this->storeFromWebsite($partnerId, $website, $directory);

        if ($stored !== null) {
            return $stored;
        }

        return $this->storeContents($partnerId, $contents, 1, $directory);
    }

    private function storeFromWebsite(int $partnerId, ?string $website, string $directory): ?string
    {
        $website = $this->httpsWebsite($website);

        if ($website === null) {
            return null;
        }

        $page = $this->fetch($website);

        $candidates = $page === null
            ? []
            : $this->candidates($page['body'], $page['url']);

        $origin = $this->origin($website);

        if ($origin !== null) {
            $candidates[] = $origin.'/apple-touch-icon.png';
            $candidates[] = $origin.'/favicon.ico';
        }

        $best = null;
        $bestWidth = 0;
        $checked = [];

        foreach ($candidates as $candidate) {
            if (isset($checked[$candidate])) {
                continue;
            }

            if (count($checked) >= 4) {
                break;
            }

            $checked[$candidate] = true;
            $image = $this->downloadImage($candidate, 1);

            if ($image === null) {
                continue;
            }

            if ($image['width'] > $bestWidth) {
                $best = $image['contents'];
                $bestWidth = $image['width'];
            }

            if ($bestWidth >= 128) {
                break;
            }
        }

        if ($best !== null) {
            return $this->storeContents($partnerId, $best, 1, $directory);
        }

        $host = parse_url($website, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $favicon = $this->downloadImage(
            'https://www.google.com/s2/favicons?domain='.rawurlencode($host).'&sz=128',
            32,
        );

        if ($favicon === null) {
            return null;
        }

        return $this->storeContents($partnerId, $favicon['contents'], 32, $directory);
    }

    /**
     * @return array<int, string>
     */
    private function candidates(string $html, string $pageUrl): array
    {
        $ranked = [];

        preg_match_all('/<link\b[^>]*>/i', $html, $links);

        foreach ($links[0] as $tag) {
            if (! preg_match('/\brel=["\']([^"\']+)["\']/i', $tag, $rel) || ! preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $href)) {
                continue;
            }

            if (! str_contains(strtolower($rel[1]), 'icon')) {
                continue;
            }

            $url = $this->resolveUrl($pageUrl, html_entity_decode($href[1], ENT_QUOTES));

            if ($url === null) {
                continue;
            }

            $score = str_contains(strtolower($rel[1]), 'apple') ? 300 : 50;

            if (preg_match('/\bsizes=["\'](\d+)x\d+["\']/i', $tag, $sizes) === 1) {
                $score += (int) $sizes[1];
            }

            $ranked[$url] = max($ranked[$url] ?? 0, $score);
        }

        preg_match_all('/<meta\b[^>]*>/i', $html, $metas);

        foreach ($metas[0] as $tag) {
            $isImage = preg_match('/\bproperty=["\']og:image(?::url)?["\']/i', $tag) === 1
                || preg_match('/\bname=["\']twitter:image["\']/i', $tag) === 1;

            if (! $isImage || preg_match('/\bcontent=["\']([^"\']+)["\']/i', $tag, $content) !== 1) {
                continue;
            }

            $url = $this->resolveUrl($pageUrl, html_entity_decode($content[1], ENT_QUOTES));

            if ($url !== null) {
                $ranked[$url] = max($ranked[$url] ?? 0, 200);
            }
        }

        arsort($ranked);

        return array_keys($ranked);
    }

    private function storeFromUrl(int $partnerId, ?string $url, string $directory): ?string
    {
        $image = $this->downloadImage($url, 1);

        if ($image === null) {
            return null;
        }

        return $this->storeContents($partnerId, $image['contents'], 1, $directory);
    }

    /**
     * @return array{contents: string, width: int}|null
     */
    private function downloadImage(?string $url, int $minimumWidth): ?array
    {
        $response = $this->fetch($url);

        if ($response === null) {
            return null;
        }

        $info = @getimagesizefromstring($response['body']);

        if (! is_array($info) || $info[0] < $minimumWidth || $this->extension($info['mime']) === null) {
            return null;
        }

        return [
            'contents' => $response['body'],
            'width' => $info[0],
        ];
    }

    /**
     * @return array{body: string, url: string}|null
     */
    private function fetch(?string $url): ?array
    {
        $current = $url;

        for ($hop = 0; $hop < 4; $hop++) {
            if ($current === null || ! $this->isPublicHttpsUrl($current)) {
                return null;
            }

            try {
                $response = Http::connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; FirstOrderDiamonds/1.0)',
                        'Accept' => 'text/html,image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8',
                    ])
                    ->get($current);
            } catch (ConnectionException) {
                return null;
            }

            if ($this->isRedirect($response)) {
                $current = $this->resolveUrl($current, $response->header('Location'));

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            if ($body === '' || strlen($body) > 2_000_000) {
                return null;
            }

            return [
                'body' => $body,
                'url' => $current,
            ];
        }

        return null;
    }

    private function storeContents(int $partnerId, ?string $contents, int $minimumWidth, string $directory): ?string
    {
        if ($contents === null || strlen($contents) > 2_000_000) {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if (! is_array($info) || $info[0] < $minimumWidth) {
            return null;
        }

        $extension = $this->extension($info['mime']);

        if ($extension === null) {
            return null;
        }

        $folder = preg_match('/^[a-z0-9-]+$/', $directory) === 1 ? $directory : 'first-order-diamonds';

        foreach (['png', 'jpg', 'gif', 'webp', 'ico'] as $existing) {
            Storage::disk('public')->delete("{$folder}/{$partnerId}.{$existing}");
        }

        $path = "{$folder}/{$partnerId}.{$extension}";

        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    private function httpsWebsite(?string $website): ?string
    {
        if (! is_string($website) || $website === '') {
            return null;
        }

        if (str_starts_with($website, 'http://')) {
            $website = 'https://'.substr($website, strlen('http://'));
        }

        return $this->isPublicHttpsUrl($website) ? $website : null;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($host) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user'])) {
            return false;
        }

        $addresses = gethostbynamel($host) ?: [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        }

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function resolveUrl(string $base, string $relative): ?string
    {
        $relative = trim($relative);

        if ($relative === '' || str_starts_with($relative, 'data:') || str_starts_with($relative, 'http://')) {
            return null;
        }

        if (str_starts_with($relative, 'https://')) {
            return $relative;
        }

        if (str_starts_with($relative, '//')) {
            return 'https:'.$relative;
        }

        $origin = $this->origin($base);

        if ($origin === null) {
            return null;
        }

        if (str_starts_with($relative, '/')) {
            return $origin.$relative;
        }

        return $origin.'/'.ltrim($relative, '/');
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        return 'https://'.$host;
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function extension(mixed $mime): ?string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => null,
        };
    }
}
