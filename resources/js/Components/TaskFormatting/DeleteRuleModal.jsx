import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';

export default function DeleteRuleModal({
    rule,
    processing,
    onClose,
    onConfirm,
}) {
    return (
        <Modal show={Boolean(rule)} onClose={onClose} maxWidth="md">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <h3 className="text-lg font-semibold text-[var(--app-text)]">Supprimer la règle</h3>
            </div>

            <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4 text-sm text-[var(--app-text)]">
                <p>
                    Cette action est définitive. La règle suivante sera supprimée:
                </p>
                <div className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2">
                    <p className="font-semibold">{rule?.name || 'Règle sans nom'}</p>
                    <p className="text-xs text-[var(--app-muted)]">{rule?.pattern || '—'}</p>
                </div>
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
