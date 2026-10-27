<?php

namespace App\Services;

use App\Data\CatalogueProduct;
use App\Models\FirstOrderDiamond;

class SalesStrategyNormalizer
{
    /**
     * Keep only catalogue products and a stable report shape.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<int, CatalogueProduct>  $catalogue
     * @param  array<string, mixed>  $research
     * @return array<string, mixed>
     */
    public function report(array $raw, array $catalogue, array $research, int $maxProducts): array
    {
        $products = $this->products($raw['recommended_products'] ?? null, $catalogue, $maxProducts);
        $profile = $this->object($raw['company_profile'] ?? null);
        $financials = $this->object($raw['financial_research'] ?? null);
        $researchProfile = $this->object($research['company_profile'] ?? null);
        $researchFinancials = $this->object($research['financial_research'] ?? null);
        $score = $this->presentScores($this->object($raw['opportunity_score'] ?? null));
        $strategy = $this->object($raw['sales_strategy'] ?? null);
        $plan = $this->object($raw['account_growth_plan'] ?? null);
        $materials = $this->object($raw['sales_materials'] ?? null);
        $next = $this->object($raw['next_action'] ?? null);
        $summary = $this->text($raw['strategy_summary'] ?? null, 1200);

        if ($summary === '') {
            $summary = $this->text($strategy['summary'] ?? null, 1200);
        }

        return [
            'executive_summary' => $this->text($raw['executive_summary'] ?? $research['executive_summary'] ?? null, 2000),
            'strategy_summary' => $summary,
            'opening_angle' => $this->text($raw['opening_angle'] ?? null, 500),
            'industry' => $this->text($raw['industry'] ?? $research['industry'] ?? null, 120),
            'country' => $this->text($raw['country'] ?? $research['country'] ?? null, 80),
            'reason_to_contact' => $this->text($research['reason_to_contact'] ?? null, 800),
            'company_profile' => [
                'overview' => $this->text($profile['overview'] ?? $researchProfile['overview'] ?? null, 2000),
                'headquarters' => $this->text($profile['headquarters'] ?? $researchProfile['headquarters'] ?? null, 255),
                'activities' => $this->text($profile['activities'] ?? $researchProfile['activities'] ?? null, 2000),
                'sites' => $this->text($profile['sites'] ?? $researchProfile['sites'] ?? null, 255),
                'employees' => $this->text($profile['employees'] ?? $researchProfile['employees'] ?? null, 255),
                'ownership' => $this->text($profile['ownership'] ?? $researchProfile['ownership'] ?? null, 255),
            ],
            'financial_research' => [
                'revenue' => $this->text($financials['revenue'] ?? $researchFinancials['revenue'] ?? null, 255),
                'profitability' => $this->text($financials['profitability'] ?? $researchFinancials['profitability'] ?? null, 255),
                'valuation' => $this->text($financials['valuation'] ?? $researchFinancials['valuation'] ?? null, 255),
                'notes' => $this->text($financials['notes'] ?? $researchFinancials['notes'] ?? null, 1000),
            ],
            'buying_needs' => $this->lines($raw['buying_needs'] ?? $research['buying_needs'] ?? null, 8, 300),
            'recommended_products' => $products,
            'opportunity_score' => [
                'total' => $score['total'],
                'product_fit' => $score['product_fit'],
                'repeat_purchasing' => $score['repeat_purchasing'],
                'addressable_spend' => $score['addressable_spend'],
                'win_probability' => $score['win_probability'],
                'strategic_value' => $score['strategic_value'],
                'purchase_probability' => $score['purchase_probability'],
                'account_potential' => $score['account_potential'],
                'purchase_probability_estimated' => $score['purchase_probability_estimated'],
                'account_potential_estimated' => $score['account_potential_estimated'],
                'research_confidence' => $this->rating($score['research_confidence'] ?? null),
                'priority' => $this->rating($score['priority'] ?? null),
                'rationale' => $this->text($score['rationale'] ?? null, 1500),
                'recurring_revenue_scenario' => $this->text($score['recurring_revenue_scenario'] ?? null, 800),
            ],
            'sales_strategy' => [
                'recommended_tone' => $this->text($strategy['recommended_tone'] ?? null, 120),
                'summary' => $this->text($strategy['summary'] ?? null, 1200),
                'buying_motivations' => $this->lines($strategy['buying_motivations'] ?? null, 6, 240),
                'tactics' => $this->pairs($strategy['tactics'] ?? null, 'name', 'reason', 3),
                'decision_makers' => $this->lines($strategy['decision_makers'] ?? $research['decision_maker_roles'] ?? null, 8, 120),
                'brand_strengths' => $this->pairs($strategy['brand_strengths'] ?? null, 'strength', 'connection', 3),
            ],
            'account_growth_plan' => [
                'initial_engagement' => $this->text($plan['initial_engagement'] ?? null, 1000),
                'discovery_questions' => $this->lines($plan['discovery_questions'] ?? null, 5, 300),
                'first_order' => $this->text($plan['first_order'] ?? null, 1000),
                'regular_purchasing' => $this->text($plan['regular_purchasing'] ?? null, 1000),
                'expansion' => $this->text($plan['expansion'] ?? null, 1000),
            ],
            'sales_materials' => [
                'initial_email' => $this->email($materials['initial_email'] ?? null),
                'follow_up_email' => $this->email($materials['follow_up_email'] ?? null),
                'telephone_script' => $this->text($materials['telephone_script'] ?? null, 1500),
                'linkedin_message' => $this->text($materials['linkedin_message'] ?? null, 600),
                'strategic_pitch' => $this->text($materials['strategic_pitch'] ?? null, 2000),
                'objection_responses' => $this->pairs($materials['objection_responses'] ?? null, 'objection', 'response', 3),
            ],
            'next_action' => [
                'strongest_opportunity' => $this->text($next['strongest_opportunity'] ?? null, 400),
                'decision_maker' => $this->text($next['decision_maker'] ?? null, 120),
                'channel' => $this->text($next['channel'] ?? null, 80),
                'opening_proposition' => $this->text($next['opening_proposition'] ?? null, 400),
                'first_products' => $this->text($next['first_products'] ?? null, 400),
                'next_step' => $this->text($next['next_step'] ?? null, 400),
                'missing_facts' => $this->lines($next['missing_facts'] ?? null, 8, 300),
            ],
            'sources' => $this->sources($raw['sources'] ?? $research['sources'] ?? null),
            'information_gaps' => $this->lines($raw['information_gaps'] ?? $research['information_gaps'] ?? null, 8, 300),
            'sales_triggers' => $this->lines($research['sales_triggers'] ?? null, 6, 300),
        ];
    }

