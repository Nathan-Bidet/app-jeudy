<?php

namespace App\Mail;

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
     * Version texte du HTML : retours à la ligne conservés pour les blocs.
     */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>|</(p|div)>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
