<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CheckForUpdatesJob;
use App\Models\OauthSetting;
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
    public function index(): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();
        $server = isCloud() ? null : Server::findOrFail(0);

        return Inertia::render('Settings/Index', [
            'settings' => [
                'fqdn' => $settings->fqdn,
                'instance_name' => $settings->instance_name,
                'public_ipv4' => $settings->public_ipv4,
                'public_ipv6' => $settings->public_ipv6,
                'instance_timezone' => $settings->instance_timezone,
                'dev_helper_version' => $settings->dev_helper_version,
            ],
            'timezones' => collect(timezone_identifiers_list())->sort()->values()->all(),
            'isDev' => isDev(),
            'hasServer' => (bool) $server,
            'defaultHelperVersion' => config('constants.coolify.helper_version'),
            'updateUrl' => route('settings.update'),
            'buildHelperImageUrl' => route('settings.build-helper-image'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = instanceSettings();
        $server = isCloud() ? null : Server::findOrFail(0);

        $validated = Validator::make($request->all(), [
            'fqdn' => ['nullable', 'string', 'max:255', 'url'],
            'instance_name' => ['nullable', 'string', 'max:255'],
            'public_ipv4' => ['nullable', 'ipv4'],
            'public_ipv6' => ['nullable', 'ipv6'],
            'instance_timezone' => ['required', 'string', 'timezone'],
            'dev_helper_version' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/'],
            'force_save_domains' => ['boolean'],
        ], [
            'fqdn.url' => 'Invalid instance URL.',
            'fqdn.max' => 'URL must not exceed 255 characters.',
            'dev_helper_version.regex' => 'Dev helper version must match Docker tag format (alphanumeric, _, ., -; first char cannot be . or -).',
        ])->validate();

        if ($settings->public_port_min > $settings->public_port_max) {
            return back()->with('error', 'The minimum port must be lower than the maximum port.');
        }

        $fqdn = $validated['fqdn'] ? trim($validated['fqdn']) : null;

        if ($settings->is_dns_validation_enabled && $fqdn && $server) {
            if (! validateDNSEntry($fqdn, $server)) {
                return back()->with('error', "Validating DNS failed.<br><br>Make sure you have added the DNS records correctly.<br><br>{$fqdn}->{$server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
            }
        }

        if ($fqdn && ! $request->boolean('force_save_domains')) {
            $result = checkDomainUsage(domain: $fqdn);
            if ($result['hasConflicts']) {
                return back()->with(['domainConflicts' => $result['conflicts'], 'showDomainConflictModal' => true]);
            }
        }

        $settings->update([
            'fqdn' => $fqdn,
            'instance_name' => $validated['instance_name'] ?? null,
            'public_ipv4' => $validated['public_ipv4'] ?? null,
            'public_ipv6' => $validated['public_ipv6'] ?? null,
            'instance_timezone' => $validated['instance_timezone'],
            'dev_helper_version' => $validated['dev_helper_version'] ?? null,
        ]);

        if ($server) {
            $server->setupDynamicProxyConfiguration();
        }

        return back()->with('success', 'Instance settings updated successfully!');
    }

    public function buildHelperImage(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        if (! isDev()) {
            return back()->with('error', 'Building helper image is only available in development mode.');
        }

        if (isCloud()) {
            return back()->with('error', 'Server not available.');
        }

        $server = Server::findOrFail(0);

        $validated = Validator::make($request->all(), [
            'dev_helper_version' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/'],
        ], [
            'dev_helper_version.regex' => 'Invalid helper version format.',
        ])->validate();

        $version = $validated['dev_helper_version'] ?: config('constants.coolify.helper_version');
        if (empty($version)) {
            return back()->with('error', 'Please specify a version to build.');
        }

        if (! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', (string) $version)) {
            return back()->with('error', 'Invalid helper version format.');
        }

        $imageRef = escapeshellarg("ghcr.io/coollabsio/coolify-helper:{$version}");
        $buildCommand = "docker build -t {$imageRef} -f docker/coolify-helper/Dockerfile .";

        $activity = remote_process(
            command: [$buildCommand],
            server: $server,
            type: 'build-helper-image'
        );

        return back()
            ->with(['activityId' => $activity->id, 'activityContext' => 'settings-helper-image'])
            ->with('success', "Building coolify-helper:{$version}...");
    }

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

    public function oauth(): Response|RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $providers = OauthSetting::all()->sortBy('provider')->map(fn (OauthSetting $setting) => [
            'id' => $setting->id,
            'provider' => $setting->provider,
            'enabled' => $setting->enabled,
            'client_id' => $setting->client_id,
            'client_secret' => $setting->client_secret,
            'redirect_uri' => $setting->redirect_uri,
            'tenant' => $setting->tenant,
            'base_url' => $setting->base_url,
            'callbackUrl' => route('auth.callback', $setting->provider),
        ])->values();

        return Inertia::render('SettingsOauth', [
            'providers' => $providers,
            'updateUrl' => route('settings.oauth.update'),
        ]);
    }

    public function oauthUpdate(Request $request): RedirectResponse
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $providers = $request->input('providers', []);
        $errors = [];

        foreach ($providers as $providerData) {
            $oauth = OauthSetting::find($providerData['id'] ?? null);
            if (! $oauth) {
                $errors[] = "OAuth setting for provider '{$providerData['provider']}' not found. It may have been deleted.";

                continue;
            }

            $oauth->fill([
                'enabled' => $providerData['enabled'] ?? false,
                'client_id' => $providerData['client_id'] ?? null,
                'client_secret' => $providerData['client_secret'] ?? null,
                'redirect_uri' => $providerData['redirect_uri'] ?? null,
                'tenant' => $providerData['tenant'] ?? null,
                'base_url' => $providerData['base_url'] ?? null,
            ]);

            if ($oauth->enabled && ! $oauth->couldBeEnabled()) {
                $oauth->enabled = false;
                $errors[] = "OAuth settings are incomplete for '{$oauth->provider}'. Required fields are missing. The provider has been disabled.";
            }

            $oauth->save();
        }

        if (! empty($errors)) {
            return back()->with('error', implode(' ', $errors));
        }

        return back()->with('success', 'Instance settings updated successfully!');
    }
}
