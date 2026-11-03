<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiatRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rates = $this->input('rates');

        if (! is_array($rates)) {
            return;
        }

        $this->merge([
            'rates' => array_map(function ($rate) {
                if (is_array($rate) && is_string($rate['symbol'] ?? null)) {
                    $rate['symbol'] = strtoupper(trim($rate['symbol']));
                }

                return $rate;
            }, $rates),
        ]);
    }

    public function rules(): array
    {
        return [
            'timestamp' => ['required', 'integer', 'min:1'],
            'rates' => ['required', 'array', 'min:1', 'max:50'],
            'rates.*' => ['required', 'array'],
            'rates.*.symbol' => ['required', 'string', 'max:10', 'distinct'],
            'rates.*.price_irr' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, int> symbol => price_irr
     */
    public function rates(): array
    {
        return collect($this->validated('rates'))
            ->mapWithKeys(fn (array $rate) => [$rate['symbol'] => (int) $rate['price_irr']])
            ->all();
    }

    public function sourceTimestamp(): int
    {
        return (int) $this->validated('timestamp');
    }
}
