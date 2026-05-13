<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return PersonRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PersonRules::normalize($this->all()));
    }
}