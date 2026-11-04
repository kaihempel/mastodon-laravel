<?php

namespace KaiHempel\Mastodon\Resources;

use DateTimeInterface;
use Illuminate\Support\LazyCollection;
use KaiHempel\Mastodon\Data\ScheduledStatus;
use KaiHempel\Mastodon\Http\Connector;
use KaiHempel\Mastodon\Http\Paginator;

class ScheduledStatusesResource
{
    public function __construct(private readonly Connector $connector) {}

    /**
     * GET /api/v1/scheduled_statuses, following Link headers lazily.
     *
     * @param  array{limit?: int, max_id?: string, min_id?: string, since_id?: string}  $query
     * @return LazyCollection<int, ScheduledStatus>
     */
    public function list(array $query = []): LazyCollection
    {
        return Paginator::lazy($this->connector, '/api/v1/scheduled_statuses', $query, fn (array $item) => ScheduledStatus::fromArray($item));
    }

    /** GET /api/v1/scheduled_statuses/:id */
    public function get(string $id): ScheduledStatus
    {
        return ScheduledStatus::fromArray((array) $this->connector->get('/api/v1/scheduled_statuses/'.rawurlencode($id))->json());
    }

    /** PUT /api/v1/scheduled_statuses/:id (at least 5 minutes in the future). */
    public function reschedule(string $id, DateTimeInterface $scheduledAt): ScheduledStatus
    {
        $response = $this->connector->put('/api/v1/scheduled_statuses/'.rawurlencode($id), [
            'json' => ['scheduled_at' => self::formatDate($scheduledAt)],
        ]);

        return ScheduledStatus::fromArray((array) $response->json());
    }

    /** DELETE /api/v1/scheduled_statuses/:id */
    public function cancel(string $id): void
    {
        $this->connector->delete('/api/v1/scheduled_statuses/'.rawurlencode($id));
    }

    public static function formatDate(DateTimeInterface $date): string
    {
        return \Carbon\CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
