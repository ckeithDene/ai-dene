<?php

namespace App\Actions;

use App\Data\DiamondCheckResult;
use App\Data\FirstOrderLead;
use App\Enums\FirstOrderDiamondStatus;
use App\Enums\OdooCompany;
use App\Models\AiModel;
use App\Models\FirstOrderDiamond;
use App\Models\OdooConnection;
use App\Services\CompanyLogoStore;
use App\Services\CompanyResearcher;
use App\Services\FirstOrderFinder;
use App\Services\OdooClient;
use RuntimeException;
use Throwable;

class ReviewFirstOrderDiamonds
{
    /**
     * Review confirmed first orders that have not been reviewed yet.
     */
    public function handle(): DiamondCheckResult
    {
        set_time_limit(0);

        $connection = OdooConnection::for(OdooCompany::MidMeds);
        $aiModel = AiModel::current();

        if ($connection === null || blank($connection->api_key)) {
            return new DiamondCheckResult(0, __('Connect Odoo before checking first orders.'));
        }

        if ($aiModel === null || blank($aiModel->api_key)) {
            return new DiamondCheckResult(0, __('Connect ChatGPT before checking first orders.'));
        }

        try {
            $leads = (new FirstOrderFinder)->newLeads(new OdooClient($connection));
        } catch (RuntimeException) {
            return new DiamondCheckResult(0, __('Could not read first orders from Odoo.'));
        }

        $checkedPartners = [];

        foreach ($leads as $lead) {
            $checkedPartners[] = $lead->partnerId;
            $this->review($lead, $aiModel);
        }

        $this->fillMissingLogos($checkedPartners);

        return new DiamondCheckResult(count($leads), null);
    }

    /**
     * Save one company brief. Finished reviews are not run again.
     */
    private function review(FirstOrderLead $lead, AiModel $aiModel): void
    {
        $diamond = FirstOrderDiamond::query()->firstOrNew([
            'odoo_partner_id' => $lead->partnerId,
        ]);

        $diamond->fill([
            'odoo_order_id' => $lead->orderId,
            'order_name' => $lead->orderName,
            'ordered_at' => $lead->orderedAt,
            'company_name' => $lead->companyName,
            'address' => $lead->address !== '' ? $lead->address : null,
            'website' => FirstOrderDiamond::httpUrl($lead->website),
            'order_lines' => $lead->orderLines === [] ? null : $lead->orderLines,
            'status' => FirstOrderDiamondStatus::Pending,
            'failure_reason' => null,
        ]);
        $diamond->save();

        try {
            $brief = (new CompanyResearcher)->research($aiModel, $lead->companyName, $lead->address);

            if (! $brief->isRegisteredCompany) {
                $diamond->fill([
                    'overview' => null,
                    'company_size' => null,
                    'estimated_value' => null,
                    'market_share' => null,
                    'website' => null,
                    'logo_path' => null,
                    'is_registered_company' => false,
                    'status' => FirstOrderDiamondStatus::Complete,
                    'failure_reason' => null,
                    'checked_at' => now(),
                ]);
                $diamond->save();

                return;
            }

            $website = $diamond->website ?? $brief->website;
            $logoPath = (new CompanyLogoStore)->store($lead->partnerId, $lead->logoContents, $brief->logoUrl, $website);

            $diamond->fill([
                'overview' => $brief->overview,
                'company_size' => $brief->companySize,
                'estimated_value' => $brief->estimatedValue,
                'market_share' => $brief->marketShare,
                'website' => $website,
                'logo_path' => $logoPath ?? $diamond->logo_path,
                'is_registered_company' => true,
                'status' => FirstOrderDiamondStatus::Complete,
                'failure_reason' => null,
                'checked_at' => now(),
            ]);
            $diamond->save();
        } catch (Throwable) {
            $diamond->fill([
                'status' => FirstOrderDiamondStatus::Failed,
                'failure_reason' => __('ChatGPT could not review this company.'),
                'checked_at' => now(),
            ]);
            $diamond->save();
        }
    }

    /**
     * Find logos for companies that were reviewed before an image could be saved.
     *
     * @param  array<int, int>  $checkedPartners
     */
    private function fillMissingLogos(array $checkedPartners): void
    {
        FirstOrderDiamond::query()
            ->where('status', FirstOrderDiamondStatus::Complete)
            ->where('is_registered_company', true)
            ->whereNull('logo_path')
            ->whereNotNull('website')
            ->when($checkedPartners !== [], fn ($query) => $query->whereNotIn('odoo_partner_id', $checkedPartners))
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(function (FirstOrderDiamond $diamond): void {
                $logoPath = (new CompanyLogoStore)->store($diamond->odoo_partner_id, null, null, $diamond->website);

                if ($logoPath === null) {
                    return;
                }

                $diamond->logo_path = $logoPath;
                $diamond->save();
            });
    }
}
