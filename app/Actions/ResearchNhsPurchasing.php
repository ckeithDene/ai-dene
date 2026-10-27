<?php

namespace App\Actions;

use App\Data\CatalogueProduct;
use App\Data\NhsPurchasingResult;
use App\Enums\OdooCompany;
use App\Models\AiModel;
use App\Models\NhsCatalogueGap;
use App\Models\NhsDirective;
use App\Models\NhsProductOpportunity;
use App\Models\NhsPurchasingSetting;
use App\Models\OdooConnection;
use App\Services\DeneCatalogue;
use App\Services\NhsPurchasingNormalizer;
use App\Services\NhsPurchasingResearcher;
use App\Services\OdooClient;
use RuntimeException;
use Throwable;

class ResearchNhsPurchasing
{
    public function __construct(
        private NhsPurchasingResearcher $researcher,
        private NhsPurchasingNormalizer $normalizer,
        private DeneCatalogue $catalogue,
    ) {}

    /**
     * Check NHS directives, predict purchasing changes, and compare them with the Dene catalogue.
     */
    public function handle(): NhsPurchasingResult
    {
        set_time_limit(0);

        $aiModel = AiModel::current();

        if ($aiModel === null || blank($aiModel->api_key)) {
            return new NhsPurchasingResult(0, 0, __('Connect ChatGPT before researching NHS purchasing patterns.'));
        }

        try {
            $existing = NhsDirective::query()
                ->latest('id')
                ->limit(40)
                ->get()
                ->map(fn (NhsDirective $directive): array => [
                    'title' => $directive->title,
                    'issuer' => $directive->issuer,
                    'effective_on' => $directive->effective_on,
                    'source_url' => $directive->source_url,
                ])
                ->all();
            $research = $this->researcher->monitor($aiModel, $existing);
        } catch (RuntimeException $exception) {
            return new NhsPurchasingResult(0, 0, $this->safeReason($exception));
        } catch (Throwable) {
            return new NhsPurchasingResult(0, 0, __('ChatGPT could not research NHS purchasing patterns.'));
        }

        $directives = $this->storeDirectives($research);
        $summary = $this->normalizer->summary($research);
        $settings = NhsPurchasingSetting::current();
        $settings->last_summary = $summary['summary'] !== '' ? $summary['summary'] : null;
        $settings->last_changes = $summary['changes'] !== '' ? $summary['changes'] : null;
        $settings->last_limitations = $summary['limitations'] !== '' ? $summary['limitations'] : null;

        if ($directives === []) {
            $settings->last_ran_at = now();
            $settings->save();

            return new NhsPurchasingResult(0, 0);
        }

        try {
            $known = NhsProductOpportunity::query()->latest('id')->limit(30)->pluck('name')->all();
            $impact = $this->researcher->interpret($aiModel, $this->directivePayload($directives), $known);
        } catch (RuntimeException $exception) {
            $settings->last_ran_at = now();
            $settings->save();

            return new NhsPurchasingResult(count($directives), 0, $this->safeReason($exception));
        } catch (Throwable) {
            $settings->last_ran_at = now();
            $settings->save();

            return new NhsPurchasingResult(count($directives), 0, __('ChatGPT could not research NHS purchasing patterns.'));
        }

        $opportunities = $this->storeOpportunities($impact);
        $limitation = $this->matchCatalogue($aiModel, $opportunities);

        if ($limitation !== null) {
            $settings->last_limitations = trim(($settings->last_limitations ?? '').' '.$limitation);
        }

        $settings->last_ran_at = now();
        $settings->save();

        return new NhsPurchasingResult(count($directives), count($opportunities));
    }

