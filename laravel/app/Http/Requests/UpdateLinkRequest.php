<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note: linkable_type and linkable_id are deliberately not in the
     * rule set. A link's polymorphic parent is fixed at creation time;
     * reparenting via the edit form would let a user accidentally move
     * a link to a different entity by tampering with hidden inputs.
     */
    public function rules(): array
    {
        return LinkRules::rules(forStore: false);
    }

    public function messages(): array
    {
        return LinkRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(LinkRules::normalize($this->all()));
    }
}