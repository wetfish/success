<?php

namespace App\Http\Requests;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Resolve the route-bound tag so the uniqueness check can
        // skip the current record. Without this, updating a tag
        // without changing its name would fail validation.
        $tag = $this->route('tag');
        $ignoreId = $tag instanceof Tag ? $tag->id : null;

        return TagRules::rules(ignoreId: $ignoreId);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TagRules::normalize($this->all()));
    }
}