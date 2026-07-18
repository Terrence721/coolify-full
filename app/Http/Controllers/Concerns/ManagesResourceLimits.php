<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\StandaloneDatabaseInstance;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The Resource Limits tab (App\Livewire\Project\Shared\ResourceLimits) — extracted from
 * ProjectDatabaseConfigurationController's inline implementation on its second consumer,
 * ProjectApplicationConfigurationController (Phase 63). Service has no Resource Limits tab
 * (its compose-based children don't take individual Docker resource limits the same way), so
 * this concern only ever has these two consumers.
 */
trait ManagesResourceLimits
{
    private const LIMIT_RULES = [
        'limitsMemory' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsMemorySwap' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsMemorySwappiness' => 'required|integer|min:0|max:100',
        'limitsMemoryReservation' => ['required', 'string', 'regex:/^(0|\d+[bBkKmMgG])$/'],
        'limitsCpus' => ['nullable', 'regex:/^\d*\.?\d+$/'],
        'limitsCpuset' => ['nullable', 'regex:/^\d+([,-]\d+)*$/'],
        'limitsCpuShares' => 'nullable|integer|min:0',
    ];

    private const LIMIT_MESSAGES = [
        'limitsMemory.regex' => 'Maximum Memory Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsMemorySwap.regex' => 'Maximum Swap Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsMemoryReservation.regex' => 'Soft Memory Limit must be a number followed by a unit (b, k, m, g). Example: 256m, 1g. Use 0 for unlimited.',
        'limitsCpus.regex' => 'Number of CPUs must be a number (integer or decimal). Example: 0.5, 2.',
        'limitsCpuset.regex' => 'CPU sets must be a comma-separated list of CPU numbers or ranges. Example: 0-2 or 0,1,3.',
        'limitsMemorySwappiness.integer' => 'Swappiness must be a whole number between 0 and 100.',
        'limitsMemorySwappiness.min' => 'Swappiness must be between 0 and 100.',
        'limitsMemorySwappiness.max' => 'Swappiness must be between 0 and 100.',
        'limitsCpuShares.integer' => 'CPU Weight must be a whole number.',
        'limitsCpuShares.min' => 'CPU Weight must be a positive number.',
    ];

    /**
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     */
    private function resourceLimitsTabProps(Application|StandaloneDatabaseInstance $resource, array $parameters, string $routePrefix): array
    {
        return [
            'limits' => [
                'limitsCpus' => $resource->limits_cpus,
                'limitsCpuset' => $resource->limits_cpuset,
                'limitsCpuShares' => $resource->limits_cpu_shares,
                'limitsMemory' => $resource->limits_memory,
                'limitsMemorySwap' => $resource->limits_memory_swap,
                'limitsMemorySwappiness' => $resource->limits_memory_swappiness,
                'limitsMemoryReservation' => $resource->limits_memory_reservation,
            ],
            'limitsUpdateUrl' => route("{$routePrefix}.resource-limits.update", $parameters),
        ];
    }

    private function applyResourceLimitsUpdate(Request $request, Application|StandaloneDatabaseInstance $resource): RedirectResponse
    {
        $this->authorize('update', $resource);

        // Same pre-validation defaulting as the original component
        $input = $request->all();
        $input['limitsMemory'] = filled($input['limitsMemory'] ?? null) ? $input['limitsMemory'] : '0';
        $input['limitsMemorySwap'] = filled($input['limitsMemorySwap'] ?? null) ? $input['limitsMemorySwap'] : '0';
        $input['limitsMemorySwappiness'] = ($input['limitsMemorySwappiness'] ?? '') === '' ? 60 : $input['limitsMemorySwappiness'];
        $input['limitsMemoryReservation'] = filled($input['limitsMemoryReservation'] ?? null) ? $input['limitsMemoryReservation'] : '0';
        $input['limitsCpus'] = filled($input['limitsCpus'] ?? null) ? $input['limitsCpus'] : '0';
        $input['limitsCpuset'] = ($input['limitsCpuset'] ?? '') === '' ? null : $input['limitsCpuset'];
        $input['limitsCpuShares'] = ($input['limitsCpuShares'] ?? '') === '' ? 1024 : $input['limitsCpuShares'];

        $validated = Validator::make($input, self::LIMIT_RULES, self::LIMIT_MESSAGES)->validate();

        $resource->update([
            'limits_cpus' => $validated['limitsCpus'],
            'limits_cpuset' => $validated['limitsCpuset'] ?? null,
            'limits_cpu_shares' => (int) ($validated['limitsCpuShares'] ?? 1024),
            'limits_memory' => $validated['limitsMemory'],
            'limits_memory_swap' => $validated['limitsMemorySwap'],
            'limits_memory_swappiness' => (int) $validated['limitsMemorySwappiness'],
            'limits_memory_reservation' => $validated['limitsMemoryReservation'],
        ]);

        return back()->with('success', 'Resource limits updated.');
    }
}
