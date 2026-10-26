<?php

namespace App\Actions;

use App\Data\DiamondCheckResult;
use App\Data\FirstOrderLead;
use App\Enums\FirstOrderDiamondStatus;
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

        $connection = OdooConnection::current();
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

        foreach ($leads as $lead) {
            $this->review($lead, $aiModel);
        }

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
            'status' => FirstOrderDiamondStatus::Pending,
            'failure_reason' => null,
        ]);
        $diamond->save();

        try {
            $brief = (new CompanyResearcher)->research($aiModel, $lead->companyName, $lead->address);
            $logoPath = (new CompanyLogoStore)->store($lead->partnerId, $lead->logoContents, $brief->logoUrl);

            $diamond->fill([
                'overview' => $brief->overview,
                'company_size' => $brief->companySize,
                'estimated_value' => $brief->estimatedValue,
                'market_share' => $brief->marketShare,
                'logo_path' => $logoPath ?? $diamond->logo_path,
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
}
