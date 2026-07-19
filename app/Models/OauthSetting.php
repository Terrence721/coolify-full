<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property string $provider
 * @property bool $enabled
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $redirect_uri
 * @property string|null $tenant
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $base_url
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereBaseUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereClientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereClientSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereRedirectUri($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereTenant($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthSetting whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class OauthSetting extends Model
{
    use HasFactory;

    protected $fillable = ['provider', 'client_id', 'client_secret', 'redirect_uri', 'tenant', 'base_url', 'enabled'];

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function clientSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => empty($value) ? null : Crypt::decryptString($value),
            set: fn (?string $value) => empty($value) ? null : Crypt::encryptString($value),
        );
    }

    public function couldBeEnabled(): bool
    {
        switch ($this->provider) {
            case 'azure':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->tenant);
            case 'authentik':
            case 'clerk':
                return filled($this->client_id) && filled($this->client_secret) && filled($this->base_url);
            default:
                return filled($this->client_id) && filled($this->client_secret);
        }
    }
}
