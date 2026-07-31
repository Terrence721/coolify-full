<?php

declare(strict_types=1);

namespace App\Actions\Application;

use Laravel\Horizon\Contracts\JobRepository;
use Lorisleiva\Actions\Concerns\AsAction;

class IsHorizonQueueEmpty
{
    use AsAction;

    public function handle(): bool
    {
        $recent = app(JobRepository::class)->getRecent();
        $running = $recent->filter(function ($job) {
            return $job->status != 'completed' &&
                   $job->status != 'failed';
        });
        if ($running->count() > 0) {
            return false;
        }

        return true;
    }
}
