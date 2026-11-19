<?php

namespace App\Services;

use App\Data\BiggestFishBatch;
use App\Data\BiggestFishLead;
use App\Enums\CustomerReviewStatus;
use App\Models\BiggestFish;
use App\Models\BiggestFishScan;

class BiggestFishFinder
{
    public const int MAX_CUSTOMERS = 20;

    private const int PAGE_SIZE = 100;

    private const int MAX_PAGES = 40;

    /**
     * Find the next unchecked customers who have a confirmed order.
     *
     * The cursor is not saved here. The caller saves it after the reviews finish,
     * so a token failure can walk the same orders again.
     */
    public function nextCustomers(OdooClient $client, int $limit): BiggestFishBatch
    {
        $cursor = (int) BiggestFishScan::query()->value('last_order_id');

        if ($limit < 1) {
            return new BiggestFishBatch([], $cursor);
        }

        $checked = array_fill_keys(
            BiggestFish::query()
                ->whereIn('status', [CustomerReviewStatus::Complete, CustomerReviewStatus::Failed])
                ->pluck('odoo_partner_id')
                ->all(),
            true,
        );

        $leads = [];
        $seen = [];
        $pages = 0;

        do {
            $orders = $client->searchRead(
                'sale.order',
                [
                    ['state', 'in', ['sale', 'done']],
                    ['id', '>', $cursor],
                ],
                ['id', 'partner_id'],
                'id asc',
                self::PAGE_SIZE,
                0,
            );

            if ($orders === []) {
                break;
            }

            $partners = $this->companies($client, $orders);

            foreach ($orders as $order) {
                $orderId = $this->relationId($order['id'] ?? null);

                if ($orderId !== null && $orderId > $cursor) {
                    $cursor = $orderId;
                }

                $contactId = $this->relationId($order['partner_id'] ?? null);

                if ($contactId === null || ! isset($partners[$contactId])) {
                    continue;
                }

                $companyId = $this->relationId($partners[$contactId]['commercial_partner_id'] ?? null) ?? $contactId;
                $company = $partners[$companyId] ?? $partners[$contactId];

                if (isset($checked[$companyId]) || isset($seen[$companyId])) {
                    continue;
                }

                $seen[$companyId] = true;
                $spent = $this->totalSpent($client, $companyId);
                $leads[] = $this->lead($companyId, $company, $spent['amount'], $spent['currency']);

                if (count($leads) >= $limit) {
                    return new BiggestFishBatch($leads, $cursor);
                }
            }

            $pages++;
        } while (count($orders) === self::PAGE_SIZE && $pages < self::MAX_PAGES);

        return new BiggestFishBatch($leads, $cursor);
    }

    /**
     * Remember how far the finished scan walked.
     */
    public function commitCursor(int $cursor): void
    {
        $scan = BiggestFishScan::current();
        $scan->last_order_id = max($scan->last_order_id, $cursor);
        $scan->touch();
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<string, mixed>>
     */
    private function companies(OdooClient $client, array $orders): array
    {
        $contactIds = [];

        foreach ($orders as $order) {
            $contactId = $this->relationId($order['partner_id'] ?? null);

            if ($contactId !== null) {
                $contactIds[] = $contactId;
            }
        }

        $partners = $this->readPartners($client, $contactIds);
        $missingCompanyIds = [];

        foreach ($partners as $partner) {
            $companyId = $this->relationId($partner['commercial_partner_id'] ?? null);

            if ($companyId !== null && ! isset($partners[$companyId])) {
                $missingCompanyIds[] = $companyId;
            }
        }

        return $partners + $this->readPartners($client, $missingCompanyIds);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function readPartners(OdooClient $client, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $partners = [];

        foreach ($client->read('res.partner', $ids, [
            'name',
            'street',
            'street2',
            'city',
            'zip',
            'country_id',
            'commercial_partner_id',
            'website',
            'image_512',
        ]) as $partner) {
            $id = $this->relationId($partner['id'] ?? null);

            if ($id !== null) {
                $partners[$id] = $partner;
            }
        }

        return $partners;
    }

    /**
     * @return array{amount: string, currency: ?string}
     */
    private function totalSpent(OdooClient $client, int $companyId): array
    {
        $groups = $client->readGroup(
            'sale.order',
            [
                ['state', 'in', ['sale', 'done']],
                ['partner_id.commercial_partner_id', '=', $companyId],
            ],
            ['amount_total:sum'],
            ['currency_id'],
        );

        $totals = [];

        foreach ($groups as $group) {
            $amount = $group['amount_total'] ?? $group['amount_total_sum'] ?? null;

            if (! is_numeric($amount)) {
                continue;
            }

            $currency = $this->relationName($group['currency_id'] ?? null) ?? 'GBP';
            $totals[$currency] = ($totals[$currency] ?? 0) + (float) $amount;
        }

        if ($totals === []) {
            return ['amount' => '0.00', 'currency' => 'GBP'];
        }

        arsort($totals);
        $currency = (string) array_key_first($totals);

        return [
            'amount' => number_format($totals[$currency], 2, '.', ''),
            'currency' => $currency,
        ];
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function lead(int $companyId, array $company, string $totalSpent, ?string $currency): BiggestFishLead
    {
        $companyName = is_string($company['name'] ?? null) && trim($company['name']) !== ''
            ? trim($company['name'])
            : 'Unknown company';

        return new BiggestFishLead(
            partnerId: $companyId,
            companyName: mb_substr($companyName, 0, 255),
            address: $this->address($company),
            website: is_string($company['website'] ?? null) ? trim($company['website']) : null,
            logoContents: $this->logoContents($company['image_512'] ?? null),
            totalSpent: $totalSpent,
            currency: $currency,
        );
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function address(array $company): string
    {
        $parts = [
            $company['street'] ?? null,
            $company['street2'] ?? null,
            $company['city'] ?? null,
            $company['zip'] ?? null,
            $this->relationName($company['country_id'] ?? null),
        ];

        return collect($parts)
            ->filter(fn (mixed $part): bool => is_string($part) && trim($part) !== '')
            ->map(fn (string $part): string => trim($part))
            ->implode(', ');
    }

    private function logoContents(mixed $image): ?string
    {
        if (! is_string($image) || $image === '') {
            return null;
        }

        $decoded = base64_decode($image, true);

        if ($decoded === false || $decoded === '') {
            return null;
        }

        return $decoded;
    }

    private function relationId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_array($value) && is_int($value[0] ?? null)) {
            return $value[0];
        }

        return null;
    }

    private function relationName(mixed $value): ?string
    {
        if (is_array($value) && is_string($value[1] ?? null)) {
            return $value[1];
        }

        return null;
    }
}
