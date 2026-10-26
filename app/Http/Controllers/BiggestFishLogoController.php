<?php

namespace App\Http\Controllers;

use App\Models\BiggestFish;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BiggestFishLogoController extends Controller
{
    /**
     * Show a stored company logo to a signed-in user.
     */
    public function __invoke(BiggestFish $fish): StreamedResponse|Response
    {
        $path = $fish->logo_path;

        abort_unless(
            is_string($path) && preg_match('/^biggest-fish\/\d+\.(png|jpe?g|gif|webp|ico)$/', $path) === 1,
            404,
        );

        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
