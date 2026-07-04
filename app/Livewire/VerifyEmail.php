<?php

declare(strict_types=1);

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class VerifyEmail extends Component
{
    use WithRateLimiting;

    public function again()
    {
        try {
            $this->rateLimit(1, 300);
            auth()->user()->sendVerificationEmail();
            $this->dispatch('success', 'Email verification link sent!');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.verify-email');
    }
}
