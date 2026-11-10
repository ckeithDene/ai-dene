<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class WarehouseLayoutAdvisor
{
    public function __construct(private WarehouseMovePlanner $planner) {}

    public function ensureConnected(): void
    {
        $aiModel = AiModel::current();

        if ($aiModel === null || blank($aiModel->api_key)) {
            throw new RuntimeException('Connect ChatGPT before asking for location advice.');
        }
    }

    /**
     * Ask the saved ChatGPT model to rearrange stock and suggest ways to pick faster.
     *
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return array{suggestions: array<int, array<string, mixed>>|null, notes: array<int, string>, warning: string|null}
     */
    public function advise(array $picks, array $suggestions): array
    {
        $this->ensureConnected();
        $aiModel = AiModel::current();

        if (! $aiModel instanceof AiModel) {
            throw new RuntimeException('Connect ChatGPT before asking for location advice.');
        }

        $fallback = [
            'suggestions' => null,
            'notes' => [],
            'warning' => 'ChatGPT could not rearrange these locations, so these are the calculated suggestions.',
        ];

        try {
            $decoded = $this->decode($this->request($aiModel, $picks, $suggestions));
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Could not reach ChatGPT.') {
                throw $exception;
            }

            return $fallback;
        }

        if ($decoded === null) {
            return $fallback;
        }

        $notes = $this->notes($decoded);
        $accepted = $this->planner->proposals($picks, $this->proposed($decoded));

        if ($accepted === null) {
            return [
                'suggestions' => null,
                'notes' => $notes,
                'warning' => $notes === []
                    ? $fallback['warning']
                    : 'ChatGPT suggested moves that would not leave a free location, so these are the calculated suggestions.',
            ];
        }

        return [
            'suggestions' => $accepted,
            'notes' => $notes,
            'warning' => null,
        ];
    }

    /**
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @param  array<int, array<string, mixed>>  $suggestions
     */
    private function request(AiModel $aiModel, array $picks, array $suggestions): string
    {
        set_time_limit(120);

        $request = Http::connectTimeout(5)
            ->timeout(90)
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
            $payload = json_encode([
                'stock' => $this->stock($picks),
                'calculated_moves' => $this->calculated($suggestions),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('ChatGPT could not rearrange these locations.');
        }

        try {
            $response = $request->post('https://api.openai.com/v1/responses', [
                'model' => $aiModel->model,
                'instructions' => $this->instructions(),
                'input' => $payload,
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach ChatGPT.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('ChatGPT could not rearrange these locations.');
        }

        return $this->responseText($response->json());
    }

    private function instructions(): string
    {
        return <<<'PROMPT'
You rearrange stock in a medical supplies warehouse so frequently picked products sit closer to packing. Reply with JSON only, no markdown.

Use only the products and location codes in the stock list. A lower "seconds" value is closer to packing. "picks" is how many units were ordered in the date range.

Rules:
- Pharmaceutical products and anything stored in a drug room stay where they are. Drug rooms are temperature controlled and their location codes start with 7L. Do not move other products into a drug room.
- PACKING ZONE is not a stock location. Do not move a product from it or to it.
- Plan the moves as one operation. After every move in your reply, each location holds one product.
- If a product moves into an occupied location, the product that was there moves to a location that this same plan leaves empty. Do not invent an empty bin.
- Do not send two products to the same location, and do not invent products, locations, quantities, or time savings.
- Prefer the calculated moves when they already follow these rules. Change them only when another arrangement reduces walking for the products that are picked most.
- notes: up to five short, practical ways to reduce picking time that follow from this stock data. Do not claim a measured saving you were not given.

Return this JSON shape:
{"moves":[{"product":"","sku":"","from":"","to":""}],"notes":[""]}
PROMPT;
    }

    /**
     * @param  array<int, array{product: string, sku: string, quantity: float, location: string, pharma: bool}>  $picks
     * @return array<int, array{product: string, sku: string, location: string, picks: float, seconds: int}>
     */
    private function stock(array $picks): array
    {
        $rows = [];

        foreach ($picks as $pick) {
            $location = strtoupper(trim($pick['location']));
            $code = str_contains($location, '/') ? substr($location, (int) strrpos($location, '/') + 1) : $location;

            if ($pick['pharma'] || str_starts_with($code, '7L:') || str_contains($location, 'PACKING')) {
                continue;
            }

            $key = strtolower(($pick['sku'] !== '' ? $pick['sku'] : $pick['product'])).'|'.$code;

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'product' => $pick['product'],
                    'sku' => $pick['sku'],
                    'location' => $code,
                    'picks' => 0.0,
                    'seconds' => $this->planner->seconds($pick['location']),
                ];
            }

            $rows[$key]['picks'] += $pick['quantity'];
        }

        $stock = array_values($rows);
        usort($stock, fn (array $left, array $right): int => $right['picks'] <=> $left['picks']);

        return array_slice($stock, 0, 60);
    }

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function calculated(array $suggestions): array
    {
        $moves = [];

        foreach ($suggestions as $suggestion) {
            $moves[] = [
                'product' => $suggestion['product'] ?? '',
                'sku' => $suggestion['sku'] ?? '',
                'from' => $suggestion['from'] ?? '',
                'to' => $suggestion['to'] ?? '',
                'displaced' => $suggestion['displaced'] ?? null,
                'follows' => $suggestion['follows'] ?? [],
            ];
        }

        return $moves;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<int, array{product: string, sku: string, from: string, to: string}>
     */
    private function proposed(array $decoded): array
    {
        $moves = is_array($decoded['moves'] ?? null) ? $decoded['moves'] : [];
        $proposed = [];

        foreach ($moves as $move) {
            if (! is_array($move)) {
                continue;
            }

            $product = $this->text($move['product'] ?? null);
            $from = $this->text($move['from'] ?? null);
            $to = $this->text($move['to'] ?? null);

            if ($product === '' || $from === '' || $to === '') {
                continue;
            }

            $proposed[] = [
                'product' => $product,
                'sku' => $this->text($move['sku'] ?? null),
                'from' => $from,
                'to' => $to,
            ];

            if (count($proposed) >= 12) {
                break;
            }
        }

        return $proposed;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<int, string>
     */
    private function notes(array $decoded): array
    {
        $notes = is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [];
        $clean = [];

        foreach ($notes as $note) {
            $text = $this->text($note);

            if ($text === '') {
                continue;
            }

            $clean[] = $text;

            if (count($clean) >= 5) {
                break;
            }
        }

        return $clean;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

        return $text === '' ? '' : mb_substr($text, 0, 400);
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
