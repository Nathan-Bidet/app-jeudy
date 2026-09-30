<?php

namespace App\Console\Commands;

use App\Services\Cotations\CotationMailAttachmentService;
use Illuminate\Console\Command;

class CotationsPurgeMailAttachmentsCommand extends Command
{
    protected $signature = 'cotations:purge-mail-attachments';

    protected $description = 'Supprime les pièces jointes temporaires des courriels de cotations abandonnés (au-delà de cotations.mail.expire_hours).';

    public function handle(CotationMailAttachmentService $attachments): int
    {
        $removed = $attachments->purgeExpired();

        $this->info($removed === 0
            ? 'Aucune pièce jointe temporaire à purger.'
            : sprintf('%d pièce(s) jointe(s) temporaire(s) purgée(s).', $removed));

        return self::SUCCESS;
    }
}
