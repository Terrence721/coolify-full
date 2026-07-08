<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CheckForUpdatesJob;
use App\Models\Server;
use App\Rules\ValidDnsServers;
use App\Rules\ValidIpOrCidr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function updates(): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();

        return Inertia::render('Settings/Updates', [
            'autoUpdateFrequency' => $settings->auto_update_frequency,
            'updateCheckFrequency' => $settings->update_check_frequency,
            'isAutoUpdateEnabled' => $settings->is_auto_update_enabled,
            'updateUrl' => route('settings.updates.update'),
            'checkManuallyUrl' => route('settings.updates.check-manually'),
        ]);
    }

    public function updatesUpdate(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();

        $rules = ['update_check_frequency' => ['required', 'string']];
        if ($request->boolean('is_auto_update_enabled')) {
            $rules['auto_update_frequency'] = ['required', 'string'];
        }
        $validated = Validator::make($request->all(), $rules)->validate();

        if ($request->boolean('is_auto_update_enabled') && ! validate_cron_expression($validated['auto_update_frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression for Auto Update Frequency.');
        }
        if (! validate_cron_expression($validated['update_check_frequency'])) {
            return back()->with('error', 'Invalid Cron / Human expression for Update Check Frequency.');
        }

        $settings->update([
            'auto_update_frequency' => $validated['auto_update_frequency'] ?? $settings->auto_update_frequency,
            'update_check_frequency' => $validated['update_check_frequency'],
            'is_auto_update_enabled' => $request->boolean('is_auto_update_enabled'),
        ]);

        if (! isCloud()) {
            Server::findOrFail(0)->setupDynamicProxyConfiguration();
        }

        return back()->with('success', 'Settings updated!');
    }

    public function updatesCheckManually(): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        CheckForUpdatesJob::dispatchSync();
        $settings = instanceSettings();

        return back()->with(
            'success',
            $settings->new_version_available ? 'New version available!' : 'No new version available.'
        );
    }

    public function advanced(): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();

        return Inertia::render('Settings/Advanced', [
            'settings' => [
                'is_registration_enabled' => $settings->is_registration_enabled,
                'do_not_track' => $settings->do_not_track,
                'is_dns_validation_enabled' => $settings->is_dns_validation_enabled,
                'custom_dns_servers' => $settings->custom_dns_servers,
                'is_api_enabled' => $settings->is_api_enabled,
                'allowed_ips' => $settings->allowed_ips,
                'is_sponsorship_popup_enabled' => $settings->is_sponsorship_popup_enabled,
                'disable_two_step_confirmation' => $settings->disable_two_step_confirmation,
                'is_wire_navigate_enabled' => $settings->is_wire_navigate_enabled ?? true,
                'is_mcp_server_enabled' => $settings->is_mcp_server_enabled ?? false,
            ],
            'mcpUrl' => url('/mcp'),
            'updateUrl' => route('settings.advanced.update'),
            'enableRegistrationUrl' => route('settings.advanced.enable-registration'),
            'disableTwoStepConfirmationUrl' => route('settings.advanced.disable-two-step-confirmation'),
        ]);
    }

    public function advancedUpdate(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();

        $validated = Validator::make($request->all(), [
            'is_registration_enabled' => ['boolean'],
            'do_not_track' => ['boolean'],
            'is_dns_validation_enabled' => ['boolean'],
            'custom_dns_servers' => ['nullable', 'string', new ValidDnsServers],
            'is_api_enabled' => ['boolean'],
            'allowed_ips' => ['nullable', 'string', new ValidIpOrCidr],
            'is_sponsorship_popup_enabled' => ['boolean'],
            'disable_two_step_confirmation' => ['boolean'],
            'is_wire_navigate_enabled' => ['boolean'],
            'is_mcp_server_enabled' => ['boolean'],
        ])->validate();

        $customDnsServers = str($validated['custom_dns_servers'] ?? '')->replaceEnd(',', '')->trim();
        $customDnsServers = $customDnsServers->explode(',')->map(fn ($dns) => str($dns)->trim()->lower())->unique()->implode(',');

        $allowedIps = str($validated['allowed_ips'] ?? '')->replaceEnd(',', '')->trim()->toString();
        if (! empty($allowedIps) && ! in_array('0.0.0.0', array_map('trim', explode(',', $allowedIps)))) {
            $invalidEntries = [];
            $validEntries = str($allowedIps)->trim()->explode(',')->map(function ($entry) use (&$invalidEntries) {
                $entry = str($entry)->trim()->toString();
                if (empty($entry)) {
                    return null;
                }
                if (str_contains($entry, '/')) {
                    [$ip, $mask] = explode('/', $entry);
                    $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                    $maxMask = $isIpv6 ? 128 : 32;
                    if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($mask) && $mask >= 0 && $mask <= $maxMask) {
                        return $entry;
                    }
                    $invalidEntries[] = $entry;

                    return null;
                }
                if (filter_var($entry, FILTER_VALIDATE_IP)) {
                    return $entry;
                }
                $invalidEntries[] = $entry;

                return null;
            })->filter()->values()->all();

            if (! empty($invalidEntries)) {
                return back()->with('error', 'Invalid IP addresses or subnets: '.implode(', ', $invalidEntries));
            }
            if (empty($validEntries)) {
                return back()->with('error', 'No valid IP addresses or subnets provided');
            }

            $allowedIps = implode(',', deduplicateAllowlist($validEntries));
        }

        $settings->update([
            'is_registration_enabled' => $validated['is_registration_enabled'] ?? false,
            'do_not_track' => $validated['do_not_track'] ?? false,
            'is_dns_validation_enabled' => $validated['is_dns_validation_enabled'] ?? false,
            'custom_dns_servers' => $customDnsServers,
            'is_api_enabled' => $validated['is_api_enabled'] ?? false,
            'allowed_ips' => $allowedIps,
            'is_sponsorship_popup_enabled' => $validated['is_sponsorship_popup_enabled'] ?? false,
            'disable_two_step_confirmation' => $validated['disable_two_step_confirmation'] ?? false,
            'is_wire_navigate_enabled' => $validated['is_wire_navigate_enabled'] ?? true,
            'is_mcp_server_enabled' => $validated['is_mcp_server_enabled'] ?? false,
        ]);

        return back()->with('success', 'Settings updated!');
    }

    public function advancedEnableRegistration(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        instanceSettings()->update(['is_registration_enabled' => true]);

        return back()->with('success', 'Registration has been enabled.');
    }

    public function advancedDisableTwoStepConfirmation(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        if (! verifyPasswordConfirmation($request->input('password'))) {
            return back()->with('error', 'The provided password is incorrect.');
        }

        instanceSettings()->update(['disable_two_step_confirmation' => true]);

        return back()->with('success', 'Two step confirmation has been disabled.');
    }
}
