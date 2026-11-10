<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WarehouseLabAssetController extends Controller
{
    /**
     * Serve one file from the Warehouse Lab prototype to a signed-in user.
     */
    public function __invoke(?string $path = null): BinaryFileResponse
    {
        $root = realpath(base_path('Dene_Warehouse_Lab_Source/dist'));
        $relative = $this->relativePath($path);

        abort_unless(is_string($root) && $relative !== null, 404);

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $full = realpath($candidate);
        $extension = strtolower(pathinfo(is_string($full) ? $full : '', PATHINFO_EXTENSION));

        abort_unless(is_string($full) && is_file($full) && $this->staysInside($root, $full) && isset($this->types()[$extension]), 404);

        return response()->file($full, [
            'Content-Type' => $this->types()[$extension],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function relativePath(?string $path): ?string
    {
        $relative = str_replace('\\', '/', (string) $path);
        $relative = ltrim($relative, '/');

        if ($relative === '') {
            $relative = 'index.html';
        }

        if (str_contains($relative, "\0") || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            return null;
        }

        return $relative;
    }

    private function staysInside(string $root, string $full): bool
    {
        $rootPrefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $fullPath = str_replace('\\', '/', $full);

        return str_starts_with($fullPath, $rootPrefix);
    }

    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        return [
            'html' => 'text/html; charset=UTF-8',
            'js' => 'text/javascript; charset=UTF-8',
            'mjs' => 'text/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'txt' => 'text/plain; charset=UTF-8',
        ];
    }
}
