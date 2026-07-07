<?php

declare(strict_types=1);

namespace App\Support\Deployment;

use App\Support\ValidationPatterns;

/**
 * Runtime defense-in-depth checks for pre/post-deployment command fields, mirroring the
 * input validation rules in ValidationPatterns. Extracted from ApplicationDeploymentJob
 * so these pure string checks aren't entangled with deployment orchestration state.
 */
final class DeploymentCommandValidation
{
    public static function validatePathField(string $value, string $fieldName): string
    {
        if (! preg_match(ValidationPatterns::FILE_PATH_PATTERN, $value)) {
            throw new \RuntimeException("Invalid {$fieldName}: contains forbidden characters.");
        }
        if (str_contains($value, '..')) {
            throw new \RuntimeException("Invalid {$fieldName}: path traversal detected.");
        }

        return $value;
    }

    public static function validateShellSafeCommand(string $value, string $fieldName): string
    {
        if (! preg_match(ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN, $value)) {
            throw new \RuntimeException("Invalid {$fieldName}: contains forbidden shell characters.");
        }

        return $value;
    }

    public static function validateContainerName(string $value): string
    {
        if (! preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $value)) {
            throw new \RuntimeException('Invalid container name: contains forbidden characters.');
        }

        return $value;
    }

    public static function sanitizeHealthCheckValue(string $value, string $pattern, string $default): string
    {
        if (preg_match($pattern, $value)) {
            return $value;
        }

        return $default;
    }
}
