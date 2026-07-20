<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ConfigurationRepository;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigurationRepositoryTest extends TestCase
{
    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new Repository([]);
    }

    private function makeSettings(array $overrides = []): object
    {
        return (object) array_merge([
            'resend_enabled' => false,
            'resend_api_key' => null,
            'smtp_enabled' => false,
            'smtp_from_address' => 'from@example.com',
            'smtp_from_name' => 'Example',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_username' => 'user',
            'smtp_password' => 'pass',
            'smtp_timeout' => 30,
            'smtp_encryption' => 'tls',
        ], $overrides);
    }

    #[Test]
    public function it_sets_resend_mail_configuration()
    {
        $settings = $this->makeSettings([
            'resend_enabled' => true,
            'resend_api_key' => 'resend-key',
        ]);

        $repo = new ConfigurationRepository($this->config);
        $repo->updateMailConfig($settings);

        $this->assertSame('resend', $this->config->get('mail.default'));
        $this->assertSame('from@example.com', $this->config->get('mail.from.address'));
        $this->assertSame('Example', $this->config->get('mail.from.name'));
        $this->assertSame('resend-key', $this->config->get('resend.api_key'));
    }

    #[Test]
    public function it_sets_smtp_mail_configuration()
    {
        $settings = $this->makeSettings([
            'smtp_enabled' => true,
            'smtp_encryption' => 'tls',
        ]);

        $repo = new ConfigurationRepository($this->config);
        $repo->updateMailConfig($settings);

        $this->assertSame('smtp', $this->config->get('mail.default'));
        $this->assertSame('from@example.com', $this->config->get('mail.from.address'));
        $this->assertSame('Example', $this->config->get('mail.from.name'));

        $smtp = $this->config->get('mail.mailers.smtp');

        $this->assertSame('smtp', $smtp['transport']);
        $this->assertSame('smtp.example.com', $smtp['host']);
        $this->assertSame(587, $smtp['port']);
        $this->assertSame('tls', $smtp['encryption']);
        $this->assertSame('user', $smtp['username']);
        $this->assertSame('pass', $smtp['password']);
        $this->assertSame(30, $smtp['timeout']);
        $this->assertSame('', $smtp['auto_tls']);
    }

    #[Test]
    public function smtp_encryption_starttls_maps_to_null()
    {
        $settings = $this->makeSettings([
            'smtp_enabled' => true,
            'smtp_encryption' => 'starttls',
        ]);

        $repo = new ConfigurationRepository($this->config);
        $repo->updateMailConfig($settings);

        $smtp = $this->config->get('mail.mailers.smtp');
        $this->assertNull($smtp['encryption']);
    }

    #[Test]
    public function smtp_encryption_none_maps_to_null_and_disables_auto_tls()
    {
        $settings = $this->makeSettings([
            'smtp_enabled' => true,
            'smtp_encryption' => 'none',
        ]);

        $repo = new ConfigurationRepository($this->config);
        $repo->updateMailConfig($settings);

        $smtp = $this->config->get('mail.mailers.smtp');

        $this->assertNull($smtp['encryption']);
        $this->assertSame('0', $smtp['auto_tls']);
    }

    #[Test]
    public function smtp_encryption_unknown_defaults_to_null()
    {
        $settings = $this->makeSettings([
            'smtp_enabled' => true,
            'smtp_encryption' => 'weird',
        ]);

        $repo = new ConfigurationRepository($this->config);
        $repo->updateMailConfig($settings);

        $smtp = $this->config->get('mail.mailers.smtp');
        $this->assertNull($smtp['encryption']);
    }

    #[Test]
    public function smtp_encryption_null_does_not_crash_and_defaults_to_null_encryption()
    {
        // Real bug found via manual smoke-test QA: InstanceSettings/EmailNotificationSettings'
        // smtp_encryption column is nullable, and smtp_enabled can be true with smtp_encryption
        // never set (e.g. saved before the field was filled in). strtolower(null) is a fatal
        // TypeError under strict_types=1 - this crashed every outgoing transactional email
        // (Fortify verification codes, password resets, etc.) whenever that combination occurred.
        $settings = $this->makeSettings([
            'smtp_enabled' => true,
            'smtp_encryption' => null,
        ]);

        $repo = new ConfigurationRepository($this->config);
        $repo->updateMailConfig($settings);

        $smtp = $this->config->get('mail.mailers.smtp');
        $this->assertNull($smtp['encryption']);
    }

    #[Test]
    public function disable_ssh_mux_sets_config_value()
    {
        $repo = new ConfigurationRepository($this->config);
        $repo->disableSshMux();

        $this->assertFalse($this->config->get('constants.ssh.mux_enabled'));
    }
}
