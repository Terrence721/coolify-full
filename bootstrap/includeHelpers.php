<?php

declare(strict_types=1);

$files = glob(__DIR__.'/helpers/*.php');
foreach ($files as $file) {
    require $file;
}
