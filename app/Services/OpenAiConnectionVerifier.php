<?php

namespace App\Services;

use App\Data\ConnectionResult;
use App\Models\AiModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiConnectionVerifier
{
    /**
     * Check that the saved details can access the ChatGPT model.
     */
    public function verify(AiModel $aiModel): ConnectionResult
    {
        $request = Http::connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->acceptJson()
            ->withToken($aiModel->api_key);

        if (filled($aiModel->organization)) {
            $request = $request->withHeaders([
                'OpenAI-Organization' => $aiModel->organization,
            ]);
        }

        try {
            $response = $request->get('https://api.openai.com/v1/models/'.rawurlencode($aiModel->model));
        } catch (ConnectionException) {
            return new ConnectionResult(false, __('Could not reach OpenAI.'));
        }

        if ($response->redirect()) {
            return new ConnectionResult(false, __('Could not reach OpenAI.'));
        }

        if ($response->successful()) {
            return new ConnectionResult(true, __('Connected to ChatGPT.'));
        }

        $message = match ($response->status()) {
            401 => filled($aiModel->organization)
                ? __('OpenAI rejected the API key. Clear the organization field unless this key belongs to that organization.')
                : __('OpenAI rejected the API key.'),
            403 => __('OpenAI refused access to that model.'),
            404 => __('OpenAI could not find that model.'),
            default => __('OpenAI returned an unexpected response.'),
        };

        return new ConnectionResult(false, $message);
    }
}
