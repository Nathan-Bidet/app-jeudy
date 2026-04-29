import TitleCaps from '@/Layouts/AppShell/TitleCaps';

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

export default function SideMenu({ sections = [], activeSection, onChange }) {
    return (
        <aside className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="mb-3 rounded-xl bg-[var(--brand-yellow-light)] px-3 py-2 text-[var(--color-black)]">
                <TitleCaps text="Données" className="text-[12px]" />
            </div>

            <nav className="space-y-1.5">
                {sections.map((section, index) => {
                    const previous = sections[index - 1];
                    const hasGroupBreak = index > 0 && previous?.group !== section?.group;

                    return (
                        <div key={section.key} className="space-y-1.5">
                            {hasGroupBreak ? <div className="my-2 border-t border-[var(--app-border)]" /> : null}
                            <button
                                type="button"
                                onClick={() => onChange(section.key)}
                                className={classNames(
                                    'w-full rounded-xl px-3 py-2 text-left text-sm font-semibold transition',
                                    activeSection === section.key
                                        ? 'bg-[var(--brand-yellow-dark)] text-[var(--color-black)]'
                                        : 'text-[var(--app-text)] hover:bg-[var(--brand-yellow-light)] hover:text-[var(--color-black)]',
                                )}
                            >
                                {section.label}
                            </button>
                        </div>
                    );
                })}
            </nav>
        </aside>
    );
}
