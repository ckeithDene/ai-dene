<?php

namespace App\Http\Controllers;

use App\Models\FirstOrderDiamond;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FirstOrderDiamondLogoController extends Controller
{
    /**
     * Show a stored company logo to a signed-in user.
     */
    public function __invoke(FirstOrderDiamond $diamond): StreamedResponse|Response
    {
        $path = $diamond->logo_path;

        abort_unless(
            is_string($path) && preg_match('/^first-order-diamonds\/\d+\.(png|jpe?g|gif|webp|ico)$/', $path) === 1,
            404,
        );

        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }
}