    /**
     * Fill purchase probability and account potential when the model left them blank.
     *
     * @param  array<string, mixed>  $score
     * @return array<string, mixed>
     */
    public function presentScores(array $score): array
    {
        $fit = $this->score($score['product_fit'] ?? null);
        $repeat = $this->score($score['repeat_purchasing'] ?? null);
        $spend = $this->score($score['addressable_spend'] ?? null);
        $win = $this->score($score['win_probability'] ?? null);
        $strategic = $this->score($score['strategic_value'] ?? null);
        $purchase = $this->scoreOrNull($score['purchase_probability'] ?? null);
        $potential = $this->scoreOrNull($score['account_potential'] ?? null);
        $total = $this->scoreOrNull($score['total'] ?? null);
        $purchaseEstimated = ($score['purchase_probability_estimated'] ?? false) === true;
        $potentialEstimated = ($score['account_potential_estimated'] ?? false) === true;

        if ($purchaseEstimated) {
            $purchase = $this->score($score['purchase_probability'] ?? null);
        } elseif ($purchase === null || ($purchase === 0 && ($win > 0 || $fit > 0))) {
            $purchase = (int) round(($win * 0.6) + ($fit * 0.4));
            $purchaseEstimated = true;
        }

        if ($potentialEstimated) {
            $potential = $this->score($score['account_potential'] ?? null);
        } elseif ($potential === null || ($potential === 0 && ($spend > 0 || $strategic > 0))) {
            $potential = (int) round(($spend * 0.6) + ($strategic * 0.4));
            $potentialEstimated = true;
        }

        if ($total === null || ($total === 0 && ($fit + $repeat + $spend + $win + $strategic) > 0)) {
            $total = (int) round(($fit * 0.30) + ($repeat * 0.25) + ($spend * 0.20) + ($win * 0.15) + ($strategic * 0.10));
        }

        $score['total'] = $total;
        $score['product_fit'] = $fit;
        $score['repeat_purchasing'] = $repeat;
        $score['addressable_spend'] = $spend;
        $score['win_probability'] = $win;
        $score['strategic_value'] = $strategic;
        $score['purchase_probability'] = $purchase;
        $score['account_potential'] = $potential;
        $score['purchase_probability_estimated'] = $purchaseEstimated;
        $score['account_potential_estimated'] = $potentialEstimated;

        return $score;
    }

