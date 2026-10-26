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
                ['id', 'name', 'date_order', 'partner_id'],
                'date_order asc, id asc',
                self::PAGE_SIZE,
                $offset,
            );

            if ($orders === []) {
                break;
            }

            $partners = $this->companies($client, $orders);

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

                $leads[] = $this->lead($order, $companyId, $company);

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
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $company
     */
    private function lead(array $order, int $companyId, array $company): FirstOrderLead
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
            logoContents: $this->logoContents($company['image_512'] ?? null),
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
