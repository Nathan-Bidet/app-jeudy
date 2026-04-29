export default function Tabs({ tabs = [], active, onChange }) {
    return (
        <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-sm">
            <div className="flex flex-wrap gap-2">
                {tabs.map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => onChange(tab.key)}
                        className={`rounded-xl px-3 py-2 text-xs font-black uppercase tracking-[0.12em] transition ${
                            active === tab.key
                                ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                : 'bg-[var(--app-surface-soft)] text-[var(--app-text)] hover:border-[var(--brand-yellow-dark)]'
                        }`}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
