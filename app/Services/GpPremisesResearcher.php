<?php

namespace App\Services;

use App\Models\AiModel;
use App\Models\GpPremisesLead;
use App\Models\GpPremisesSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GpPremisesResearcher
{
    public function __construct(private GpPremisesPrompt $prompt) {}

    /**
     * Search for GP premises projects and return the decoded research.
     *
     * @param  array<int, array<string, string|null>>  $existing
     * @return array<string, mixed>
     */
    public function discover(AiModel $aiModel, GpPremisesSetting $settings, array $existing): array
    {
        return $this->request($aiModel, $this->prompt->discover($settings), [
            'search' => $settings->toPrompt(),
            'existing_projects' => $existing,
        ]);
    }

    /**
     * Check one saved project for a newer public status.
     *
     * @return array<string, mixed>
     */
    public function refresh(AiModel $aiModel, GpPremisesLead $lead): array
    {
        return $this->request($aiModel, $this->prompt->refresh(), [
            'search_date' => now()->format('d/m/Y'),
            'project_to_check' => [
                'surgery_name' => $lead->surgery_name,
                'website' => $lead->website,
                'town' => $lead->town,
                'county' => $lead->county,
                'nation' => $lead->nation->label(),
                'development_type' => $lead->development_type->value,
                'development_details' => $lead->development_details,
                'project_stage' => $lead->project_stage->value,
                'source_title' => $lead->source_title,
                'source_published_on' => $lead->source_published_on,
                'source_url' => $lead->source_url,
                'project_dates' => $lead->project_dates,
                'funding' => $lead->funding,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function request(AiModel $aiModel, string $instructions, array $input): array
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

        try {
            $response = $request->post('https://api.openai.com/v1/responses', [
                'model' => $aiModel->model,
                'tools' => [
                    ['type' => 'web_search'],
                ],
                'instructions' => $instructions,
                'input' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach ChatGPT.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('ChatGPT could not research GP premises.');
        }

        $decoded = json_decode($this->jsonText($response->json()), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('ChatGPT could not research GP premises.');
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
