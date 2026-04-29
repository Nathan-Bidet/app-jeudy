import Modal from '@/Components/Modal';
import ArchiveDetailModal from '@/Components/Tasks/Archive/ArchiveDetailModal';
import ArchiveTable from '@/Components/Tasks/Archive/ArchiveTable';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

function makeInitialFilters(filters = {}) {
    return {
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        search: filters.search || '',
        assignee: filters.assignee || '',
        contract: filters.contract || '',
        direct: Boolean(filters.direct),
        boursagri: Boolean(filters.boursagri),
        indicators: Array.isArray(filters.indicators) ? filters.indicators : [],
        per_page: String(filters.per_page || 50),
    };
}

function normalizeFilters(filters = {}) {
    const perPage = Number.parseInt(filters.per_page, 10);

    return {
        date_from: filters.date_from || undefined,
        date_to: filters.date_to || undefined,
        search: (filters.search || '').trim() || undefined,
        assignee: filters.assignee || undefined,
        contract: (filters.contract || '').trim() || undefined,
        direct: filters.direct ? 1 : undefined,
        boursagri: filters.boursagri ? 1 : undefined,
        indicators: Array.isArray(filters.indicators) && filters.indicators.length > 0 ? filters.indicators : undefined,
        per_page: Number.isNaN(perPage) ? undefined : perPage,
    };
}

export default function TasksArchiveIndex({
    archives = { data: [], links: [], total: 0 },
    filters = {},
    options = {},
}) {
    const page = usePage();
    const canManage = Boolean(page.props?.auth?.permissions?.task_archive_manage);
    const [localFilters, setLocalFilters] = useState(makeInitialFilters(filters));
    const [selectedRow, setSelectedRow] = useState(null);
    const [restoreCandidate, setRestoreCandidate] = useState(null);
    const [restoreProcessing, setRestoreProcessing] = useState(false);

    useEffect(() => {
        setLocalFilters(makeInitialFilters(filters));
    }, [filters]);

    const only = useMemo(() => ['archives', 'filters', 'options', 'errors', 'flash'], []);

    const submitFilters = (nextFilters = localFilters) => {
        router.get(
            route('task.archive.index'),
            normalizeFilters(nextFilters),
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only,
            },
        );
    };

    const resetFilters = () => {
        const next = {
            date_from: '',
            date_to: '',
            search: '',
            assignee: '',
            contract: '',
            direct: false,
            boursagri: false,
            indicators: [],
            per_page: String(options?.per_page?.[0] || 50),
        };

        setLocalFilters(next);
        submitFilters(next);
    };

    const changePage = (url) => {
        if (!url) {
            return;
        }

        router.visit(url, {
            method: 'get',
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only,
        });
    };

    const changePerPage = (value) => {
        const next = { ...localFilters, per_page: String(value || '50') };
        setLocalFilters(next);
        submitFilters(next);
    };

    const changeLocalFilter = (key, value) => {
        setLocalFilters((prev) => ({
            ...prev,
            [key]: value,
        }));
    };

    const pageHeader = (
        <h1 className="text-[22px] leading-none">
            <span className="block text-[22px] leading-none font-black uppercase tracking-[0.06em]">
                Archive
            </span>
        </h1>
    );

    const confirmRestore = () => {
        if (!restoreCandidate || restoreProcessing) {
            return;
        }

        setRestoreProcessing(true);

        router.post(route('task.archive.restore', restoreCandidate.id), {}, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only,
            onSuccess: () => setRestoreCandidate(null),
            onFinish: () => setRestoreProcessing(false),
        });
    };

    return (
        <AppLayout title="Archive" header={pageHeader}>
            <Head title="Archive" />

            <div className="space-y-4">
                <ArchiveTable
                    archives={archives}
                    perPageOptions={options?.per_page || [25, 50, 100, 150]}
                    perPage={localFilters.per_page}
                    filters={localFilters}
                    assigneeOptions={options?.assignees || []}
                    indicatorOptions={options?.indicators || []}
                    onFilterChange={changeLocalFilter}
                    onApplyFilters={submitFilters}
                    onResetFilters={resetFilters}
                    onChangePage={changePage}
                    onPerPageChange={changePerPage}
                    onOpenDetail={setSelectedRow}
                    canManage={canManage}
                    onRequestRestore={setRestoreCandidate}
                />
            </div>

            <ArchiveDetailModal
                show={Boolean(selectedRow)}
                row={selectedRow}
                onClose={() => setSelectedRow(null)}
            />

            <Modal show={Boolean(restoreCandidate)} onClose={() => setRestoreCandidate(null)} maxWidth="md">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Restaurer une ligne archivée</h3>
                </div>
                <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4">
                    <p className="text-sm text-[var(--app-text)]">Confirmer la restauration de cette ligne dans À Prévoir ?</p>
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-sm">
                        <p className="font-semibold text-[var(--app-text)]">{restoreCandidate?.task || '—'}</p>
                        <p className="mt-1 text-xs text-[var(--app-muted)]">
                            {restoreCandidate?.date_label || '—'} · {restoreCandidate?.assignee_label || 'Sans assigné'}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={() => setRestoreCandidate(null)}
                        className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] sm:w-auto"
                    >
                        Annuler
                    </button>
                    <button
                        type="button"
                        onClick={confirmRestore}
                        disabled={restoreProcessing}
                        className="w-full rounded-xl border border-emerald-600 bg-emerald-600 px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-white disabled:opacity-60 sm:w-auto"
                    >
                        {restoreProcessing ? (
                            'Restauration...'
                        ) : (
                            <span className="inline-flex items-center gap-1.5">
                                <RotateCcw className="h-3.5 w-3.5" strokeWidth={2.2} />
                                <span>Restaurer</span>
                            </span>
                        )}
                    </button>
                </div>
            </Modal>
        </AppLayout>
    );
}
