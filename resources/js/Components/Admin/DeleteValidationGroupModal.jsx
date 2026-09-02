import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * Confirmation de suppression d'un groupe de validation.
 *
 * La suppression libère les membres sans toucher à l'historique : chaque
 * demande de congé conserve le valideur figé au moment de sa soumission.
 */
export default function DeleteValidationGroupModal({ group, processing, onClose, onConfirm }) {
    const memberCount = Number(group?.member_count ?? 0);

    return (
        <Modal show={Boolean(group)} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-lg font-semibold text-[var(--app-text)]">Supprimer le groupe</h3>
            </div>

            <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4 text-sm text-[var(--app-text)]">
                <p>Cette action est définitive. Le groupe suivant sera supprimé&nbsp;:</p>

                <div className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2">
                    <p className="font-semibold">{group?.name || 'Groupe sans nom'}</p>
                    <p className="text-xs text-[var(--app-muted)]">
                        Valideur 1 : {group?.validator_1_label || '—'} · Valideur 2 : {group?.validator_2_label || '—'}
                    </p>
                </div>

                <p className="text-xs text-[var(--app-muted)]">
                    {memberCount > 0
                        ? `Ses ${memberCount} membre${memberCount > 1 ? 's' : ''} seront libérés et pourront rejoindre un autre groupe.`
                        : 'Ce groupe ne contient aucun membre.'}{' '}
                    Les demandes de congé déjà soumises conservent leur valideur : l'historique n'est pas modifié.
                </p>
            </div>

            <div className="flex items-center justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <SecondaryButton type="button" onClick={onClose} disabled={processing}>
                    Annuler
                </SecondaryButton>
                <DangerButton type="button" onClick={onConfirm} disabled={processing}>
                    Supprimer
                </DangerButton>
            </div>
        </Modal>
    );
}
