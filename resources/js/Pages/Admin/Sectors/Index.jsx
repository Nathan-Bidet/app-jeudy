import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import AdminLayout from '@/Layouts/AdminLayout';
import { abilityLabel } from '@/Support/abilityLabels';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, Copy, Save, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const ABILITY_GROUP_ORDER = [
    'dashboard',
    'directory',
    'a_prevoir',
    'ldt',
    'task.data',
    'task.formatting',
    'heures',
    'admin.users',
    'admin.sectors',
    'admin.access',
    'admin.logs',
    'admin.entities',
    'other',
];

const ABILITY_GROUP_LABELS = {
    dashboard: 'Dashboard',
    directory: 'Annuaire',
    a_prevoir: 'À Prévoir',
    ldt: 'Livre du Travail',
    'task.data': 'Tâches - Données',
    'task.formatting': 'Mise en forme',
    heures: 'Heures',
    'admin.users': 'Administration - Utilisateurs',
    'admin.sectors': 'Administration - Secteurs',
    'admin.access': 'Administration - Accès',
    'admin.logs': 'Administration - Logs',
    'admin.entities': 'Administration - Entités',
    other: 'Autres permissions',
};

function abilityGroupKey(ability) {
    if (ability.startsWith('task.formatting.')) return 'task.formatting';
    if (ability.startsWith('task.data.')) return 'task.data';
    if (ability.startsWith('heures.')) return 'heures';
    if (ability.startsWith('admin.users.')) return 'admin.users';
    if (ability.startsWith('admin.sectors.')) return 'admin.sectors';
    if (ability.startsWith('admin.access.')) return 'admin.access';
    if (ability.startsWith('admin.logs.')) return 'admin.logs';
    if (ability.startsWith('admin.entities.')) return 'admin.entities';

    const [prefix] = ability.split('.');

    if (['dashboard', 'directory', 'a_prevoir', 'ldt'].includes(prefix)) {
        return prefix;
    }

    return 'other';
}

function groupAbilities(abilities) {
    const grouped = abilities.reduce((acc, ability) => {
        const key = abilityGroupKey(ability);
        if (!acc.has(key)) {
            acc.set(key, []);
        }
        acc.get(key).push(ability);
        return acc;
    }, new Map());

    return Array.from(grouped.entries())
        .sort(([left], [right]) => {
            const leftIndex = ABILITY_GROUP_ORDER.indexOf(left);
            const rightIndex = ABILITY_GROUP_ORDER.indexOf(right);
            const safeLeft = leftIndex === -1 ? Number.MAX_SAFE_INTEGER : leftIndex;
            const safeRight = rightIndex === -1 ? Number.MAX_SAFE_INTEGER : rightIndex;
            return safeLeft - safeRight;
        })
        .map(([key, list]) => ({
            key,
            label: ABILITY_GROUP_LABELS[key] || key,
            abilities: [...list].sort((left, right) => left.localeCompare(right)),
        }));
}

