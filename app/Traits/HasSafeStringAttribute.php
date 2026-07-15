<?php

declare(strict_types=1);

namespace App\Traits;

trait HasSafeStringAttribute
{
    /**
     * Set the name attribute - strip any HTML tags for safety
     */
    public function setNameAttribute(string $value): void
    {
        $sanitized = strip_tags($value);
        $this->attributes['name'] = $this->customizeName($sanitized);
    }

    protected function customizeName(string $value): string
    {
        return $value; // Default: no customization
    }

    public function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = is_null($value) ? null : strip_tags($value);
    }
}
