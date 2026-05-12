<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return TagRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TagRules::normalize($this->all()));
    }
}