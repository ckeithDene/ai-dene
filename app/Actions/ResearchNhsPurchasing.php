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
     * Check NHS directives, news, blogs and Greener NHS, then compare them with the Dene catalogue.
     */
    public function handle(): NhsPurchasingResult
    {
        set_time_limit(0);

        $failures = [];
        $completed = 0;

        foreach (['directives', 'directive-opportunities', 'news', 'greener', 'catalogue'] as $step) {
            $result = $this->runStep($step);

            if ($result->skippedReason === __('Connect ChatGPT before researching NHS purchasing patterns.')) {
                return $result;
            }

            if ($result->skippedReason !== null) {
                $failures[] = $result->skippedReason;
            } else {
                $completed++;
            }
        }

        if ($failures !== []) {
            $settings = NhsPurchasingSetting::current();
            $settings->last_limitations = trim(($settings->last_limitations ?? '').' '.implode(' ', array_unique($failures)));
            $settings->last_ran_at = now();
            $settings->save();
        }

        return new NhsPurchasingResult(
            NhsDirective::query()->count(),
            NhsProductOpportunity::query()->count(),
            $completed === 0 ? $failures[0] : null,
        );
    }

    /**
     * Run one part of the research so a single ChatGPT call can finish before the next starts.
     */
    public function runStep(string $step): NhsPurchasingResult
    {
        $aiModel = AiModel::current();

        if ($aiModel === null || blank($aiModel->api_key)) {
            return new NhsPurchasingResult(0, 0, __('Connect ChatGPT before researching NHS purchasing patterns.'));
        }

        try {
            return match ($step) {
                'directives' => $this->directivesStep($aiModel),
                'directive-opportunities' => $this->directiveOpportunitiesStep($aiModel),
                'news' => $this->sourcedOpportunitiesStep($aiModel, 'news'),
                'greener' => $this->sourcedOpportunitiesStep($aiModel, 'greener'),
                'catalogue' => $this->catalogueStep($aiModel),
                default => new NhsPurchasingResult(0, 0),
            };
        } catch (RuntimeException $exception) {
            return new NhsPurchasingResult(0, 0, $this->safeReason($exception));
        } catch (Throwable) {
            return new NhsPurchasingResult(0, 0, __('ChatGPT could not research NHS purchasing patterns.'));
        }
    }

    private function directivesStep(AiModel $aiModel): NhsPurchasingResult
    {
        $existing = NhsDirective::query()
            ->latest('id')
            ->limit(8)
            ->pluck('title')
            ->map(fn (mixed $title): string => (string) $title)
            ->all();
        $research = $this->researcher->searchDirectives($aiModel, $existing);
        $saved = $this->storeDirectives($research, 'directive');
        $this->pruneDirectives($saved, 'directive');
        NhsProductOpportunity::query()->whereDoesntHave('directives')->delete();
        $this->rememberSummary($research);

        return new NhsPurchasingResult(count($saved), 0);
    }

    private function directiveOpportunitiesStep(AiModel $aiModel): NhsPurchasingResult
    {
        $directives = NhsDirective::query()->get()
            ->filter(fn (NhsDirective $directive): bool => $directive->researchGroup() === 'directive')
            ->values()
            ->all();

        if ($directives === []) {
            return new NhsPurchasingResult(0, 0);
        }

        $impact = $this->researcher->interpret($aiModel, $this->directivePayload($directives), []);
        $saved = $this->storeOpportunities($impact, 'directive');
        $this->pruneOpportunities($saved, 'directive');

        return new NhsPurchasingResult(count($directives), count($saved));
    }

    private function sourcedOpportunitiesStep(AiModel $aiModel, string $group): NhsPurchasingResult
    {
        $research = $group === 'news'
            ? $this->researcher->searchNews($aiModel)
            : $this->researcher->searchGreener($aiModel);
        $directives = $this->storeDirectives($research, $group);
        $this->pruneDirectives($directives, $group);
        NhsProductOpportunity::query()->whereDoesntHave('directives')->delete();
        $opportunities = $this->storeOpportunities($research, $group);
        $this->pruneOpportunities($opportunities, $group);
        $this->rememberSummary($research);

        return new NhsPurchasingResult(count($directives), count($opportunities));
    }

    private function catalogueStep(AiModel $aiModel): NhsPurchasingResult
    {
        $opportunities = NhsProductOpportunity::query()->get()->all();
        $limitation = $this->matchCatalogue($aiModel, $opportunities);
        $settings = NhsPurchasingSetting::current();

        if ($limitation !== null) {
            $settings->last_limitations = trim(($settings->last_limitations ?? '').' '.$limitation);
        }

        $settings->last_ran_at = now();
        $settings->save();

        return new NhsPurchasingResult(NhsDirective::query()->count(), count($opportunities));
    }

    /**
     * @param  array<string, mixed>  $research
     */
    private function rememberSummary(array $research): void
    {
        $summary = $this->normalizer->summary($research);
        $settings = NhsPurchasingSetting::current();

        if ($summary['summary'] !== '' && blank($settings->last_summary)) {
            $settings->last_summary = $summary['summary'];
        }

        if ($summary['changes'] !== '') {
            $settings->last_changes = $summary['changes'];
        }

        if ($summary['limitations'] !== '') {
            $settings->last_limitations = trim(($settings->last_limitations ?? '').' '.$summary['limitations']);
        }

        $settings->save();
    }

    /**
     * @param  array<string, mixed>  $research
     * @return array<int, NhsDirective>
     */
    private function storeDirectives(array $research, string $group): array
    {
        $saved = [];
        $items = $this->selectDirectives(is_array($research['directives'] ?? null) ? $research['directives'] : [], $group);

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
    private function storeOpportunities(array $impact, string $group): array
    {
        $saved = [];
        $directives = NhsDirective::query()->get();
        $items = $this->selectOpportunities(
            is_array($impact['opportunities'] ?? null) ? $impact['opportunities'] : [],
            $directives,
            $group,
        );

        foreach ($items as $item) {
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
     * Keep the requested source group up to its limit.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function selectDirectives(array $items, string $group): array
    {
        $selected = [];
        $limits = [
            'directive' => 8,
            'news' => 8,
            'greener' => 5,
        ];

        foreach ($items as $item) {
            if (! is_array($item) || $this->normalizer->directive($item) === null || count($selected) >= $limits[$group]) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? $item['title'] : null;
            $url = is_string($item['source_url'] ?? null) ? $item['source_url'] : null;
            $sourceTitle = is_string($item['source_title'] ?? null) ? $item['source_title'] : null;

            if (NhsDirective::researchGroupFor($title, $url, $sourceTitle) !== $group) {
                continue;
            }

            $selected[] = $item;
        }

        return $selected;
    }

    /**
     * @param  array<int, NhsDirective>  $saved
     */
    private function pruneDirectives(array $saved, string $group): void
    {
        if ($saved === []) {
            return;
        }

        $ids = collect($saved)->pluck('id');

        NhsDirective::query()->get()
            ->filter(fn (NhsDirective $directive): bool => $directive->researchGroup() === $group && ! $ids->contains($directive->id))
            ->each->delete();
    }

    /**
     * @param  array<int, NhsProductOpportunity>  $saved
     */
    private function pruneOpportunities(array $saved, string $group): void
    {
        if ($saved === []) {
            return;
        }

        $ids = collect($saved)->pluck('id');

        NhsProductOpportunity::query()->with('directives')->get()
            ->filter(function (NhsProductOpportunity $opportunity) use ($group, $ids): bool {
                if ($ids->contains($opportunity->id)) {
                    return false;
                }

                $best = $opportunity->directives
                    ->map(fn (NhsDirective $directive): string => $directive->researchGroup())
                    ->sortBy(fn (string $item): int => match ($item) {
                        'news' => 1,
                        'greener' => 2,
                        default => 0,
                    })
                    ->first();

                return $best === $group;
            })
            ->each->delete();
    }

    /**
     * Combine similar opportunities and keep directive-backed ones ahead of news and Greener NHS.
     *
     * @param  array<int, mixed>  $items
     * @param  Collection<int, NhsDirective>  $directives
     * @return array<int, array<string, mixed>>
     */
    private function selectOpportunities(array $items, Collection $directives, string $onlyGroup): array
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

        $limits = [
            'directive' => 8,
            'news' => 8,
            'greener' => 5,
        ];
        $picked = [];

        foreach ($selected as $item) {
            $group = match ($this->opportunityRank($item, $directives)) {
                0 => 'directive',
                1 => 'news',
                2 => 'greener',
                default => null,
            };

            if ($group !== $onlyGroup || count($picked) >= $limits[$onlyGroup]) {
                continue;
            }

            $picked[] = $item;
        }

        return $picked;
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
