import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { CalendarDays, EyeOff, Flag, Pencil, Trash2 } from 'lucide-react';

function DateRange({ task }) {
    const start = task?.date_label || task?.date;

    if (!start) return null;

    return (
        <span className="inline-flex items-center gap-1.5">
            <CalendarDays className="h-3.5 w-3.5 shrink-0" strokeWidth={2.2} />
            <span>{task.fin_label ? `${start} → ${task.fin_label}` : start}</span>
        </span>
    );
}

export default function MaintenanceTaskCard({
    task,
    placeResolver = {},
    onEdit,
    onDelete,
    deleting = false,
}) {
    return (
        <article className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 sm:p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2 text-xs font-bold text-[var(--app-muted)]">
                    <DateRange task={task} />

                    {task.due_label ? (
                        <span
                            className="inline-flex items-center gap-1 rounded-md border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 py-0.5"
                            title="Date de fin souhaitée"
                        >
                            <Flag className="h-3 w-3" strokeWidth={2.4} />
                            <span>Souhaité le {task.due_label}</span>
                        </span>
                    ) : null}

                    {task.is_request ? (
                        <span className="rounded-md border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-[0.06em] text-amber-700">
                            Demande{task.requested_by ? ` • ${task.requested_by}` : ''}
                        </span>
                    ) : null}
                </div>

                <div className="flex shrink-0 items-center gap-1.5">
                    {task.can_update ? (
                        <button
                            type="button"
                            onClick={() => onEdit?.(task)}
                            title="Modifier"
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] hover:border-[var(--brand-yellow-dark)]"
                        >
                            <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                        </button>
                    ) : null}

                    {task.can_delete ? (
                        <button
                            type="button"
                            onClick={() => onDelete?.(task)}
                            disabled={deleting}
                            title="Supprimer"
                            className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-red-600 hover:border-red-400 disabled:opacity-50"
                        >
                            <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                        </button>
                    ) : null}
                </div>
            </div>

            <p className="mt-2 whitespace-pre-line text-sm font-semibold text-[var(--app-text)]">{task.task}</p>

            {task.place ? (
                <div className="mt-2 text-xs">
                    <PlaceActionsLink
                        text={task.place}
                        placeResolver={placeResolver}
                        coordinates={task.depot?.gps || null}
                        buttonClassName="font-semibold text-[var(--app-text)]"
                    />
                </div>
            ) : null}

            {task.comment_withheld ? (
                <p className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-xs italic text-[var(--app-muted)]">
                    <EyeOff className="h-3.5 w-3.5" strokeWidth={2.2} />
                    <span>Commentaire masqué</span>
                </p>
            ) : task.comment ? (
                <p className="mt-2 whitespace-pre-line rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-xs text-[var(--app-muted)]">
                    {task.comment_hidden ? (
                        <span className="mr-1.5 inline-flex items-center gap-1 align-middle text-[10px] font-black uppercase tracking-[0.06em] text-[var(--app-muted)]">
                            <EyeOff className="h-3 w-3" strokeWidth={2.4} />
                            Masqué
                        </span>
                    ) : null}
                    {task.comment}
                </p>
            ) : null}

            <p className="mt-2 text-[11px] text-[var(--app-muted)]">
                {task.is_request ? 'Demandé' : 'Créé'} par {task.created_by || '—'}
                {task.updated_by && task.updated_by !== task.created_by ? ` • modifié par ${task.updated_by}` : ''}
            </p>
        </article>
    );
}
