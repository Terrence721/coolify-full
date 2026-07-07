<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DeploymentConfiguration;

use App\Services\DeploymentConfiguration\ConfigurationDiffer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConfigurationDifferTest extends TestCase
{
    #[Test]
    public function it_reports_no_changes_when_snapshots_are_identical()
    {
        $snapshot = [
            'sections' => [
                'build' => [
                    'label' => 'Build',
                    'items' => [
                        [
                            'key' => 'pack',
                            'label' => 'Pack',
                            'compare_value' => 'node',
                            'display_value' => 'node',
                            'display_full' => 'node',
                            'impact' => 'build',
                            'sensitive' => false,
                        ],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($snapshot, $snapshot);

        $this->assertFalse($diff->isChanged());
        $this->assertSame([], $diff->changes());
    }

    #[Test]
    public function it_detects_added_items()
    {
        $previous = [
            'sections' => [
                'runtime' => [
                    'label' => 'Runtime',
                    'items' => [],
                ],
            ],
        ];

        $current = [
            'sections' => [
                'runtime' => [
                    'label' => 'Runtime',
                    'items' => [
                        [
                            'key' => 'start_command',
                            'label' => 'Start command',
                            'compare_value' => 'php artisan serve',
                            'display_value' => 'php artisan serve',
                            'display_full' => 'php artisan serve',
                            'impact' => 'redeploy',
                            'sensitive' => false,
                        ],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($previous, $current);

        $this->assertTrue($diff->isChanged());
        $this->assertSame(1, $diff->count());

        $change = $diff->changes()[0];
        $this->assertSame('added', $change['type']);
        $this->assertSame('runtime.start_command', $change['key']);
        $this->assertSame('php artisan serve', $change['new_display_value']);
    }

    #[Test]
    public function it_detects_removed_items()
    {
        $previous = [
            'sections' => [
                'source' => [
                    'label' => 'Source',
                    'items' => [
                        [
                            'key' => 'git_branch',
                            'label' => 'Branch',
                            'compare_value' => 'main',
                            'display_value' => 'main',
                            'display_full' => 'main',
                            'impact' => 'build',
                            'sensitive' => false,
                        ],
                    ],
                ],
            ],
        ];

        $current = [
            'sections' => [
                'source' => [
                    'label' => 'Source',
                    'items' => [],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($previous, $current);

        $this->assertTrue($diff->isChanged());
        $this->assertSame(1, $diff->count());

        $change = $diff->changes()[0];
        $this->assertSame('removed', $change['type']);
        $this->assertSame('source.git_branch', $change['key']);
        $this->assertSame('main', $change['old_display_value']);
    }

    #[Test]
    public function it_detects_changed_items()
    {
        $previous = [
            'sections' => [
                'build' => [
                    'label' => 'Build',
                    'items' => [
                        [
                            'key' => 'pack',
                            'label' => 'Pack',
                            'compare_value' => 'node',
                            'display_value' => 'node',
                            'display_full' => 'node',
                            'impact' => 'build',
                            'sensitive' => false,
                        ],
                    ],
                ],
            ],
        ];

        $current = [
            'sections' => [
                'build' => [
                    'label' => 'Build',
                    'items' => [
                        [
                            'key' => 'pack',
                            'label' => 'Pack',
                            'compare_value' => 'php',
                            'display_value' => 'php',
                            'display_full' => 'php',
                            'impact' => 'build',
                            'sensitive' => false,
                        ],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($previous, $current);

        $this->assertTrue($diff->isChanged());
        $change = $diff->changes()[0];

        $this->assertSame('changed', $change['type']);
        $this->assertSame('node', $change['old_display_value']);
        $this->assertSame('php', $change['new_display_value']);
    }

    #[Test]
    public function sensitive_values_are_masked()
    {
        $previous = [
            'sections' => [
                'environment' => [
                    'label' => 'Environment',
                    'items' => [
                        [
                            'key' => 'API_KEY',
                            'label' => 'API_KEY',
                            'compare_value' => 'hash1',
                            'display_value' => '••••••••',
                            'impact' => 'redeploy',
                            'sensitive' => true,
                        ],
                    ],
                ],
            ],
        ];

        $current = [
            'sections' => [
                'environment' => [
                    'label' => 'Environment',
                    'items' => [
                        [
                            'key' => 'API_KEY',
                            'label' => 'API_KEY',
                            'compare_value' => 'hash2',
                            'display_value' => '••••••••',
                            'impact' => 'redeploy',
                            'sensitive' => true,
                        ],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($previous, $current);

        $change = $diff->changes()[0];

        $this->assertSame('changed', $change['type']);
        $this->assertSame('••••••••', $change['old_display_value']);
        $this->assertSame('••••••••', $change['new_display_value']);
        $this->assertSame('Changed', $change['display_summary']);
        $this->assertNull($change['old_full_value']);
        $this->assertNull($change['new_full_value']);
    }

    #[Test]
    public function line_diff_mode_extracts_only_changed_lines()
    {
        $previous = [
            'sections' => [
                'domains' => [
                    'label' => 'Domains',
                    'items' => [
                        [
                            'key' => 'custom_labels',
                            'label' => 'Labels',
                            'compare_value' => 'x',
                            'display_value' => 'old',
                            'display_full' => "a\nb\nc\nd",
                            'impact' => 'redeploy',
                            'sensitive' => false,
                            'diff_mode' => 'lines',
                        ],
                    ],
                ],
            ],
        ];

        $current = [
            'sections' => [
                'domains' => [
                    'label' => 'Domains',
                    'items' => [
                        [
                            'key' => 'custom_labels',
                            'label' => 'Labels',
                            'compare_value' => 'y',
                            'display_value' => 'new',
                            'display_full' => "a\nb\nX\nY",
                            'impact' => 'redeploy',
                            'sensitive' => false,
                            'diff_mode' => 'lines',
                        ],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($previous, $current);

        $change = $diff->changes()[0];

        // Only the lines that actually differ ("c\nd" / "X\nY") are surfaced,
        // not the shared "a\nb" lines both versions have in common.
        $this->assertSame("c\nd", $change['old_display_value']);
        $this->assertSame("X\nY", $change['new_display_value']);
        $this->assertNotNull($change['old_full_value']);
        $this->assertNotNull($change['new_full_value']);
        $this->assertTrue($change['expandable']);
    }

    #[Test]
    public function ignored_keys_are_not_reported()
    {
        $previous = [
            'sections' => [
                'build' => [
                    'label' => 'Build',
                    'items' => [
                        [
                            'key' => 'docker_compose',
                            'compare_value' => 'old',
                            'display_value' => 'old',
                        ],
                    ],
                ],
            ],
        ];

        $current = [
            'sections' => [
                'build' => [
                    'label' => 'Build',
                    'items' => [
                        [
                            'key' => 'docker_compose',
                            'compare_value' => 'new',
                            'display_value' => 'new',
                        ],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;
        $diff = $differ->diff($previous, $current);

        $this->assertFalse($diff->isChanged());
        $this->assertSame([], $diff->changes());
    }

    #[Test]
    public function flatten_items_expands_sections_correctly()
    {
        $snapshot = [
            'sections' => [
                'runtime' => [
                    'label' => 'Runtime',
                    'items' => [
                        ['key' => 'start', 'label' => 'Start', 'compare_value' => 'x'],
                        ['key' => 'port', 'label' => 'Port', 'compare_value' => '8080'],
                    ],
                ],
            ],
        ];

        $differ = new ConfigurationDiffer;

        $ref = new \ReflectionClass($differ);
        $method = $ref->getMethod('flattenItems');
        $method->setAccessible(true);

        $result = $method->invoke($differ, $snapshot);

        $this->assertArrayHasKey('runtime.start', $result);
        $this->assertArrayHasKey('runtime.port', $result);

        $this->assertSame('Runtime', $result['runtime.start']['section_label']);
        $this->assertSame('runtime', $result['runtime.start']['section']);
    }

    #[Test]
    public function changed_lines_returns_expected_removed_and_added()
    {
        $differ = new ConfigurationDiffer;

        $ref = new \ReflectionClass($differ);
        $method = $ref->getMethod('changedLines');
        $method->setAccessible(true);

        [$removed, $added] = $method->invoke($differ, "a\nb\nc", "a\nb\nD");

        $this->assertSame('c', $removed);
        $this->assertSame('D', $added);
    }

    #[Test]
    public function text_lines_splits_and_trims_correctly()
    {
        $differ = new ConfigurationDiffer;

        $ref = new \ReflectionClass($differ);
        $method = $ref->getMethod('textLines');
        $method->setAccessible(true);

        $result = $method->invoke($differ, " a \n\n b\nc ");

        // Leading indentation is preserved (meaningful for YAML/Compose),
        // only trailing whitespace is stripped, and blank lines are dropped.
        $this->assertSame([' a', ' b', 'c'], $result);
    }
}
