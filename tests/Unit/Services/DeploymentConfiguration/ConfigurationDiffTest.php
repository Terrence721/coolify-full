<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DeploymentConfiguration;

use App\Services\DeploymentConfiguration\ConfigurationDiff;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigurationDiffTest extends TestCase
{
    #[Test]
    public function unchanged_returns_instance_with_no_changes()
    {
        $diff = ConfigurationDiff::unchanged();

        $this->assertFalse($diff->isChanged());
        $this->assertFalse($diff->isLegacyFallback());
        $this->assertSame(0, $diff->count());
        $this->assertSame([], $diff->changes());
        $this->assertFalse($diff->requiresBuild());
        $this->assertFalse($diff->requiresRedeploy());
    }

    #[Test]
    public function legacy_returns_unchanged_when_flag_is_false()
    {
        $diff = ConfigurationDiff::legacy(false);

        $this->assertFalse($diff->isChanged());
        $this->assertFalse($diff->isLegacyFallback());
        $this->assertSame(0, $diff->count());
        $this->assertSame([], $diff->changes());
    }

    #[Test]
    public function legacy_returns_expected_single_change_when_flag_is_true()
    {
        $diff = ConfigurationDiff::legacy(true);

        $this->assertTrue($diff->isChanged());
        $this->assertTrue($diff->isLegacyFallback());
        $this->assertSame(1, $diff->count());

        $change = $diff->changes()[0];

        $this->assertSame('legacy.configuration', $change['key']);
        $this->assertSame('configuration', $change['section']);
        $this->assertSame('Configuration', $change['section_label']);
        $this->assertSame('Configuration', $change['label']);
        $this->assertSame('changed', $change['type']);
        $this->assertSame('build', $change['impact']);
        $this->assertFalse($change['sensitive']);
        $this->assertSame('Previously deployed configuration', $change['old_display_value']);
        $this->assertSame('Current configuration', $change['new_display_value']);
    }

    #[Test]
    public function from_changes_wraps_changes_correctly()
    {
        $changes = [
            [
                'key' => 'x',
                'impact' => 'redeploy',
            ],
            [
                'key' => 'y',
                'impact' => 'build',
            ],
        ];

        $diff = ConfigurationDiff::fromChanges($changes);

        $this->assertTrue($diff->isChanged());
        $this->assertSame(2, $diff->count());
        $this->assertSame($changes, $diff->changes());
    }

    #[Test]
    public function is_changed_returns_true_only_when_changes_exist()
    {
        $diff1 = new ConfigurationDiff([]);
        $diff2 = new ConfigurationDiff([['key' => 'a']]);

        $this->assertFalse($diff1->isChanged());
        $this->assertTrue($diff2->isChanged());
    }

    #[Test]
    public function requires_build_returns_true_when_any_change_has_build_impact()
    {
        $diff = new ConfigurationDiff([
            ['impact' => 'redeploy'],
            ['impact' => 'build'],
        ]);

        $this->assertTrue($diff->requiresBuild());
    }

    #[Test]
    public function requires_build_returns_false_when_no_build_impacts_exist()
    {
        $diff = new ConfigurationDiff([
            ['impact' => 'redeploy'],
            ['impact' => 'redeploy'],
        ]);

        $this->assertFalse($diff->requiresBuild());
    }

    #[Test]
    public function requires_redeploy_is_true_when_changed()
    {
        $diff = new ConfigurationDiff([
            ['impact' => 'redeploy'],
        ]);

        $this->assertTrue($diff->requiresRedeploy());
    }

    #[Test]
    public function requires_redeploy_is_false_when_unchanged()
    {
        $diff = new ConfigurationDiff([]);

        $this->assertFalse($diff->requiresRedeploy());
    }

    #[Test]
    public function to_array_returns_expected_structure()
    {
        $changes = [
            ['key' => 'a', 'impact' => 'build'],
            ['key' => 'b', 'impact' => 'redeploy'],
        ];

        $diff = new ConfigurationDiff($changes, true);

        $array = $diff->toArray();

        $this->assertSame(true, $array['changed']);
        $this->assertSame(2, $array['count']);
        $this->assertSame(true, $array['requires_build']);
        $this->assertSame(true, $array['requires_redeploy']);
        $this->assertSame(true, $array['legacy_fallback']);
        $this->assertSame($changes, $array['changes']);
    }
}
