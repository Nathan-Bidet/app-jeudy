import Modal from '@/Components/Modal';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function AdminLogsIndex({
    logs,
    filters = {},
    options = {},
}) {
    const [localFilters, setLocalFilters] = useState({
        user_id: filters.user_id ? String(filters.user_id) : '',
        module: filters.module || '',
        action: filters.action || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        per_page: String(filters.per_page || 25),
    });
    const [selectedLog, setSelectedLog] = useState(null);

    const users = Array.isArray(options?.users) ? options.users : [];
    const modules = Array.isArray(options?.modules) ? options.modules : [];
    const actions = Array.isArray(options?.actions) ? options.actions : [];
    const rows = Array.isArray(logs?.data) ? logs.data : [];
    const links = Array.isArray(logs?.links) ? logs.links : [];

    const payloadPreview = useMemo(() => {
        if (!selectedLog?.payload) {
            return 'Aucun payload';
        }

        try {
            return JSON.stringify(selectedLog.payload, null, 2);
        } catch {
            return 'Payload non affichable';
        }
    }, [selectedLog]);

    const applyFilters = () => {
        router.get(route('admin.logs.index'), {
            user_id: localFilters.user_id || undefined,
            module: localFilters.module || undefined,
            action: localFilters.action || undefined,
            date_from: localFilters.date_from || undefined,
            date_to: localFilters.date_to || undefined,
            per_page: localFilters.per_page || 25,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        const next = {
            user_id: '',
            module: '',
            action: '',
            date_from: '',
            date_to: '',
            per_page: '25',
        };
        setLocalFilters(next);

        router.get(route('admin.logs.index'), { per_page: 25 }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const openPage = (url) => {
        if (!url) return;
        router.visit(url, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const labelForPagination = (label) => {
        const raw = String(label || '')
            .replace(/<[^>]*>/g, '')
            .replace(/&nbsp;/g, ' ')
            .trim();

        const normalized = raw
            .replace(/&laquo;|«/g, '')
            .replace(/&raquo;|»/g, '')
            .trim()
            .toLowerCase();

        if (
            normalized.includes('previous')
            || normalized.includes('précédent')
            || normalized.includes('precedent')
            || normalized.includes('pagination.previous')
        ) {
            return '←';
        }

        if (
            normalized.includes('next')
            || normalized.includes('suivant')
            || normalized.includes('pagination.next')
        ) {
            return '→';
        }

        return raw;
    };

    return (
        <AdminLayout title="Admin - Logs">
            <Head title="Admin Logs" />

            <div className="space-y-6">
                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm sm:p-6">
                    <h3 className="text-lg font-semibold text-[var(--app-text)]">Filtres</h3>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-[var(--app-muted)]">Utilisateur</label>
                            <select
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                value={localFilters.user_id}
                                onChange={(event) => setLocalFilters((prev) => ({ ...prev, user_id: event.target.value }))}
                            >
                                <option value="">Tous</option>
                                {users.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-[var(--app-muted)]">Module</label>
                            <select
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                value={localFilters.module}
                                onChange={(event) => setLocalFilters((prev) => ({ ...prev, module: event.target.value }))}
                            >
                                <option value="">Tous</option>
                                {modules.map((module) => (
                                    <option key={module} value={module}>
                                        {module}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-[var(--app-muted)]">Action</label>
                            <select
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                value={localFilters.action}
                                onChange={(event) => setLocalFilters((prev) => ({ ...prev, action: event.target.value }))}
                            >
                                <option value="">Toutes</option>
                                {actions.map((action) => (
                                    <option key={action} value={action}>
                                        {action}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-[var(--app-muted)]">Du</label>
                            <input
                                type="date"
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                value={localFilters.date_from}
                                onChange={(event) => setLocalFilters((prev) => ({ ...prev, date_from: event.target.value }))}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-[var(--app-muted)]">Au</label>
                            <input
                                type="date"
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-text)]"
                                value={localFilters.date_to}
                                onChange={(event) => setLocalFilters((prev) => ({ ...prev, date_to: event.target.value }))}
                            />
                        </div>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={applyFilters}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-sm font-semibold text-[var(--color-black)]"
                        >
                            Appliquer
                        </button>
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold text-[var(--app-text)]"
                        >
                            Réinitialiser
                        </button>
                    </div>
                </section>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm sm:p-6">
                    <h3 className="text-lg font-semibold text-[var(--app-text)]">Journal d audit</h3>

                    <div className="mt-4 overflow-auto rounded-xl border border-[var(--app-border)]">
                        <table className="min-w-[980px] w-full border-collapse text-sm">
                            <thead>
                                <tr className="bg-[var(--app-surface-soft)] text-left text-xs font-semibold uppercase tracking-wide text-[var(--app-muted)]">
                                    <th className="border-b border-[var(--app-border)] px-3 py-2">Date</th>
                                    <th className="border-b border-[var(--app-border)] px-3 py-2">Utilisateur</th>
                                    <th className="border-b border-[var(--app-border)] px-3 py-2">Action</th>
                                    <th className="border-b border-[var(--app-border)] px-3 py-2">Module</th>
                                    <th className="border-b border-[var(--app-border)] px-3 py-2">Description</th>
                                    <th className="border-b border-[var(--app-border)] px-3 py-2">IP</th>
                                    <th className="border-b border-[var(--app-border)] px-3 py-2 text-right">Détail</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-3 py-6 text-center text-sm text-[var(--app-muted)]">
                                            Aucun log.
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((log) => (
                                        <tr key={log.id} className="odd:bg-[var(--app-surface)] even:bg-[var(--app-surface-soft)]">
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">{log.created_at_label || '-'}</td>
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">{log.user_name || 'Système'}</td>
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 font-mono text-xs text-[var(--app-muted)]">{log.action}</td>
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 font-mono text-xs text-[var(--app-muted)]">{log.module}</td>
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 text-[var(--app-text)]">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span>{log.description_display || log.description || '-'}</span>
                                                    {log.task_href && log.task_id ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => router.get(log.task_href, {}, {
                                                                preserveScroll: false,
                                                                preserveState: false,
                                                            })}
                                                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-2 py-0.5 text-xs font-semibold text-[var(--color-black)] hover:opacity-90"
                                                        >
                                                            Tâche #{log.task_id}
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 font-mono text-xs text-[var(--app-muted)]">{log.ip_address || '-'}</td>
                                            <td className="border-b border-[var(--app-border)] px-3 py-2 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedLog(log)}
                                                    className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-xs font-semibold text-[var(--app-text)]"
                                                >
                                                    Payload
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {links.length > 0 ? (
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-2">
                                {links.map((link, index) => (
                                    <button
                                        key={`${link.label}-${index}`}
                                        type="button"
                                        disabled={!link.url || link.active}
                                        onClick={() => openPage(link.url)}
                                        className={`rounded-xl border px-2.5 py-1.5 text-xs ${
                                            link.active
                                                ? 'border-[var(--app-border)] bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                                : 'border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] disabled:opacity-40'
                                        }`}
                                    >
                                        {labelForPagination(link.label)}
                                    </button>
                                ))}
                            </div>

                            <div className="flex items-center gap-2">
                                <label className="text-sm font-medium text-[var(--app-muted)]">Lignes/page</label>
                                <select
                                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-sm text-[var(--app-text)]"
                                    value={localFilters.per_page}
                                    onChange={(event) => {
                                        const value = event.target.value;
                                        setLocalFilters((prev) => ({ ...prev, per_page: value }));
                                        router.get(route('admin.logs.index'), {
                                            user_id: localFilters.user_id || undefined,
                                            module: localFilters.module || undefined,
                                            action: localFilters.action || undefined,
                                            date_from: localFilters.date_from || undefined,
                                            date_to: localFilters.date_to || undefined,
                                            per_page: value || 25,
                                            page: 1,
                                        }, {
                                            preserveScroll: true,
                                            preserveState: true,
                                            replace: true,
                                        });
                                    }}
                                >
                                    {[25, 50, 100, 150, 200].map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    ) : null}
                </section>
            </div>

            <Modal show={Boolean(selectedLog)} onClose={() => setSelectedLog(null)} maxWidth="4xl">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Détail du log</h3>
                </div>
                <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4 text-sm">
                    <div className="grid gap-2 sm:grid-cols-2">
                        <div><span className="font-semibold">Date:</span> {selectedLog?.created_at_label || '-'}</div>
                        <div><span className="font-semibold">Utilisateur:</span> {selectedLog?.user_name || 'Système'}</div>
                        <div><span className="font-semibold">Action:</span> {selectedLog?.action || '-'}</div>
                        <div><span className="font-semibold">Module:</span> {selectedLog?.module || '-'}</div>
                        <div><span className="font-semibold">Méthode:</span> {selectedLog?.method || '-'}</div>
                        <div><span className="font-semibold">Route:</span> {selectedLog?.route || '-'}</div>
                        {selectedLog?.task_href && selectedLog?.task_id ? (
                            <div className="sm:col-span-2">
                                <span className="font-semibold">Tâche:</span>{' '}
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSelectedLog(null);
                                        router.get(selectedLog.task_href, {}, {
                                            preserveScroll: false,
                                            preserveState: false,
                                        });
                                    }}
                                    className="text-[var(--app-text)] underline decoration-dotted underline-offset-2 hover:opacity-80"
                                >
                                    Ouvrir la tâche #{selectedLog.task_id} dans À Prévoir
                                </button>
                            </div>
                        ) : null}
                        <div className="sm:col-span-2"><span className="font-semibold">URL:</span> {selectedLog?.url || '-'}</div>
                        <div className="sm:col-span-2"><span className="font-semibold">User Agent:</span> {selectedLog?.user_agent || '-'}</div>
                    </div>

                    <div>
                        <p className="mb-1 font-semibold">Payload JSON</p>
                        <pre className="max-h-[420px] overflow-auto rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-xs text-[var(--app-text)]">
                            {payloadPreview}
                        </pre>
                    </div>

                    {Array.isArray(selectedLog?.changes) && selectedLog.changes.length > 0 ? (
                        <div>
                            <p className="mb-1 font-semibold">Avant / Après (champs modifiés)</p>
                            <div className="overflow-auto rounded-lg border border-[var(--app-border)]">
                                <table className="min-w-[620px] w-full border-collapse text-xs">
                                    <thead>
                                        <tr className="bg-[var(--app-surface-soft)] text-left uppercase tracking-wide text-[var(--app-muted)]">
                                            <th className="border-b border-[var(--app-border)] px-2 py-1.5">Champ</th>
                                            <th className="border-b border-[var(--app-border)] px-2 py-1.5">Avant</th>
                                            <th className="border-b border-[var(--app-border)] px-2 py-1.5">Après</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {selectedLog.changes.map((change, index) => (
                                            <tr key={`${change.field}-${index}`} className="odd:bg-[var(--app-surface)] even:bg-[var(--app-surface-soft)]">
                                                <td className="border-b border-[var(--app-border)] px-2 py-1.5 font-medium text-[var(--app-text)]">{change.label}</td>
                                                <td className="border-b border-[var(--app-border)] px-2 py-1.5 text-[var(--app-text)]">{change.before}</td>
                                                <td className="border-b border-[var(--app-border)] px-2 py-1.5 text-[var(--app-text)]">{change.after}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : null}
                </div>
                <div className="flex justify-end border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={() => setSelectedLog(null)}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                    >
                        Fermer
                    </button>
                </div>
            </Modal>
        </AdminLayout>
    );
}
