<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Data\Account;
use KaiHempel\Mastodon\Data\AppRegistration;
use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Data\MediaAttachment;
use KaiHempel\Mastodon\Data\OAuthMetadata;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Data\Token;
use PHPUnit\Framework\TestCase;

class DataMappingTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/../Fixtures/{$name}.json"), true);
    }

    public function test_instance_info(): void
    {
        $info = InstanceInfo::fromArray($this->fixture('instance'));

        $this->assertSame('example.social', $info->domain);
        $this->assertSame('4.4.0', $info->version);
        $this->assertSame(6, $info->apiVersion());
        $this->assertSame(500, $info->maxCharacters);
        $this->assertSame(4, $info->maxMediaAttachments);
        $this->assertSame(23, $info->charactersReservedPerUrl);
        $this->assertSame(1500, $info->descriptionLimit);
        $this->assertSame(16777216, $info->imageSizeLimit);
        $this->assertSame(103809024, $info->videoSizeLimit);
        $this->assertSame(120, $info->videoFrameRateLimit);
        $this->assertSame(4, $info->pollMaxOptions);
        $this->assertSame(50, $info->pollMaxCharactersPerOption);
        $this->assertSame(300, $info->pollMinExpiration);
        $this->assertSame(2629746, $info->pollMaxExpiration);
        $this->assertTrue($info->isMimeTypeSupported('image/PNG'));
        $this->assertFalse($info->isMimeTypeSupported('image/tiff'));
        $this->assertTrue($info->supportsMediaDeletion());
        $this->assertTrue($info->supportsProfileScope());
    }

    public function test_instance_versions_and_fallbacks(): void
    {
        $info = InstanceInfo::fromArray(['version' => '4.2.10+glitch', 'configuration' => ['statuses' => ['max_characters' => 5000]]]);

        $this->assertSame('4.2.10', $info->semanticVersion());
        $this->assertFalse($info->supports('4.3.0'));
        $this->assertTrue($info->supports('4.2.0'));
        $this->assertSame(0, $info->apiVersion());
        $this->assertSame(5000, $info->maxCharacters);
        $this->assertSame(23, $info->charactersReservedPerUrl);
        $this->assertNull($info->imageSizeLimit);
        $this->assertTrue($info->isMimeTypeSupported('image/tiff'), 'Without a list every type is accepted.');

        $this->assertSame('0.0.0', InstanceInfo::fromArray(['version' => 'unknown'])->semanticVersion());
        $this->assertTrue(InstanceInfo::fromArray(['version' => '4.5.0-beta.1'])->supports('4.5.0'));
    }

    public function test_status_keeps_ids_as_strings(): void
    {
        $status = Status::fromArray($this->fixture('status'));

        $this->assertSame('103270115826048975', $status->id);
        $this->assertSame('https://example.social/@trwnh/103270115826048975', $status->url);
        $this->assertSame('2019-12-08T03:48:33+00:00', $status->createdAt?->toIso8601String());
        $this->assertSame('de', $status->language);
        $this->assertSame('14715', $status->account?->id);
        $this->assertNull($status->poll);
        $this->assertNull($status->text);
        $this->assertSame([], $status->mediaAttachments);
    }

    public function test_status_with_media_poll_and_source_text(): void
    {
        $status = Status::fromArray($this->fixture('status') + [
            'text' => 'source text',
        ] + ['poll' => null]);
        $this->assertSame('source text', $status->text);

        $data = $this->fixture('status');
        $data['media_attachments'] = [$this->fixture('media_processed')];
        $data['poll'] = ['id' => 34830, 'expires_at' => '2026-11-05T12:00:00.000Z', 'expired' => false, 'multiple' => true,
            'votes_count' => 3, 'options' => [['title' => 'Ja', 'votes_count' => 2], ['title' => 'Nein', 'votes_count' => null]]];

        $status = Status::fromArray($data);

        $this->assertSame('22348641', $status->mediaAttachments[0]->id);
        $this->assertSame('34830', $status->poll?->id);
        $this->assertTrue($status->poll->multiple);
        $this->assertSame([['title' => 'Ja', 'votes_count' => 2], ['title' => 'Nein', 'votes_count' => null]], $status->poll->options);
    }

    public function test_scheduled_status(): void
    {
        $scheduled = ScheduledStatus::fromArray($this->fixture('scheduled_status'));

        $this->assertSame('3221', $scheduled->id);
        $this->assertSame('2026-11-04T14:00:00+00:00', $scheduled->scheduledAt?->toIso8601String());
        $this->assertSame('Neue Version ist da #release', $scheduled->text());
    }

    public function test_media_attachment_processing_state(): void
    {
        $this->assertTrue(MediaAttachment::fromArray($this->fixture('media_processed'))->isProcessed());
        $processing = MediaAttachment::fromArray($this->fixture('media_processing'));
        $this->assertFalse($processing->isProcessed());
        $this->assertSame('video', $processing->type);
    }

    public function test_token_without_expiry_and_refresh_token(): void
    {
        $token = Token::fromArray($this->fixture('token'));

        $this->assertSame('ZA-Yj3aBD8U8Cm7lKUp-lm9O9BmDgdhHzDeqsY8tlL0', $token->accessToken);
        $this->assertSame(['profile', 'write:statuses', 'write:media', 'read:statuses'], $token->scopes);
        $this->assertSame(1573979017, $token->createdAt?->getTimestamp());
        $this->assertStringNotContainsString($token->accessToken, print_r($token, true));

        $this->assertSame(['read'], Token::fromArray(['access_token' => 'x'])->scopes, 'Default scope is read.');
    }

    public function test_app_registration(): void
    {
        $app = AppRegistration::fromArray($this->fixture('app'));

        $this->assertSame('TWhM-tNSuncnqN7DBJmoyeLnk6K3iJJ71KKXxgL1hPM', $app->clientId);
        $this->assertSame(['https://app.test/mastodon/callback'], $app->redirectUris);
        $this->assertNull($app->clientSecretExpiresAt, '0 means the secret does not expire.');
        $this->assertStringNotContainsString($app->clientSecret, print_r($app, true));

        $legacy = AppRegistration::fromArray(['client_id' => 'a', 'client_secret' => 'b', 'redirect_uri' => "https://a.test/cb\nhttps://b.test/cb", 'client_secret_expires_at' => 1893456000]);
        $this->assertSame(['https://a.test/cb', 'https://b.test/cb'], $legacy->redirectUris);
        $this->assertSame(1893456000, $legacy->clientSecretExpiresAt?->getTimestamp());
    }

    public function test_account_and_oauth_metadata(): void
    {
        $account = Account::fromArray($this->fixture('account'));
        $this->assertSame('14715', $account->id);
        $this->assertSame('trwnh', $account->acct);

        $metadata = OAuthMetadata::fromArray($this->fixture('oauth_metadata'));
        $this->assertTrue($metadata->supportsScope('profile'));
        $this->assertSame(['S256'], $metadata->codeChallengeMethodsSupported);
        $this->assertSame('https://example.social/oauth/revoke', $metadata->revocationEndpoint);
    }

    public function test_unknown_and_missing_fields_are_tolerated(): void
    {
        $status = Status::fromArray(['id' => 12345, 'brand_new_field' => ['x' => 1]]);

        $this->assertSame('12345', $status->id);
        $this->assertNull($status->url);
        $this->assertNull($status->createdAt);
        $this->assertSame('public', $status->visibility);
        $this->assertSame(['x' => 1], $status->raw['brand_new_field']);
    }
}
