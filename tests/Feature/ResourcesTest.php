<?php

namespace KaiHempel\Mastodon\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use KaiHempel\Mastodon\Events\MastodonRateLimited;
use KaiHempel\Mastodon\Exceptions\RateLimitException;
use KaiHempel\Mastodon\Exceptions\UnsupportedFeatureException;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Tests\TestCase;

class ResourcesTest extends TestCase
{
    public function test_instance_info_is_cached(): void
    {
        $this->fakeInstance();

        $first = Mastodon::instance('example.social')->info();
        $second = Mastodon::instance('example.social')->info();
        Mastodon::instance('example.social')->info(fresh: true);

        $this->assertSame('4.4.0', $first->version);
        $this->assertSame($first->maxCharacters, $second->maxCharacters);
        Http::assertSentCount(2);
    }

    public function test_oauth_metadata_is_null_before_4_3(): void
    {
        Http::fake([self::INSTANCE.'/.well-known/oauth-authorization-server' => Http::response('Not found', 404)]);

        $this->assertNull(Mastodon::instance('example.social')->oauthMetadata());
    }

    public function test_get_edit_and_delete_status(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v1/statuses/1*' => Http::response($this->fixture('status', ['text' => 'Quelltext'])),
        ]);
        $client = Mastodon::for($this->createAccount());

        $this->assertSame('103270115826048975', $client->statuses()->get('103270115826048975')->id);

        $client->statuses()->edit('103270115826048975', [
            'status' => 'Korrigiert',
            'media_ids' => ['1'],
            'media_attributes' => [['id' => '1', 'description' => 'Alt', 'focus' => '0,0']],
        ]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['status'] === 'Korrigiert' && $r['media_attributes'][0]['description'] === 'Alt');

        $deleted = $client->statuses()->delete('103270115826048975', deleteMedia: true);
        $this->assertSame('Quelltext', $deleted->text);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::INSTANCE.'/api/v1/statuses/103270115826048975?delete_media=true');
    }

    public function test_deletes_are_throttled_locally(): void
    {
        Event::fake([MastodonRateLimited::class]);
        config(['mastodon.throttle.deletes' => ['max' => 2, 'per_seconds' => 1800]]);
        Http::fake([self::INSTANCE.'/api/v1/statuses/*' => Http::response($this->fixture('status'))]);
        $statuses = Mastodon::for($this->createAccount())->statuses();

        $statuses->delete('1');
        $statuses->delete('2');

        try {
            $statuses->delete('3');
            $this->fail('RateLimitException expected');
        } catch (RateLimitException $e) {
            $this->assertSame(1800, $e->retryAfterSeconds());
        }

        Http::assertSentCount(2);
        Event::assertDispatched(MastodonRateLimited::class, fn ($e) => $e->scope === 'deletes');
    }

    public function test_media_uploads_are_throttled_locally(): void
    {
        config(['mastodon.throttle.media_uploads' => ['max' => 1, 'per_seconds' => 1800]]);
        Http::fake([self::INSTANCE.'/api/v2/media' => Http::response($this->fixture('media_processed'))]);
        $media = Mastodon::for($this->createAccount())->media();
        $file = \KaiHempel\Mastodon\Publishing\MediaFile::fromPath(__DIR__.'/../Fixtures/pixel.png');

        $media->upload($file);

        $this->expectException(RateLimitException::class);
        $media->upload($file);
    }

    public function test_update_media(): void
    {
        Http::fake([self::INSTANCE.'/api/v1/media/22348641' => Http::response($this->fixture('media_processed'))]);

        Mastodon::for($this->createAccount())->media()->update('22348641', description: 'Neu', focus: [-0.5, 1]);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['description'] === 'Neu' && $r['focus'] === '-0.5,1');
    }

    public function test_delete_media_requires_4_4(): void
    {
        $this->fakeInstance(['version' => '4.3.2']);

        $this->expectException(UnsupportedFeatureException::class);

        Mastodon::for($this->createAccount())->media()->delete('22348641');
    }

    public function test_delete_media_on_4_4(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v2/instance' => Http::response($this->fixture('instance')),
            self::INSTANCE.'/api/v1/media/22348641' => Http::response([]),
        ]);

        Mastodon::for($this->createAccount())->media()->delete('22348641');

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::INSTANCE.'/api/v1/media/22348641');
    }

    public function test_scheduled_statuses_are_paginated_lazily(): void
    {
        $page1 = [$this->fixture('scheduled_status', ['id' => '3']), $this->fixture('scheduled_status', ['id' => '2'])];
        $page2 = [$this->fixture('scheduled_status', ['id' => '1'])];

        Http::fake([
            self::INSTANCE.'/api/v1/scheduled_statuses?limit=2' => Http::response($page1, 200, [
                'Link' => '<'.self::INSTANCE.'/api/v1/scheduled_statuses?limit=2&max_id=2>; rel="next", <'.self::INSTANCE.'/api/v1/scheduled_statuses?limit=2&min_id=3>; rel="prev"',
            ]),
            self::INSTANCE.'/api/v1/scheduled_statuses?limit=2&max_id=2' => Http::response($page2),
        ]);

        $list = Mastodon::for($this->createAccount())->scheduled()->list(['limit' => 2]);
        Http::assertNothingSent();

        $this->assertSame(['3', '2', '1'], $list->map(fn ($s) => $s->id)->all());
        $this->assertSame('3', $list->first()->id);
    }

    public function test_pagination_does_not_follow_foreign_hosts(): void
    {
        Http::fake([
            self::INSTANCE.'/api/v1/scheduled_statuses' => Http::response([$this->fixture('scheduled_status')], 200, [
                'Link' => '<https://evil.example/steal>; rel="next"',
            ]),
        ]);

        $this->assertCount(1, Mastodon::for($this->createAccount())->scheduled()->list()->all());
        Http::assertSentCount(1);
    }

    public function test_reschedule_and_cancel(): void
    {
        Http::fake([self::INSTANCE.'/api/v1/scheduled_statuses/3221' => Http::response($this->fixture('scheduled_status', ['scheduled_at' => '2026-11-05T10:00:00.000Z']))]);
        $scheduled = Mastodon::for($this->createAccount())->scheduled();

        $result = $scheduled->reschedule('3221', now()->addDay()->setTime(11, 0, 0, 0)->setTimezone('Europe/Berlin'));
        $this->assertSame('2026-11-05T10:00:00+00:00', $result->scheduledAt->toIso8601String());
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['scheduled_at'] === '2026-11-05T11:00:00.000Z');

        $this->assertSame('3221', $scheduled->get('3221')->id);

        $scheduled->cancel('3221');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_apps_verify_and_client_credentials(): void
    {
        Http::fake([
            self::INSTANCE.'/oauth/token' => Http::response($this->fixture('token', ['scope' => 'read'])),
        ]);
        $app = $this->createApp();

        $token = Mastodon::oauth($app)->clientCredentials();

        $this->assertSame(['read'], $token->scopes);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'client_credentials' && $r['scope'] === 'read');
    }
}
