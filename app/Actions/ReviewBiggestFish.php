<?php

namespace App\Actions;

use App\Data\BiggestFishLead;
use App\Data\CompanyBrief;
use App\Data\DiamondCheckResult;
use App\Enums\CustomerReviewStatus;
use App\Enums\OdooCompany;
use App\Exceptions\OutOfTokens;
use App\Models\AiModel;
use App\Models\BiggestFish;
use App\Models\BiggestFishScan;
use App\Models\FirstOrderDiamond;
use App\Models\OdooConnection;
use App\Services\BiggestFishFinder;
use App\Services\CompanyLogoStore;
use App\Services\CompanyResearcher;
use App\Services\OdooClient;
use RuntimeException;
use Throwable;

class ReviewBiggestFish
{
    /**
     * Review the next batch of confirmed customers.
     */
    public function handle(): DiamondCheckResult
    {
        set_time_limit(0);

        $connection = OdooConnection::for(OdooCompany::MidMeds);
        $aiModel = AiModel::current();

        if ($connection === null || blank($connection->api_key)) {
            return new DiamondCheckResult(0, __('Connect Odoo before checking customers.'));
        }

        if ($aiModel === null || blank($aiModel->api_key)) {
            return new DiamondCheckResult(0, __('Connect ChatGPT before checking customers.'));
        }

        if (! (new CompanyResearcher)->hasTokens($aiModel)) {
            return new DiamondCheckResult(0, __('ChatGPT has no tokens left, so no customers were checked.'));
        }

        $reviewed = 0;
        $waiting = BiggestFish::query()
            ->whereIn('status', [CustomerReviewStatus::Failed, CustomerReviewStatus::Pending])
            ->orderBy('id')
            ->limit(BiggestFishFinder::MAX_CUSTOMERS)
            ->get();

        foreach ($waiting as $fish) {
            if (! $this->reviewSaved($fish, $aiModel)) {
                return new DiamondCheckResult($reviewed, $this->stoppedEarly());
            }

            $reviewed++;
        }

        $remaining = BiggestFishFinder::MAX_CUSTOMERS - $reviewed;
        $finder = new BiggestFishFinder;

        if ($remaining === 0) {
            $finder->commitCursor((int) BiggestFishScan::query()->value('last_order_id'));

            return new DiamondCheckResult($reviewed, null);
        }

        try {
            $batch = $finder->nextCustomers(new OdooClient($connection), $remaining);
        } catch (RuntimeException) {
            return new DiamondCheckResult($reviewed, __('Could not read customers from Odoo.'));
        }

        foreach ($batch->customers as $customer) {
            if (! $this->reviewLead($customer, $aiModel)) {
                return new DiamondCheckResult($reviewed, $this->stoppedEarly());
            }

            $reviewed++;
        }

        $finder->commitCursor($batch->cursor);

        return new DiamondCheckResult($reviewed, null);
    }

    /**
     * Review one customer found from a confirmed order.
     */
    private function reviewLead(BiggestFishLead $customer, AiModel $aiModel): bool
    {
        $fish = BiggestFish::query()->firstOrNew([
            'odoo_partner_id' => $customer->partnerId,
        ]);

        return $this->research($fish, $aiModel, $customer->companyName, $customer->address, $customer);
    }

    /**
     * Review a customer whose earlier check did not finish.
     */
    private function reviewSaved(BiggestFish $fish, AiModel $aiModel): bool
    {
        return $this->research($fish, $aiModel, $fish->company_name, (string) $fish->address, null);
    }

    /**
     * Save one customer brief. A token failure is left unfinished so the next scan can try again.
     */
    private function research(BiggestFish $fish, AiModel $aiModel, string $company, string $address, ?BiggestFishLead $customer): bool
    {
        try {
            $brief = (new CompanyResearcher)->research($aiModel, $company, $address);
        } catch (OutOfTokens) {
            return false;
        } catch (Throwable) {
            $this->markFailed($fish, $customer);

            return true;
        }

        $this->saveBrief($fish, $brief, $customer);

        return true;
    }

    private function saveBrief(BiggestFish $fish, CompanyBrief $brief, ?BiggestFishLead $customer): void
    {
        $qualifies = $brief->isRegisteredCompany
            && $brief->estimatedValueGbp !== null
            && $brief->estimatedValueGbp > BiggestFish::VALUE_THRESHOLD_GBP;
        $partnerId = $customer?->partnerId ?? $fish->odoo_partner_id;
        $website = ($customer?->website !== null ? FirstOrderDiamond::httpUrl($customer->website) : null)
            ?? $fish->website
            ?? $brief->website;
        $logoPath = $qualifies
            ? (new CompanyLogoStore)->store($partnerId, $customer?->logoContents, $brief->logoUrl, $website, 'biggest-fish')
            : null;

        $fish->fill([
            'company_name' => $customer?->companyName ?? $fish->company_name,
            'address' => $customer !== null
                ? ($customer->address !== '' ? $customer->address : null)
                : $fish->address,
            'website' => $qualifies ? $website : null,
            'total_spent' => $customer?->totalSpent ?? $fish->total_spent,
            'currency' => $customer?->currency ?? $fish->currency,
            'overview' => $qualifies ? $brief->overview : null,
            'company_size' => $qualifies ? $brief->companySize : null,
            'estimated_value' => $brief->estimatedValue,
            'estimated_value_gbp' => $brief->estimatedValueGbp,
            'market_share' => $qualifies ? $brief->marketShare : null,
            'logo_path' => $logoPath,
            'is_registered_company' => $brief->isRegisteredCompany,
            'status' => CustomerReviewStatus::Complete,
            'failure_reason' => null,
            'checked_at' => now(),
        ]);
        $fish->save();
    }

    private function markFailed(BiggestFish $fish, ?BiggestFishLead $customer): void
    {
        if ($customer !== null) {
            $fish->fill([
                'company_name' => $customer->companyName,
                'address' => $customer->address !== '' ? $customer->address : null,
                'website' => FirstOrderDiamond::httpUrl($customer->website),
                'total_spent' => $customer->totalSpent,
                'currency' => $customer->currency,
            ]);
        }

        $fish->fill([
            'status' => CustomerReviewStatus::Failed,
            'failure_reason' => __('ChatGPT could not review this company.'),
            'checked_at' => now(),
        ]);
        $fish->save();
    }

    private function stoppedEarly(): string
    {
        return __('ChatGPT ran out of tokens, so the scan stopped. Customers that were not reviewed will be checked again.');
    }
}
