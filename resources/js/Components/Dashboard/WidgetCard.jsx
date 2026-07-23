import { Link } from '@inertiajs/react';
import { ArrowRight, BookUser, CalendarDays, CalendarX2, Check, Clock3, FileText, LayoutGrid, Newspaper, TrendingUp } from 'lucide-react';

const iconClass = 'h-4 w-4';

function iconFor(type) {
    switch (type) {
        case 'check':
            return <Check className={iconClass} strokeWidth={2.2} />;
        case 'calendar':
            return <LayoutGrid className={iconClass} strokeWidth={2} />;
        case 'document':
            return <FileText className={iconClass} strokeWidth={2} />;
        case 'news':
            return <Newspaper className={iconClass} strokeWidth={2} />;
        case 'shortcut':
            return <ArrowRight className={iconClass} strokeWidth={2.2} />;
        default:
            return <Check className={iconClass} strokeWidth={2} />;
    }
}

function accentClasses(accent) {
    const map = {
        yellow: 'border-[var(--brand-yellow-dark)]/60',
        red: 'border-red-500/50',
        green: 'border-[#F1BF0C]',
        brown: 'border-[var(--brand-brown)]/60',
    };

    return map[accent] ?? 'border-[var(--app-border)]';
}

function CardInner({ widget, openLinkEnabled = false }) {
    return (
        <>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-xs font-black">
                            {iconFor(widget.icon)}
                        </span>
                        <h3 className="truncate text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                            {widget.title}
                        </h3>
                    </div>
                    {widget.subtitle && (
                        <p className="mt-2 text-xs text-[var(--app-muted)]">{widget.subtitle}</p>
                    )}
                </div>

                {widget.clickable && widget.href ? (
                    openLinkEnabled ? (
                        <Link
                            href={widget.href}
                            className="rounded-full border border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-muted)] transition hover:bg-[var(--app-surface-soft)]"
                        >
                            Ouvrir
                        </Link>
                    ) : (
                        <span className="rounded-full border border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-muted)]">
                            Ouvrir
                        </span>
                    )
                ) : widget.type === 'quick_links' ? null : (
                    <span className="rounded-full border border-[var(--app-border)] px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--app-muted)]">
                        Placeholder
                    </span>
                )}
            </div>

            {widget.type === 'list' && (
                <div className="mt-4 space-y-2">
                    {(widget.items ?? []).length === 0 ? (
                        <div className="rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 text-sm text-[var(--app-muted)]">
                            {widget.empty_message || 'Aucune donnée.'}
                        </div>
                    ) : (
                        (widget.items ?? []).map((item, index) => {
                            const content = (
                                <>
                                    <div className="text-sm font-semibold text-[var(--app-text)]">{item.label}</div>
                                    <div className="mt-0.5 flex items-center justify-between gap-2 text-xs text-[var(--app-muted)]">
                                        <span>{item.meta}</span>
                                        {item.status ? <span>{item.status}</span> : null}
                                    </div>
                                </>
                            );

                            const classes = 'block rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-left transition hover:bg-[var(--app-surface)] hover:shadow-sm';

                            if (item.href) {
                                return (
                                    <Link key={`${item.label}-${index}`} href={item.href} className={`${classes} cursor-pointer`}>
                                        {content}
                                    </Link>
                                );
                            }

                            return (
                                <div key={`${item.label}-${index}`} className={classes}>
                                    {content}
                                </div>
                            );
                        })
                    )}
                </div>
            )}

            {widget.type === 'metrics' && (
                <div className="mt-4 grid grid-cols-3 gap-2">
                    {(widget.metrics ?? []).map((metric, index) => (
                        <div
                            key={`${metric.label}-${index}`}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-3 text-center"
                        >
                            <div className="text-lg font-black text-[var(--app-text)]">{metric.value}</div>
                            <div className="mt-1 text-[10px] font-semibold uppercase tracking-wider text-[var(--app-muted)]">
                                {metric.label}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {widget.type === 'links' && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {(widget.links ?? []).map((link, index) => (
                        <Link
                            key={`${link.label}-${index}`}
                            href={link.href}
                            className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-3 py-2 text-xs font-bold uppercase tracking-wide text-[var(--color-black)] transition hover:brightness-95"
                        >
                            {link.label}
                        </Link>
                    ))}
                </div>
            )}

            {widget.type === 'quick_links' && (() => {
                const links = widget.links ?? [];
                const wideLinks = links.filter((item) => item.full_width);
                const regularLinks = links.filter((item) => !item.full_width);

                return (
                    <div className="mt-4 space-y-2">
                        {wideLinks.map((item, index) => (
                            <QuickAccessTile key={`${item.label}-${index}`} item={item} fullWidth />
                        ))}
                        {regularLinks.length ? (
                            <div className="grid grid-cols-3 gap-2 md:flex md:flex-nowrap">
                                {regularLinks.map((item, index) => (
                                    <QuickAccessTile key={`${item.label}-${index}`} item={item} />
                                ))}
                            </div>
                        ) : null}
                    </div>
                );
            })()}

            {widget.footer && (
                <div className="mt-4 border-t border-[var(--app-border)] pt-3 text-xs text-[var(--app-muted)]">
                    {widget.footer}
                </div>
            )}
        </>
    );
}

function QuickAccessTile({ item, fullWidth = false }) {
    const Icon = iconForQuickAccessItem(item);

    return (
        <Link
            href={item.href}
            className={`flex h-12 min-w-0 items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-1.5 text-center text-[10px] font-black uppercase leading-3 tracking-[0.06em] text-[var(--app-text)] transition hover:bg-[var(--app-surface)] hover:shadow-sm sm:px-3 sm:text-xs ${
                fullWidth ? 'w-full' : 'md:flex-1'
            }`}
        >
            <span className="flex h-[22px] w-[22px] shrink-0 items-center justify-center">
                <Icon className="h-[20px] w-[20px]" strokeWidth={2.3} />
            </span>
            <span className="min-w-0 truncate">{item.label}</span>
        </Link>
    );
}

function iconForQuickAccessItem(item) {
    const iconKey = String(item.icon ?? item.label ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[\s_]+/g, '-');

    switch (iconKey) {
        case 'cotations':
            return TrendingUp;
        case 'calendar':
        case 'calendrier':
            return CalendarDays;
        case 'leaves':
        case 'conges':
            return CalendarX2;
        case 'hours':
        case 'heures':
            return Clock3;
        case 'ldt':
        case 'ldt-book':
        case 'livre-du-travail':
            return FileText;
        case 'a_prevoir':
        case 'a-prevoir':
        case 'ldt-planning':
            return Clock3;
        case 'directory':
        case 'annuaire':
            return BookUser;
        default:
            return ArrowRight;
    }
}

export default function WidgetCard({ widget }) {
    const shouldWrap = widget.clickable && widget.href && widget.type !== 'links' && widget.type !== 'list';
    const cardClass = `block rounded-2xl border bg-[var(--app-surface)] p-4 shadow-sm transition ${accentClasses(
        widget.accent,
    )} ${shouldWrap ? 'hover:-translate-y-0.5 hover:shadow-md' : ''}`;

    if (shouldWrap) {
        return (
            <Link href={widget.href} className={cardClass}>
                <CardInner widget={widget} />
            </Link>
        );
    }

    return (
        <section className={cardClass}>
            <CardInner widget={widget} openLinkEnabled={widget.clickable && widget.href && widget.type === 'list'} />
        </section>
    );
}
