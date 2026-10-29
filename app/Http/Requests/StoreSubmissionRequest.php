<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An upload from the web form or the API: an image, a caption, or both.
 */
class StoreSubmissionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:2000', Rule::requiredIf(! $this->hasFile('image'))],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.config('moderation.max_upload_kb')],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'caption.required' => 'Add an image, a caption, or both.',
        ];
    }
}
