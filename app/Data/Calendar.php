<?php

namespace App\Data;

readonly class Calendar
{
    public function __construct(
        public string $id,
        public string $name,
        public string $timezone,
        public bool $isPrimary,
        public bool $isWritable,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  One item from the Google calendarList response.
     */
    public static function fromGoogle(array $entry): self
    {
        $accessRole = (string) ($entry['accessRole'] ?? 'reader');

        return new self(
            id: (string) $entry['id'],
            name: (string) ($entry['summaryOverride'] ?? $entry['summary'] ?? $entry['id']),
            timezone: (string) ($entry['timeZone'] ?? 'UTC'),
            isPrimary: (bool) ($entry['primary'] ?? false),
            isWritable: in_array($accessRole, ['owner', 'writer'], true),
        );
    }

    /**
     * Rebuild from the primitive form. Caching the array rather than the object means a
     * rename or a namespace move cannot leave unreadable entries behind for a whole TTL.
     *
     * @param  array{id: string, name: string, timezone: string, isPrimary: bool, isWritable: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            timezone: $data['timezone'],
            isPrimary: $data['isPrimary'],
            isWritable: $data['isWritable'],
        );
    }

    /**
     * @return array{id: string, name: string, timezone: string, isPrimary: bool, isWritable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'isPrimary' => $this->isPrimary,
            'isWritable' => $this->isWritable,
        ];
    }
}
