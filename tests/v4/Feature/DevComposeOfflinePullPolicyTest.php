<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// Found live 2026-07-26 the night before a real interview: cold-starting the dev stack with
// no internet access failed outright - `docker compose up` errored trying to resolve
// registry-1.docker.io for coollabsio/maxio:latest, even though that exact image was already
// cached locally. Root cause: 7 third-party-image services in docker-compose.dev.yml set
// `pull_policy: always`, which forces a registry round-trip on every single `up`, with no
// fallback to the already-cached local image if that check fails. Removed `pull_policy: always`
// from all 7 (autoheal, postgres, redis, vite, mailpit, minio, minio-init); Compose's default
// ("missing" - only pull if not already present locally) is correct for a dev stack that must
// be able to cold-start fully offline. The 3 locally-built images (coolify, soketi,
// testing-host) correctly keep `pull_policy: never` - untouched. See issue #62 (closed).

it('never forces an always-pull on a third-party image in the dev compose override, so cold-starting the stack works fully offline', function () {
    $compose = Yaml::parseFile(base_path('docker-compose.dev.yml'));

    $offenders = [];
    foreach ($compose['services'] as $name => $service) {
        if (($service['pull_policy'] ?? null) === 'always') {
            $offenders[] = $name;
        }
    }

    expect($offenders)->toBe([]);
});
