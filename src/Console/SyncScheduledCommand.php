<?php

namespace KaiHempel\Mastodon\Console;

use Illuminate\Console\Command;
use KaiHempel\Mastodon\Jobs\SyncScheduledStatusesJob;

class SyncScheduledCommand extends Command
{
    protected $signature = 'mastodon:scheduled:sync {account? : ID of the account (default: all)} {--queue : Dispatch to the queue instead of running now}';

    protected $description = 'Synchronize locally tracked scheduled posts with the server';

    public function handle(): int
    {
        $job = new SyncScheduledStatusesJob($this->argument('account'));

        if ($this->option('queue')) {
            dispatch($job);
            $this->info('Synchronization queued.');
        } else {
            dispatch_sync($job);
            $this->info('Scheduled posts synchronized.');
        }

        return self::SUCCESS;
    }
}
