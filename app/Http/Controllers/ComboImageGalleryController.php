<?php

namespace App\Http\Controllers;

use App\Models\ComboImage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ComboImageGalleryController extends Controller
{
    /**
     * Show a saved combo image.
     */
    public function show(ComboImage $image): BinaryFileResponse
    {
        return response()->file($this->path($image), $this->headers());
    }

    /**
     * Download a saved combo image.
     */
    public function download(ComboImage $image): BinaryFileResponse
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

    private function path(ComboImage $image): string
    {
        $path = 'combo-gallery/'.$image->uuid.'.png';

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->path($path);
    }
}
