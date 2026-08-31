import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { CalendarCheck, CalendarDays, CheckCircle2, Circle, EyeOff, Flag, Pencil, Trash2 } from 'lucide-react';

const ACTION_TONES = {
    neutral: 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]',
    danger: 'border-[var(--app-border)] bg-[var(--app-surface)] text-red-600 hover:border-red-400',
    active: 'border-emerald-600 bg-emerald-600 text-white',
    muted: 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] hover:border-[var(--brand-yellow-dark)]',
    dashed: 'border-dashed border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] hover:border-[var(--brand-yellow-dark)]',
};

const ACTION_BASE =
    'inline-flex items-center justify-center rounded-lg border disabled:cursor-not-allowed disabled:opacity-60';

/** Action libellée : occupe toute la largeur de la colonne. */
function ActionButton({ icon: Icon, label, title, onClick, disabled = false, tone = 'neutral', pressed }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title}
            aria-pressed={pressed}
            className={`${ACTION_BASE} w-full gap-1.5 px-2 py-1.5 text-[10px] font-bold uppercase tracking-[0.08em] ${ACTION_TONES[tone]}`}
        >
            <Icon className="h-3.5 w-3.5 shrink-0" strokeWidth={2.2} />
            <span>{label}</span>
        </button>
    );
}

/**
 * Action réduite à son icône. Le libellé reste porté par title et aria-label,
 * pour rester identifiable au survol comme aux technologies d'assistance.
 */
function IconActionButton({ icon: Icon, label, title, onClick, disabled = false, tone = 'neutral', pressed }) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title || label}
            aria-label={label}
            aria-pressed={pressed}
            className={`${ACTION_BASE} h-8 w-8 shrink-0 ${ACTION_TONES[tone]}`}
        >
            <Icon className="h-3.5 w-3.5" strokeWidth={2.2} />
        </button>
    );
}

function PointingButton({ active, label, title, onClick, disabled = false }) {
    return (
        <ActionButton
            icon={active ? CheckCircle2 : Circle}
            label={label}
            title={title}
            onClick={onClick}
            disabled={disabled}
            pressed={active}
            tone={active ? 'active' : 'neutral'}
        />
    );
}

/** État du partiel en lecture seule, pour qui n'a pas le droit de le modifier. */
function PartialStateBadge({ active }) {
    const Icon = active ? CheckCircle2 : Circle;

    return (
        <span
            title={active ? 'Pointé partiellement par la personne affectée' : 'Pas encore pointé par la personne affectée'}
            className={`${ACTION_BASE} w-full gap-1.5 border-dashed px-2 py-1.5 text-[10px] font-bold uppercase tracking-[0.08em] ${
                active
                    ? 'border-emerald-500 text-emerald-700'
                    : 'border-[var(--app-border)] text-[var(--app-muted)]'
            }`}
        >
            <Icon className="h-3.5 w-3.5 shrink-0" strokeWidth={2.2} />
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
    // Première ligne : les actions les plus utilisées, réduites à leur icône.
    const hasPrimaryRow = task.can_point || task.can_update || task.can_delete;
    // Dessous : les actions libellées, chacune sur sa ligne.
    const hasStackedActions =
        task.can_partial_point || showPartialState || task.first_pointed_on_label
        || task.can_edit_pointing_date;

    return (
        <article className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 sm:p-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                {/* Colonne gauche : tout ce qui décrit la tâche. */}
                <div className="min-w-0 flex-1">
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

                {/* Aucun commentaire transmis : rien n'est rendu, pas même un
                    espace. Une tâche dont le commentaire est masqué se présente
                    exactement comme une tâche sans commentaire. */}
                {task.comment ? (
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

                {/* Le bouton « Dater » n'affiche plus la valeur : elle reste
                    lisible ici, avec le reste de l'historique. */}
                {task.first_pointed_on_label ? (
                    <p className="mt-0.5 text-[11px] text-[var(--app-muted)]">
                        Premier pointage le {task.first_pointed_on_label}
                    </p>
                ) : null}

                <p className="mt-2 text-[11px] text-[var(--app-muted)]">
                    {task.is_request ? 'Demandé' : 'Créé'} par {task.created_by || '—'}
                    {task.updated_by && task.updated_by !== task.created_by ? ` • modifié par ${task.updated_by}` : ''}
                </p>
                </div>

                {/* Colonne droite : toutes les actions, alignées verticalement.
                    Compactes et en ligne sur mobile, empilées dès sm. */}
                {hasPrimaryRow || hasStackedActions ? (
                    <div className="flex shrink-0 flex-col gap-2 sm:w-32">
                        {hasPrimaryRow ? (
                            /* Les trois icônes sont sœurs dans un unique flex :
                               une seule gouttière, donc des écarts forcément
                               identiques, et le groupe se centre dans la
                               colonne. Un ml-auto sur une paire creusait au
                               contraire l'écart avant Modifier. */
                            <div className="flex items-center justify-center gap-2">
                                {task.can_point ? (
                                    <IconActionButton
                                        icon={pointed ? CheckCircle2 : Circle}
                                        label="Pointer"
                                        title={
                                            pointed
                                                ? 'Retirer le pointage définitif'
                                                : 'Pointer définitivement'
                                        }
                                        tone={pointed ? 'active' : 'neutral'}
                                        pressed={pointed}
                                        disabled={saving}
                                        onClick={() => onTogglePoint?.(task, !pointed)}
                                    />
                                ) : null}

                                {task.can_update ? (
                                    <IconActionButton
                                        icon={Pencil}
                                        label="Modifier"
                                        onClick={() => onEdit?.(task)}
                                    />
                                ) : null}

                                {task.can_delete ? (
                                    <IconActionButton
                                        icon={Trash2}
                                        label="Supprimer"
                                        tone="danger"
                                        disabled={deleting}
                                        onClick={() => onDelete?.(task)}
                                    />
                                ) : null}
                            </div>
                        ) : null}

                        {hasStackedActions ? (
                            <div
                                className={`flex flex-col gap-2 ${
                                    hasPrimaryRow ? 'border-t border-[var(--app-border)] pt-2' : ''
                                }`}
                            >
                                {task.can_partial_point ? (
                                    <PointingButton
                                        active={partiallyPointed}
                                        label="Partiel"
                                        title={
                                            partiallyPointed
                                                ? 'Retirer mon pointage partiel'
                                                : 'Pointer partiellement'
                                        }
                                        disabled={saving}
                                        onClick={() => onTogglePartialPoint?.(task, !partiallyPointed)}
                                    />
                                ) : showPartialState ? (
                                    <PartialStateBadge active={partiallyPointed} />
                                ) : null}

                                {task.first_pointed_on_label ? (
                                    <ActionButton
                                        icon={CalendarCheck}
                                        label="Dater"
                                        title={`Premier pointage le ${task.first_pointed_on_label}${
                                            task.can_edit_pointing_date ? ' — cliquer pour corriger' : ''
                                        }`}
                                        tone="muted"
                                        disabled={!task.can_edit_pointing_date}
                                        onClick={
                                            task.can_edit_pointing_date
                                                ? () => onEditPointingDate?.(task)
                                                : undefined
                                        }
                                    />
                                ) : task.can_edit_pointing_date ? (
                                    <ActionButton
                                        icon={CalendarCheck}
                                        label="Dater"
                                        title="Renseigner la date du premier pointage"
                                        tone="dashed"
                                        onClick={() => onEditPointingDate?.(task)}
                                    />
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </article>
    );
}
