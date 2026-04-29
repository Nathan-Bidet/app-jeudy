import DragHandle from '@/Components/Aprevoir/DragHandle';
import FormattedText from '@/Components/FormattedText';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { adaptiveTaskStyle } from '@/Support/taskColorStyle';
import { BookOpen, CheckCircle2, Circle, Copy, Pencil, Trash2 } from 'lucide-react';

function bookButton(task) {
    const left = task?.updated_by?.initials || '--';
    const right = task?.created_by?.initials || '--';
    const projected = Boolean(task?.book?.projected && task?.book?.url);

    const content = (
        <span className="inline-flex items-center gap-1">
            <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded bg-[var(--app-surface-soft)] px-1 text-[10px] font-black">
                {left}
            </span>
            <BookOpen className="h-3.5 w-3.5" strokeWidth={2.2} />
            <span className="text-[10px]">Livre</span>
            <span className="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded bg-[var(--app-surface-soft)] px-1 text-[10px] font-black">
                {right}
            </span>
        </span>
    );

    if (!projected) {
        return (
            <span className="inline-flex rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-text)] opacity-60">
                {content}
            </span>
        );
    }

    return (
        <a
            href={task.book.url}
            className="inline-flex rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]"
        >
            {content}
        </a>
    );
}

export default function TaskRow({
    task,
    placeResolver = {},
    highlighted = false,
    saving = false,
    canUpdate = false,
    canDelete = false,
    canPoint = false,
    draggable = false,
    onDragStart,
    onDragOver,
    onDrop,
    onEdit,
    onDuplicate,
    onDelete,
    onTogglePoint,
}) {
    const customStyle = adaptiveTaskStyle(task?.style || {});

    return (
        <div
            data-task-id={task?.id}
            draggable={draggable}
            onDragStart={(e) => onDragStart?.(e, task)}
            onDragOver={(e) => onDragOver?.(e, task)}
            onDrop={(e) => onDrop?.(e, task)}
            onDoubleClick={() => canUpdate && onEdit?.(task)}
            className={`rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2.5 shadow-sm sm:p-3 ${
                highlighted ? 'ring-2 ring-orange-500 aprevoir-focus-blink-mobile' : ''
            } ${canUpdate ? 'cursor-pointer' : ''} ${saving ? 'opacity-70' : ''}`}
            style={customStyle}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        {draggable ? <DragHandle className="h-7 w-7" /> : null}
                        <FormattedText
                            as="p"
                            className="min-w-0 flex-1 text-sm font-semibold leading-tight"
                            text={task.task}
                            multiline
                        />
                    </div>

                    {task.loading_place || task.delivery_place ? (
                        <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs opacity-95">
                            {task.loading_place ? (
                                <span className="inline-flex items-center gap-1">
                                    <span className="font-semibold">Chargement :</span>
                                    <PlaceActionsLink text={task.loading_place} placeResolver={placeResolver} buttonClassName="text-xs" />
                                </span>
                            ) : null}
                            {task.delivery_place ? (
                                <span className="inline-flex items-center gap-1">
                                    <span className="font-semibold">Livraison :</span>
                                    <PlaceActionsLink text={task.delivery_place} placeResolver={placeResolver} buttonClassName="text-xs" />
                                </span>
                            ) : null}
                        </div>
                    ) : null}

                    {task.comment ? (
                        <FormattedText
                            as="p"
                            className="mt-2 text-xs opacity-90"
                            text={task.comment}
                            multiline
                        />
                    ) : null}

                    <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                        {task.fin_label ? (
                            <span className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-0.5 text-[var(--app-text)]">
                                Fin • {task.fin_label}
                            </span>
                        ) : null}
                        {task.vehicle ? (
                            <span className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-0.5 text-[var(--app-text)]">
                                {task.vehicle.name || 'Véhicule'}{task.vehicle.registration ? ` • ${task.vehicle.registration}` : ''}
                            </span>
                        ) : null}
                        {task.remorque ? (
                            <span className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-0.5 text-[var(--app-text)]">
                                Remorque • {task.remorque.name || 'Véhicule'}{task.remorque.registration ? ` • ${task.remorque.registration}` : ''}
                            </span>
                        ) : null}
                        {task.is_direct ? (
                            <span className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-emerald-700">
                                D
                            </span>
                        ) : null}
                        {task.is_boursagri ? (
                            <span className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-amber-700">
                                B{task.boursagri_contract_number ? ` • ${task.boursagri_contract_number}` : ''}
                            </span>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    {saving ? (
                        <span
                            className="inline-flex h-6 w-6 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface)]"
                            title="Enregistrement..."
                            aria-label="Enregistrement..."
                        >
                            <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[var(--brand-yellow-dark)] border-t-transparent" />
                        </span>
                    ) : null}
                    {bookButton(task)}

                    {canPoint ? (
                        <button
                            type="button"
                            onClick={() => onTogglePoint?.(task, !task.pointed)}
                            className={`inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] ${
                                task.pointed
                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                    : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)]'
                            }`}
                            title={task.pointed ? 'Dépointé' : 'Pointer'}
                        >
                            {task.pointed ? (
                                <CheckCircle2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                            ) : (
                                <Circle className="h-3.5 w-3.5" strokeWidth={2.2} />
                            )}
                            <span>{task.pointed ? 'Pointé' : 'Pointer'}</span>
                        </button>
                    ) : null}

                    {canUpdate ? (
                        <button
                            type="button"
                            onClick={() => onEdit?.(task)}
                            className="inline-flex items-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            <Pencil className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>Éditer</span>
                        </button>
                    ) : null}

                    {(canUpdate || canDelete) ? (
                        <span className="inline-flex items-center gap-2">
                            {canUpdate ? (
                                <button
                                    type="button"
                                    onClick={() => onDuplicate?.(task)}
                                    className="inline-flex items-center gap-1 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-[var(--app-text)]"
                                >
                                    <Copy className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    <span>Dupliquer</span>
                                </button>
                            ) : null}

                            {canDelete ? (
                                <button
                                    type="button"
                                    onClick={() => onDelete?.(task)}
                                    className="inline-flex items-center gap-1 rounded-lg border border-red-600 bg-red-600 px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em] text-white"
                                >
                                    <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    <span>Supprimer</span>
                                </button>
                            ) : null}
                        </span>
                    ) : null}
                </div>
            </div>

            {task.pointed && (task.pointed_at_label || task.pointed_by) ? (
                <div className="mt-2 text-[11px] text-[var(--app-muted)]">
                    Pointé {task.pointed_at_label ? `le ${task.pointed_at_label}` : ''}{task.pointed_by ? ` par ${task.pointed_by}` : ''}
                </div>
            ) : null}
        </div>
    );
}
