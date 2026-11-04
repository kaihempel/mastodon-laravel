<?php

namespace KaiHempel\Mastodon\Console;

use Illuminate\Console\Command;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Models\MastodonAccount;

class VerifyCommand extends Command
{
    protected $signature = 'mastodon:verify {account? : ID of the account (default: all active accounts)}';

    protected $description = 'Verify the credentials of connected Mastodon accounts';

    public function handle(MastodonManager $manager): int
    {
        $accounts = $this->argument('account') !== null
            ? MastodonAccount::query()->whereKey($this->argument('account'))->get()
            : MastodonAccount::query()->active()->get();

        if ($accounts->isEmpty()) {
            $this->warn('No accounts found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $remote = $manager->for($account)->accounts()->verify();
                $account->forceFill([
                    'acct' => $remote->acct,
                    'username' => $remote->username,
                    'display_name' => $remote->displayName,
                    'url' => $remote->url,
                    'last_verified_at' => now(),
                    'disabled_at' => null,
                ])->save();
                $this->info("OK      #{$account->getKey()} {$account->handle()}");
            } catch (MastodonException $e) {
                $failed++;
                $this->error("FAILED  #{$account->getKey()} {$account->handle()}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