function SectorRow({ sector, abilities }) {
    const form = useForm({
        name: sector.name,
        description: sector.description ?? '',
        default_abilities: sector.default_abilities ?? [],
    });

    const destroyForm = useForm({});
    const duplicateForm = useForm({});
    const [isDeleting, setIsDeleting] = useState(false);
    const groupedAbilities = useMemo(() => groupAbilities(abilities), [abilities]);
    const [showPermissions, setShowPermissions] = useState(false);
    const [openGroups, setOpenGroups] = useState({});

    useEffect(() => {
        form.setData('default_abilities', sector.default_abilities ?? []);
    }, [sector.default_abilities]);

    useEffect(() => {
        const nextState = {};
        groupedAbilities.forEach((group) => {
            nextState[group.key] = false;
        });

        setOpenGroups(nextState);
        setShowPermissions(false);
    }, [sector.id, groupedAbilities]);

    useEffect(() => {
        if (form.errors.default_abilities) {
            setShowPermissions(true);
        }
    }, [form.errors.default_abilities]);

    const submit = (event) => {
        event.preventDefault();

        form.put(route('admin.sectors.save', sector.id), {
            preserveScroll: true,
        });
    };

    const toggleAbility = (ability) => {
        const selected = new Set(form.data.default_abilities ?? []);

        if (selected.has(ability)) {
            selected.delete(ability);
        } else {
            selected.add(ability);
        }

        form.setData('default_abilities', Array.from(selected));
    };

    const destroy = () => {
        setIsDeleting(true);
        destroyForm.delete(route('admin.sectors.destroy', sector.id), {
            preserveScroll: true,
            onFinish: () => setIsDeleting(false),
        });
    };

    const duplicate = () => {
        duplicateForm.post(route('admin.sectors.duplicate', sector.id), {
            preserveScroll: true,
        });
    };

    const toggleGroup = (groupKey) => {
        setOpenGroups((prev) => ({
            ...prev,
            [groupKey]: !prev[groupKey],
        }));
    };

    return (
        <div className="space-y-4 rounded border border-gray-200 p-4">
            <div className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Nom</label>
                    <input
                        className="w-full rounded border-gray-300"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                    <InputError className="mt-2" message={form.errors.name} />
                </div>

                <div>
                    <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                    <input
                        className="w-full rounded border-gray-300"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.target.value)}
                    />
                    <InputError className="mt-2" message={form.errors.description} />
                </div>

                <div className="text-xs text-gray-500">
                    <p>Slug: {sector.slug}</p>
                    <p>Users: {sector.users_count}</p>
                </div>

            </div>

            <form onSubmit={submit} className="space-y-3 rounded-md bg-gray-50 p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <h4 className="min-w-0 flex-1 text-sm font-semibold text-gray-800">
                        Permissions par défaut du secteur
                    </h4>
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-gray-600 sm:text-right">
                            {form.data.default_abilities.length} sélectionnée(s)
                        </span>
                        <button
                            type="button"
                            onClick={() => setShowPermissions((prev) => !prev)}
                            className="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-2 py-1 text-[11px] font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            <ChevronDown
                                className={`h-3.5 w-3.5 transition-transform ${showPermissions ? 'rotate-180' : ''}`}
                                strokeWidth={2.2}
                            />
                            {showPermissions ? 'Masquer' : 'Afficher'}
                        </button>
                    </div>
                </div>

                {showPermissions ? (
                    <>
                        <div className="space-y-2">
                            {groupedAbilities.map((group) => {
                                const selectedCount = group.abilities.filter((ability) =>
                                    (form.data.default_abilities ?? []).includes(ability)
                                ).length;
                                const isOpen = Boolean(openGroups[group.key]);

                                return (
                                    <div key={group.key} className="overflow-hidden rounded-md border border-gray-200 bg-white">
                                        <button
                                            type="button"
                                            onClick={() => toggleGroup(group.key)}
                                            className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-xs hover:bg-gray-50"
                                        >
                                            <span className="font-semibold text-gray-800">
                                                {group.label}
                                                <span className="ml-2 text-[11px] font-medium text-gray-500">
                                                    ({selectedCount}/{group.abilities.length})
                                                </span>
                                            </span>
                                            <ChevronDown
                                                className={`h-4 w-4 text-gray-500 transition-transform ${isOpen ? 'rotate-180' : ''}`}
                                                strokeWidth={2.2}
                                            />
                                        </button>

                                        {isOpen ? (
                                            <div className="grid gap-2 border-t border-gray-100 bg-gray-50 p-2 sm:grid-cols-2">
                                                {group.abilities.map((ability) => {
                                                    const checked = (form.data.default_abilities ?? []).includes(ability);

                                                    return (
                                                        <label
                                                            key={ability}
                                                            className={`flex cursor-pointer items-center gap-2 rounded border px-2 py-1.5 text-xs ${
                                                                checked
                                                                    ? 'border-green-300 bg-green-50 text-green-800'
                                                                    : 'border-gray-200 bg-white text-gray-700'
                                                            }`}
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                className="rounded border-gray-300 text-green-600 focus:ring-green-500"
                                                                checked={checked}
                                                                onChange={() => toggleAbility(ability)}
                                                            />
                                                            <span className="min-w-0">
                                                                <span className="block truncate font-semibold">{abilityLabel(ability)}</span>
                                                                <span className="block truncate font-mono text-[10px] opacity-75">{ability}</span>
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })}
                        </div>

                        <InputError className="mt-2" message={form.errors.default_abilities} />
                    </>
                ) : null}

                <div className="flex flex-wrap justify-end gap-2">
                    <button
                        type="button"
                        onClick={duplicate}
                        disabled={duplicateForm.processing || form.processing || isDeleting}
                        className="inline-flex w-full items-center justify-center rounded border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-60 sm:w-auto"
                    >
                        <span className="inline-flex items-center gap-1.5">
                            <Copy className="h-4 w-4" strokeWidth={2.2} />
                            <span>Dupliquer</span>
                        </span>
                    </button>
                    <button
                        type="button"
                        onClick={destroy}
                        disabled={isDeleting || form.processing || duplicateForm.processing}
                        className="inline-flex w-full items-center justify-center rounded border border-red-200 px-3 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-60 sm:w-auto"
                    >
                        <span className="inline-flex items-center gap-1.5">
                            <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                            <span>Supprimer</span>
                        </span>
                    </button>
                    <SecondaryButton
                        type="submit"
                        disabled={form.processing}
                        className="w-full justify-center gap-1.5 sm:w-auto"
                    >
                        <span className="inline-flex items-center gap-1.5">
                            <Save className="h-4 w-4" strokeWidth={2.2} />
                            <span>Enregistrer</span>
                        </span>
                    </SecondaryButton>
                </div>
            </form>
        </div>
    );
}

export default function AdminSectorsIndex({ sectors, abilities = [] }) {
    const form = useForm({
        name: '',
        description: '',
    });

    const submit = (event) => {
        event.preventDefault();

        form.post(route('admin.sectors.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'description'),
        });
    };

    return (
        <AdminLayout title="Admin - Secteurs">
            <Head title="Admin Secteurs" />

            <div className="space-y-6">
                <section className="rounded-lg bg-white p-4 shadow-sm sm:p-6">
                    <h3 className="text-lg font-semibold text-gray-900">Nouveau secteur</h3>
                    <form onSubmit={submit} className="mt-4 grid gap-4 sm:grid-cols-3 sm:items-end">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Nom</label>
                            <input
                                className="w-full rounded border-gray-300"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                            <InputError className="mt-2" message={form.errors.name} />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-gray-700">Description</label>
                            <input
                                className="w-full rounded border-gray-300"
                                value={form.data.description}
                                onChange={(event) => form.setData('description', event.target.value)}
                            />
                            <InputError className="mt-2" message={form.errors.description} />
                        </div>

                        <div>
                            <PrimaryButton disabled={form.processing} className="w-full justify-center sm:w-auto">
                                Créer secteur
                            </PrimaryButton>
                        </div>
                    </form>
                </section>

                <section className="space-y-3 rounded-lg bg-white p-4 shadow-sm sm:p-6">
                    <h3 className="text-lg font-semibold text-gray-900">Secteurs existants</h3>
                    {sectors.map((sector) => (
                        <SectorRow key={sector.id} sector={sector} abilities={abilities} />
                    ))}
                </section>
            </div>
        </AdminLayout>
    );
}
