<?php

namespace App\Console\Commands;

use App\Actions\ResearchNhsPurchasing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nhs-purchasing-patterns:check')]
#[Description('Check NHS directives and update Dene Healthcare purchasing patterns')]
class CheckNhsPurchasing extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ResearchNhsPurchasing $research): int
    {
        $result = $research->handle();

        if ($result->skippedReason !== null) {
            $this->error($result->skippedReason);

            return self::FAILURE;
        }

        $this->info("Checked NHS purchasing patterns. Directives {$result->directives}. Opportunities {$result->opportunities}.");

        return self::SUCCESS;
    }
}
