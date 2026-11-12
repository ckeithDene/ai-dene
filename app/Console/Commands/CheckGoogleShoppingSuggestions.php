<?php

namespace App\Console\Commands;

use App\Actions\CheckGoogleShoppingPrices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('google-shopping-suggestions:check')]
#[Description('Compare the next best-selling products with the cheapest Google Shopping prices')]
class CheckGoogleShoppingSuggestions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CheckGoogleShoppingPrices $check): int
    {
        $result = $check->handle();

        if ($result->skippedReason !== null) {
            $this->error($result->skippedReason);

            return self::FAILURE;
        }

        $this->info(trans_choice(
            'Checked :count product.|Checked :count products.',
            $result->reviewed,
            ['count' => $result->reviewed],
        ));

        return self::SUCCESS;
    }
}
