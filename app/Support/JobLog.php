<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Collects timestamped, redacted log lines for a backup or restore.
 */
final class JobLog
{
    /** @var list<string> */
    private array $lines = [];

    /**
     * @param  list<string|null>  $secrets
     */
    public function __construct(private array $secrets = []) {}

    /**
     * @param  list<string|null>  $secrets
     */
    public function addSecrets(array $secrets): void
    {
        $this->secrets = [...$this->secrets, ...$secrets];
    }

    public function add(string $line): void
    {
        $this->lines[] = '['.now()->format('H:i:s').'] '.SecretRedactor::redactString($line, $this->secrets);
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }
}
