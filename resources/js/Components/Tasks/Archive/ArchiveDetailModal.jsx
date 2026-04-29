import Modal from '@/Components/Modal';
import { CalendarDays, CheckCircle2, Clock3, Package, UserRound } from 'lucide-react';

function Badge({ children, tone = 'neutral' }) {
    const toneClass = {
        neutral: 'border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]',
        success: 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700/50 dark:bg-emerald-950/30 dark:text-emerald-300',
        info: 'border-sky-300 bg-sky-50 text-sky-700 dark:border-sky-700/50 dark:bg-sky-950/30 dark:text-sky-300',
        warning: 'border-amber-300 bg-amber-50 text-amber-700 dark:border-amber-700/50 dark:bg-amber-950/30 dark:text-amber-300',
    }[tone];

    return (
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold ${toneClass}`}>
            {children}
        </span>
    );
}

function MetaRow({ label, value }) {
    return (
        <div className="grid grid-cols-[140px_minmax(0,1fr)] gap-3 text-sm">
            <dt className="font-semibold text-[var(--app-muted)]">{label}</dt>
            <dd className="text-[var(--app-text)]">{value || '—'}</dd>
        </div>
    );
}

export default function ArchiveDetailModal({ show = false, row = null, onClose = () => {} }) {
    if (!show || !row) {
        return null;
    }

    return (
        <Modal show={show} onClose={onClose} maxWidth="2xl">
            <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-lg font-semibold text-[var(--app-text)]">Détail ligne archivée</h3>
                    <Badge tone={row.archived_by_system ? 'info' : 'neutral'}>
                        {row.archived_by_system ? 'Archivage automatique' : 'Archivage manuel'}
                    </Badge>
                </div>
            </div>

            <div className="space-y-4 bg-[var(--app-surface)] px-5 py-4">
                <div className="flex flex-wrap gap-2">
                    <Badge>
                        <CalendarDays className="mr-1 h-3.5 w-3.5" />
                        {row.date_label || '—'}
                    </Badge>
                    <Badge>
                        <Clock3 className="mr-1 h-3.5 w-3.5" />
                        Archivé le {row.archived_at_label || '—'}
                    </Badge>
                    <Badge>
                        <UserRound className="mr-1 h-3.5 w-3.5" />
                        {row.assignee_label || 'Sans assigné'}
                    </Badge>
                    {row.vehicle_label ? (
                        <Badge>
                            <Package className="mr-1 h-3.5 w-3.5" />
                            {row.vehicle_label}
                        </Badge>
                    ) : null}
                    {row.pointed ? (
                        <Badge tone="success">
                            <CheckCircle2 className="mr-1 h-3.5 w-3.5" />
                            Pointée
                        </Badge>
                    ) : (
                        <Badge tone="warning">Non pointée</Badge>
                    )}
                    {row.is_direct ? <Badge tone="info">D · Direct</Badge> : null}
                    {row.is_boursagri ? <Badge tone="warning">B · Boursagri</Badge> : null}
                </div>

                <dl className="space-y-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                    <MetaRow label="Assigné (type)" value={row.assignee_meta || '—'} />
                    <MetaRow
                        label="Contrat Boursagri"
                        value={row.boursagri_contract_number || '—'}
                    />
                    <MetaRow
                        label="ID source"
                        value={row.original_task_id ? `#${row.original_task_id}` : '—'}
                    />
                    <MetaRow label="Position" value={String(row.position ?? '—')} />
                    <MetaRow label="Pointée le" value={row.pointed_at_label || '—'} />
                </dl>

                <section className="space-y-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                    <p className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Tâche</p>
                    <pre className="whitespace-pre-wrap break-words text-sm font-medium text-[var(--app-text)]">
                        {row.task || '—'}
                    </pre>
                </section>

                <section className="space-y-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                    <p className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Commentaire</p>
                    <pre className="whitespace-pre-wrap break-words text-sm text-[var(--app-text)]">
                        {row.comment || '—'}
                    </pre>
                </section>
            </div>

            <div className="flex items-center justify-end border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                <button
                    type="button"
                    onClick={onClose}
                    className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                >
                    Fermer
                </button>
            </div>
        </Modal>
    );
}
