<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A user-facing SFTP error. The message is safe to show in the UI.
 */
class SftpException extends RuntimeException
{
    /**
     * @param  array  $context  extra, non-sensitive data returned to the UI (e.g. the server's current mtime)
     */
    public function __construct(string $message, public readonly int $status = 400, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
