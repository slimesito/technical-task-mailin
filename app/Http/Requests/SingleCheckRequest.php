<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SingleCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'input' => ['required', 'string', 'max:320'],
            'dkim_selector' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9._-]+$/'],
        ];
    }
}
