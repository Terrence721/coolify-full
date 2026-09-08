#!/usr/bin/env bash
#
# Transparent wrapper that runs `php` inside the coolify container instead of
# on the host - the host's PHP is 8.5 (present only for editor-extension
# detection), while the app targets 8.4, so running artisan/pint/phpstan
# directly on the host fails in confusing ways (missing extensions, undefined
# functions) that look like a broken repo rather than a version mismatch.
#
# Point IDE-extension "PHP path" settings at this script instead of a bare
# `php` binary - e.g. VSCode's georgykurian.laravel-ide-helper extension's
# `helper.phpPath` setting, which only accepts a single executable path, not
# a full command template the way LaravelExtraIntellisense.phpCommand does.
cd "$(dirname "$0")/.."
exec docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php "$@"
