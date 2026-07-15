<?php

declare(strict_types=1);

namespace App\Actions\Server;

use App\Enums\ProxyTypes;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\HetznerService;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateHetznerServer
{
    use AsAction;

    /**
     * @param  array<int, int>  $hetznerSshKeyIds
     */
    public function handle(
        CloudProviderToken $token,
        PrivateKey $privateKey,
        int $teamId,
        string $location,
        string $serverType,
        int $image,
        string $name,
        bool $enableIpv4 = true,
        bool $enableIpv6 = true,
        array $hetznerSshKeyIds = [],
        ?string $cloudInitScript = null,
        bool $instantValidate = false,
    ): Server {
        $hetznerService = new HetznerService($token->token);

        // Get public key and MD5 fingerprint
        $publicKey = $privateKey->getPublicKey();
        $md5Fingerprint = PrivateKey::generateMd5Fingerprint($privateKey->private_key);

        // Check if SSH key already exists on Hetzner
        $existingSshKeys = $hetznerService->getSshKeys();
        $existingKey = null;

        foreach ($existingSshKeys as $key) {
            if ($key['fingerprint'] === $md5Fingerprint) {
                $existingKey = $key;
                break;
            }
        }

        // Upload SSH key if it doesn't exist
        if ($existingKey) {
            $sshKeyId = $existingKey['id'];
        } else {
            $uploadedKey = $hetznerService->uploadSshKey($privateKey->name, $publicKey);
            $sshKeyId = $uploadedKey['id'];
        }

        // Normalize server name to lowercase for RFC 1123 compliance
        $normalizedServerName = strtolower(trim($name));

        // Prepare SSH keys array: Coolify key + user-selected Hetzner keys
        $sshKeys = array_merge([$sshKeyId], $hetznerSshKeyIds);
        $sshKeys = array_values(array_unique($sshKeys));

        // Prepare server creation parameters
        $params = [
            'name' => $normalizedServerName,
            'server_type' => $serverType,
            'image' => $image,
            'location' => $location,
            'start_after_create' => true,
            'ssh_keys' => $sshKeys,
            'public_net' => [
                'enable_ipv4' => $enableIpv4,
                'enable_ipv6' => $enableIpv6,
            ],
        ];

        if (! empty($cloudInitScript)) {
            $params['user_data'] = $cloudInitScript;
        }

        // Create server on Hetzner
        $hetznerServer = $hetznerService->createServer($params);

        // Determine IP address to use (prefer IPv4, fallback to IPv6)
        $ipAddress = null;
        if ($enableIpv4 && isset($hetznerServer['public_net']['ipv4']['ip'])) {
            $ipAddress = $hetznerServer['public_net']['ipv4']['ip'];
        } elseif ($enableIpv6 && isset($hetznerServer['public_net']['ipv6']['ip'])) {
            $ipAddress = $hetznerServer['public_net']['ipv6']['ip'];
        }

        if (! $ipAddress) {
            throw new \Exception('No public IP address available. Enable at least one of IPv4 or IPv6.');
        }

        // Create server in Coolify database
        $server = Server::create([
            'name' => $normalizedServerName,
            'ip' => $ipAddress,
            'user' => 'root',
            'port' => 22,
            'team_id' => $teamId,
            'private_key_id' => $privateKey->id,
            'cloud_provider_token_id' => $token->id,
            'hetzner_server_id' => $hetznerServer['id'],
        ]);

        $server->proxy->set('status', 'exited');
        $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
        $server->save();

        if ($instantValidate) {
            ValidateServer::dispatch($server);
        }

        return $server;
    }
}
