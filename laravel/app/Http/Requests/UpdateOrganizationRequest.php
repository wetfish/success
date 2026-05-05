<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return OrganizationRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(OrganizationRules::normalize($this->all()));
    }
}