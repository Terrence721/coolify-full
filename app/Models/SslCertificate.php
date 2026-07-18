<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ssl_certificate
 * @property string $ssl_private_key
 * @property string|null $configuration_dir
 * @property string|null $mount_path
 * @property string|null $resource_type
 * @property int|null $resource_id
 * @property int $server_id
 * @property string $common_name
 * @property array<array-key, mixed>|null $subject_alternative_names
 * @property Carbon $valid_until
 * @property bool $is_ca_certificate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereCommonName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereConfigurationDir($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereIsCaCertificate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereMountPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereResourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereResourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereSslCertificate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereSslPrivateKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereSubjectAlternativeNames($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SslCertificate whereValidUntil($value)
 *
 * @mixin \Eloquent
 */
class SslCertificate extends Model
{
    protected $fillable = [
        'ssl_certificate',
        'ssl_private_key',
        'configuration_dir',
        'mount_path',
        'resource_type',
        'resource_id',
        'server_id',
        'common_name',
        'subject_alternative_names',
        'valid_until',
        'is_ca_certificate',
    ];

    protected $casts = [
        'ssl_certificate' => 'encrypted',
        'ssl_private_key' => 'encrypted',
        'subject_alternative_names' => 'array',
        'valid_until' => 'datetime',
    ];

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
