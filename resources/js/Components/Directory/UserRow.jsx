import { Link, router } from '@inertiajs/react';
import { ContactRound, Eye } from 'lucide-react';

const DIRECTORY_RETURN_CONTEXT_KEY = 'directory:return-context';

function initials(user) {
    const first = (user.first_name || user.name || '').trim().charAt(0);
    const last = (user.last_name || '').trim().charAt(0);

    return `${first}${last}`.toUpperCase() || 'U';
}

export default function UserRow({ user, striped = false }) {
    const saveReturnContext = () => {
        if (typeof window === 'undefined') {
            return;
        }

        const returnUrl = `${window.location.pathname}${window.location.search}`;

        window.sessionStorage.setItem(
            DIRECTORY_RETURN_CONTEXT_KEY,
            JSON.stringify({
                contactId: user.id,
                returnUrl,
                shouldRestore: true,
                savedAt: Date.now(),
            }),
        );
    };

    const onRowClick = () => {
        saveReturnContext();
        router.visit(user.show_url, { preserveScroll: true });
    };

    const stop = (e) => e.stopPropagation();
    const onShowLinkClick = (e) => {
        e.stopPropagation();
        saveReturnContext();
    };

    return (
        <div
            id={`contact-${user.id}`}
            className={`cursor-pointer rounded-2xl border border-[var(--app-border)] p-3 shadow-sm transition hover:border-[var(--brand-yellow-dark)] ${
                striped ? 'directory-row-alt' : 'bg-[var(--app-surface)]'
            }`}
            onClick={onRowClick}
            role="button"
            tabIndex={0}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onRowClick();
                }
            }}
        >
            <div className="flex items-start gap-3">
                {user.photo_url ? (
                    <img
                        src={user.photo_url}
                        alt={user.name}
                        className="h-12 w-12 rounded-xl border border-[var(--app-border)] object-cover"
                    />
                ) : (
                    <div className="flex h-12 w-12 items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-sm font-black text-[var(--app-text)]">
                        {initials(user)}
                    </div>
                )}

                <div className="min-w-0 flex-1">
                    <Link
                        href={user.show_url}
                        onClick={onShowLinkClick}
                        className="block truncate text-sm font-bold text-[var(--app-text)] transition hover:text-[var(--brand-yellow-dark)]"
                    >
                        {user.name}
                    </Link>

                    <div className="mt-1 space-y-0.5 text-xs text-[var(--app-muted)]">
                        <p className="truncate">
                            {user.email ? (
                                <a
                                    href={`mailto:${user.email}`}
                                    onClick={stop}
                                    className="hover:underline"
                                >
                                    {user.email}
                                </a>
                            ) : (
                                'Email non renseigné'
                            )}
                        </p>
                        <div className="flex flex-wrap gap-x-3 gap-y-1">
                            {(user.phones ?? []).slice(0, 2).map((phone, index) =>
                                phone.href ? (
                                    <a
                                        key={`${phone.label}-${phone.number}-${index}`}
                                        href={phone.href}
                                        onClick={stop}
                                        className="hover:underline"
                                    >
                                        {phone.label ? `${phone.label} : ` : ''}
                                        {phone.number}
                                    </a>
                                ) : (
                                    <span key={`${phone.label}-${phone.number}-${index}`}>
                                        {phone.label ? `${phone.label} : ` : ''}
                                        {phone.number}
                                    </span>
                                ),
                            )}
                            {(user.phones ?? []).length === 0 ? <span>Téléphone non renseigné</span> : null}
                            {user.internal_number ? (
                                <a
                                    href={`tel:${String(user.internal_number).replace(/[^0-9+]/g, '')}`}
                                    onClick={stop}
                                    className="hover:underline"
                                >
                                    Interne : {user.internal_number}
                                </a>
                            ) : null}
                            {user.sector_name ? <span>Secteur : {user.sector_name}</span> : null}
                        </div>
                    </div>
                </div>

                <div className="flex shrink-0 flex-col gap-2">
                    <Link
                        href={user.show_url}
                        onClick={onShowLinkClick}
                        className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--app-text)] transition hover:border-[var(--brand-yellow-dark)]"
                    >
                        <span className="inline-flex items-center gap-1">
                            <Eye className="h-3 w-3" strokeWidth={2.2} />
                            <span>Voir</span>
                        </span>
                    </Link>
                    <a
                        href={user.vcard_url}
                        onClick={stop}
                        className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-[var(--color-black)] transition hover:brightness-95"
                    >
                        <span className="inline-flex items-center gap-1">
                            <ContactRound className="h-3 w-3" strokeWidth={2.2} />
                            <span>vCard</span>
                        </span>
                    </a>
                </div>
            </div>
        </div>
    );
}
