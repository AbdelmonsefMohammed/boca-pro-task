<?php

namespace App\Data;

use Carbon\CarbonImmutable;

readonly class EventDetails
{
    public function __construct(
        public string $id,
        public string $title,
        public string $customerName,
        public string $customerEmail,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
    ) {}

    /**
     * The customer is written into the description rather than added as an attendee,
     * so booking never sends mail to a real address as a side effect. See the README.
     *
     * @return array<string, mixed>
     */
    public function toGooglePayload(): array
    {
        return [
            'id' => $this->id,
            'summary' => $this->title,
            'description' => "Booked by {$this->customerName} ({$this->customerEmail})",
            'start' => [
                'dateTime' => $this->startsAt->setTimezone($this->timezone)->toRfc3339String(),
                'timeZone' => $this->timezone,
            ],
            'end' => [
                'dateTime' => $this->endsAt->setTimezone($this->timezone)->toRfc3339String(),
                'timeZone' => $this->timezone,
            ],
        ];
    }
}
