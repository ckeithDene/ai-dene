<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ComboImageController extends Controller
{
    /**
     * Show a generated combo image.
     */
    public function show(string $image): BinaryFileResponse
    {
        return response()->file($this->path($image), $this->headers());
    }

    /**
     * Download a generated combo image.
     */
    public function download(string $image): BinaryFileResponse
    {
        return response()->download($this->path($image), 'midmeds-combo-image.png', $this->headers());
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function path(string $image): string
    {
        abort_unless(Str::isUuid($image), 404);

        $path = 'combo-images/'.$image.'.png';

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->path($path);
    }
}
