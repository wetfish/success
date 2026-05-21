<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return JobListingRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(JobListingRules::normalize($this->all()));
    }
}