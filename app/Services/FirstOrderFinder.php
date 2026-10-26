<?php

namespace App\Services;

use App\Data\FirstOrderLead;
use App\Enums\FirstOrderDiamondStatus;
use App\Models\FirstOrderDiamond;

class FirstOrderFinder
{
    private const int MAX_NEW_LEADS = 20;

    private const int PAGE_SIZE = 100;

    /**
     * Confirmed orders before this moment are outside the review window.
     */
    private const string FIRST_ORDER_FROM = '2026-10-01 00:00:00';

    /**
     * Find confirmed first orders that still need a review.
     *
     * @return array<int, FirstOrderLead>
     */
    public function newLeads(OdooClient $client): array
    {
        $reviewed = array_fill_keys(
            FirstOrderDiamond::query()
                ->whereIn('status', [
                    FirstOrderDiamondStatus::Complete,
                    FirstOrderDiamondStatus::Failed,
                ])
                ->pluck('odoo_partner_id')
                ->all(),
            true,
        );

        $leads = [];
        $seen = [];
        $offset = 0;

        do {
            $orders = $client->searchRead(
                'sale.order',
                [
                    ['state', 'in', ['sale', 'done']],
                    ['date_order', '>=', self::FIRST_ORDER_FROM],
                ],
                ['id', 'name', 'date_order', 'partner_id', 'order_line'],
                'date_order asc, id asc',
                self::PAGE_SIZE,
                $offset,
            );

            if ($orders === []) {
                break;
            }

            $partners = $this->companies($client, $orders);
            $lines = $this->linesByOrder($client, $orders);

            foreach ($orders as $order) {
                if (! $this->isInReviewWindow($order['date_order'] ?? null)) {
                    continue;
                }

                $contactId = $this->relationId($order['partner_id'] ?? null);

                if ($contactId === null || ! isset($partners[$contactId])) {
                    continue;
                }

                $companyId = $this->relationId($partners[$contactId]['commercial_partner_id'] ?? null) ?? $contactId;
                $company = $partners[$companyId] ?? $partners[$contactId];

                if (isset($reviewed[$companyId]) || isset($seen[$companyId])) {
                    continue;
                }

                $seen[$companyId] = true;

                if ($this->orderedBeforeWindow($client, $companyId)) {
                    continue;
                }

                $orderId = $this->relationId($order['id'] ?? null) ?? 0;
                $leads[] = $this->lead($order, $companyId, $company, $lines[$orderId] ?? []);

                if (count($leads) >= self::MAX_NEW_LEADS) {
                    return $leads;
                }
            }

            $offset += count($orders);
        } while (count($orders) === self::PAGE_SIZE);

        return $leads;
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
     * A company is not a first-time customer when it already had a confirmed order.
     */
    private function orderedBeforeWindow(OdooClient $client, int $companyId): bool
    {
        return $client->searchRead(
            'sale.order',
            [
                ['state', 'in', ['sale', 'done']],
                ['date_order', '<', self::FIRST_ORDER_FROM],
                ['partner_id.commercial_partner_id', '=', $companyId],
            ],
            ['id'],
            'id asc',
            1,
            0,
        ) !== [];
    }

    private function isInReviewWindow(mixed $orderedAt): bool
    {
        return is_string($orderedAt) && $orderedAt >= self::FIRST_ORDER_FROM;
    }

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<int, array{quantity: string, name: string}>>
     */
    private function linesByOrder(OdooClient $client, array $orders): array
    {
        $lineIds = [];

        foreach ($orders as $order) {
            if (! is_array($order['order_line'] ?? null)) {
                continue;
            }

            foreach ($order['order_line'] as $lineId) {
                if (is_int($lineId)) {
                    $lineIds[] = $lineId;
                }
            }
        }

        $lineIds = array_values(array_unique($lineIds));

        if ($lineIds === []) {
            return [];
        }

        $grouped = [];

        foreach ($client->read('sale.order.line', $lineIds, ['name', 'product_uom_qty', 'order_id', 'product_id']) as $line) {
            $orderId = $this->relationId($line['order_id'] ?? null);

            if ($orderId === null || count($grouped[$orderId] ?? []) >= 15) {
                continue;
            }

            $name = $this->lineName($line);

            if ($name === null) {
                continue;
            }

            $grouped[$orderId][] = [
                'quantity' => $this->quantity($line['product_uom_qty'] ?? 1),
                'name' => $name,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineName(array $line): ?string
    {
        $name = $this->relationName($line['product_id'] ?? null);

        if ($name === null && is_string($line['name'] ?? null)) {
            $name = trim(explode("\n", str_replace("\r", '', $line['name']))[0]);
        }

        if ($name === null || $name === '') {
            return null;
        }

        return mb_substr($name, 0, 255);
    }

    private function quantity(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '1';
        }

        $quantity = (float) $value;

        if (floor($quantity) === $quantity) {
            return (string) (int) $quantity;
        }

        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $company
     * @param  array<int, array{quantity: string, name: string}>  $orderLines
     */
    private function lead(array $order, int $companyId, array $company, array $orderLines): FirstOrderLead
    {
        $orderId = $this->relationId($order['id'] ?? null) ?? 0;
        $orderedAt = is_string($order['date_order'] ?? null) ? $order['date_order'] : null;
        $orderName = is_string($order['name'] ?? null) && $order['name'] !== '' ? $order['name'] : 'Order';
        $companyName = is_string($company['name'] ?? null) && trim($company['name']) !== ''
            ? trim($company['name'])
            : 'Unknown company';

        return new FirstOrderLead(
            partnerId: $companyId,
            orderId: $orderId,
            orderName: $orderName,
            orderedAt: $orderedAt,
            companyName: mb_substr($companyName, 0, 255),
            address: $this->address($company),
            website: is_string($company['website'] ?? null) ? trim($company['website']) : null,
            logoContents: $this->logoContents($company['image_512'] ?? null),
            orderLines: $orderLines,
        );
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function address(array $company): string
    {
        $country = $this->relationName($company['country_id'] ?? null);
        $parts = [
            $company['street'] ?? null,
            $company['street2'] ?? null,
            $company['city'] ?? null,
            $company['zip'] ?? null,
            $country,
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
