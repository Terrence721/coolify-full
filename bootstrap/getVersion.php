<?php

declare(strict_types=1);

// To prevent github actions from failing
function env()
{
    return null;
}

$version = include 'config/constants.php';
echo $version['coolify']['version'] ?: 'unknown';
