<?php

namespace App\Actions;

use App\Data\BiggestFishLead;
use App\Data\DiamondCheckResult;
use App\Enums\CustomerReviewStatus;
use App\Enums\OdooCompany;
use App\Models\AiModel;
use App\Models\BiggestFish;
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

        try {
            $customers = (new BiggestFishFinder)->nextCustomers(new OdooClient($connection));
        } catch (RuntimeException) {
            return new DiamondCheckResult(0, __('Could not read customers from Odoo.'));
        }

        foreach ($customers as $customer) {
            $this->review($customer, $aiModel);
        }

        return new DiamondCheckResult(count($customers), null);
    }

    /**
     * Save one customer brief. A finished check is not run again.
     */
    private function review(BiggestFishLead $customer, AiModel $aiModel): void
    {
        $fish = BiggestFish::query()->firstOrNew([
            'odoo_partner_id' => $customer->partnerId,
        ]);

        $fish->fill([
            'company_name' => $customer->companyName,
            'address' => $customer->address !== '' ? $customer->address : null,
            'website' => FirstOrderDiamond::httpUrl($customer->website),
            'total_spent' => $customer->totalSpent,
            'currency' => $customer->currency,
            'status' => CustomerReviewStatus::Pending,
            'failure_reason' => null,
        ]);
        $fish->save();

        try {
            $brief = (new CompanyResearcher)->research($aiModel, $customer->companyName, $customer->address);
            $qualifies = $brief->isRegisteredCompany
                && $brief->estimatedValueGbp !== null
                && $brief->estimatedValueGbp > BiggestFish::VALUE_THRESHOLD_GBP;
            $website = $fish->website ?? $brief->website;
            $logoPath = $qualifies
                ? (new CompanyLogoStore)->store($customer->partnerId, $customer->logoContents, $brief->logoUrl, $website, 'biggest-fish')
                : null;

            $fish->fill([
                'overview' => $qualifies ? $brief->overview : null,
                'company_size' => $qualifies ? $brief->companySize : null,
                'estimated_value' => $brief->estimatedValue,
                'estimated_value_gbp' => $brief->estimatedValueGbp,
                'market_share' => $qualifies ? $brief->marketShare : null,
                'website' => $qualifies ? $website : null,
                'logo_path' => $logoPath,
                'is_registered_company' => $brief->isRegisteredCompany,
                'status' => CustomerReviewStatus::Complete,
                'failure_reason' => null,
                'checked_at' => now(),
            ]);
            $fish->save();
        } catch (Throwable) {
            $fish->fill([
                'status' => CustomerReviewStatus::Failed,
                'failure_reason' => __('ChatGPT could not review this company.'),
                'checked_at' => now(),
            ]);
            $fish->save();
        }
    }
}
