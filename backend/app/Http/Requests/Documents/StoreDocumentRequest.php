<?php

namespace App\Http\Requests\Documents;

use App\Enums\MediaCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * documentable_type is a friendly alias (see DocumentController::TYPES),
     * not the model's FQCN — resolving it and checking documentable_id
     * exists happens in the controller, since which table it belongs to
     * depends on the type.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'documentable_type' => [
                'required',
                Rule::in(['farm', 'asset', 'animal', 'crop_season', 'purchase_order']),
            ],
            'documentable_id' => ['required', 'integer'],
            'category' => ['required', Rule::enum(MediaCategory::class)],
            'description' => ['nullable', 'string', 'max:1000'],
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx'],
        ];
    }
}
