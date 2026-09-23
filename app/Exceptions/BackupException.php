<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An expected backup/restore failure with a message safe to show in the UI.
 */
class BackupException extends RuntimeException {}
