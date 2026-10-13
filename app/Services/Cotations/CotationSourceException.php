<?php

namespace App\Services\Cotations;

use RuntimeException;
use Throwable;

/**
 * Échec de récupération des cours auprès de la source externe.
 *
 * `transient` distingue une indisponibilité probablement passagère (HTTP 5xx,
 * 408/425/429, délai dépassé, connexion refusée, réponse vide) d'un échec
 * définitif (autre erreur HTTP 4xx) que de nouvelles tentatives ne corrigeront pas.
 */
class CotationSourceException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient,
        public readonly ?int $httpStatus = null,
        public int $attempts = 1,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
