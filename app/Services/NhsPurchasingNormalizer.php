<?php

namespace App\Services;

use App\Data\CatalogueProduct;
use App\Enums\NhsCoverage;
use App\Enums\NhsDemandDirection;
use App\Enums\NhsGapType;
use App\Enums\NhsObligation;
use App\Enums\NhsSourcingAction;
use App\Enums\NhsTimeframe;
use App\Models\FirstOrderDiamond;
use App\Models\NhsProductOpportunity;

class NhsPurchasingNormalizer
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{summary: string, changes: string, limitations: string}
     */
    public function summary(array $raw): array
    {
        return [
            'summary' => $this->text($raw['summary'] ?? null, 2000),
            'changes' => $this->text($raw['changes'] ?? null, 2000),
            'limitations' => $this->text($raw['limitations'] ?? null, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public function directive(array $raw): ?array
    {
        if ($this->bool($raw['relevant'] ?? null) === false || $this->bool($raw['is_gp_relevant'] ?? null) === false) {
            return null;
        }

        $title = $this->text($raw['title'] ?? null, 255);
        $summary = $this->text($raw['summary'] ?? null, 2000);

        if ($title === '') {
            return null;
        }

        $url = FirstOrderDiamond::httpUrl($raw['source_url'] ?? null);
        $host = parse_url((string) $url, PHP_URL_HOST);

        if (! is_string($host) || ! str_contains($host, '.')) {
            $url = null;
        }

        if ($url === null && $summary === '') {
            return null;
        }

        $obligation = $this->obligation($raw['obligation_type'] ?? null);
        $confirmed = $obligation->canConfirmRequirement() && $this->bool($raw['requirement_confirmed'] ?? null) === true;

        return [
            'fingerprint' => $this->fingerprint($title),
            'title' => $title,
            'issuer' => $this->text($raw['issuer'] ?? null, 160) ?: null,
            'published_on' => $this->text($raw['published_on'] ?? null, 40) ?: null,
            'updated_on' => $this->text($raw['updated_on'] ?? null, 40) ?: null,
            'effective_on' => $this->text($raw['effective_on'] ?? null, 40) ?: null,
            'obligation' => $obligation,
            'applies_to' => $this->text($raw['applies_to'] ?? null, 255) ?: null,
            'source_title' => $this->text($raw['source_title'] ?? null, 255) ?: null,
            'source_url' => $url,
            'summary' => $summary !== '' ? $summary : null,
            'commercial_implications' => $this->text($raw['commercial_implications'] ?? null, 2000) ?: null,
            'confidence' => $url === null ? 'Low' : $this->confidence($raw['confidence'] ?? null),
            'requirement_confirmed' => $confirmed,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<int, string>
     */
    public function directiveTitles(array $raw): array
    {
        $titles = $raw['directive_titles'] ?? [];

        if (! is_array($titles)) {
            return [];
        }

        $clean = [];

        foreach ($titles as $title) {
            $text = $this->text($title, 255);

            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>|null
     */
    public function opportunity(array $raw, bool $directiveCanConfirm): ?array
    {
        $name = $this->text($raw['name'] ?? null, 255);

        if ($name === '') {
            return null;
        }

        $category = $this->text($raw['category'] ?? null, 120);

        return [
            'fingerprint' => $this->fingerprint($category.' '.$name),
            'name' => $name,
            'category' => $category !== '' ? $category : null,
            'demand_direction' => $this->direction($raw['demand_direction'] ?? null),
            'timeframe' => $this->timeframe($raw['timeframe'] ?? null),
            'explanation' => $this->text($raw['explanation'] ?? null, 2000) ?: null,
            'commercial_action' => $this->text($raw['commercial_action'] ?? null, 2000) ?: null,
            'secondary_demand' => $this->text($raw['secondary_demand'] ?? null, 2000) ?: null,
            'sales_proposition' => $this->text($raw['sales_proposition'] ?? null, 2000) ?: null,
            'opportunity_score' => $this->score($raw['opportunity_score'] ?? null),
            'confidence' => $this->confidence($raw['confidence'] ?? null),
            'search_terms' => $this->searchTerms($raw['search_terms'] ?? null),
            'requirement_confirmed' => $directiveCanConfirm && $this->bool($raw['requirement_confirmed'] ?? null) === true,
        ];
    }

    /**
     * Keep catalogue rows that match this opportunity's search phrases.
     *
     * @param  array<int, CatalogueProduct>  $catalogue
     * @return array<int, CatalogueProduct>
     */
    public function matchingProducts(NhsProductOpportunity $opportunity, array $catalogue): array
    {
        $terms = is_array($opportunity->search_terms) ? $opportunity->search_terms : [];
        $terms[] = $opportunity->name;
        $matched = [];

        foreach ($catalogue as $product) {
            $haystack = strtolower($product->name.' '.$product->sku.' '.$product->category);

            foreach ($terms as $term) {
                $needle = strtolower(trim($term));

                if (strlen($needle) < 3) {
                    continue;
                }

                if (str_contains($haystack, $needle)) {
                    $matched[$product->id] = $product;
                    break;
                }
            }
        }

        return array_values($matched);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, CatalogueProduct>  $products
     * @return array<string, mixed>
     */
    public function gap(array $raw, array $products, bool $catalogueSearched): array
    {
        $missing = $this->text($raw['missing_items'] ?? null, 1000);
        $coverage = $this->coverage($catalogueSearched, $products !== [], $missing !== '');
        $action = $catalogueSearched
            ? ($this->action($raw['sourcing_action'] ?? null) ?? ($products === [] ? NhsSourcingAction::FindSupplier : NhsSourcingAction::Bundle))
            : NhsSourcingAction::Monitor;

        return [
            'gap_type' => $this->gapType($raw['gap_type'] ?? null),
            'coverage' => $coverage,
            'sourcing_action' => $action,
            'gap_score' => $catalogueSearched ? $this->optionalScore($raw['gap_score'] ?? null) : null,
            'recommendation' => $catalogueSearched
                ? ($this->text($raw['recommendation'] ?? null, 2000) ?: null)
                : 'Catalogue matching required.',
            'missing_items' => $missing !== '' ? $missing : null,
            'matched_products' => array_map(fn (CatalogueProduct $product): array => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category,
                'list_price' => $product->listPrice,
            ], $products),
        ];
    }

    public function fingerprint(string $value): string
    {
        $normalized = strtolower($value);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;

        return hash('sha256', trim($normalized));
    }

    private function obligation(mixed $value): NhsObligation
    {
        $text = strtolower($this->text($value, 80));

        return match (true) {
            str_contains($text, 'binding') || str_contains($text, 'legal') => NhsObligation::Binding,
            str_contains($text, 'contract') => NhsObligation::Contractual,
            str_contains($text, 'procurement') || str_contains($text, 'framework') => NhsObligation::ProcurementPolicy,
            str_contains($text, 'guideline') || str_contains($text, 'nice') => NhsObligation::Guideline,
            str_contains($text, 'fund') => NhsObligation::Funded,
            str_contains($text, 'proposal') || str_contains($text, 'consult') => NhsObligation::Proposal,
            str_contains($text, 'infer') => NhsObligation::Inference,
            default => NhsObligation::tryFrom($text) ?? NhsObligation::Strategic,
        };
    }

    private function direction(mixed $value): NhsDemandDirection
    {
        $text = strtolower($this->text($value, 40));

        return match (true) {
            str_contains($text, 'strong_increase') || str_contains($text, 'strong increase') => NhsDemandDirection::StrongIncrease,
            str_contains($text, 'moderate_increase') || str_contains($text, 'moderate increase') || $text === 'increase' || $text === 'growth' => NhsDemandDirection::ModerateIncrease,
            str_contains($text, 'strong_decrease') || str_contains($text, 'strong decrease') => NhsDemandDirection::StrongDecrease,
            str_contains($text, 'moderate_decrease') || str_contains($text, 'moderate decrease') || $text === 'decrease' || $text === 'decline' => NhsDemandDirection::ModerateDecrease,
            $text === 'stable' => NhsDemandDirection::Stable,
            default => NhsDemandDirection::tryFrom($text) ?? NhsDemandDirection::Uncertain,
        };
    }

    private function timeframe(mixed $value): ?NhsTimeframe
    {
        $text = strtolower($this->text($value, 40));

        return match (true) {
            str_contains($text, 'immediate') => NhsTimeframe::Immediate,
            str_contains($text, 'short') => NhsTimeframe::Short,
            str_contains($text, 'medium') => NhsTimeframe::Medium,
            str_contains($text, 'long') => NhsTimeframe::Long,
            default => NhsTimeframe::tryFrom($text),
        };
    }

    private function coverage(bool $catalogueSearched, bool $hasProducts, bool $hasMissing): NhsCoverage
    {
        if (! $catalogueSearched) {
            return NhsCoverage::Uncertain;
        }

        if ($hasProducts && $hasMissing) {
            return NhsCoverage::Partial;
        }

        if ($hasProducts) {
            return NhsCoverage::Covered;
        }

        return NhsCoverage::PotentialGap;
    }

    private function action(mixed $value): ?NhsSourcingAction
    {
        return NhsSourcingAction::tryFrom(strtolower($this->text($value, 40)));
    }

    private function gapType(mixed $value): NhsGapType
    {
        return NhsGapType::tryFrom(strtolower($this->text($value, 40))) ?? NhsGapType::Directive;
    }

    /**
     * @return array<int, string>
     */
    private function searchTerms(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $terms = [];

        foreach ($value as $term) {
            $text = $this->text($term, 40);
            $text = trim((string) preg_replace('/[^a-zA-Z0-9 \-]/', '', $text));

            if (strlen($text) < 3) {
                continue;
            }

            $terms[] = $text;

            if (count($terms) === 6) {
                break;
            }
        }

        return array_values(array_unique($terms));
    }

    private function confidence(mixed $value): string
    {
        $text = ucfirst(strtolower($this->text($value, 20)));

        return in_array($text, ['High', 'Medium', 'Low'], true) ? $text : 'Low';
    }

    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    private function score(mixed $value): int
    {
        return $this->optionalScore($value) ?? 0;
    }

    private function optionalScore(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $limit);
    }
}
