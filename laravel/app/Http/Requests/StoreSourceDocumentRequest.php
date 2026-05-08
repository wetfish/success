<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSourceDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return SourceDocumentRules::rules();
    }

    /**
     * Normalize input before validation runs. Trims metadata fields,
     * coerces empty strings to null on nullable fields, and leaves the
     * body untouched (line breaks and indentation are meaningful).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(SourceDocumentRules::normalize($this->all()));
    }
}