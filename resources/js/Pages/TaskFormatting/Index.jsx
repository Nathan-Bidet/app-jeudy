import DeleteRuleModal from '@/Components/TaskFormatting/DeleteRuleModal';
import RuleFormModal from '@/Components/TaskFormatting/RuleFormModal';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { GripVertical, Pencil, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const SCOPE_LABELS = {
    task: 'Tâche',
    comment: 'Commentaire',
    both: 'Tâche + Commentaire',
};

const MATCH_TYPE_LABELS = {
    contains: 'Contient',
    starts_with: 'Commence par',
    regex: 'Regex',
};

function emptyFormData(options) {
    const scopes = Array.isArray(options?.scopes) && options.scopes.length ? options.scopes : ['both'];
    const matchTypes = Array.isArray(options?.match_types) && options.match_types.length ? options.match_types : ['contains'];

    return {
        name: '',
        scope: scopes[0],
        match_type: matchTypes[0],
        pattern: '',
        text_color: '',
        bg_color: '',
        is_active: true,
        applies_to_a_prevoir: true,
        applies_to_ldt: true,
        description: '',
    };
}

function targetLabel(rule) {
    const targets = [];

    if (rule?.applies_to_a_prevoir) {
        targets.push('À Prévoir');
    }

    if (rule?.applies_to_ldt) {
        targets.push('LDT');
    }

    return targets.length ? targets.join(' / ') : 'Aucun';
}

function previewStyle(rule) {
    const style = {};

    if (rule?.text_color) {
        style.color = rule.text_color;
    }

    if (rule?.bg_color) {
        style.backgroundColor = rule.bg_color;
    }

    return style;
}

export default function TaskFormattingIndex({
    rules = [],
    permissions = {},
    options = {},
}) {
    const canManage = Boolean(permissions?.can_manage);
    const defaults = useMemo(() => emptyFormData(options), [options]);

    const [isFormOpen, setIsFormOpen] = useState(false);
    const [editingRule, setEditingRule] = useState(null);
    const [deletingRule, setDeletingRule] = useState(null);
    const [localRules, setLocalRules] = useState(rules);
    const [dragState, setDragState] = useState(null);
    const [dropPreview, setDropPreview] = useState(null);

    const form = useForm(defaults);
    const deleteForm = useForm({});

    useEffect(() => {
        setLocalRules(rules);
    }, [rules]);

    const openCreateModal = () => {
        form.setData(defaults);
        form.clearErrors();
        setEditingRule(null);
        setIsFormOpen(true);
    };

    const openEditModal = (rule) => {
        form.setData({
            name: rule?.name || '',
            scope: rule?.scope || defaults.scope,
            match_type: rule?.match_type || defaults.match_type,
            pattern: rule?.pattern || '',
            text_color: rule?.text_color || '',
            bg_color: rule?.bg_color || '',
            is_active: Boolean(rule?.is_active),
            applies_to_a_prevoir: Boolean(rule?.applies_to_a_prevoir),
            applies_to_ldt: Boolean(rule?.applies_to_ldt),
            description: rule?.description || '',
        });
        form.clearErrors();
        setEditingRule(rule);
        setIsFormOpen(true);
    };

    const closeFormModal = () => {
        setIsFormOpen(false);
        setEditingRule(null);
        form.clearErrors();
    };

    const submitForm = (event) => {
        event.preventDefault();

        const request = editingRule
            ? form.put(route('task.formatting.update', editingRule.id), {
                preserveScroll: true,
                onSuccess: closeFormModal,
            })
            : form.post(route('task.formatting.store'), {
                preserveScroll: true,
                onSuccess: closeFormModal,
            });

        return request;
    };

    const confirmDelete = () => {
        if (!deletingRule) {
            return;
        }

        deleteForm.delete(route('task.formatting.destroy', deletingRule.id), {
            preserveScroll: true,
            onSuccess: () => setDeletingRule(null),
        });
    };

    const buildReorderedRules = (rows, draggedId, targetId, position = 'before') => {
        const from = rows.findIndex((row) => Number(row.id) === Number(draggedId));
        const to = rows.findIndex((row) => Number(row.id) === Number(targetId));
        if (from === -1 || to === -1 || Number(draggedId) === Number(targetId)) return rows;

        const nextRows = [...rows];
        const [moved] = nextRows.splice(from, 1);
        const targetIndex = nextRows.findIndex((row) => Number(row.id) === Number(targetId));
        if (targetIndex === -1) return rows;
        const insertIndex = position === 'after' ? targetIndex + 1 : targetIndex;
        nextRows.splice(insertIndex, 0, moved);
        return nextRows;
    };

    const onDragStartRule = (event, rule) => {
        if (!canManage) return;
        setDragState({ ruleId: rule.id });
        setDropPreview(null);
        event.dataTransfer.effectAllowed = 'move';
    };

    const onDragEndRule = () => {
        setDragState(null);
        setDropPreview(null);
    };

    const onDragOverRule = (event, rule) => {
        if (!canManage || !dragState) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';

        const rect = event.currentTarget.getBoundingClientRect();
        const midpoint = rect.top + (rect.height / 2);
        const position = event.clientY >= midpoint ? 'after' : 'before';

        setDropPreview((prev) => {
            const next = {
                targetRuleId: rule.id,
                position,
            };

            if (
                prev
                && Number(prev.targetRuleId) === Number(next.targetRuleId)
                && prev.position === next.position
            ) {
                return prev;
            }

            return next;
        });
    };

    const onDropRule = (event, targetRule) => {
        event.preventDefault();
        if (!canManage || !dragState) return;
        if (Number(dragState.ruleId) === Number(targetRule.id)) {
            setDragState(null);
            setDropPreview(null);
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const fallbackPosition = event.clientY >= rect.top + (rect.height / 2) ? 'after' : 'before';
        const dropPosition = dropPreview && Number(dropPreview.targetRuleId) === Number(targetRule.id)
            ? dropPreview.position
            : fallbackPosition;

        const nextRows = buildReorderedRules(localRules, dragState.ruleId, targetRule.id, dropPosition);
        if (nextRows !== localRules) {
            setLocalRules(nextRows);
            router.patch(
                route('task.formatting.reorder'),
                { ordered_ids: nextRows.map((row) => row.id) },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onError: () => setLocalRules(rules),
                },
            );
        }

        setDragState(null);
        setDropPreview(null);
    };

    return (
        <AppLayout title="Mise en forme">
            <Head title="Mise en forme" />

            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm sm:p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-[var(--app-text)]">Règles de mise en forme des tâches</h2>
                        <p className="mt-1 text-sm text-[var(--app-muted)]">
                            Ces règles colorent automatiquement les lignes dans À Prévoir et Livre du Travail.
                        </p>
                    </div>

                    {canManage ? (
                        <button
                            type="button"
                            onClick={openCreateModal}
                            className="inline-flex items-center gap-2 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold text-[var(--app-text)]"
                        >
                            <Plus className="h-4 w-4" />
                            Ajouter
                        </button>
                    ) : null}
                </div>

                <div className="mt-4 overflow-x-auto rounded-xl border border-[var(--app-border)]">
                    <table className="min-w-full divide-y divide-[var(--app-border)] text-sm">
                        <thead className="bg-[var(--app-surface-soft)] text-left">
                            <tr>
                                <th className="px-3 py-2 font-semibold">Nom</th>
                                <th className="px-3 py-2 font-semibold">Pattern</th>
                                <th className="px-3 py-2 font-semibold">Scope</th>
                                <th className="px-3 py-2 font-semibold">Type de match</th>
                                <th className="px-3 py-2 font-semibold">Aperçu couleur</th>
                                <th className="px-3 py-2 font-semibold">Ordre</th>
                                <th className="px-3 py-2 font-semibold">Active</th>
                                <th className="px-3 py-2 font-semibold">Cible</th>
                                {canManage ? <th className="px-3 py-2 font-semibold text-right">Actions</th> : null}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[var(--app-border)]">
                            {localRules.length === 0 ? (
                                <tr>
                                    <td className="px-3 py-4 text-[var(--app-muted)]" colSpan={canManage ? 9 : 8}>
                                        Aucune règle configurée.
                                    </td>
                                </tr>
                            ) : localRules.map((rule, index) => (
                                <tr
                                    key={rule.id}
                                    draggable={canManage}
                                    onDragStart={(event) => onDragStartRule(event, rule)}
                                    onDragEnd={onDragEndRule}
                                    onDragOver={(event) => onDragOverRule(event, rule)}
                                    onDrop={(event) => onDropRule(event, rule)}
                                    className={
                                        dropPreview && Number(dropPreview.targetRuleId) === Number(rule.id)
                                            ? (dropPreview.position === 'before' ? 'border-t-2 border-t-[var(--brand-yellow-dark)]' : 'border-b-2 border-b-[var(--brand-yellow-dark)]')
                                            : ''
                                    }
                                >
                                    <td className="px-3 py-2">
                                        <p className="font-semibold inline-flex items-center gap-2">
                                            {canManage ? <GripVertical className="h-4 w-4 text-[var(--app-muted)]" /> : null}
                                            <span>{rule.name}</span>
                                        </p>
                                        {rule.description ? (
                                            <p className="text-xs text-[var(--app-muted)]">{rule.description}</p>
                                        ) : null}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">{rule.pattern}</td>
                                    <td className="px-3 py-2">{SCOPE_LABELS[rule.scope] || rule.scope}</td>
                                    <td className="px-3 py-2">{MATCH_TYPE_LABELS[rule.match_type] || rule.match_type}</td>
                                    <td className="px-3 py-2">
                                        <span
                                            className="inline-flex rounded-md border border-[var(--app-border)] px-2 py-1 text-xs"
                                            style={previewStyle(rule)}
                                        >
                                            Exemple
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className="inline-flex rounded-full bg-[var(--app-surface-soft)] px-2 py-0.5 text-xs font-semibold">
                                            #{index + 1}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${rule.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-700'}`}>
                                            {rule.is_active ? 'Oui' : 'Non'}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2">{targetLabel(rule)}</td>
                                    {canManage ? (
                                        <td className="px-3 py-2 text-right">
                                            <div className="inline-flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => openEditModal(rule)}
                                                    className="inline-flex items-center gap-1 rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1 text-xs font-semibold"
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                    Modifier
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeletingRule(rule)}
                                                    className="inline-flex items-center gap-1 rounded-md border border-red-300 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                    Supprimer
                                                </button>
                                            </div>
                                        </td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <RuleFormModal
                show={isFormOpen}
                mode={editingRule ? 'edit' : 'create'}
                form={form}
                scopes={options?.scopes || []}
                matchTypes={options?.match_types || []}
                canManage={canManage}
                onClose={closeFormModal}
                onSubmit={submitForm}
            />

            <DeleteRuleModal
                rule={deletingRule}
                processing={deleteForm.processing}
                onClose={() => setDeletingRule(null)}
                onConfirm={confirmDelete}
            />
        </AppLayout>
    );
}
