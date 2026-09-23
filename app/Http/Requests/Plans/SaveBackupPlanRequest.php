<?php

declare(strict_types=1);

namespace App\Http\Requests\Plans;

use App\Models\BackupPlan;
use App\Models\Database;
use App\Support\CronSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBackupPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof BackupPlan
            ? ($this->user()?->can('update', $plan) ?? false)
            : ($this->user()?->can('create', BackupPlan::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['cron_expression' => preg_replace('/\s+/', ' ', trim((string) $this->input('cron_expression')))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('backup_plans', 'name')->ignore($plan instanceof BackupPlan ? $plan->id : null)],
            'connection_id' => ['required', 'integer', Rule::exists('connections', 'id')],
            'cron_expression' => ['required', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'retention' => ['required', 'array'],
            'retention.keep_daily' => ['required', 'integer', 'between:0,365'],
            'retention.keep_weekly' => ['required', 'integer', 'between:0,260'],
            'retention.keep_monthly' => ['required', 'integer', 'between:0,120'],
            'all_included_databases' => ['required', 'boolean'],
            'database_ids' => ['array', 'required_if:all_included_databases,false'],
            'database_ids.*' => ['integer'],
            'destination_ids' => ['required', 'array', 'min:1'],
            'destination_ids.*' => ['integer', Rule::exists('destinations', 'id')],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! CronSchedule::isValid((string) $this->input('cron_expression'))) {
                    $validator->errors()->add('cron_expression', 'This is not a valid 5-field cron expression (minute hour day month weekday).');
                }

                $ids = array_map('intval', (array) $this->input('database_ids', []));
                if (! $this->boolean('all_included_databases') && $ids !== []) {
                    $valid = Database::query()->where('connection_id', (int) $this->input('connection_id'))->whereIn('id', $ids)->count();
                    if ($valid !== count(array_unique($ids))) {
                        $validator->errors()->add('database_ids', 'Selected databases must belong to the chosen connection.');
                    }
                }
            },
        ];
    }
}
