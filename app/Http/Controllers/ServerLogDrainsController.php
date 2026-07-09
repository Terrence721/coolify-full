<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Models\Server;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ServerLogDrainsController extends Controller
{
    use AuthorizesRequests;

    private const array PROVIDERS = ['newrelic', 'axiom', 'custom'];

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $settings = $server->settings;

        return Inertia::render('Server/LogDrains', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'log-drains'),
            'isFunctional' => $server->isFunctional(),
            'isLogDrainEnabled' => $server->isLogDrainEnabled(),
            'isLogDrainNewRelicEnabled' => (bool) $settings->is_logdrain_newrelic_enabled,
            'logDrainNewRelicLicenseKey' => $settings->logdrain_newrelic_license_key,
            'logDrainNewRelicBaseUri' => $settings->logdrain_newrelic_base_uri,
            'isLogDrainAxiomEnabled' => (bool) $settings->is_logdrain_axiom_enabled,
            'logDrainAxiomDatasetName' => $settings->logdrain_axiom_dataset_name,
            'logDrainAxiomApiKey' => $settings->logdrain_axiom_api_key,
            'isLogDrainCustomEnabled' => (bool) $settings->is_logdrain_custom_enabled,
            'logDrainCustomConfig' => $settings->logdrain_custom_config,
            'logDrainCustomConfigParser' => $settings->logdrain_custom_config_parser,
            'toggleUrl' => route('server.log-drains.toggle', ['server_uuid' => $server->uuid]),
            'submitUrl' => route('server.log-drains.submit', ['server_uuid' => $server->uuid]),
        ]);
    }

    /**
     * Toggling a provider "on" validates and saves that provider's currently-entered fields
     * together with the enabled flag (mirroring the original Livewire component, whose
     * wire:model-bound fields are already live-synced by the time the checkbox is toggled),
     * then starts/stops the log drain service over SSH. Toggling "off" needs no field
     * validation, matching the original's customValidation() (only runs when enabling).
     */
    public function toggle(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'type' => ['required', 'string', 'in:'.implode(',', self::PROVIDERS)],
            'enabled' => ['required', 'boolean'],
        ])->validate();

        $type = $validated['type'];
        $enabled = $validated['enabled'];
        $settings = $server->settings;

        if ($enabled) {
            try {
                $fields = $this->validateAndExtractProviderFields($request, $type);
            } catch (ValidationException $e) {
                return back()->withErrors($e->errors());
            }
            foreach ($fields as $column => $value) {
                $settings->{$column} = $value;
            }
        }

        $settings->{"is_logdrain_{$type}_enabled"} = $enabled;
        $settings->save();

        if ($server->isLogDrainEnabled()) {
            StartLogDrain::run($server);

            return back()->with('success', 'Log drain service started.');
        }

        StopLogDrain::run($server);

        return back()->with('success', 'Log drain service stopped.');
    }

    public function submit(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $type = $request->input('type');
        if (! in_array($type, self::PROVIDERS, true)) {
            return back()->with('error', 'Invalid log drain provider.');
        }

        $fields = $this->validateAndExtractProviderFields($request, $type);

        $settings = $server->settings;
        foreach ($fields as $column => $value) {
            $settings->{$column} = $value;
        }
        $settings->save();

        return back()->with('success', 'Settings saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAndExtractProviderFields(Request $request, string $type): array
    {
        if ($type === 'newrelic') {
            $validated = Validator::make($request->all(), [
                'logDrainNewRelicLicenseKey' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                'logDrainNewRelicBaseUri' => ['required', 'url'],
            ])->validate();

            return [
                'logdrain_newrelic_license_key' => $validated['logDrainNewRelicLicenseKey'],
                'logdrain_newrelic_base_uri' => $validated['logDrainNewRelicBaseUri'],
            ];
        }

        if ($type === 'axiom') {
            $validated = Validator::make($request->all(), [
                'logDrainAxiomDatasetName' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
                'logDrainAxiomApiKey' => ['required', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            ])->validate();

            return [
                'logdrain_axiom_dataset_name' => $validated['logDrainAxiomDatasetName'],
                'logdrain_axiom_api_key' => $validated['logDrainAxiomApiKey'],
            ];
        }

        $validated = Validator::make($request->all(), [
            'logDrainCustomConfig' => ['required', 'string'],
            'logDrainCustomConfigParser' => ['string', 'nullable'],
        ])->validate();

        return [
            'logdrain_custom_config' => $validated['logDrainCustomConfig'],
            'logdrain_custom_config_parser' => $validated['logDrainCustomConfigParser'] ?? null,
        ];
    }
}
