<?php

namespace App\Console\Commands;

use App\Actions\ReviewBiggestFish;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('biggest-fish:check')]
#[Description('Review the next confirmed customers for Biggest Fish')]
class CheckBiggestFish extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReviewBiggestFish $review): int
    {
        $result = $review->handle();

        if ($result->skippedReason !== null) {
            $this->error($result->skippedReason);

            return self::FAILURE;
        }

        $this->info(trans_choice(
            'Reviewed :count customer.|Reviewed :count customers.',
            $result->reviewed,
            ['count' => $result->reviewed],
        ));

        return self::SUCCESS;
    }
}
