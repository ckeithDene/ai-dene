<?php

namespace App\Services;

use App\Data\CatalogueProduct;
use App\Data\SalesProspect;
use App\Models\AiModel;
use App\Models\SalesEngineSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesStrategist
{
    public function __construct(private SalesStrategyPrompt $prompt) {}

    /**
     * Research the company and choose catalogue search phrases.
     *
     * @param  array<int, string>  $categories
     * @param  array<int, array{name: string, quantity: string, spent: string}>  $purchases
     * @return array<string, mixed>
     */
    public function research(AiModel $aiModel, SalesProspect $prospect, SalesEngineSetting $settings, array $categories, array $purchases): array
    {
        return $this->request($aiModel, $this->prompt->research($settings), [
            'company' => $prospect->toArray(),
            'settings' => $settings->toPrompt(),
            'category_names' => $categories,
            'known_purchases' => $purchases,
        ], true);
    }

    /**
     * Match catalogue products, score the account, and write the sales materials.
     *
     * @param  array<string, mixed>  $research
     * @param  array<int, CatalogueProduct>  $products
     * @param  array<int, array{name: string, quantity: string, spent: string}>  $purchases
     * @return array<string, mixed>
     */
    public function strategy(AiModel $aiModel, SalesProspect $prospect, SalesEngineSetting $settings, array $research, array $products, array $purchases): array
    {
        return $this->request($aiModel, $this->prompt->strategy($settings), [
            'company' => $prospect->toArray(),
            'settings' => $settings->toPrompt(),
            'research' => $research,
            'catalogue_products' => array_map(fn (CatalogueProduct $product): array => $product->toPrompt(), $products),
            'known_purchases' => $purchases,
        ], false);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function request(AiModel $aiModel, string $instructions, array $input, bool $search): array
    {
        set_time_limit(330);

        $request = Http::connectTimeout(5)
            ->timeout(300)
            ->withoutRedirecting()
            ->acceptJson()
            ->asJson()
            ->withToken($aiModel->api_key);

        if (filled($aiModel->organization)) {
            $request = $request->withHeaders([
                'OpenAI-Organization' => $aiModel->organization,
            ]);
        }

        $payload = [
            'model' => $aiModel->model,
            'instructions' => $instructions,
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];

        if ($search) {
            $payload['tools'] = [
                ['type' => 'web_search'],
            ];
        }

        try {
            $response = $request->post('https://api.openai.com/v1/responses', $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach ChatGPT.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('ChatGPT could not build this sales strategy.');
        }

        $decoded = json_decode($this->jsonText($response->json()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('ChatGPT could not build this sales strategy.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function jsonText(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $text = is_string($payload['output_text'] ?? null) ? $payload['output_text'] : '';

        if ($text === '' && is_array($payload['output'] ?? null)) {
            foreach ($payload['output'] as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'message' || ! is_array($item['content'] ?? null)) {
                    continue;
                }

                foreach ($item['content'] as $content) {
                    if (is_array($content) && ($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                        $text = $content['text'];
                    }
                }
            }
        }

        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return $text;
        }

        return substr($text, $start, $end - $start + 1);
    }
}
