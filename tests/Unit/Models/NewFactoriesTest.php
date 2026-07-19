<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\LocalFileVolume;
use App\Models\OauthSetting;
use App\Models\S3Storage;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the 5 factories added to close out PHPStan's
 * HasFactory<TFactory> findings (LocalFileVolume/OauthSetting/S3Storage/
 * ServiceApplication/ServiceDatabase previously had none). Phase 60 already
 * demonstrated that an unexercised factory can silently reference a dropped
 * column for over a year without anyone noticing — these tests exist purely
 * to prove each new factory actually persists a valid row, not to test any
 * model behavior.
 */
class NewFactoriesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function local_file_volume_factory_creates_a_valid_record()
    {
        $volume = LocalFileVolume::factory()->create();

        $this->assertDatabaseHas('local_file_volumes', ['id' => $volume->id]);
        $this->assertNotEmpty($volume->fs_path);
        $this->assertNotEmpty($volume->mount_path);
        $this->assertNotNull($volume->resource);
    }

    #[Test]
    public function oauth_setting_factory_creates_a_valid_record()
    {
        $setting = OauthSetting::factory()->create();

        $this->assertDatabaseHas('oauth_settings', ['id' => $setting->id]);
        $this->assertNotEmpty($setting->provider);
    }

    #[Test]
    public function s3_storage_factory_creates_a_valid_record()
    {
        $storage = S3Storage::factory()->create();

        $this->assertDatabaseHas('s3_storages', ['id' => $storage->id]);
        $this->assertNotEmpty($storage->name);
        $this->assertNotEmpty($storage->key);
        $this->assertNotEmpty($storage->secret);
        $this->assertNotEmpty($storage->bucket);
    }

    #[Test]
    public function service_application_factory_creates_a_valid_record()
    {
        $serviceApplication = ServiceApplication::factory()->create();

        $this->assertDatabaseHas('service_applications', ['id' => $serviceApplication->id]);
        $this->assertNotEmpty($serviceApplication->name);
        $this->assertNotNull($serviceApplication->service);
    }

    #[Test]
    public function service_database_factory_creates_a_valid_record()
    {
        $serviceDatabase = ServiceDatabase::factory()->create();

        $this->assertDatabaseHas('service_databases', ['id' => $serviceDatabase->id]);
        $this->assertNotEmpty($serviceDatabase->name);
        $this->assertNotNull($serviceDatabase->service);
    }
}
