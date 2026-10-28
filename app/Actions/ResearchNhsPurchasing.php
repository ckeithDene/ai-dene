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
use Illuminate\Support\Collection;
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

        if ($directives !== []) {
            NhsDirective::query()->whereNotIn('id', collect($directives)->pluck('id'))->delete();
        }

        $summary = $this->normalizer->summary($research);
        $settings = NhsPurchasingSetting::current();
        $settings->last_summary = $summary['summary'] !== '' ? $summary['summary'] : null;
        $settings->last_changes = $summary['changes'] !== '' ? $summary['changes'] : null;
        $settings->last_limitations = $summary['limitations'] !== '' ? $summary['limitations'] : null;

        if ($directives === []) {
            $named = is_array($research['directives'] ?? null) ? count($research['directives']) : 0;

            if ($named > 0) {
                $settings->last_limitations = trim(($settings->last_limitations ?? '').' ChatGPT named directives, but none had a title and a summary or source link to save.');
            }

            $settings->last_ran_at = now();
            $settings->save();

            return new NhsPurchasingResult(0, 0);
        }

        $impact = is_array($research['opportunities'] ?? null) && $research['opportunities'] !== []
            ? $research
            : null;

        try {
            if ($impact === null) {
                $known = NhsProductOpportunity::query()->latest('id')->limit(30)->pluck('name')->all();
                $impact = $this->researcher->interpret($aiModel, $this->directivePayload($directives), $known);
            }
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

        if ($opportunities !== []) {
            NhsProductOpportunity::query()->whereNotIn('id', collect($opportunities)->pluck('id'))->delete();
        }

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
        $items = $this->selectDirectives(is_array($research['directives'] ?? null) ? $research['directives'] : []);

        foreach ($items as $item) {
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
        $directives = NhsDirective::query()->get();
        $items = $this->selectOpportunities(
            is_array($impact['opportunities'] ?? null) ? $impact['opportunities'] : [],
            $directives,
        );

        foreach ($items as $item) {
            if (count($saved) >= 15) {
                break;
            }

            $titles = $this->normalizer->directiveTitles($item);
            $linked = $directives->filter(fn (NhsDirective $directive): bool => collect($titles)->contains(
                fn (string $title): bool => $this->titlesMatch($title, $directive->title),
            ));

            if ($linked->isEmpty() && $directives->count() === 1 && $titles === []) {
                $linked = $directives;
            }

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
                $client = new OdooClient($connection);
                $terms = [];

                foreach ($opportunities as $opportunity) {
                    foreach ($opportunity->search_terms ?? [] as $term) {
                        $terms[] = $term;
                    }

                    $terms[] = $opportunity->name;
                }

                try {
                    $targeted = $this->catalogue->products($client, $terms);
                    $searched = true;
                } catch (RuntimeException) {
                    $targeted = [];
                    $limitation = 'Could not read the Dene Healthcare catalogue from Odoo.';
                }

                try {
                    $sample = $this->catalogue->sample($client);
                    $searched = true;
                    $limitation = null;
                } catch (RuntimeException) {
                    $sample = [];

                    if (! $searched) {
                        $limitation = 'Could not read the Dene Healthcare catalogue from Odoo.';
                    }
                }

                foreach ([$targeted, $sample] as $list) {
                    foreach ($list as $product) {
                        $products[$product->id] = $product;

                        if (count($products) >= 60) {
                            break 2;
                        }
                    }
                }

                $products = array_values($products);
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
            $raw = $gaps[strtolower($opportunity->name)] ?? [];
            $picked = $this->normalizer->selectedProducts($raw, $products);
            $found = $picked !== [] ? $picked : ($searched ? $this->normalizer->matchingProducts($opportunity, $products) : []);
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
     * Keep official directives first, then 8 news and blog items, then 7 Greener NHS items.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function selectDirectives(array $items): array
    {
        $groups = [
            'directive' => [],
            'news' => [],
            'greener' => [],
        ];
        $limits = [
            'directive' => 20,
            'news' => 8,
            'greener' => 7,
        ];

        foreach ($items as $item) {
            if (! is_array($item) || $this->normalizer->directive($item) === null) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? $item['title'] : null;
            $url = is_string($item['source_url'] ?? null) ? $item['source_url'] : null;
            $sourceTitle = is_string($item['source_title'] ?? null) ? $item['source_title'] : null;
            $group = NhsDirective::researchGroupFor($title, $url, $sourceTitle);

            if (count($groups[$group]) >= $limits[$group]) {
                continue;
            }

            $groups[$group][] = $item;
        }

        return array_merge($groups['directive'], $groups['news'], $groups['greener']);
    }

    /**
     * Combine similar opportunities and keep directive-backed ones ahead of news and Greener NHS.
     *
     * @param  array<int, mixed>  $items
     * @param  Collection<int, NhsDirective>  $directives
     * @return array<int, array<string, mixed>>
     */
    private function selectOpportunities(array $items, Collection $directives): array
    {
        $combined = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            $key = $this->normalizer->similarOpportunityKey($name);

            if ($key === '' || ! isset($combined[$key])) {
                $combined[$key === '' ? 'item-'.count($combined) : $key] = $item;

                continue;
            }

            $combined[$key] = $this->preferOpportunity($combined[$key], $item);
        }

        $selected = array_values($combined);

        usort($selected, function (array $left, array $right) use ($directives): int {
            $rank = $this->opportunityRank($left, $directives) <=> $this->opportunityRank($right, $directives);

            if ($rank !== 0) {
                return $rank;
            }

            return ((int) ($right['opportunity_score'] ?? 0)) <=> ((int) ($left['opportunity_score'] ?? 0));
        });

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function preferOpportunity(array $current, array $candidate): array
    {
        $preferred = ((int) ($candidate['opportunity_score'] ?? 0)) > ((int) ($current['opportunity_score'] ?? 0))
            ? $candidate
            : $current;
        $other = $preferred === $candidate ? $current : $candidate;
        $titles = array_values(array_unique(array_merge(
            $this->normalizer->directiveTitles($preferred),
            $this->normalizer->directiveTitles($other),
        )));

        if ($titles !== []) {
            $preferred['directive_titles'] = $titles;
        }

        return $preferred;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, NhsDirective>  $directives
     */
    private function opportunityRank(array $item, Collection $directives): int
    {
        $rank = 3;

        foreach ($this->normalizer->directiveTitles($item) as $title) {
            foreach ($directives as $directive) {
                if ($this->titlesMatch($title, $directive->title)) {
                    $rank = min($rank, $directive->researchGroupRank());
                }
            }
        }

        return $rank;
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
            'commercial_implications' => $directive->commercial_implications,
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
            'ChatGPT did not return any NHS research.',
        ], true) ? $exception->getMessage() : __('ChatGPT could not research NHS purchasing patterns.');
    }

    private function titlesMatch(string $left, string $right): bool
    {
        $left = strtolower(trim($left));
        $right = strtolower(trim($right));

        if ($left === '' || $right === '') {
            return false;
        }

        if ($left === $right) {
            return true;
        }

        if (strlen($left) < 12 || strlen($right) < 12) {
            return false;
        }

        return str_contains($left, $right) || str_contains($right, $left);
    }
}
