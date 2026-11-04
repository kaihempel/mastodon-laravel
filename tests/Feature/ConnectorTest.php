<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use KaiHempel\Mastodon\Events\MastodonAccountDisconnected;
use KaiHempel\Mastodon\Events\MastodonRateLimited;
use KaiHempel\Mastodon\Exceptions\AuthenticationException;
use KaiHempel\Mastodon\Exceptions\AuthorizationException;
use KaiHempel\Mastodon\Exceptions\ConnectionException;
use KaiHempel\Mastodon\Exceptions\GoneException;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\Exceptions\NotFoundException;
use KaiHempel\Mastodon\Exceptions\RateLimitException;
use KaiHempel\Mastodon\Exceptions\ServerException;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Support\RateLimitStore;
use KaiHempel\Mastodon\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConnectorTest extends TestCase
{
    private const VERIFY = self::INSTANCE.'/api/v1/accounts/verify_credentials';

    public static function errorResponses(): array
    {
        return [
            '401' => [401, AuthenticationException::class],
            '403' => [403, AuthorizationException::class],
            '404' => [404, NotFoundException::class],
            '410' => [410, GoneException::class],
            '422' => [422, ValidationException::class],
            '400' => [400, MastodonException::class],
        ];
    }

    /** @param class-string<MastodonException> $class */
    #[DataProvider('errorResponses')]
    public function test_error_responses_are_mapped(int $status, string $class): void
    {
        Http::fake([self::VERIFY => Http::response(['error' => 'Something went wrong'], $status)]);
        $account = $this->createAccount();

        try {
            Mastodon::for($account)->accounts()->verify();
            $this->fail("{$class} expected");
        } catch (MastodonException $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame('Something went wrong', $e->serverMessage);
            $this->assertFalse($e->isRetryable());
        }

        Http::assertSentCount(1);
    }

    public function test_unauthorized_disables_account_and_fires_event(): void
    {
        Event::fake([MastodonAccountDisconnected::class]);
        Http::fake([self::VERIFY => Http::response(['error' => 'The access token is invalid'], 401)]);
        $account = $this->createAccount();

        try {
            Mastodon::for($account)->accounts()->verify();
        } catch (AuthenticationException) {
        }

        $this->assertNotNull($account->fresh()->disabled_at);
        Event::assertDispatched(MastodonAccountDisconnected::class, fn ($e) => $e->reason === 'unauthorized');
    }

    public function test_server_errors_are_retried_with_backoff(): void
    {
        Http::fake([self::VERIFY => Http::sequence()
            ->push(['error' => 'Remote data could not be fetched'], 503)
            ->push(['error' => 'oops'], 500)
            ->push($this->fixture('account'))]);

        $account = Mastodon::for($this->createAccount())->accounts()->verify();

        $this->assertSame('14715', $account->id);
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::usleep(10_000), Sleep::usleep(20_000)]);
    }

    public function test_server_errors_give_up_after_configured_retries(): void
    {
        Http::fake([self::VERIFY => Http::response(['error' => 'down'], 503)]);

        $this->expectException(ServerException::class);

        try {
            Mastodon::for($this->createAccount())->accounts()->verify();
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_connection_errors_are_retried_and_mapped(): void
    {
        Http::fake([self::VERIFY => Http::failedConnection()]);

        try {
            Mastodon::for($this->createAccount())->accounts()->verify();
            $this->fail('ConnectionException expected');
        } catch (ConnectionException $e) {
            $this->assertTrue($e->isRetryable());
        }

        Http::assertSentCount(3);
    }

    public function test_rate_limit_waits_until_reset_and_retries(): void
    {
        Http::fake([self::VERIFY => Http::sequence()
            ->push(['error' => 'Too many requests'], 429, [
                'X-RateLimit-Limit' => '300',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => now()->addSeconds(5)->toIso8601String(),
            ])
            ->push($this->fixture('account'), 200, ['X-RateLimit-Limit' => '300', 'X-RateLimit-Remaining' => '299'])]);

        Mastodon::for($this->createAccount())->accounts()->verify();

        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 5);
    }

    public function test_long_rate_limit_throws_and_fires_event(): void
    {
        Event::fake([MastodonRateLimited::class]);
        $reset = now()->addMinutes(10)->startOfSecond();
        Http::fake([self::VERIFY => Http::response(['error' => 'Too many requests'], 429, [
            'X-RateLimit-Limit' => '300',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => $reset->toIso8601String(),
        ])]);
        $account = $this->createAccount();

        try {
            Mastodon::for($account)->accounts()->verify();
            $this->fail('RateLimitException expected');
        } catch (RateLimitException $e) {
            $this->assertTrue($reset->equalTo($e->resetAt));
            $this->assertSame(600, $e->retryAfterSeconds());
        }

        Http::assertSentCount(1);
        Event::assertDispatched(MastodonRateLimited::class, fn ($e) => $e->key === 'account:'.$account->id && $e->scope === 'server');
    }

    public function test_rate_limit_state_is_stored_and_checked_before_requests(): void
    {
        Http::fake([self::VERIFY => Http::response($this->fixture('account'), 200, [
            'X-RateLimit-Limit' => '300',
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => now()->addMinutes(3)->toIso8601String(),
        ])]);
        $account = $this->createAccount();
        $client = Mastodon::for($account);

        $client->accounts()->verify();

        $state = app(RateLimitStore::class)->get('account:'.$account->id);
        $this->assertSame(300, $state->limit);
        $this->assertSame(0, $state->remaining);

        $this->expectException(RateLimitException::class);

        try {
            $client->accounts()->verify();
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_deprecation_header_is_logged_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Http::fake([self::VERIFY => Http::response($this->fixture('account'), 200, ['Deprecation' => '@1735689600'])]);

        Mastodon::for($this->createAccount())->accounts()->verify();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'deprecated')
            && $context['path'] === '/api/v1/accounts/verify_credentials');
    }

    public function test_requests_send_token_user_agent_and_accept_header(): void
    {
        Http::fake([self::VERIFY => Http::response($this->fixture('account'))]);

        Mastodon::for($this->createAccount())->accounts()->verify();

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer user-token')
            && $r->hasHeader('Accept', 'application/json')
            && str_contains($r->header('User-Agent')[0], 'mastodon-laravel'));
    }
}
