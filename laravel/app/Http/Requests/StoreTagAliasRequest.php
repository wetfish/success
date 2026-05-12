<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return TagAliasRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TagAliasRules::normalize($this->all()));
    }
}