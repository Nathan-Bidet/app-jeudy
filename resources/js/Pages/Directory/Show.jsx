import Modal from '@/Components/Modal';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import FileList from '@/Components/Directory/FileList';
import FileUploader from '@/Components/Directory/FileUploader';
import AppLayout from '@/Layouts/AppLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ContactRound, MessageSquare, Pencil, Phone } from 'lucide-react';
import { useState } from 'react';

const DIRECTORY_RETURN_CONTEXT_KEY = 'directory:return-context';

function readDirectoryReturnContext() {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.sessionStorage.getItem(DIRECTORY_RETURN_CONTEXT_KEY);
        if (!raw) {
            return null;
        }

        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function InfoRow({ label, value, href }) {
    const isExternal = typeof href === 'string' && /^https?:\/\//i.test(href);

    return (
        <div className="grid gap-1 py-2 sm:grid-cols-[180px_1fr] sm:gap-3">
            <div className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                {label}
            </div>
            <div className="text-sm text-[var(--app-text)]">
                {href && value ? (
                    <a
                        href={href}
                        target={isExternal ? '_blank' : undefined}
                        rel={isExternal ? 'noreferrer' : undefined}
                        className="hover:underline"
                    >
                        {value}
                    </a>
                ) : (
                    value || <span className="text-[var(--app-muted)]">-</span>
                )}
            </div>
        </div>
    );
}

function normalizePhone(value) {
    const digits = String(value || '').replace(/[^\d+]/g, '');

    if (digits.startsWith('+33')) {
        return `0${digits.slice(3)}`;
    }

    if (digits.startsWith('33') && digits.length >= 11) {
        return `0${digits.slice(2)}`;
    }

    return digits;
}

function isSmsCapableFrenchMobile(value) {
    const normalized = normalizePhone(value).replace(/[^\d]/g, '');
    return normalized.startsWith('06') || normalized.startsWith('07');
}

function telHref(value) {
    const normalized = String(value || '').replace(/[^0-9+]/g, '');
    return normalized ? `tel:${normalized}` : null;
}

function smsHref(value) {
    const normalized = String(value || '').replace(/[^0-9+]/g, '');
    return normalized ? `sms:${normalized}` : null;
}

function buildCallOptions(profile) {
    const options = [];
    const seen = new Set();

    const pushOption = (label, number) => {
        if (!number) return;
        const href = telHref(number);
        if (!href) return;
        const key = `${String(label || '').toLowerCase()}|${normalizePhone(number)}`;
        if (seen.has(key)) return;
        seen.add(key);
        options.push({ label, number, href });
    };

    pushOption('Téléphone', profile?.phone);
    pushOption('Mobile', profile?.mobile_phone);
    pushOption('Interne', profile?.internal_number);

    (profile?.phones ?? []).forEach((phone) => {
        pushOption(phone?.label || 'Téléphone', phone?.number);
    });

    return options;
}

function buildSmsOptions(callOptions) {
    return callOptions
        .filter((option) => isSmsCapableFrenchMobile(option.number))
        .map((option) => ({
            ...option,
            href: smsHref(option.number),
        }))
        .filter((option) => Boolean(option.href));
}

function ActionMenu({ label, options, variant = 'default', icon: Icon = null }) {
    if (!options?.length) {
        return null;
    }

    const baseButtonClass =
        variant === 'yellow'
            ? 'rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-light)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]'
            : 'rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]';

    if (options.length === 1) {
        return (
            <a href={options[0].href} className={baseButtonClass}>
                <span className="inline-flex items-center gap-1.5">
                    {Icon ? <Icon className="h-3.5 w-3.5" strokeWidth={2} /> : null}
                    <span>{label}</span>
                </span>
            </a>
        );
    }

    return (
        <div className="relative">
            <details className="group">
                <summary className={`${baseButtonClass} cursor-pointer list-none`}>
                    <span className="inline-flex items-center gap-1.5">
                        {Icon ? <Icon className="h-3.5 w-3.5" strokeWidth={2} /> : null}
                        <span>{label}</span>
                        <ChevronDown className="h-3.5 w-3.5" strokeWidth={2} />
                    </span>
                </summary>
                <div className="absolute right-0 z-20 mt-2 w-56 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] p-2 shadow-lg">
                    <div className="space-y-1">
                        {options.map((option, index) => (
                            <a
                                key={`${option.label}-${option.number}-${index}`}
                                href={option.href}
                                className="block rounded-lg border border-transparent px-3 py-2 text-xs text-[var(--app-text)] transition hover:border-[var(--app-border)] hover:bg-[var(--app-surface-soft)]"
                            >
                                <span className="block font-bold uppercase tracking-[0.08em]">
                                    {option.label}
                                </span>
                                <span className="block text-[11px] text-[var(--app-muted)]">
                                    {option.number}
                                </span>
                            </a>
                        ))}
                    </div>
                </div>
            </details>
        </div>
    );
}

function PhoneList({ phones = [] }) {
    if (!phones.length) {
        return <span className="text-[var(--app-muted)]">-</span>;
    }

    return (
        <div className="space-y-1">
            {phones.map((phone, index) => (
                <div key={`${phone.label}-${phone.number}-${index}`} className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-bold uppercase tracking-[0.06em] text-[var(--app-muted)]">
                        {phone.label}
                    </span>
                    <a
                        href={`tel:${String(phone.number || '').replace(/[^0-9+]/g, '')}`}
                        className="text-sm text-[var(--app-text)] hover:underline"
                    >
                        {phone.number}
                    </a>
                </div>
            ))}
        </div>
    );
}

function ValidityGrid({ validities = [] }) {
    return (
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {validities.map((item) => (
                <div
                    key={item.label}
                    className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2"
                >
                    <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                        {item.label}
                    </p>
                    <p className="mt-1 text-sm font-semibold text-[var(--app-text)]">
                        {item.formatted || '-'}
                    </p>
                </div>
            ))}
        </div>
    );
}

export default function DirectoryShow({ profile, files, permissions, routes }) {
    const fullName =
        [profile?.first_name, profile?.last_name].filter(Boolean).join(' ') || profile?.name || 'Utilisateur';
    const callOptions = buildCallOptions(profile);
    const smsOptions = buildSmsOptions(callOptions);
    const [isDepotModalOpen, setDepotModalOpen] = useState(false);
    const depot = profile?.depot ?? null;
    const mapQueryText = depot?.map_query || depot?.address_full || profile?.depot_address || depot?.name || '';
    const onBackToDirectory = (event) => {
        event.preventDefault();
        const context = readDirectoryReturnContext();
        const target = typeof context?.returnUrl === 'string' && context.returnUrl !== '' ? context.returnUrl : routes.index;
        router.visit(target, { preserveState: true });
    };

    return (
        <AppLayout title="Fiche Annuaire">
            <Head title={`Annuaire - ${fullName}`} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link
                        href={routes.index}
                        onClick={onBackToDirectory}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)] shadow-sm"
                    >
                        <span className="inline-flex items-center gap-1.5">
                            <ChevronLeft className="h-3.5 w-3.5" strokeWidth={2.2} />
                            <span>Retour annuaire</span>
                        </span>
                    </Link>

                    <div className="flex flex-wrap gap-2">
                        {permissions?.can_update ? (
                            <Link
                                href={routes.edit}
                                className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]"
                            >
                                <span className="inline-flex items-center gap-1.5">
                                    <Pencil className="h-3.5 w-3.5" strokeWidth={2} />
                                    <span>Éditer</span>
                                </span>
                            </Link>
                        ) : null}
                        <ActionMenu label="Appeler" options={callOptions} variant="yellow" icon={Phone} />
                        {smsOptions.length > 0 ? (
                            <ActionMenu label="SMS" options={smsOptions} variant="default" icon={MessageSquare} />
                        ) : null}
                        {mapQueryText ? (
                            <PlaceActionsLink
                                text={mapQueryText}
                                triggerLabel="GPS"
                                triggerClassName="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]"
                            />
                        ) : null}
                        <a
                            href={profile?.actions?.vcard_url}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)]"
                        >
                            <span className="inline-flex items-center gap-1.5">
                                <ContactRound className="h-3.5 w-3.5" strokeWidth={2} />
                                <span>vCard</span>
                            </span>
                        </a>
                    </div>
                </div>

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                        <div className="flex items-start gap-4">
                            {profile?.photo_url ? (
                                <img
                                    src={profile.photo_url}
                                    alt={fullName}
                                    className="h-20 w-20 rounded-2xl border border-[var(--app-border)] object-cover"
                                />
                            ) : (
                                <div className="flex h-20 w-20 items-center justify-center rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-2xl font-black text-[var(--app-text)]">
                                    {(profile?.first_name?.[0] || profile?.name?.[0] || 'U').toUpperCase()}
                                </div>
                            )}

                            <div className="min-w-0">
                                <h2 className="text-lg font-black uppercase tracking-[0.06em] text-[var(--app-text)]">
                                    {fullName}
                                </h2>
                                <p className="mt-1 text-sm text-[var(--app-muted)]">
                                    {profile?.sector?.name || 'Secteur non défini'}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2 text-xs">
                                    <span className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1 text-[var(--app-text)]">
                                        Poste: {profile?.job_title || 'Placeholder'}
                                    </span>
                                    <span className="rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2.5 py-1 text-[var(--app-text)]">
                                        Responsable secteur: {profile?.sector_manager || 'Placeholder'}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="mt-4 divide-y divide-[var(--app-border)]">
                            <InfoRow label="Email" value={profile?.email} href={profile?.email ? `mailto:${profile.email}` : null} />
                            <div className="grid gap-1 py-2 sm:grid-cols-[180px_1fr] sm:gap-3">
                                <div className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                    Téléphones
                                </div>
                                <div className="text-sm text-[var(--app-text)]">
                                    <PhoneList phones={profile?.phones ?? []} />
                                </div>
                            </div>
                            <InfoRow
                                label="Numéro interne"
                                value={profile?.internal_number}
                                href={
                                    profile?.internal_number
                                        ? `tel:${String(profile.internal_number).replace(/[^0-9+]/g, '')}`
                                        : null
                                }
                            />
                            <InfoRow label="Lien GLPI" value={profile?.glpi_url} href={profile?.glpi_url} />
                            {depot ? (
                                <div className="grid gap-1 py-2 sm:grid-cols-[180px_1fr] sm:gap-3">
                                    <div className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Dépôt
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 text-sm text-[var(--app-text)]">
                                        <button
                                            type="button"
                                            onClick={() => setDepotModalOpen(true)}
                                            className="underline decoration-dotted underline-offset-2 hover:opacity-80"
                                        >
                                            {depot.name}
                                        </button>
                                        {mapQueryText ? (
                                            <PlaceActionsLink
                                                text={mapQueryText}
                                                triggerLabel="GPS"
                                                triggerClassName="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-2 py-1 text-[11px] font-bold uppercase tracking-[0.08em]"
                                            />
                                        ) : null}
                                    </div>
                                </div>
                            ) : (
                                <div className="grid gap-1 py-2 sm:grid-cols-[180px_1fr] sm:gap-3">
                                    <div className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                        Dépôt
                                    </div>
                                    <div className="text-sm text-[var(--app-text)]">
                                        {profile?.depot_address ? (
                                            <PlaceActionsLink
                                                text={profile.depot_address}
                                                buttonClassName="text-sm"
                                            />
                                        ) : (
                                            <span className="text-[var(--app-muted)]">-</span>
                                        )}
                                    </div>
                                </div>
                            )}
                            <InfoRow
                                label="Anniversaire"
                                value={
                                    profile?.birthday?.formatted
                                        ? `${profile.birthday.formatted}${profile?.birthday?.age ? ` (${profile.birthday.age} ans)` : ''}`
                                        : null
                                }
                            />
                        </div>
                    </section>

                    <div className="space-y-5">
                        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                            <h2 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                                Matériel attribué
                            </h2>
                            <div className="mt-3 space-y-2">
                                {(profile?.equipment ?? []).map((item) => (
                                    <div
                                        key={item.label}
                                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2"
                                    >
                                        <p className="text-[11px] font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                            {item.label}
                                        </p>
                                        <p className="mt-1 text-sm text-[var(--app-text)]">{item.value}</p>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <FileUploader uploadUrl={routes.upload} canUpload={permissions?.can_attach_file} />
                    </div>
                </div>

                <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                    <h2 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                        Validités / Certifications
                    </h2>
                    <div className="mt-4">
                        <ValidityGrid validities={profile?.validities ?? []} />
                    </div>
                </section>

                <FileList
                    files={files}
                    canDelete={permissions?.can_delete_file}
                    canRename={permissions?.can_rename_file}
                />
            </div>

            {depot ? (
                <Modal show={isDepotModalOpen} onClose={() => setDepotModalOpen(false)} maxWidth="lg">
                    <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <h3 className="text-sm font-black uppercase tracking-[0.08em] text-[var(--app-text)]">
                            Dépôt rattaché
                        </h3>
                        <p className="mt-1 text-base font-semibold text-[var(--app-text)]">{depot.name}</p>
                    </div>

                    <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4 text-sm text-[var(--app-text)]">
                        <div className="grid gap-1 py-2 sm:grid-cols-[180px_1fr] sm:gap-3">
                            <div className="text-xs font-bold uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                Adresse
                            </div>
                            <div className="text-sm text-[var(--app-text)]">
                                {depot.address_full ? (
                                    <PlaceActionsLink text={depot.address_full} buttonClassName="text-sm" />
                                ) : (
                                    <span className="text-[var(--app-muted)]">-</span>
                                )}
                            </div>
                        </div>
                        <InfoRow
                            label="Téléphone"
                            value={depot.phone}
                            href={depot.phone ? `tel:${String(depot.phone).replace(/[^0-9+]/g, '')}` : null}
                        />
                        <InfoRow
                            label="Email"
                            value={depot.email}
                            href={depot.email ? `mailto:${depot.email}` : null}
                        />
                    </div>

                    <div className="flex justify-end border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                        <button
                            type="button"
                            onClick={() => setDepotModalOpen(false)}
                            className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em] text-[var(--app-text)]"
                        >
                            Fermer
                        </button>
                    </div>
                </Modal>
            ) : null}
        </AppLayout>
    );
}
