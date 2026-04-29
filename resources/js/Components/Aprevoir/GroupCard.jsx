import TaskRow from '@/Components/Aprevoir/TaskRow';
import { Plus } from 'lucide-react';

export default function GroupCard({
    group,
    depotPlaceMap = {},
    highlightedTaskId = null,
    savingTaskIds = {},
    canCreate = false,
    canUpdate = false,
    canDelete = false,
    canPoint = false,
    onCreateInGroup,
    onEditTask,
    onDuplicateTask,
    onDeleteTask,
    onTogglePoint,
    onDragStartTask,
    onDragOverTask,
    onDropTask,
}) {
    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2.5 shadow-sm sm:p-5">
            <div className="mb-2.5 flex flex-wrap items-start justify-between gap-3 sm:mb-4">
                <div>
                    <p className="text-xs font-black uppercase tracking-[0.12em] text-[var(--app-muted)]">
                        {group?.date_label || group?.date || 'Date'}
                    </p>
                    <h3 className="mt-1 text-base font-extrabold text-[var(--app-text)]">
                        {group?.assignee?.name || 'Assignataire'}
                    </h3>
                    <p className="mt-1 text-xs text-[var(--app-muted)]">
                        {group?.assignee?.type === 'depot' ? 'Dépôt' : 'Utilisateur'} • {group?.tasks?.length || 0} tâche(s)
                    </p>
                </div>

                {canCreate ? (
                    <button
                        type="button"
                        onClick={() => onCreateInGroup?.(group)}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                    >
                        <Plus className="h-3.5 w-3.5" strokeWidth={2.3} />
                        <span>Ajouter</span>
                    </button>
                ) : null}
            </div>

            <div className="space-y-3 lg:hidden">
                {(group?.tasks || []).map((task) => (
                    <TaskRow
                        key={task.id}
                        task={task}
                        placeResolver={depotPlaceMap}
                        highlighted={Number(highlightedTaskId || 0) === Number(task?.id || 0)}
                        saving={Boolean(savingTaskIds?.[task?.id])}
                        draggable={false}
                        canUpdate={canUpdate}
                        canDelete={canDelete}
                        canPoint={canPoint}
                        onDragStart={(event, draggedTask) => onDragStartTask?.(event, group, draggedTask)}
                        onDragOver={(event, targetTask) => onDragOverTask?.(event, group, targetTask)}
                        onDrop={(event, targetTask) => onDropTask?.(event, group, targetTask)}
                        onEdit={onEditTask}
                        onDuplicate={onDuplicateTask}
                        onDelete={onDeleteTask}
                        onTogglePoint={onTogglePoint}
                    />
                ))}
            </div>
        </section>
    );
}
