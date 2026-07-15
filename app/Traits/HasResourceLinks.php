<?php

declare(strict_types=1);

namespace App\Traits;

use Spatie\Url\Url;

/**
 * Shared link()/taskLink() URL builders for Application and Service.
 * Extracted from both models, which were structurally identical and
 * differed in exactly one axis: the route-name prefix and the route
 * param key ("application_uuid" vs "service_uuid"). Both reduce to the
 * resourceTypeSlug() hook each consuming model implements.
 */
trait HasResourceLinks
{
    abstract protected function resourceTypeSlug(): string;

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            $slug = $this->resourceTypeSlug();

            return route("project.{$slug}.configuration", [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                "{$slug}_uuid" => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function taskLink(string $task_uuid)
    {
        if (data_get($this, 'environment.project.uuid')) {
            $slug = $this->resourceTypeSlug();

            $route = route("project.{$slug}.scheduled-tasks", [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_uuid' => data_get($this, 'environment.uuid'),
                "{$slug}_uuid" => data_get($this, 'uuid'),
                'task_uuid' => $task_uuid,
            ]);
            $settings = instanceSettings();
            if (data_get($settings, 'fqdn')) {
                $url = Url::fromString($route);
                $url = $url->withPort(null);
                $fqdn = data_get($settings, 'fqdn');
                $fqdn = str_replace(['http://', 'https://'], '', $fqdn);
                $url = $url->withHost($fqdn);

                return $url->__toString();
            }

            return $route;
        }

        return null;
    }
}
