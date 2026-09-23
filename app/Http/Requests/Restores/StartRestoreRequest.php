<?php

declare(strict_types=1);

namespace App\Http\Requests\Restores;

use App\Enums\BackupFileStatus;
use App\Enums\RestoreMode;
use App\Enums\RestoreStatus;
use App\Models\BackupCopy;
use App\Models\BackupFile;
use App\Models\RestoreJob;
use App\Models\ServerConnection;
use App\Services\Database\DatabaseServerClient;
use App\Support\DatabaseName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class StartRestoreRequest extends FormRequest
{
    public const IDENTITY_PATTERN = '/AGE-SECRET-KEY-1[0-9A-Z]{58}/';

    public function authorize(): bool
    {
        return $this->user()?->can('create', RestoreJob::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $identityFile = (string) config('backup-manager.age_identity_file');

        return [
            'backup_file_id' => ['required', 'integer', Rule::exists('backup_files', 'id')->where('status', BackupFileStatus::Success->value)],
            'source_copy_id' => ['required', 'integer'],
            'target_connection_id' => ['required', 'integer', Rule::exists('connections', 'id')->where('is_active', true)],
            'target_database' => ['required', 'string', 'regex:'.DatabaseName::PATTERN],
            'mode' => ['required', Rule::enum(RestoreMode::class)],
            'confirmation' => ['required', 'string', 'same:target_database'],
            'age_identity' => [$identityFile === '' ? 'required' : 'nullable', 'string', 'max:10000', 'regex:'.self::IDENTITY_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.same' => 'Type the target database name exactly to confirm.',
            'age_identity.required' => 'Paste the age private key (AGE-SECRET-KEY-1…) used to decrypt this backup.',
            'age_identity.regex' => 'This does not look like an age private key (AGE-SECRET-KEY-1…).',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $file = BackupFile::query()->with('database')->findOrFail((int) $this->input('backup_file_id'));
                $target = (string) $this->input('target_database');
                $connectionId = (int) $this->input('target_connection_id');
                $mode = RestoreMode::from((string) $this->input('mode'));

                $copy = BackupCopy::query()->where('backup_file_id', $file->id)->find((int) $this->input('source_copy_id'));
                if ($copy === null || ! $copy->isRestorable()) {
                    $validator->errors()->add('source_copy_id', 'Pick an uploaded or verified copy of this backup.');
                }

                if (DatabaseName::isSystem($target)) {
                    $validator->errors()->add('target_database', 'System databases cannot be restored into.');

                    return;
                }

                if ($mode === RestoreMode::Replace && ($target !== $file->database->name || $connectionId !== $file->database->connection_id)) {
                    $validator->errors()->add('target_database', 'Replace mode restores over the original database only. Use "new copy" for another name or server.');
                }

                if ($mode === RestoreMode::NewCopy) {
                    try {
                        $connection = ServerConnection::query()->findOrFail($connectionId);
                        if (app(DatabaseServerClient::class)->databaseExists($connection, $target)) {
                            $validator->errors()->add('target_database', 'A database with this name already exists. Choose another name.');
                        }
                    } catch (Throwable $e) {
                        $validator->errors()->add('target_connection_id', 'Could not reach the target server: '.$e->getMessage());
                    }
                }

                $busy = RestoreJob::query()
                    ->where('target_connection_id', $connectionId)
                    ->where('target_database', $target)
                    ->whereNotIn('status', [RestoreStatus::Success->value, RestoreStatus::Failed->value])
                    ->exists();
                if ($busy) {
                    $validator->errors()->add('target_database', 'Another restore into this database is still running.');
                }
            },
        ];
    }

    /**
     * Only the key line, without comments or the public key line.
     */
    public function identity(): ?string
    {
        $raw = (string) $this->input('age_identity', '');

        return preg_match(self::IDENTITY_PATTERN, $raw, $m) === 1 ? $m[0] : null;
    }
}
