<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\ServiceApplication;
use Spatie\Url\Url;

/**
 * FQDN normalization shared by the service-resource settings page and the resource cards'
 * Edit Domains modal (extracted from ProjectServiceResourceController when the Phase 59
 * card port became its second consumer). Mirrors the original Livewire EditDomain::submit()
 * pre-processing: strip stray commas, validate each domain as an http(s) URL, lowercase,
 * dedupe.
 */
trait NormalizesServiceFqdns
{
    private function normalizeFqdn(string $fqdn): ?string
    {
        $fqdn = str($fqdn)->replaceEnd(',', '')->trim()->toString();
        $fqdn = str($fqdn)->replaceStart(',', '')->trim()->toString();
        if ($fqdn === '') {
            return null;
        }
        $domains = str($fqdn)->trim()->explode(',')->map(function (string $domain) {
            $domain = trim($domain);
            Url::fromString($domain, ['http', 'https']);

            return str($domain)->lower();
        });

        return $domains->unique()->implode(',');
    }

    private function fqdnsMissingPort(?string $fqdn): bool
    {
        if (! $fqdn) {
            return false;
        }
        foreach (str($fqdn)->trim()->explode(',') as $singleFqdn) {
            $singleFqdn = trim($singleFqdn);
            if ($singleFqdn === '') {
                continue;
            }
            if (ServiceApplication::extractPortFromUrl($singleFqdn) === null) {
                return true;
            }
        }

        return false;
    }
}
