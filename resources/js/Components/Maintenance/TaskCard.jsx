import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { CalendarCheck, CalendarDays, CheckCircle2, Circle, ClipboardCheck, EyeOff, Flag, Pencil, Trash2 } from 'lucide-react';

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
            title="Marqué effectué par la personne affectée"
            className={`${ACTION_BASE} w-full gap-1.5 border-dashed px-2 py-1.5 text-[10px] font-bold uppercase tracking-[0.08em] ${
                active
                    ? 'border-emerald-500 text-emerald-700'
                    : 'border-[var(--app-border)] text-[var(--app-muted)]'
            }`}
        >
            <Icon className="h-3.5 w-3.5 shrink-0" strokeWidth={2.2} />
            <span>Effectué</span>
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
    onConvert,
    deleting = false,
    saving = false,
}) {
    // Une demande en attente n'est pas encore une tâche : aucune action de
    // tâche n'a de sens dessus, seule sa prise en charge en a une.
    const isPendingRequest = Boolean(task.is_request);
    const partiallyPointed = Boolean(task.partially_pointed);
    const pointed = Boolean(task.pointed);
    // Le pointage a commencé dès qu'il y a quelque chose à dater : la personne
    // affectée a validé, ou le définitif a déjà été posé.
    const pointingStarted = partiallyPointed || pointed || Boolean(task.first_pointed_on_label);
    // Le responsable ne voit l'état « Effectué » qu'une fois la personne
    // affectée passée par là — jamais une case vide en attente.
    const showPartialState = !task.can_partial_point && task.can_point && partiallyPointed;
    // Dater n'a de sens qu'à partir du moment où une date existe ou peut naître.
    const showPointingDate = Boolean(task.can_edit_pointing_date) && pointingStarted;
    // Traces de pointage, dans l'ordre chronologique du suivi. Une seule dans
    // le cas courant : elle occupe alors la droite de la ligne de suivi.
    const trackingNotes = [
        partiallyPointed && task.partially_pointed_by
            ? `Effectué par ${task.partially_pointed_by}${
                task.partially_pointed_at_label ? ` le ${task.partially_pointed_at_label}` : ''
            }`
            : null,
        pointed && task.pointed_by
            ? `Pointé par ${task.pointed_by}${
                task.pointed_at_label ? ` le ${task.pointed_at_label}` : ''
            }`
            : null,
        task.first_pointed_on_label ? `Premier pointage le ${task.first_pointed_on_label}` : null,
    ].filter(Boolean);

    // Première ligne : les actions les plus utilisées, réduites à leur icône.
    const hasPrimaryRow =
        ! isPendingRequest && (task.can_point || task.can_update || task.can_delete);
    // Dessous : les actions libellées, chacune sur sa ligne.
    const hasStackedActions =
        ! isPendingRequest && (task.can_partial_point || showPartialState || showPointingDate);

    return (
        <article
            className={`rounded-xl border border-[var(--app-border)] p-3 sm:p-4 ${
                // Une demande se détache sur le fond hachuré de son groupe.
                task.is_request ? 'bg-[var(--app-surface)]' : 'bg-[var(--app-surface-soft)]'
            }`}
        >
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                {/* Colonne gauche : tout ce qui décrit la tâche. */}
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2 text-xs font-bold text-[var(--app-muted)]">
                        {isPendingRequest ? (
                            task.due_label ? (
                                <span className="inline-flex items-center gap-1 rounded-lg border-2 border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-[11px] font-black uppercase tracking-[0.12em] text-[var(--app-text)]">
                                    <CalendarDays className="h-3.5 w-3.5" strokeWidth={2.2} />
                                    {task.due_label}
                                </span>
                            ) : null
                        ) : (
                            <DateRange task={task} />
                        )}

                        {task.due_label && ! isPendingRequest ? (
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
                                Pointée
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

                {/* Suivi : auteurs à gauche, traces de pointage à droite, sur
                    une même ligne. justify-between n'occupe aucune place quand
                    la partie droite est absente, et flex-wrap replie proprement
                    sur mobile plutôt que de déborder. */}
                <div className="mt-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-[11px] text-[var(--app-muted)]">
                    <span>
                        {task.is_request ? 'Demandé' : 'Créé'} par {task.created_by || '—'}
                        {task.updated_by && task.updated_by !== task.created_by
                            ? ` • Modifié par ${task.updated_by}`
                            : ''}
                    </span>

                    {trackingNotes.length ? (
                        <span className="flex flex-col gap-0.5 sm:items-end sm:text-right">
                            {trackingNotes.map((note) => (
                                <span key={note}>{note}</span>
                            ))}
                        </span>
                    ) : null}
                </div>
                </div>

                {/* Colonne droite : toutes les actions, alignées verticalement.
                    Compactes et en ligne sur mobile, empilées dès sm. */}
                {isPendingRequest ? (
                    /* Une seule zone d'actions, quels que soient les droits :
                       le demandeur qui peut aussi créer ne voit pas deux
                       boutons Supprimer. */
                    task.can_update || task.can_delete || task.can_convert ? (
                        <div className="flex shrink-0 flex-col gap-2 sm:w-32">
                            {task.can_update || task.can_delete ? (
                                <div className="flex items-center justify-center gap-2">
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

                            {task.can_convert ? (
                                <div
                                    className={
                                        task.can_update || task.can_delete
                                            ? 'border-t border-[var(--app-border)] pt-2'
                                            : ''
                                    }
                                >
                                    <ActionButton
                                        icon={ClipboardCheck}
                                        label="Créer la tâche"
                                        title="Transformer cette demande en tâche"
                                        onClick={() => onConvert?.(task)}
                                    />
                                </div>
                            ) : null}
                        </div>
                    ) : null
                ) : hasPrimaryRow || hasStackedActions ? (
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
                                        label="Effectué"
                                        title={
                                            partiallyPointed
                                                ? 'Revenir sur « effectué »'
                                                : 'Marquer la tâche comme effectuée'
                                        }
                                        disabled={saving}
                                        onClick={() => onTogglePartialPoint?.(task, !partiallyPointed)}
                                    />
                                ) : showPartialState ? (
                                    <PartialStateBadge active={partiallyPointed} />
                                ) : null}

                                {/* Dater n'apparaît qu'une fois le pointage
                                    entamé, et seulement à qui peut le corriger.
                                    La date reste lisible dans la ligne de suivi
                                    pour tous les autres. */}
                                {showPointingDate ? (
                                    <ActionButton
                                        icon={CalendarCheck}
                                        label="Dater"
                                        title={
                                            task.first_pointed_on_label
                                                ? `Premier pointage le ${task.first_pointed_on_label} — cliquer pour corriger`
                                                : 'Renseigner la date du premier pointage'
                                        }
                                        tone={task.first_pointed_on_label ? 'muted' : 'dashed'}
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
