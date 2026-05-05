<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return OrganizationRules::rules();
    }

    /**
     * Normalize input before validation runs. Strips thousands separators
     * from founded_year, trims string fields, and coerces empty strings
     * to null on nullable fields so validation treats them consistently.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(OrganizationRules::normalize($this->all()));
    }
}