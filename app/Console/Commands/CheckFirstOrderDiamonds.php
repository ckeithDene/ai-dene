<?php

namespace App\Console\Commands;

use App\Actions\ReviewFirstOrderDiamonds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('first-order-diamonds:check')]
#[Description('Review new confirmed first orders from Odoo')]
class CheckFirstOrderDiamonds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReviewFirstOrderDiamonds $review): int
    {
        $result = $review->handle();

        if ($result->skippedReason !== null) {
            $this->error($result->skippedReason);

            return self::FAILURE;
        }

        $this->info(trans_choice(
            'Reviewed :count first order.|Reviewed :count first orders.',
            $result->reviewed,
            ['count' => $result->reviewed],
        ));

        return self::SUCCESS;
    }
}
