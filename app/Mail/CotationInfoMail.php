<?php

namespace App\Mail;

use App\Models\CotationMailAttachment;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Message d'information des cotations, composé depuis la page Cotations.
 *
 * Volontairement non mis en file d'attente : l'envoi est déclenché par un
 * utilisateur qui attend un retour clair (succès ou erreur du service de
 * messagerie). Le HTML reçu doit déjà avoir été assaini par
 * CerealInfoSanitizer ; ses styles en ligne sont conservés tels quels.
 */
class CotationInfoMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $bodyHtml,
        public readonly ?string $replyToAddress = null,
        /** @var iterable<int, CotationMailAttachment> */
        public readonly iterable $fileAttachments = [],
        public readonly string $attachmentDisk = 'local',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
            replyTo: $this->replyToAddress ? [$this->replyToAddress] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.cotations.info',
            text: 'emails.cotations.info-text',
            with: ['bodyHtml' => $this->bodyHtml],
        );
    }

    /**
     * Pièces jointes lues sur le disque privé, sous leur nom d'affichage.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];
        foreach ($this->fileAttachments as $file) {
            $attachments[] = Attachment::fromStorageDisk($this->attachmentDisk, $file->storagePath())
                ->as($file->original_name)
                ->withMime($file->mime_type);
        }

        return $attachments;
    }

    /**
     * Version texte du HTML : retours à la ligne conservés pour les blocs.
     */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>|</(p|div)>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
