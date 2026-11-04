<?php

namespace KaiHempel\Mastodon\Resources;

use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Data\Status;
use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Support\LocalThrottle;

class StatusesResource
{
    public function __construct(
        private readonly Connector $connector,
        private readonly LocalThrottle $throttle,
    ) {}

    /**
     * POST /api/v1/statuses. Returns a ScheduledStatus when scheduled_at is set.
     *
     * Parameters are sent as JSON with the documented field names (status,
     * media_ids, poll[options|expires_in|multiple|hide_totals], in_reply_to_id,
     * sensitive, spoiler_text, visibility, language, scheduled_at).
     *
     * @param  array<string, mixed>  $params
     */
    public function create(array $params, string $idempotencyKey): Status|ScheduledStatus
    {
        $response = $this->connector->post('/api/v1/statuses', [
            'json' => $params,
            // Retries are safe: the server deduplicates by Idempotency-Key.
            'headers' => ['Idempotency-Key' => $idempotencyKey],
        ]);

        $data = (array) $response->json();

        return (isset($data['scheduled_at']) && isset($data['params']))
            ? ScheduledStatus::fromArray($data)
            : Status::fromArray($data);
    }

    /** GET /api/v1/statuses/:id */
    public function get(string $id): Status
    {
        return Status::fromArray((array) $this->connector->get('/api/v1/statuses/'.rawurlencode($id))->json());
    }

    /**
     * PUT /api/v1/statuses/:id. Fields: status, spoiler_text, sensitive,
     * language, media_ids, media_attributes (id, description, focus), poll.
     * Changing poll options resets the votes.
     *
     * @param  array<string, mixed>  $params
     */
    public function edit(string $id, array $params): Status
    {
        return Status::fromArray((array) $this->connector->put('/api/v1/statuses/'.rawurlencode($id), ['json' => $params])->json());
    }

    /**
     * DELETE /api/v1/statuses/:id. The returned Status contains the source
     * text ($status->text) for "delete & redraft". Without $deleteMedia the
     * server keeps the media for about 24 hours.
     */
    public function delete(string $id, bool $deleteMedia = false): Status
    {
        $this->throttle->hit('deletes');

        $response = $this->connector->delete('/api/v1/statuses/'.rawurlencode($id), $deleteMedia ? ['query' => ['delete_media' => 'true']] : []);

        return Status::fromArray((array) $response->json());
    }
}
