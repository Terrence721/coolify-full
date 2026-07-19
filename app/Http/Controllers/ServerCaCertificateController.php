<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\SslHelper;
use App\Jobs\RegenerateSslCertJob;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Support\ServerChromeData;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerCaCertificateController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $caCertificate = $this->caCertificate($server);

        return Inertia::render('Server/CaCertificate/Show', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'ca-certificate'),
            'certificateContent' => $caCertificate->ssl_certificate ?? '',
            'certificateValidUntil' => $caCertificate?->valid_until?->toIso8601String(),
            'canManage' => Gate::forUser($request->user())->allows('update', $server),
            'canView' => Gate::forUser($request->user())->allows('view', $server),
            'saveUrl' => route('server.ca-certificate.save', ['server_uuid' => $server->uuid]),
            'regenerateUrl' => route('server.ca-certificate.regenerate', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function save(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageCaCertificate', $server);

        $validated = Validator::make($request->all(), [
            'certificateContent' => ['required', 'string'],
        ])->validate();

        try {
            $parsedCert = openssl_x509_read($validated['certificateContent']);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in save().', ['error' => $e->getMessage()]);
            $parsedCert = false;
        }
        if (! $parsedCert) {
            return back()->with('error', 'Invalid certificate format.');
        }
        if (! openssl_x509_export($parsedCert, $cleanedCertificate)) {
            return back()->with('error', 'Failed to process certificate.');
        }

        $caCertificate = $this->caCertificate($server);
        if ($caCertificate) {
            $caCertificate->ssl_certificate = $cleanedCertificate;
            $caCertificate->save();

            $this->writeCertificateToServer($server, $cleanedCertificate);

            dispatch(new RegenerateSslCertJob(server_id: $server->id, force_regeneration: true));
        }

        return back()->with('success', 'CA Certificate saved successfully.');
    }

    public function regenerate(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('manageCaCertificate', $server);

        SslHelper::generateSslCertificate(
            commonName: 'Coolify CA Certificate',
            serverId: $server->id,
            isCaCertificate: true,
            validityDays: 10 * 365
        );

        $caCertificate = $this->caCertificate($server);
        if ($caCertificate) {
            $this->writeCertificateToServer($server, $caCertificate->ssl_certificate);
        }

        dispatch(new RegenerateSslCertJob(server_id: $server->id, force_regeneration: true));

        return back()->with('success', 'CA Certificate regenerated successfully.');
    }

    private function caCertificate(Server $server): ?SslCertificate
    {
        return $server->sslCertificates()->where('is_ca_certificate', true)->first();
    }

    private function writeCertificateToServer(Server $server, string $certificateContent): void
    {
        $caCertPath = config('constants.coolify.base_config_path').'/ssl/';
        $base64Cert = base64_encode($certificateContent);

        $commands = collect([
            "mkdir -p $caCertPath",
            "chown -R 9999:root $caCertPath",
            "chmod -R 700 $caCertPath",
            "rm -rf $caCertPath/coolify-ca.crt",
            "echo '{$base64Cert}' | base64 -d | tee $caCertPath/coolify-ca.crt > /dev/null",
            "chmod 644 $caCertPath/coolify-ca.crt",
        ]);

        remote_process($commands, $server);
    }
}
