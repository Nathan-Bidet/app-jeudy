<?php

namespace App\Http\Controllers;

use RuntimeException;
use Throwable;

class TaskTiersImportException extends RuntimeException
{
    public function __construct(
        public readonly string $step,
        string $message,
        public readonly ?int $row = null,
        public readonly ?string $column = null,
        public readonly array $rowValues = [],
        public readonly array $columns = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        $parts = [];

        if ($this->row !== null) {
            $parts[] = 'ligne '.$this->row;
        }

        if ($this->column !== null && $this->column !== '') {
            $parts[] = 'colonne '.$this->column;
        }

        $context = $parts === [] ? '' : ' '.implode(' : ', $parts);

        return 'Erreur '.$this->step.$context.' : '.$this->getMessage();
    }
}
