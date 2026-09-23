<?php

namespace App\Modules\Traceability\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordEventRequest extends FormRequest
{
    /** Event types users may add by hand; domain modules record the rest. */
    public const MANUAL_TYPES = ['note', 'inspection', 'certification', 'storage_check'];

    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', 'in:'.implode(',', self::MANUAL_TYPES)],
            'occurred_at' => ['sometimes', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'payload' => ['sometimes', 'array'],
            'location' => ['sometimes', 'array'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string,mixed> Recorder input */
    public function toEventData(): array
    {
        $data = ['payload' => $this->validated('payload', [])];
        if ($this->has('occurred_at')) {
            $data['occurred_at'] = $this->validated('occurred_at');
        }
        if ($location = $this->validated('location')) {
            $data['latitude'] = $location['lat'];
            $data['longitude'] = $location['lng'];
            $data['gps_accuracy_m'] = $location['accuracy_m'] ?? null;
        }

        return $data;
    }
}
