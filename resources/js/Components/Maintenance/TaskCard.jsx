import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { CalendarCheck, CalendarDays, CheckCircle2, Circle, EyeOff, Flag, Pencil, Trash2 } from 'lucide-react';

function PointingButton({ active, label, title, onClick, disabled = false }) {
    const Icon = active ? CheckCircle2 : Circle;

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title}
            aria-pressed={active}
            className={`inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] disabled:cursor-not-allowed disabled:opacity-60 ${
                active
                    ? 'border-emerald-600 bg-emerald-600 text-white'
                    : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)]'
            }`}
        >
            <Icon className="h-3.5 w-3.5" strokeWidth={2.2} />
            <span>{label}</span>
        </button>
    );
}

/** État du partiel en lecture seule, pour qui n'a pas le droit de le modifier. */
function PartialStateBadge({ active }) {
    const Icon = active ? CheckCircle2 : Circle;

    return (
        <span
            title={active ? 'Pointé partiellement par la personne affectée' : 'Pas encore pointé par la personne affectée'}
            className={`inline-flex items-center gap-1 rounded-lg border border-dashed px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] ${
                active
                    ? 'border-emerald-500 text-emerald-700'
                    : 'border-[var(--app-border)] text-[var(--app-muted)]'
            }`}
        >
            <Icon className="h-3.5 w-3.5" strokeWidth={2.2} />
            <span>Partiel</span>
        </span>
    );
}

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
    onTogglePartialPoint,
    onTogglePoint,
    onEditPointingDate,
    deleting = false,
    saving = false,
}) {
    const partiallyPointed = Boolean(task.partially_pointed);
    const pointed = Boolean(task.pointed);
    // Le responsable voit l'état du partiel sans pouvoir le modifier.
    const showPartialState = !task.can_partial_point && task.can_point;
    const hasPointingRow =
        task.can_partial_point || task.can_point || showPartialState || task.first_pointed_on_label;
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

                    {pointed ? (
                        <span className="rounded-md border border-emerald-500 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-[0.06em] text-emerald-700">
                            Terminée
                        </span>
                    ) : partiallyPointed ? (
                        <span className="rounded-md border border-sky-400 bg-sky-50 px-1.5 py-0.5 text-[10px] font-black uppercase tracking-[0.06em] text-sky-700">
                            En cours
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

            <p className="mt-2 whitespace-pre-line break-words text-sm font-semibold text-[var(--app-text)]">{task.task}</p>

            {task.place ? (
                <div className="mt-2 text-xs">
                    <PlaceActionsLink
                        text={task.place}
                        placeResolver={placeResolver}
                        coordinates={task.depot?.gps || null}
                        buttonClassName="font-semibold text-[var(--app-text)]"
                    />
                    {task.address_free_is_detail ? (
                        <p className="mt-0.5 whitespace-pre-line break-words pl-5 text-[var(--app-muted)]">
                            {task.address_free}
                        </p>
                    ) : null}
                </div>
            ) : null}

            {task.comment_withheld ? (
                <p className="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-dashed border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-xs italic text-[var(--app-muted)]">
                    <EyeOff className="h-3.5 w-3.5" strokeWidth={2.2} />
                    <span>Commentaire masqué</span>
                </p>
            ) : task.comment ? (
                <p className="mt-2 whitespace-pre-line break-words rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-xs text-[var(--app-muted)]">
                    {task.comment_hidden ? (
                        <span className="mr-1.5 inline-flex items-center gap-1 align-middle text-[10px] font-black uppercase tracking-[0.06em] text-[var(--app-muted)]">
                            <EyeOff className="h-3 w-3" strokeWidth={2.4} />
                            Masqué
                        </span>
                    ) : null}
                    {task.comment}
                </p>
            ) : null}

            {hasPointingRow ? (
                <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--app-border)] pt-2.5">
                    {task.can_partial_point ? (
                        <PointingButton
                            active={partiallyPointed}
                            label="Partiel"
                            title={partiallyPointed ? 'Retirer mon pointage partiel' : 'Pointer partiellement'}
                            disabled={saving}
                            onClick={() => onTogglePartialPoint?.(task, !partiallyPointed)}
                        />
                    ) : showPartialState ? (
                        <PartialStateBadge active={partiallyPointed} />
                    ) : null}

                    {task.can_point ? (
                        <PointingButton
                            active={pointed}
                            label={pointed ? 'Pointé' : 'Pointer'}
                            title={pointed ? 'Retirer le pointage définitif' : 'Pointer définitivement'}
                            disabled={saving}
                            onClick={() => onTogglePoint?.(task, !pointed)}
                        />
                    ) : null}

                    {task.first_pointed_on_label ? (
                        <button
                            type="button"
                            onClick={
                                task.can_edit_pointing_date ? () => onEditPointingDate?.(task) : undefined
                            }
                            disabled={!task.can_edit_pointing_date}
                            title={
                                task.can_edit_pointing_date
                                    ? 'Modifier la date du premier pointage'
                                    : 'Date du premier pointage'
                            }
                            className={`inline-flex items-center gap-1 rounded-lg border border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)] ${
                                task.can_edit_pointing_date
                                    ? 'hover:border-[var(--brand-yellow-dark)]'
                                    : 'cursor-default'
                            }`}
                        >
                            <CalendarCheck className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>1er pointage {task.first_pointed_on_label}</span>
                        </button>
                    ) : task.can_edit_pointing_date ? (
                        <button
                            type="button"
                            onClick={() => onEditPointingDate?.(task)}
                            className="inline-flex items-center gap-1 rounded-lg border border-dashed border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)] hover:border-[var(--brand-yellow-dark)]"
                        >
                            <CalendarCheck className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>Dater le 1er pointage</span>
                        </button>
                    ) : null}
                </div>
            ) : null}

            {partiallyPointed && task.partially_pointed_by ? (
                <p className="mt-1.5 text-[11px] text-[var(--app-muted)]">
                    Partiel par {task.partially_pointed_by}
                    {task.partially_pointed_at_label ? ` le ${task.partially_pointed_at_label}` : ''}
                </p>
            ) : null}

            {pointed && task.pointed_by ? (
                <p className="mt-0.5 text-[11px] text-[var(--app-muted)]">
                    Pointé par {task.pointed_by}
                    {task.pointed_at_label ? ` le ${task.pointed_at_label}` : ''}
                </p>
            ) : null}

            <p className="mt-2 text-[11px] text-[var(--app-muted)]">
                {task.is_request ? 'Demandé' : 'Créé'} par {task.created_by || '—'}
                {task.updated_by && task.updated_by !== task.created_by ? ` • modifié par ${task.updated_by}` : ''}
            </p>
        </article>
    );
}
