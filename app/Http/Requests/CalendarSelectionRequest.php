<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CalendarSelectionRequest extends FormRequest
{
    /**
     * Shape only. That the id belongs to the connected account is checked against the
     * provider in the controller, because that needs a call Google might refuse.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'calendar_id' => ['required', 'string', 'max:255'],
        ];
    }
}
