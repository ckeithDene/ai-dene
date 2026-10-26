<?php

namespace App\Services;

use App\Data\ConnectionResult;
use App\Models\OdooConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OdooConnectionVerifier
{
    /**
     * Check that the saved details can authenticate with Odoo.
     */
    public function verify(OdooConnection $connection): ConnectionResult
    {
        try {
            $response = Http::connectTimeout(3)
                ->timeout(10)
                ->withoutRedirecting()
                ->acceptJson()
                ->asJson()
                ->post(rtrim($connection->url, '/').'/jsonrpc', [
                    'jsonrpc' => '2.0',
                    'method' => 'call',
                    'params' => [
                        'service' => 'common',
                        'method' => 'authenticate',
                        'args' => [
                            $connection->database,
                            $connection->username,
                            $connection->api_key,
                            (object) [],
                        ],
                    ],
                    'id' => 1,
                ]);
        } catch (ConnectionException) {
            return new ConnectionResult(false, __('Could not reach the Odoo server.'));
        }

        if ($response->redirect()) {
            return new ConnectionResult(false, __('Could not reach the Odoo server.'));
        }

        $userId = $response->json('result');

        if ($response->successful() && is_int($userId) && $userId > 0) {
            return new ConnectionResult(true, __('Connected to Odoo.'));
        }

        return new ConnectionResult(false, __('Odoo rejected these connection details.'));
    }
}
