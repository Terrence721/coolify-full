<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Visus\Cuid2\Cuid2;

/**
 * @property string|null $uuid
 */
abstract class BaseModel extends Model
{
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            // Generate a UUID if one isn't set
            if (! $model->uuid) {
                $model->uuid = (string) new Cuid2;
            }
        });
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function sanitizedName(): Attribute
    {
        return new Attribute(
            get: fn () => sanitize_string($this->getRawOriginal('name')),
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function image(): Attribute
    {
        return new Attribute(
            get: fn () => sanitize_string($this->getRawOriginal('image')),
        );
    }
}
