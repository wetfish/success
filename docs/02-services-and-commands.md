# Services and Commands

## Service classes

### `App\Support\Money`

Static helper for converting between integer cents (how money is stored) and human-readable strings (how it's shown and entered).

```php
Money::format(?int $cents): ?string
Money::parse(?string $input): ?int
```

`format()` is called from views. `parse()` is called from form requests in `prepareForValidation()`. Models do not call Money — they expose raw integer cents and apply no conversion.

See [AI Development Notes](05-ai-development-notes.md#money-storage) for the rationale on integer cents.

## Artisan commands

None yet. Default Laravel commands (`migrate`, `tinker`, `make:*`, `test`) are the only ones available.