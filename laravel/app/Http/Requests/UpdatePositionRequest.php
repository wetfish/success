<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return PositionRules::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PositionRules::normalize($this->all()));
    }
}