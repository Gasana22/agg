<?php

namespace App\Http\Requests\WorkerManagement;

use App\Enums\TaskStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDailyTaskStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * cost/gps/photo/inputs_used are optional on every status change, but
     * this is really how they get captured: the worker reports them when
     * moving the task to ongoing or completed, not at assignment time.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'gps_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'photo' => ['nullable', 'image', 'max:5120'],
            'inputs_used' => ['nullable', 'string'],
        ];
    }
}
