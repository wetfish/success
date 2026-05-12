<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return LinkRules::rules(forStore: true);
    }

    public function messages(): array
    {
        return LinkRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(LinkRules::normalize($this->all()));
    }
}