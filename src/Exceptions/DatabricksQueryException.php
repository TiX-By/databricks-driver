<?php

declare(strict_types=1);

namespace TixBy\Databricks\Exceptions;

class DatabricksQueryException extends DatabricksException
{
    public function __construct(
        string $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $statementId = null,
    ) {
        parent::__construct($message);
    }
}
