<?php

namespace App\Http\Controllers;

use App\Actions\BuildGoogleShoppingFeed;
use App\Enums\OdooCompany;
use App\Models\OdooConnection;
use App\Services\OdooClient;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoogleShoppingFeedController extends Controller
{
    /**
     * Download the current catalogue as a Google Merchant Center feed.
     */
    public function __invoke(BuildGoogleShoppingFeed $feed): StreamedResponse|RedirectResponse
    {
        $connection = OdooConnection::for(OdooCompany::MidMeds);

        if ($connection === null || blank($connection->api_key)) {
            return $this->back(__('Connect Odoo before downloading the Google Shopping feed.'));
        }

        try {
            $contents = $feed->handle(new OdooClient($connection));
        } catch (RuntimeException) {
            return $this->back(__('Could not read products from Odoo.'));
        }

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, 'midmeds-google-shopping.txt', [
            'Content-Type' => 'text/tab-separated-values; charset=UTF-8',
        ]);
    }

    private function back(string $reason): RedirectResponse
    {
        return redirect()
            ->route('google-shopping-suggestions')
            ->with('google-shopping-feed-error', $reason);
    }
}
