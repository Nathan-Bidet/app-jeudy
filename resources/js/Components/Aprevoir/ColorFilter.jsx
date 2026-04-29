export default function ColorFilter({ value = 'all', onChange }) {
    return (
        <div className="flex items-center gap-2">
            <label className="text-xs font-bold uppercase tracking-[0.1em] text-[var(--app-muted)]">
                Couleur
            </label>
            <select
                value={value}
                onChange={(e) => onChange?.(e.target.value)}
                className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
            >
                <option value="all">Toutes</option>
                <option value="colored">Colorées</option>
                <option value="unstyled">Sans règle</option>
            </select>
        </div>
    );
}