    /**
     * @param  array<string, mixed>  $research
     * @return array<int, NhsDirective>
     */
    private function storeDirectives(array $research): array
    {
        $saved = [];
        $items = is_array($research['directives'] ?? null) ? $research['directives'] : [];

        foreach ($items as $item) {
            if (! is_array($item) || count($saved) >= 15) {
                continue;
            }

            $normalized = $this->normalizer->directive($item);

            if ($normalized === null) {
                continue;
            }

            $record = NhsDirective::query()->firstOrNew([
                'fingerprint' => $normalized['fingerprint'],
            ]);

            if ($record->exists) {
                unset($normalized['fingerprint']);
            }

            $sources = is_array($record->sources) ? $record->sources : [];
            $url = $normalized['source_url'];
            $known = collect($sources)->contains(fn (array $source): bool => ($source['url'] ?? null) === $url);

            if (is_string($url) && ! $known) {
                $sources[] = [
                    'title' => is_string($normalized['source_title'] ?? null) ? $normalized['source_title'] : $url,
                    'url' => $url,
                    'published_on' => is_string($normalized['published_on'] ?? null) ? $normalized['published_on'] : '',
                ];
            }

            $record->fill($normalized);
            $record->sources = array_slice($sources, -8);
            $record->last_verified_at = now();
            $record->save();
            $saved[] = $record;
        }

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $impact
     * @return array<int, NhsProductOpportunity>
     */
    private function storeOpportunities(array $impact): array
    {
        $saved = [];
        $items = is_array($impact['opportunities'] ?? null) ? $impact['opportunities'] : [];
        $directives = NhsDirective::query()->get();

        foreach ($items as $item) {
            if (! is_array($item) || count($saved) >= 20) {
                continue;
            }

            $titles = $this->normalizer->directiveTitles($item);
            $linked = $directives->filter(fn (NhsDirective $directive): bool => collect($titles)->contains(
                fn (string $title): bool => strcasecmp($title, $directive->title) === 0,
            ));

            if ($linked->isEmpty()) {
                continue;
            }

            $canConfirm = $linked->contains(fn (NhsDirective $directive): bool => $directive->obligation->canConfirmRequirement());
            $normalized = $this->normalizer->opportunity($item, $canConfirm);

            if ($normalized === null) {
                continue;
            }

            $record = NhsProductOpportunity::query()->firstOrNew([
                'fingerprint' => $normalized['fingerprint'],
            ]);

            if ($record->exists) {
                unset($normalized['fingerprint']);
            }

            $record->fill($normalized);
            $record->last_verified_at = now();
            $record->save();
            $record->directives()->syncWithoutDetaching($linked->modelKeys());
            $saved[] = $record;
        }

        return $saved;
    }

    /**
     * @param  array<int, NhsProductOpportunity>  $opportunities
     */
    private function matchCatalogue(AiModel $aiModel, array $opportunities): ?string
    {
        if ($opportunities === []) {
            return null;
        }

        $connection = OdooConnection::for(OdooCompany::Dene);
        $products = [];
        $searched = false;
        $limitation = null;

        if ($connection === null || blank($connection->api_key)) {
            $limitation = 'Catalogue matching required. Connect the Dene Healthcare Odoo connection to compare these opportunities with the catalogue.';
        } else {
            try {
                $terms = [];

                foreach ($opportunities as $opportunity) {
                    foreach ($opportunity->search_terms ?? [] as $term) {
                        $terms[] = $term;
                    }
                }

                $products = $this->catalogue->products(new OdooClient($connection), $terms);
                $searched = true;
            } catch (RuntimeException) {
                $limitation = 'Could not read the Dene Healthcare catalogue from Odoo.';
            }
        }

        $gaps = [];

        if ($searched && $products !== []) {
            try {
                $matched = $this->researcher->matchCatalogue(
                    $aiModel,
                    $this->opportunityPayload($opportunities),
                    array_map(fn (CatalogueProduct $product): array => $product->toPrompt(), $products),
                );
                $items = is_array($matched['gaps'] ?? null) ? $matched['gaps'] : [];

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $name = is_string($item['opportunity_name'] ?? null) ? strtolower(trim($item['opportunity_name'])) : '';

                    if ($name !== '') {
                        $gaps[$name] = $item;
                    }
                }
            } catch (Throwable) {
                $limitation = 'ChatGPT could not compare the Dene Healthcare catalogue.';
            }
        }

        foreach ($opportunities as $opportunity) {
            $found = $searched ? $this->normalizer->matchingProducts($opportunity, $products) : [];
            $raw = $gaps[strtolower($opportunity->name)] ?? [];
            $normalized = $this->normalizer->gap($raw, $found, $searched);
            $gap = NhsCatalogueGap::query()->firstOrNew([
                'nhs_product_opportunity_id' => $opportunity->id,
            ]);
            $gap->fill($normalized);
            $gap->save();
        }

        return $limitation;
    }

    /**
     * @param  array<int, NhsDirective>  $directives
     * @return array<int, array<string, mixed>>
     */
    private function directivePayload(array $directives): array
    {
        return array_map(fn (NhsDirective $directive): array => [
            'title' => $directive->title,
            'issuer' => $directive->issuer,
            'obligation' => $directive->obligation->value,
            'applies_to' => $directive->applies_to,
            'effective_on' => $directive->effective_on,
            'summary' => $directive->summary,
            'source_url' => $directive->source_url,
            'requirement_confirmed' => $directive->requirement_confirmed,
        ], $directives);
    }

    /**
     * @param  array<int, NhsProductOpportunity>  $opportunities
     * @return array<int, array<string, mixed>>
     */
    private function opportunityPayload(array $opportunities): array
    {
        return array_map(fn (NhsProductOpportunity $opportunity): array => [
            'name' => $opportunity->name,
            'category' => $opportunity->category,
            'demand_direction' => $opportunity->demand_direction->value,
            'explanation' => $opportunity->explanation,
            'search_terms' => $opportunity->search_terms,
        ], $opportunities);
    }

    private function safeReason(RuntimeException $exception): string
    {
        return in_array($exception->getMessage(), [
            'Could not reach ChatGPT.',
            'ChatGPT could not research NHS purchasing patterns.',
        ], true) ? $exception->getMessage() : __('ChatGPT could not research NHS purchasing patterns.');
    }
}
