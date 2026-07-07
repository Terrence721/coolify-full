<?php

declare(strict_types=1);

namespace App\Support\Deployment;

use App\Exceptions\DeploymentException;
use Illuminate\Support\Collection;
use JsonException;

/**
 * Pure helpers for building and merging Railpack build configuration, extracted from
 * ApplicationDeploymentJob so this JSON/array/shell-flag logic isn't entangled with
 * deployment orchestration state.
 */
final class RailpackConfigHelper
{
    /** @return array<string, mixed> */
    public static function decodeConfig(string $config, string $source): array
    {
        try {
            $decoded = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DeploymentException("Invalid {$source}: {$exception->getMessage()}", $exception->getCode(), $exception);
        }

        if (! is_array($decoded)) {
            throw new DeploymentException("Invalid {$source}: expected a JSON object.");
        }

        return $decoded;
    }

    /** @param  array<array-key, mixed>  $value */
    public static function isAssocArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function mergeConfig(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (
                array_key_exists($key, $base)
                && is_array($base[$key])
                && is_array($value)
                && self::isAssocArray($base[$key])
                && self::isAssocArray($value)
            ) {
                $base[$key] = self::mergeConfig($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /** @param  Collection<string, string>  $variables */
    public static function buildEnvironmentPrefix(Collection $variables): string
    {
        if ($variables->isEmpty()) {
            return '';
        }

        return 'env '.$variables
            ->map(function ($value, $key) {
                return escapeShellValue("{$key}={$value}");
            })
            ->implode(' ').' ';
    }

    /** @param  Collection<string, string>  $variables */
    public static function buildSecretFlags(Collection $variables): string
    {
        if ($variables->isEmpty()) {
            return '';
        }

        return ' '.$variables
            ->map(function ($value, $key) {
                return '--secret '.escapeShellValue("id={$key},env={$key}");
            })
            ->implode(' ');
    }

    /**
     * @param  Collection<string, string>  $variables
     * @return Collection<string, string>
     */
    public static function mergeDeployAptPackages(Collection $variables): Collection
    {
        $packages = collect(preg_split('/\s+/', trim((string) $variables->get('RAILPACK_DEPLOY_APT_PACKAGES', ''))) ?: [])
            ->filter()
            ->values();

        foreach (['curl', 'wget'] as $package) {
            if (! $packages->contains($package)) {
                $packages->push($package);
            }
        }

        $variables->put('RAILPACK_DEPLOY_APT_PACKAGES', $packages->implode(' '));

        return $variables;
    }
}
