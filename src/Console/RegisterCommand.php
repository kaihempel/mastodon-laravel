<?php

namespace KaiHempel\Mastodon\Console;

use Illuminate\Console\Command;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\MastodonManager;
use KaiHempel\Mastodon\Support\Scopes;

class RegisterCommand extends Command
{
    protected $signature = 'mastodon:register
        {instance : Host name or URL of the instance}
        {--redirect-uri= : Redirect URI (default: mastodon.redirect_uri or the package route)}';

    protected $description = 'Register the OAuth application at a Mastodon instance (if not registered yet)';

    public function handle(MastodonManager $manager): int
    {
        try {
            $instance = $manager->normalizeInstance((string) $this->argument('instance'));
            $flow = $manager->authorization();
            $redirectUri = $this->option('redirect-uri') ?: $flow->redirectUri();
            $metadata = $manager->instance($instance)->oauthMetadata();
            $legacy = $metadata === null;
            $scopes = Scopes::parse($manager->config($legacy || ! $metadata->supportsScope('profile') ? 'fallback_scopes' : 'scopes'));

            $app = $flow->findOrRegisterApp($instance, $redirectUri, $scopes, $legacy);
        } catch (MastodonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Application for {$app->instance_url} is registered (id {$app->getKey()}).");
        $this->line('Redirect URI: '.$app->redirect_uri);
        $this->line('Scopes: '.implode(' ', $app->scopes ?? []));

        return self::SUCCESS;
    }
}
