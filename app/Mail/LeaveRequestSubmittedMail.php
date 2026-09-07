<?php

namespace App\Mail;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email informatif adressé aux destinataires configurés sur un groupe de
 * validation, lorsqu'une demande de congé lui est soumise.
 *
 * EN PLUS des notifications internes des deux valideurs, jamais à leur place.
 *
 * PUREMENT INFORMATIF : aucun lien de validation ni de refus. Les décisions se
 * prennent dans l'application, où l'identité du décideur est établie et tracée.
 * Un lien agissant depuis un email demanderait un jeton signé, un mécanisme que
 * ce projet n'a pas et qui n'a pas à naître d'une demande d'information.
 *
 * Mis en file d'attente : la soumission d'une demande ne doit pas attendre
 * l'envoi de plusieurs emails.
 */
class LeaveRequestSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(public readonly array $details)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Nouvelle demande de congé — %s',
                $this->details['requester_label'] ?? 'Collaborateur',
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.validation.leave-request',
            with: ['details' => $this->details],
        );
    }

    /**
     * Charge utile de l'email, construite à partir de la demande.
     *
     * Volontairement restreinte à ce qui permet de comprendre ce qui a été
     * soumis : ni email, ni téléphone, ni identifiant technique du demandeur.
     *
     * @return array<string, mixed>
     */
    public static function detailsFor(LeaveRequest $leaveRequest, string $requesterLabel): array
    {
        return [
            'requester_label' => $requesterLabel,
            'leave_type' => $leaveRequest->leaveType?->name,
            // Dates rendues en chaînes : la charge utile traverse la file
            // d'attente, autant qu'elle ne dépende d'aucune désérialisation.
            'start_at' => $leaveRequest->start_at?->format('d/m/Y'),
            'end_at' => $leaveRequest->end_at?->format('d/m/Y'),
            'start_portion' => self::portionLabel($leaveRequest->start_portion),
            'end_portion' => self::portionLabel($leaveRequest->end_portion),
            'message' => $leaveRequest->message,
            'group_name' => $leaveRequest->validation_group_name,
        ];
    }

    /**
     * Libellé d'une demi-journée, ou null pour une journée entière : préciser
     * « journée complète » sur chaque borne n'apprendrait rien.
     */
    private static function portionLabel(?string $portion): ?string
    {
        return match ((string) $portion) {
            'morning' => 'matin',
            'afternoon' => 'après-midi',
            'custom' => 'horaires personnalisés',
            default => null,
        };
    }
}
