<?php

namespace KaiHempel\Mastodon\Tests\Unit;

use KaiHempel\Mastodon\Data\InstanceInfo;
use KaiHempel\Mastodon\Exceptions\ValidationException;
use KaiHempel\Mastodon\Facades\Mastodon;
use KaiHempel\Mastodon\Models\MastodonAccount;
use KaiHempel\Mastodon\Publishing\MediaFile;
use KaiHempel\Mastodon\Publishing\PendingStatus;
use KaiHempel\Mastodon\Publishing\StatusValidator;
use KaiHempel\Mastodon\Tests\TestCase;

class StatusValidatorTest extends TestCase
{
    private InstanceInfo $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance = InstanceInfo::fromArray($this->fixture('instance'));
    }

    private function draft(string $text = 'Hallo'): PendingStatus
    {
        return Mastodon::for(new MastodonAccount)->status($text);
    }

    private function errors(PendingStatus $status, ?InstanceInfo $instance = null): array
    {
        return (new StatusValidator)->errors($status, $instance ?? $this->instance);
    }

    private function png(int $padding = 0): MediaFile
    {
        // 1x1 PNG (+ optional padding to simulate large files)
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return MediaFile::fromContents($png.str_repeat("\0", $padding), 'pixel.png');
    }

    public function test_valid_status_has_no_errors(): void
    {
        $status = $this->draft('Neue Version ist da #release')
            ->visibility('unlisted')
            ->language('de')
            ->scheduleAt(now()->addMinutes(10));

        $this->assertSame([], $this->errors($status));
    }

    public function test_empty_text_requires_media(): void
    {
        $this->assertArrayHasKey('status', $this->errors($this->draft('   ')));
        $this->assertSame([], $this->errors($this->draft('')->attach($this->png())));
    }

    public function test_character_limit_counts_urls_as_reserved_length(): void
    {
        $url = 'https://example.com/'.str_repeat('a', 600);

        $this->assertSame([], $this->errors($this->draft(str_repeat('x', 476).' '.$url)), '476 + 1 + 23 = 500');
        $this->assertArrayHasKey('status', $this->errors($this->draft(str_repeat('x', 477).' '.$url)));
    }

    public function test_spoiler_text_counts_towards_the_limit(): void
    {
        $status = $this->draft(str_repeat('x', 490))->spoilerText(str_repeat('y', 11));

        $this->assertArrayHasKey('status', $this->errors($status));
    }

    public function test_limits_are_read_from_the_instance(): void
    {
        $small = InstanceInfo::fromArray(['configuration' => ['statuses' => ['max_characters' => 10]]]);

        $this->assertArrayHasKey('status', $this->errors($this->draft('elf Zeichen'), $small));
        $this->assertSame([], $this->errors($this->draft('zehn Zeich'), $small));
    }

    public function test_visibility_and_language(): void
    {
        $errors = $this->errors($this->draft()->visibility('friends')->language('deu'));

        $this->assertArrayHasKey('visibility', $errors);
        $this->assertArrayHasKey('language', $errors);
    }

    public function test_media_count_mime_type_size_and_description(): void
    {
        $status = $this->draft();
        for ($i = 0; $i < 5; $i++) {
            $status->attach($this->png());
        }
        $this->assertArrayHasKey('media', $this->errors($status));

        $tiny = InstanceInfo::fromArray($this->fixture('instance', ['configuration' => ['media_attachments' => ['image_size_limit' => 50, 'description_limit' => 5]]]));
        $errors = $this->errors($this->draft()->attach($this->png(100), description: 'zu lang', focus: [2.0, 0]), $tiny);
        $this->assertCount(3, $errors['media.0'], 'size, description and focus');

        $errors = $this->errors($this->draft()->attach(MediaFile::fromContents('%PDF-1.4 test', 'doc.pdf')));
        $this->assertStringContainsString('application/pdf', $errors['media.0'][0]);
    }

    public function test_poll_rules(): void
    {
        $this->assertSame([], $this->errors($this->draft('Frage?')->poll(['Ja', 'Nein'], 3600)));

        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['Ja'], 3600)));
        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['a', 'b', 'c', 'd', 'e'], 3600)));
        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['Ja', str_repeat('n', 51)], 3600)));
        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['Ja', 'Ja'], 3600)));
        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['Ja', 'Nein'], 299)));
        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['Ja', 'Nein'], 2629747)));
        $this->assertArrayHasKey('poll', $this->errors($this->draft('Frage?')->poll(['Ja', 'Nein'], 3600)->attach($this->png())));
    }

    public function test_scheduled_at_must_be_five_minutes_ahead(): void
    {
        $this->assertArrayHasKey('scheduled_at', $this->errors($this->draft()->scheduleAt(now()->addMinutes(4))));
        $this->assertSame([], $this->errors($this->draft()->scheduleAt(now()->addMinutes(5))));
    }

    public function test_validate_throws_with_all_errors(): void
    {
        try {
            (new StatusValidator)->validate($this->draft('')->visibility('nope'), $this->instance);
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors);
            $this->assertArrayHasKey('visibility', $e->errors);
            $this->assertStringContainsString('visibility:', $e->getMessage());
        }
    }
}
