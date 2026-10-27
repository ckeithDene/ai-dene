<?php

namespace App\Actions;

use App\Data\SalesProspect;
use App\Enums\SalesStrategyStatus;
use App\Models\AiModel;
use App\Models\OdooConnection;
use App\Models\SalesEngineSetting;
use App\Models\SalesStrategy;
use App\Services\MidMedsCatalogue;
use App\Services\OdooClient;
use App\Services\SalesProspectFinder;
use App\Services\SalesStrategist;
use App\Services\SalesStrategyNormalizer;
use RuntimeException;
use Throwable;

class GenerateSalesStrategy
{
    public function __construct(
        private SalesProspectFinder $prospects,
        private MidMedsCatalogue $catalogue,
        private SalesStrategist $strategist,
        private SalesStrategyNormalizer $normalizer,
    ) {}

    /**
     * Research a company, match the MidMeds catalogue, and write the sales strategy.
     */
    public function handle(int $partnerId, SalesEngineSetting $settings, bool $reuseResearch): SalesStrategy
    {
        set_time_limit(0);

        $prospect = $this->prospects->find($partnerId);

        if ($prospect === null) {
            abort(404);
        }

        $strategy = SalesStrategy::query()->firstOrNew([
            'odoo_partner_id' => $partnerId,
        ]);
        $strategy->company_name = $prospect->companyName;
        $strategy->status = SalesStrategyStatus::Pending;
        $strategy->failure_reason = null;
        $strategy->save();

        $connection = OdooConnection::current();
        $aiModel = AiModel::current();

        if ($connection === null || blank($connection->api_key)) {
            return $this->fail($strategy, __('Connect Odoo before building a sales strategy.'));
        }

        if ($aiModel === null || blank($aiModel->api_key)) {
            return $this->fail($strategy, __('Connect ChatGPT before building a sales strategy.'));
        }

        $client = new OdooClient($connection);

        try {
            $categories = $this->catalogue->categories($client);
        } catch (RuntimeException) {
            return $this->fail($strategy, __('Could not read the MidMeds catalogue from Odoo.'));
        }

        $purchases = $this->catalogue->purchaseHistory($client, $partnerId);

        try {
            $research = $this->research($strategy, $aiModel, $prospect, $settings, $categories, $purchases, $reuseResearch);
        } catch (RuntimeException $exception) {
            return $this->fail($strategy, $this->safeReason($exception));
        } catch (Throwable) {
            return $this->fail($strategy, __('ChatGPT could not build this sales strategy.'));
        }

        try {
            $products = $this->catalogue->products(
                $client,
                $this->searchTerms($research),
                max($settings->max_products * 3, $settings->max_products),
                $settings->evaluate_pharmaceuticals,
            );
        } catch (RuntimeException) {
            return $this->fail($strategy, __('Could not read the MidMeds catalogue from Odoo.'));
        }

        try {
            $report = $this->strategist->strategy($aiModel, $prospect, $settings, $research, $products, $purchases);
            $strategy->report = $this->normalizer->report($report, $products, $research, $settings->max_products);
            $strategy->status = SalesStrategyStatus::Complete;
            $strategy->failure_reason = null;
            $strategy->generated_at = now();
            $strategy->save();
        } catch (RuntimeException $exception) {
            return $this->fail($strategy, $this->safeReason($exception));
        } catch (Throwable) {
            return $this->fail($strategy, __('ChatGPT could not build this sales strategy.'));
        }

        return $strategy;
    }

    /**
     * @param  array<int, string>  $categories
     * @param  array<int, array{name: string, quantity: string, spent: string}>  $purchases
     * @return array<string, mixed>
     */
    private function research(SalesStrategy $strategy, AiModel $aiModel, SalesProspect $prospect, SalesEngineSetting $settings, array $categories, array $purchases, bool $reuseResearch): array
    {
        $stored = $strategy->research;

        if ($reuseResearch && is_array($stored) && $stored !== []) {
            return $stored;
        }

        $research = $this->strategist->research($aiModel, $prospect, $settings, $categories, $purchases);
        $research['catalogue_search_terms'] = $this->searchTerms($research);
        $strategy->research = $research;
        $strategy->save();

        return $research;
    }

    /**
     * @param  array<string, mixed>  $research
     * @return array<int, string>
     */
    private function searchTerms(array $research): array
    {
        $terms = $this->cleanTerms($research['catalogue_search_terms'] ?? null);

        if ($terms === []) {
            $terms = $this->cleanTerms($research['buying_needs'] ?? null);
        }

        return $terms;
    }

    /**
     * @return array<int, string>
     */
    private function cleanTerms(mixed $terms): array
    {
        if (! is_array($terms)) {
            return [];
        }

        $clean = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }

            $term = trim(preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $term) ?? '');

            if (mb_strlen($term) < 3) {
                continue;
            }

            $clean[] = mb_substr($term, 0, 40);

            if (count($clean) === 8) {
                break;
            }
        }

        return array_values(array_unique($clean));
    }

    private function safeReason(RuntimeException $exception): string
    {
        $message = $exception->getMessage();

        return in_array($message, [
            'Could not reach ChatGPT.',
            'ChatGPT could not build this sales strategy.',
            'Could not read the MidMeds catalogue from Odoo.',
        ], true) ? $message : __('ChatGPT could not build this sales strategy.');
    }

    private function fail(SalesStrategy $strategy, string $reason): SalesStrategy
    {
        $strategy->status = SalesStrategyStatus::Failed;
        $strategy->failure_reason = $reason;
        $strategy->save();

        return $strategy;
    }
}
