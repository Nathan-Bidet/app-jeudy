import Modal from '@/Components/Modal';
import PollDisplay from '@/Components/Announcements/PollDisplay';

/**
 * Modal "Répondre au nom de" / "Modifier la réponse de" un destinataire,
 * ouvert depuis les listes "Ont répondu" / "En attente" de PollResults pour
 * les administrateurs et le créateur de l'annonce. Réutilise intégralement
 * PollDisplay (variant="full") pour garantir les mêmes choix et règles de
 * validation (choix unique/multiple) que le formulaire de vote normal —
 * seule la cible de la réponse et le libellé changent.
 */
export default function AnswerPollOnBehalfModal({ context, onClose, onSubmit, processing = false, errors = {} }) {
    const isOpen = Boolean(context);
    const poll = context?.announcement?.poll;
    const user = context?.user;
    const isEditing = Boolean(context?.response);

    return (
        <Modal show={isOpen} onClose={processing ? () => {} : onClose} maxWidth="lg">
            {isOpen ? (
                <>
                    <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <h3 className="text-base font-black uppercase tracking-[0.08em]">
                                    {isEditing ? 'Modifier la réponse de' : 'Répondre au nom de'} {user?.name}
                                </h3>
                                <p className="mt-1 text-xs text-[var(--app-muted)]">
                                    Cette réponse sera enregistrée pour {user?.name}, pas pour vous.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={processing}
                                className="rounded-lg border border-[var(--app-border)] px-2 py-1 text-xs font-semibold disabled:opacity-50"
                            >
                                Annuler
                            </button>
                        </div>
                    </div>

                    <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4 text-sm">
                        <PollDisplay
                            poll={poll}
                            variant="full"
                            hideResults
                            respondingFor={{
                                id: user?.id,
                                name: user?.name,
                                response: context?.response ?? null,
                            }}
                            submitLabel="Enregistrer la réponse"
                            onSubmitResponse={onSubmit}
                            responseProcessing={processing}
                            errors={errors}
                        />
                    </div>
                </>
            ) : null}
        </Modal>
    );
}
