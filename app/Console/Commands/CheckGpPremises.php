<?php

namespace App\Console\Commands;

use App\Actions\ResearchGpPremises;
use App\Models\GpPremisesSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('primary-care-spaces:check')]
#[Description('Refresh the Primary Care Spaces GP premises pipeline')]
class CheckGpPremises extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ResearchGpPremises $research): int
    {
        $result = $research->checkPipeline(GpPremisesSetting::current());

        if ($result->skippedReason !== null) {
            $this->error($result->skippedReason);

            return self::FAILURE;
        }

        $this->info("Checked {$result->checked} projects. Added {$result->added}. Updated {$result->updated}.");

        return self::SUCCESS;
    }
}