    /**
     * @param  array<int, CatalogueProduct>  $catalogue
     * @return array<int, array<string, mixed>>
     */
    private function products(mixed $items, array $catalogue, int $maxProducts): array
    {
        $byId = [];
        $bySku = [];

        foreach ($catalogue as $product) {
            $byId[$product->id] = $product;

            if ($product->sku !== '') {
                $bySku[strtolower($product->sku)] = $product;
            }
        }

        if (! is_array($items)) {
            return [];
        }

        $products = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['odoo_product_id'] ?? null;
            $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
            $sku = strtolower($this->text($item['sku'] ?? null, 64));
            $product = $id !== null ? ($byId[$id] ?? null) : null;
            $product ??= $sku !== '' ? ($bySku[$sku] ?? null) : null;

            if (! $product instanceof CatalogueProduct) {
                continue;
            }

            $products[] = [
                'odoo_product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category,
                'list_price' => $product->listPrice,
                'url' => $product->url,
                'available' => $product->available,
                'use' => $this->text($item['use'] ?? null, 300),
                'reason' => $this->text($item['reason'] ?? null, 500),
                'frequency' => $this->text($item['frequency'] ?? null, 80),
                'repeat_order' => in_array($item['repeat_order'] ?? null, [true, 1, '1', 'true'], true),
                'confidence' => $this->rating($item['confidence'] ?? null),
                'group' => $this->group($item['group'] ?? null),
                'evidence' => $this->text($item['evidence'] ?? null, 500),
            ];

            if (count($products) >= max(1, $maxProducts)) {
                break;
            }
        }

        return $products;
    }

    /**
     * @return array<int, array{title: string, url: string}>
     */
    private function sources(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $sources = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = FirstOrderDiamond::httpUrl($item['url'] ?? null);

            if ($url === null) {
                continue;
            }

            $title = $this->text($item['title'] ?? null, 160);
            $sources[] = [
                'title' => $title !== '' ? $title : $url,
                'url' => $url,
            ];

            if (count($sources) === 8) {
                break;
            }
        }

        return $sources;
    }

    /**
     * @return array<int, array{name: string, detail: string}>
     */
    private function pairs(mixed $items, string $nameKey, string $detailKey, int $limit): array
    {
        if (! is_array($items)) {
            return [];
        }

        $pairs = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->text($item[$nameKey] ?? null, 180);
            $detail = $this->text($item[$detailKey] ?? null, 800);

            if ($name === '' && $detail === '') {
                continue;
            }

            $pairs[] = [
                'name' => $name,
                'detail' => $detail,
            ];

            if (count($pairs) === $limit) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function email(mixed $value): array
    {
        if (! is_array($value)) {
            return ['subject' => '', 'body' => ''];
        }

        return [
            'subject' => $this->text($value['subject'] ?? null, 180),
            'body' => $this->text($value['body'] ?? null, 2500),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function lines(mixed $items, int $limit, int $length): array
    {
        if (! is_array($items)) {
            return [];
        }

        $lines = [];

        foreach ($items as $item) {
            $line = $this->text($item, $length);

            if ($line === '') {
                continue;
            }

            $lines[] = $line;

            if (count($lines) === $limit) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function text(mixed $value, int $limit): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return mb_substr(trim((string) $value), 0, $limit);
    }

    private function score(mixed $value): int
    {
        return $this->scoreOrNull($value) ?? 0;
    }

    private function scoreOrNull(mixed $value): ?int
    {
        if (is_string($value)) {
            $rating = strtolower(trim($value));
            $value = match ($rating) {
                'high' => 75,
                'medium' => 50,
                'low' => 25,
                default => $value,
            };
        }

        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function rating(mixed $value): string
    {
        $rating = is_string($value) ? ucfirst(strtolower(trim($value))) : '';

        return in_array($rating, ['High', 'Medium', 'Low'], true) ? $rating : 'Low';
    }

    private function group(mixed $value): string
    {
        $group = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($group, ['immediate', 'recurring', 'equipment', 'expansion'], true) ? $group : 'immediate';
    }
}
