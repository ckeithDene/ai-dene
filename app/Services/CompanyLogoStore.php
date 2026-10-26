<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CompanyLogoStore
{
    /**
     * Store a company logo from Odoo, or from a public image URL.
     */
    public function store(int $partnerId, ?string $contents, ?string $url): ?string
    {
        $stored = $this->storeContents($partnerId, $contents);

        if ($stored !== null) {
            return $stored;
        }

        return $this->storeFromUrl($partnerId, $url);
    }

    private function storeContents(int $partnerId, ?string $contents): ?string
    {
        if ($contents === null || strlen($contents) > 2_000_000) {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if (! is_array($info)) {
            return null;
        }

        $extension = $this->extension($info['mime']);

        if ($extension === null) {
            return null;
        }

        return $this->put($partnerId, $extension, $contents);
    }

    private function storeFromUrl(int $partnerId, ?string $url): ?string
    {
        if ($url === null || ! $this->isPublicHttpsUrl($url)) {
            return null;
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return $this->storeContents($partnerId, $response->body());
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! is_string($host) || ($parts['scheme'] ?? null) !== 'https') {
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

    private function extension(mixed $mime): ?string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function put(int $partnerId, string $extension, string $contents): string
    {
        foreach (['png', 'jpg', 'gif', 'webp'] as $existing) {
            Storage::disk('public')->delete("first-order-diamonds/{$partnerId}.{$existing}");
        }

        $path = "first-order-diamonds/{$partnerId}.{$extension}";

        Storage::disk('public')->put($path, $contents);

        return $path;
    }
}
