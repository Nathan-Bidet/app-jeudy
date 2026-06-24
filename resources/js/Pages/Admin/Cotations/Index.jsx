import AppLayout from '@/Layouts/AppLayout';
import { abilityLabel } from '@/Support/abilityLabels';
import { Head, useForm } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useMemo, useState } from 'react';

function hasAbility(list, ability) {
    return Array.isArray(list) && list.includes(ability);
}

function toggleAbility(list, ability) {
    const current = Array.isArray(list) ? list : [];
    if (current.includes(ability)) {
        return current.filter((item) => item !== ability);
    }

    return [...current, ability];
}

function PermissionCheckbox({ checked, onChange, label }) {
    return (
        <label className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold">
            <input type="checkbox" checked={checked} onChange={onChange} />
            <span>{label}</span>
        </label>
    );
}

function RoleSectorPanel({ title, rows, abilities, onToggle }) {
    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <h2 className="text-base font-black uppercase tracking-[0.08em]">{title}</h2>
            <div className="mt-4 space-y-3">
                {rows.map((row) => (
                    <div key={row.id} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                        <div className="mb-2 font-black">{row.name}</div>
                        <div className="flex flex-wrap gap-2">
                            {abilities.map((ability) => (
                                <PermissionCheckbox
                                    key={ability}
                                    checked={hasAbility(row.abilities, ability)}
                                    onChange={() => onToggle(row.id, ability)}
                                    label={abilityLabel(ability).replace('Cotations - ', '')}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}

function UserOverridesPanel({ users, abilities, onChange }) {
    const [query, setQuery] = useState('');
    const filteredUsers = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase('fr');
        if (!needle) return users;

        return users.filter((user) => [
            user.name,
            user.email,
            user.sector_name,
            user.role,
        ].filter(Boolean).join(' ').toLocaleLowerCase('fr').includes(needle));
    }, [query, users]);

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <h2 className="text-base font-black uppercase tracking-[0.08em]">Exceptions utilisateur</h2>
                <input
                    type="text"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Rechercher un utilisateur..."
                    className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm lg:w-72"
                />
            </div>

            <div className="mt-4 space-y-3">
                {filteredUsers.map((user) => (
                    <div key={user.id} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                        <div className="mb-3">
                            <div className="font-black">{user.name}</div>
                            <div className="text-xs text-[var(--app-muted)]">
                                {[user.email, user.role, user.sector_name].filter(Boolean).join(' • ')}
                            </div>
                        </div>

                        <div className="grid gap-2 md:grid-cols-3">
                            {abilities.map((ability) => (
                                <label key={ability} className="block">
                                    <span className="mb-1 block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        {abilityLabel(ability).replace('Cotations - ', '')}
                                    </span>
                                    <select
                                        value={user.overrides?.[ability] || 'inherit'}
                                        onChange={(event) => onChange(user.id, ability, event.target.value)}
                                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                                    >
                                        <option value="inherit">Hériter</option>
                                        <option value="allow">Autoriser</option>
                                        <option value="deny">Refuser</option>
                                    </select>
                                </label>
                            ))}
                        </div>
                    </div>
                ))}

                {filteredUsers.length === 0 ? (
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4 text-sm text-[var(--app-muted)]">
                        Aucun utilisateur trouvé.
                    </div>
                ) : null}
            </div>
        </section>
    );
}

export default function AdminCotationsIndex({
    abilities = [],
    roles = [],
    sectors = [],
    users = [],
}) {
    const form = useForm({
        roles: roles.map((role) => ({ id: role.id, name: role.name, abilities: role.abilities || [] })),
        sectors: sectors.map((sector) => ({ id: sector.id, name: sector.name, abilities: sector.abilities || [] })),
        users: users.map((user) => ({
            id: user.id,
            name: user.name,
            email: user.email,
            role: user.role,
            sector_name: user.sector_name,
            overrides: user.overrides || {},
        })),
    });

    const toggleRole = (id, ability) => {
        form.setData('roles', form.data.roles.map((role) => (
            Number(role.id) === Number(id) ? { ...role, abilities: toggleAbility(role.abilities, ability) } : role
        )));
    };

    const toggleSector = (id, ability) => {
        form.setData('sectors', form.data.sectors.map((sector) => (
            Number(sector.id) === Number(id) ? { ...sector, abilities: toggleAbility(sector.abilities, ability) } : sector
        )));
    };

    const setUserOverride = (id, ability, effect) => {
        form.setData('users', form.data.users.map((user) => (
            Number(user.id) === Number(id)
                ? { ...user, overrides: { ...user.overrides, [ability]: effect } }
                : user
        )));
    };

    const submit = () => {
        form.put(route('admin.cotations.update'), {
            preserveScroll: true,
        });
    };

    const header = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 className="text-[22px] leading-none font-black uppercase tracking-[0.06em]">Droits Cotations</h1>
                <p className="mt-1 text-sm text-[var(--app-muted)]">
                    Gestion des accès par rôle, secteur et exception utilisateur.
                </p>
            </div>
            <button
                type="button"
                onClick={submit}
                disabled={form.processing}
                className="inline-flex items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] disabled:opacity-60"
            >
                <Save className="h-4 w-4" strokeWidth={2.4} />
                Enregistrer
            </button>
        </div>
    );

    return (
        <AppLayout title="Droits Cotations" header={header}>
            <Head title="Droits Cotations" />

            <div className="space-y-5">
                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 text-sm text-[var(--app-muted)]">
                    <strong className="text-[var(--app-text)]">Voir</strong> donne accès à la page Cotations.
                    {' '}<strong className="text-[var(--app-text)]">Modifier céréales/transports</strong> autorise les cotations et le transport.
                    {' '}<strong className="text-[var(--app-text)]">Modifier carburant</strong> autorise uniquement le bloc Prix carburant.
                    {' '}<strong className="text-[var(--app-text)]">Historique carburant</strong> autorise à consulter les anciennes versions des prix carburant.
                    {' '}<strong className="text-[var(--app-text)]">Administrer</strong> autorise cet écran de droits.
                </div>

                <div className="grid gap-5 xl:grid-cols-2">
                    <RoleSectorPanel
                        title="Rôles"
                        rows={form.data.roles}
                        abilities={abilities}
                        onToggle={toggleRole}
                    />
                    <RoleSectorPanel
                        title="Secteurs"
                        rows={form.data.sectors}
                        abilities={abilities}
                        onToggle={toggleSector}
                    />
                </div>

                <UserOverridesPanel
                    users={form.data.users}
                    abilities={abilities}
                    onChange={setUserOverride}
                />
            </div>
        </AppLayout>
    );
}
