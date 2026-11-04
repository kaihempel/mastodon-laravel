<?php

namespace KaiHempel\Mastodon\Console;

use Illuminate\Console\Command;
use KaiHempel\Mastodon\Exceptions\MastodonException;
use KaiHempel\Mastodon\MastodonManager;

class InstanceCommand extends Command
{
    protected $signature = 'mastodon:instance {instance : Host name or URL of the instance} {--fresh : Bypass the cache}';

    protected $description = 'Show version and limits of a Mastodon instance';

    public function handle(MastodonManager $manager): int
    {
        try {
            $resource = $manager->instance((string) $this->argument('instance'));
            $info = $resource->info((bool) $this->option('fresh'));
            $metadata = $resource->oauthMetadata();
        } catch (MastodonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Setting', 'Value'], [
            ['Domain', $info->domain],
            ['Title', $info->title],
            ['Version', $info->version],
            ['API version (mastodon)', (string) $info->apiVersion()],
            ['OAuth metadata', $metadata !== null ? 'yes' : 'no (< 4.3)'],
            ['Supported scopes', $metadata !== null ? implode(' ', $metadata->scopesSupported) : '-'],
            ['statuses.max_characters', (string) $info->maxCharacters],
            ['statuses.max_media_attachments', (string) $info->maxMediaAttachments],
            ['statuses.characters_reserved_per_url', (string) $info->charactersReservedPerUrl],
            ['media.description_limit', (string) $info->descriptionLimit],
            ['media.image_size_limit', (string) $info->imageSizeLimit],
            ['media.video_size_limit', (string) $info->videoSizeLimit],
            ['media.image_matrix_limit', (string) $info->imageMatrixLimit],
            ['media.video_matrix_limit', (string) $info->videoMatrixLimit],
            ['media.video_frame_rate_limit', (string) $info->videoFrameRateLimit],
            ['media.supported_mime_types', implode(', ', $info->supportedMimeTypes)],
            ['polls.max_options', (string) $info->pollMaxOptions],
            ['polls.max_characters_per_option', (string) $info->pollMaxCharactersPerOption],
            ['polls.min_expiration', (string) $info->pollMinExpiration],
            ['polls.max_expiration', (string) $info->pollMaxExpiration],
        ]);

        return self::SUCCESS;
    }
}
