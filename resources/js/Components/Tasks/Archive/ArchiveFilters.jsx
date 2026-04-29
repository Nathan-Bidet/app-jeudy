import { Calendar } from 'lucide-react';

export default function ArchiveFilters({
    filters,
    setFilters,
    assigneeOptions = [],
    perPageOptions = [25, 50, 100, 150],
    onApply,
    onReset,
}) {
    const handleSubmit = (event) => {
        event.preventDefault();
        onApply();
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm sm:p-6">
            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <label>
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Du
                        </span>
                        <span className="flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3">
                            <Calendar className="h-4 w-4 text-[var(--app-muted)]" />
                            <input
                                type="date"
                                value={filters.date_from}
                                onChange={(event) => setFilters((prev) => ({ ...prev, date_from: event.target.value }))}
                                className="h-11 w-full border-0 bg-transparent p-0 text-sm text-[var(--app-text)] focus:outline-none focus:ring-0"
                            />
                        </span>
                    </label>

                    <label>
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Au
                        </span>
                        <span className="flex items-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3">
                            <Calendar className="h-4 w-4 text-[var(--app-muted)]" />
                            <input
                                type="date"
                                value={filters.date_to}
                                onChange={(event) => setFilters((prev) => ({ ...prev, date_to: event.target.value }))}
                                className="h-11 w-full border-0 bg-transparent p-0 text-sm text-[var(--app-text)] focus:outline-none focus:ring-0"
                            />
                        </span>
                    </label>

                    <label>
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Assigné
                        </span>
                        <select
                            value={filters.assignee}
                            onChange={(event) => setFilters((prev) => ({ ...prev, assignee: event.target.value }))}
                            className="h-11 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 text-sm text-[var(--app-text)] focus:border-[var(--brand-yellow-dark)] focus:outline-none focus:ring-0"
                        >
                            <option value="">Tous</option>
                            {assigneeOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <label>
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                            Contrat Boursagri
                        </span>
                        <input
                            type="text"
                            value={filters.contract}
                            onChange={(event) => setFilters((prev) => ({ ...prev, contract: event.target.value }))}
                            placeholder="Numéro de contrat"
                            className="h-11 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 text-sm text-[var(--app-text)] placeholder:text-[var(--app-muted)] focus:border-[var(--brand-yellow-dark)] focus:outline-none focus:ring-0"
                        />
                    </label>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-[var(--app-muted)]">
                        {/*
                          Le compteur est rendu côté page/table, ce bloc garde l’équilibre visuel
                          du bandeau de filtres façon À Prévoir.
                        */}
                        Filtres archive
                    </p>

                    <label className="inline-flex items-center gap-2 text-sm text-[var(--app-muted)]">
                        <span className="font-medium">Lignes / page</span>
                        <select
                            value={String(filters.per_page)}
                            onChange={(event) => setFilters((prev) => ({ ...prev, per_page: event.target.value }))}
                            className="h-9 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 text-sm text-[var(--app-text)] focus:border-[var(--brand-yellow-dark)] focus:outline-none focus:ring-0"
                        >
                            {perPageOptions.map((value) => (
                                <option key={value} value={value}>
                                    {value}
                                </option>
                            ))}
                        </select>
                    </label>

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 text-sm font-black uppercase tracking-[0.08em] text-[var(--color-black)]"
                        >
                            Appliquer
                        </button>
                        <button
                            type="button"
                            onClick={onReset}
                            className="h-10 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-4 text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]"
                        >
                            Réinitialiser
                        </button>
                    </div>
                </div>
            </form>
        </section>
    );
}
