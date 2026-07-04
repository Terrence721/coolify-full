<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Seeder extends Command
{
    protected $signature = 'start:seeder';

    protected $description = 'Start Seeder';

    public function handle(): void
    {
        if (config('constants.seeder.is_seeder_enabled')) {
            $this->info('Seeder is enabled on this server.');
            $this->call('db:seed', ['--class' => 'ProductionSeeder', '--force' => true]);
            exit(0);
        } else {
            $this->info('Seeder is disabled on this server.');
            exit(0);
        }
    }
}
