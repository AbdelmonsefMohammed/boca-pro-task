<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * Durations we offer. A fixed set keeps the booking window predictable and stops a
     * crafted request reserving an absurd span of the calendar.
     *
     * @var list<int>
     */
    public static array $durations = [15, 30, 45, 60, 90, 120];

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'string', 'email', 'max:255'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', Rule::in(self::$durations)],
            'timezone' => ['required', 'string', 'timezone'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->startsAt()->isFuture()) {
                    return;
                }

                $validator->errors()->add('start_time', __('Pick a time in the future.'));
            },
        ];
    }

    /**
     * The one place a local date and time becomes an instant.
     *
     * The form submits a wall clock time plus the zone it was meant in. Interpreting it
     * in that zone and converting to UTC here means everything downstream, including the
     * overlap check, compares real instants rather than strings (4.4).
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            "{$this->string('date')} {$this->string('start_time')}",
            $this->string('timezone')->toString(),
        )->setTimezone('UTC');
    }

    public function durationMinutes(): int
    {
        return $this->integer('duration_minutes');
    }

    /**
     * @return array{title: string, customer_name: string, customer_email: string}
     */
    public function details(): array
    {
        return [
            'title' => $this->string('title')->toString(),
            'customer_name' => $this->string('customer_name')->toString(),
            'customer_email' => $this->string('customer_email')->toString(),
        ];
    }
}
