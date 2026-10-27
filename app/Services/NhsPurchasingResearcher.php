<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NhsPurchasingResearcher
{
    public function __construct(private NhsPurchasingPrompt $prompt) {}

    /**
     * Search official sources for GP purchasing directives.
     *
     * @param  array<int, array<string, string|null>>  $existing
     * @return array<string, mixed>
     */
    public function monitor(AiModel $aiModel, array $existing): array
    {
        $notes = $this->request($aiModel, $this->prompt->monitor(), [
            'search_date' => now()->format('d/m/Y'),
            'existing_directives' => $existing,
        ], true);
        $decoded = $this->decode($notes);

        if (! is_array($decoded) || ! is_array($decoded['directives'] ?? null)) {
            if (trim($notes) === '') {
                throw new RuntimeException('ChatGPT did not return any NHS research.');
            }

            $decoded = $this->decode($this->request($aiModel, $this->prompt->structureDirectives(), [
                'research_notes' => mb_substr($notes, 0, 14000),
            ], false));
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('ChatGPT could not research NHS purchasing patterns.');
        }

        return $decoded;
    }

    /**
     * Translate directives into product demand.
     *
     * @param  array<int, array<string, mixed>>  $directives
     * @param  array<int, string>  $existingOpportunities
     * @return array<string, mixed>
     */
    public function interpret(AiModel $aiModel, array $directives, array $existingOpportunities): array
    {
        $notes = $this->request($aiModel, $this->prompt->interpret(), [
            'search_date' => now()->format('d/m/Y'),
            'directives_to_interpret' => $directives,
            'existing_opportunities' => $existingOpportunities,
        ], true);
        $decoded = $this->decode($notes);

        if (! is_array($decoded)) {
            throw new RuntimeException('ChatGPT could not research NHS purchasing patterns.');
        }

        return $decoded;
    }

    /**
     * Compare opportunities with products read from the Dene catalogue.
     *
     * @param  array<int, array<string, mixed>>  $opportunities
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    public function matchCatalogue(AiModel $aiModel, array $opportunities, array $products): array
    {
        $decoded = $this->decode($this->request($aiModel, $this->prompt->matchCatalogue(), [
            'opportunities' => $opportunities,
            'catalogue_products' => $products,
            'catalogue_note' => 'This is a search sample, not the full Dene Healthcare catalogue. Margin, stock and supplier data were not read.',
        ], false));

        if (! is_array($decoded)) {
            throw new RuntimeException('ChatGPT could not research NHS purchasing patterns.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function request(AiModel $aiModel, string $instructions, array $input, bool $search): string
    {
        $request = Http::connectTimeout(5)
            ->timeout(120)
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
            throw new RuntimeException('ChatGPT could not research NHS purchasing patterns.');
        }

        return $this->responseText($response->json());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function responseText(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $chunks = [];

        if (is_string($payload['output_text'] ?? null) && trim($payload['output_text']) !== '') {
            $chunks[] = $payload['output_text'];
        }

        if (is_array($payload['output'] ?? null)) {
            foreach ($payload['output'] as $item) {
                if (! is_array($item) || ! is_array($item['content'] ?? null)) {
                    continue;
                }

                foreach ($item['content'] as $content) {
                    if (! is_array($content) || ! in_array($content['type'] ?? null, ['output_text', 'text'], true)) {
                        continue;
                    }

                    if (is_string($content['text'] ?? null) && trim($content['text']) !== '') {
                        $chunks[] = $content['text'];
                    }
                }
            }
        }

        return trim(implode("\n", $chunks));
    }
}
