<?php

declare(strict_types=1);

namespace App\Http\Requests\Rules;

use App\Enums\RuleType;
use App\Models\SelectionRule;
use App\Services\Discovery\RuleMatcher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSelectionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rule = $this->route('rule');

        return $rule instanceof SelectionRule
            ? ($this->user()?->can('update', $rule) ?? false)
            : ($this->user()?->can('create', SelectionRule::class) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'connection_id' => ['required', 'integer', Rule::exists('connections', 'id')],
            'type' => ['required', Rule::enum(RuleType::class)],
            'pattern' => ['required', 'string', 'regex:'.RuleMatcher::PATTERN_RULE],
            'priority' => ['required', 'integer', 'between:0,100000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['pattern.regex' => 'Use letters, digits, underscore, * and ? only (e.g. kreethya_* or *_test).'];
    }
}
