<?php

declare(strict_types=1);

// Found via a real deployment smoke test (2026-07-23): convertDockerRunToCompose() passed its
// nullable $custom_docker_run_options parameter straight into preg_match_all()'s $subject
// argument, which requires a real string under strict_types=1 - a TypeError for any application
// that has never set custom Docker run options (the overwhelming majority). generate_compose_file()
// calls this from 11 different places across the deployment job (nixpacks, dockerfile,
// dockercompose, PR previews...), so this crashed nearly every real deployment's happy path.

it('does not crash when custom_docker_run_options is null', function () {
    $result = convertDockerRunToCompose(null);

    expect($result)->toBe([]);
});

it('still parses real custom docker run options correctly', function () {
    $result = convertDockerRunToCompose('--cap-add SYS_ADMIN --privileged');

    expect($result)->toHaveKey('cap_add');
    expect($result)->toHaveKey('privileged');
});
