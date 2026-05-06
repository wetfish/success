<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccomplishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return AccomplishmentRules::rules();
    }

    public function messages(): array
    {
        return AccomplishmentRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(AccomplishmentRules::normalize($this->all()));
    }
}