<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\UserChangelogRead;
use App\Services\ChangelogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelMarkdown\MarkdownRenderer;
use Tests\TestCase;

class ChangelogServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a fake changelogs directory
        $this->basePath = base_path('changelogs');
        if (! is_dir($this->basePath)) {
            mkdir($this->basePath, 0777, true);
        }

        // Ensure no legacy changelog.json exists
        @unlink(base_path('changelog.json'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Clean up test changelog files
        foreach (glob($this->basePath.'/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    #[Test]
    public function get_available_months_returns_sorted_months()
    {
        file_put_contents($this->basePath.'/2024-01.json', '{}');
        file_put_contents($this->basePath.'/2023-12.json', '{}');
        file_put_contents($this->basePath.'/invalid.json', '{}');

        $service = new ChangelogService;
        $months = $service->getAvailableMonths();

        $this->assertSame(['2024-01', '2023-12'], $months->all());
    }

    #[Test]
    public function get_entries_for_month_returns_empty_for_missing_file()
    {
        $service = new ChangelogService;
        $entries = $service->getEntriesForMonth('2024-01');

        $this->assertInstanceOf(Collection::class, $entries);
        $this->assertCount(0, $entries);
    }

    #[Test]
    public function get_entries_for_month_parses_valid_entries()
    {
        $content = json_encode([
            'entries' => [
                [
                    'tag_name' => 'v1',
                    'title' => 'Test',
                    'content' => 'Hello **world**',
                    'published_at' => now()->subDay()->toISOString(),
                ],
            ],
        ]);

        file_put_contents($this->basePath.'/2024-01.json', $content);

        // Mock MarkdownRenderer
        $renderer = $this->createStub(MarkdownRenderer::class);
        $renderer->method('toHtml')->willReturn('<p>Rendered</p>');
        $this->app->instance(MarkdownRenderer::class, $renderer);

        $service = new ChangelogService;
        $entries = $service->getEntriesForMonth('2024-01');

        $this->assertCount(1, $entries);
        $this->assertSame('v1', $entries[0]->tag_name);
        $this->assertSame('<p class="mb-2 dark:text-neutral-300">Rendered</p>', $entries[0]->content_html);
    }

    #[Test]
    public function get_entries_skips_future_entries()
    {
        $content = json_encode([
            'entries' => [
                [
                    'tag_name' => 'v1',
                    'title' => 'Test',
                    'content' => 'Hello',
                    'published_at' => now()->addDay()->toISOString(),
                ],
            ],
        ]);

        file_put_contents($this->basePath.'/2024-01.json', $content);

        $renderer = $this->createStub(MarkdownRenderer::class);
        $renderer->method('toHtml')->willReturn('<p>Rendered</p>');
        $this->app->instance(MarkdownRenderer::class, $renderer);

        $service = new ChangelogService;
        $entries = $service->getEntriesForMonth('2024-01');

        $this->assertCount(0, $entries);
    }

    #[Test]
    public function validate_entry_data_returns_false_for_missing_fields()
    {
        $service = new ChangelogService;

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('validateEntryData');

        $this->assertFalse($method->invoke($service, ['tag_name' => 'v1']));
        $this->assertFalse($method->invoke($service, ['title' => 'Test']));
        $this->assertFalse($method->invoke($service, ['content' => 'Hello']));
        $this->assertFalse($method->invoke($service, ['published_at' => now()->toISOString()]));
    }

    #[Test]
    public function validate_entry_data_returns_true_for_valid_entry()
    {
        $service = new ChangelogService;

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('validateEntryData');

        $entry = [
            'tag_name' => 'v1',
            'title' => 'Test',
            'content' => 'Hello',
            'published_at' => now()->toISOString(),
        ];

        $this->assertTrue($method->invoke($service, $entry));
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function get_entries_for_user_marks_read_status()
    {
        $user = User::factory()->create();

        // Real read record instead of mocking the static UserChangelogRead call
        UserChangelogRead::create([
            'user_id' => $user->id,
            'release_tag' => 'v1',
            'read_at' => now(),
        ]);

        // Fake entries
        $service = $this->getMockBuilder(ChangelogService::class)
            ->onlyMethods(['getEntries'])
            ->getMock();

        $service->method('getEntries')->willReturn(collect([
            (object) ['tag_name' => 'v1', 'published_at' => now()],
            (object) ['tag_name' => 'v2', 'published_at' => now()],
        ]));

        $entries = $service->getEntriesForUser($user);

        // Unread entries sort first
        $this->assertSame('v2', $entries[0]->tag_name);
        $this->assertFalse($entries[0]->is_read);

        $this->assertSame('v1', $entries[1]->tag_name);
        $this->assertTrue($entries[1]->is_read);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function get_unread_count_for_user_counts_correctly()
    {
        $user = User::factory()->create();

        UserChangelogRead::create([
            'user_id' => $user->id,
            'release_tag' => 'v1',
            'read_at' => now(),
        ]);

        $service = $this->getMockBuilder(ChangelogService::class)
            ->onlyMethods(['getEntries'])
            ->getMock();

        $service->method('getEntries')->willReturn(collect([
            (object) ['tag_name' => 'v1'],
            (object) ['tag_name' => 'v2'],
            (object) ['tag_name' => 'v3'],
        ]));

        $count = $service->getUnreadCountForUser($user);

        $this->assertSame(2, $count);
    }

    #[Test]
    public function apply_custom_styling_modifies_html()
    {
        $service = new ChangelogService;

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('applyCustomStyling');

        $html = '<h1>Title</h1><p>Text</p><ul><li>Item</li></ul>';

        $styled = $method->invoke($service, $html);

        $this->assertStringContainsString('class="text-xl font-bold', $styled);
        $this->assertStringContainsString('class="mb-2 dark:text-neutral-300"', $styled);
        $this->assertStringContainsString('class="mb-2 ml-4 list-disc"', $styled);
    }

    #[Test]
    public function clear_all_read_status_truncates_and_clears_cache()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        UserChangelogRead::create(['user_id' => $user->id, 'release_tag' => 'v1', 'read_at' => now()]);
        UserChangelogRead::create(['user_id' => $user->id, 'release_tag' => 'v2', 'read_at' => now()]);
        UserChangelogRead::create(['user_id' => $otherUser->id, 'release_tag' => 'v1', 'read_at' => now()]);

        Cache::put('user_unread_changelog_count_'.$user->id, 1, now()->addHour());
        Cache::put('user_unread_changelog_count_'.$otherUser->id, 1, now()->addHour());

        $service = new ChangelogService;
        $result = $service->clearAllReadStatus();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Successfully cleared 3 read status records', $result['message']);
        $this->assertSame(0, UserChangelogRead::count());
        $this->assertNull(Cache::get('user_unread_changelog_count_'.$user->id));
        $this->assertNull(Cache::get('user_unread_changelog_count_'.$otherUser->id));
    }
}
