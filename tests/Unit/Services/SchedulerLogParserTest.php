<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SchedulerLogParser;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchedulerLogParserTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = storage_path('logs');

        if (! is_dir($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }

        foreach (glob($this->logDir.'/scheduled-*.log') ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->logDir.'/scheduled-*.log') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function writeLog(string $filename, array $lines): void
    {
        File::put($this->logDir.'/'.$filename, implode("\n", $lines));
    }

    #[Test]
    public function parse_log_line_parses_valid_line()
    {
        $parser = new SchedulerLogParser;

        $line = '[2024-01-15 10:30:00] production.INFO: Job executed {"foo":"bar"}';

        $ref = new \ReflectionClass($parser);
        $method = $ref->getMethod('parseLogLine');

        $entry = $method->invoke($parser, $line);

        $this->assertSame('2024-01-15 10:30:00', $entry['timestamp']);
        $this->assertSame('INFO', $entry['level']);
        $this->assertSame('Job executed', $entry['message']);
        $this->assertSame(['foo' => 'bar'], $entry['context']);
    }

    #[Test]
    public function parse_log_line_returns_null_for_invalid_format()
    {
        $parser = new SchedulerLogParser;

        $ref = new \ReflectionClass($parser);
        $method = $ref->getMethod('parseLogLine');

        $this->assertNull($method->invoke($parser, 'invalid line'));
    }

    #[Test]
    public function get_recent_skips_extracts_skip_events()
    {
        $this->writeLog('scheduled-1.log', [
            '[2024-01-15 10:30:00] production.INFO: Skip event {"skip_reason":"rate_limit","team_id":5,"type":"job"}',
            '[2024-01-15 10:31:00] production.INFO: Something else {"foo":"bar"}',
        ]);

        $parser = new SchedulerLogParser;
        $skips = $parser->getRecentSkips();

        $this->assertCount(1, $skips);
        $this->assertSame('rate_limit', $skips[0]['reason']);
        $this->assertSame(5, $skips[0]['team_id']);
        $this->assertSame('job', $skips[0]['type']);
    }

    #[Test]
    public function get_recent_skips_filters_by_team_id()
    {
        $this->writeLog('scheduled-1.log', [
            '[2024-01-15 10:30:00] production.INFO: Skip event {"skip_reason":"rate_limit","team_id":5}',
            '[2024-01-15 10:31:00] production.INFO: Skip event {"skip_reason":"timeout","team_id":7}',
        ]);

        $parser = new SchedulerLogParser;
        $skips = $parser->getRecentSkips(100, 7);

        $this->assertCount(1, $skips);
        $this->assertSame('timeout', $skips[0]['reason']);
        $this->assertSame(7, $skips[0]['team_id']);
    }

    #[Test]
    public function get_recent_runs_extracts_manager_complete_events()
    {
        $this->writeLog('scheduled-1.log', [
            '[2024-01-15 10:30:00] production.INFO: ScheduledJobManager completed {"duration_ms":120,"dispatched":3,"skipped":1}',
            '[2024-01-15 10:31:00] production.INFO: ScheduledJobManager started {"foo":"bar"}',
            '[2024-01-15 10:32:00] production.INFO: Other message {"foo":"bar"}',
        ]);

        $parser = new SchedulerLogParser;
        $runs = $parser->getRecentRuns();

        $this->assertCount(1, $runs);
        $this->assertSame(120, $runs[0]['duration_ms']);
        $this->assertSame(3, $runs[0]['dispatched']);
        $this->assertSame(1, $runs[0]['skipped']);
    }

    #[Test]
    public function get_log_files_returns_only_recent_scheduled_logs()
    {
        $this->writeLog('scheduled-1.log', ['line']);
        $this->writeLog('scheduled-2.log', ['line']);
        $this->writeLog('scheduled-3.log', ['line']);

        File::put($this->logDir.'/laravel.log', 'ignored');

        $parser = new SchedulerLogParser;

        $ref = new \ReflectionClass($parser);
        $method = $ref->getMethod('getLogFiles');

        $files = $method->invoke($parser);

        $this->assertCount(3, $files);
        $this->assertStringContainsString('scheduled-', $files[0]);
    }

    #[Test]
    public function read_last_lines_reads_correct_number_of_lines()
    {
        $parser = new SchedulerLogParser;

        $file = $this->logDir.'/scheduled-test.log';
        $lines = range(1, 100);
        File::put($file, implode("\n", $lines));

        $ref = new \ReflectionClass($parser);
        $method = $ref->getMethod('readLastLines');

        $result = $method->invoke($parser, $file, 10);

        $this->assertSame(range(91, 100), array_map('intval', $result));
    }

    #[Test]
    public function get_recent_skips_limits_results()
    {
        $lines = [];
        for ($i = 1; $i <= 200; $i++) {
            $timestamp = date('Y-m-d H:i:s', strtotime('2024-01-15 10:30:00') + $i);
            $lines[] = "[$timestamp] production.INFO: Skip event {\"skip_reason\":\"r$i\"}";
        }

        $this->writeLog('scheduled-1.log', $lines);

        $parser = new SchedulerLogParser;
        $skips = $parser->getRecentSkips(5);

        $this->assertCount(5, $skips);
        $this->assertSame('r200', $skips[0]['reason']);
    }

    #[Test]
    public function get_recent_runs_limits_results()
    {
        $lines = [];
        for ($i = 1; $i <= 200; $i++) {
            $timestamp = date('Y-m-d H:i:s', strtotime('2024-01-15 10:30:00') + $i);
            $lines[] = "[$timestamp] production.INFO: ScheduledJobManager completed {\"duration_ms\":$i}";
        }

        $this->writeLog('scheduled-1.log', $lines);

        $parser = new SchedulerLogParser;
        $runs = $parser->getRecentRuns(3);

        $this->assertCount(3, $runs);
        $this->assertSame(200, $runs[0]['duration_ms']);
    }
}
