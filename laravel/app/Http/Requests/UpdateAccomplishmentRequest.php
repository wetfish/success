<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccomplishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return AccomplishmentRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(AccomplishmentRules::normalize($this->all()));
    }
}