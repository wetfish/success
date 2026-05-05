<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ProjectRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ProjectRules::normalize($this->all()));
    }
}