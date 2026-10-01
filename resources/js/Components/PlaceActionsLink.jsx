import Modal from '@/Components/Modal';
import { buildPlaceIndex, resolvePlace } from '@/Support/placeResolution';
import { stripTextMarkers } from '@/Support/textFormatting';
import { ExternalLink, MapPin } from 'lucide-react';
import { useMemo, useState } from 'react';

function detectPlatform() {
    if (typeof navigator === 'undefined') {
        return 'other';
    }

    const userAgent = String(navigator.userAgent || '');
    const platform = String(navigator.platform || '');
    const maxTouchPoints = Number(navigator.maxTouchPoints || 0);

    const isIOS = /iPhone|iPad|iPod/i.test(userAgent) || (platform === 'MacIntel' && maxTouchPoints > 1);
    if (isIOS) return 'ios';
    if (/Android/i.test(userAgent)) return 'android';
    if (/Win/i.test(platform) || /Windows/i.test(userAgent)) return 'windows';
    if (/Mac/i.test(platform)) return 'mac';

    return 'other';
}

function normalizeCoordinates(coordinates) {
    if (!coordinates || typeof coordinates !== 'object') return null;
    const lat = Number.parseFloat(coordinates.lat);
    const lng = Number.parseFloat(coordinates.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
    return { lat, lng };
}

function buildMapOptions(place, platform, coordinates = null) {
    const query = encodeURIComponent(place);
    const coords = normalizeCoordinates(coordinates);
    const coordValue = coords ? `${coords.lat},${coords.lng}` : null;
    const googleHref = coords
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(coordValue)}`
        : `https://www.google.com/maps/search/?api=1&query=${query}`;
    const wazeHref = coords
        ? `https://waze.com/ul?ll=${encodeURIComponent(coordValue)}&navigate=yes`
        : `https://waze.com/ul?q=${query}&navigate=yes`;
    const appleHref = coords
        ? `https://maps.apple.com/?ll=${encodeURIComponent(coordValue)}&q=${query}`
        : `https://maps.apple.com/?q=${query}`;

    if (platform === 'android') {
        return [
            { key: 'google', label: 'Google Maps', href: googleHref },
            { key: 'waze', label: 'Waze', href: wazeHref },
        ];
    }

    if (platform === 'ios' || platform === 'mac') {
        return [
            { key: 'google', label: 'Google Maps', href: googleHref },
            { key: 'waze', label: 'Waze', href: wazeHref },
            { key: 'apple', label: 'Plans Apple', href: appleHref },
        ];
    }

    return [
        { key: 'google', label: 'Google Maps', href: googleHref },
        { key: 'waze', label: 'Waze', href: wazeHref },
    ];
}

export default function PlaceActionsLink({
    text,
    placeResolver = null,
    coordinates = null,
    className = '',
    buttonClassName = '',
    triggerLabel = null,
    triggerClassName = '',
}) {
    const [placeToOpen, setPlaceToOpen] = useState('');
    const platform = useMemo(() => detectPlatform(), []);

    const places = useMemo(
        () =>
            String(text || '')
                .split(/\r\n|\r|\n/)
                .map((value) => stripTextMarkers(value).trim())
                .filter(Boolean),
        [text],
    );

    const options = useMemo(
        () => buildMapOptions(placeToOpen, platform, coordinates),
        [placeToOpen, platform, coordinates],
    );
    const placeIndex = useMemo(() => buildPlaceIndex(placeResolver), [placeResolver]);

    if (!places.length) {
        return null;
    }

    const openPlace = (place) => {
        const { destination } = resolvePlace(place, placeIndex);
        if (!destination) return;

        if (platform === 'windows') {
            const href = buildMapOptions(destination, 'windows', coordinates)[0]?.href;
            if (href) {
                window.open(href, '_blank', 'noopener,noreferrer');
            }
            return;
        }

        setPlaceToOpen(destination);
    };

    const displayPlace = (place) => {
        if (triggerLabel) {
            return triggerLabel;
        }

        return resolvePlace(place, placeIndex).label;
    };

    return (
        <>
            <div className={`inline-flex flex-col gap-1 ${className}`}>
                {places.map((place, index) => (
                    <button
                        key={`${place}-${index}`}
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            openPlace(place);
                        }}
                        title={triggerLabel ? place : undefined}
                        className={`inline-flex items-center gap-1.5 text-left hover:opacity-80 ${
                            triggerLabel || triggerClassName
                                ? ''
                                : 'underline decoration-dotted underline-offset-2'
                        } ${buttonClassName} ${triggerClassName}`}
                    >
                        <MapPin className="h-3.5 w-3.5 shrink-0" strokeWidth={2.2} />
                        <span>{displayPlace(place)}</span>
                    </button>
                ))}
            </div>

            <Modal show={Boolean(placeToOpen)} onClose={() => setPlaceToOpen('')} maxWidth="md" zIndexClass="z-[90]">
                <div className="border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h3 className="text-sm font-black uppercase tracking-[0.08em]">Ouvrir l’itinéraire</h3>
                </div>

                <div className="space-y-3 bg-[var(--app-surface)] px-5 py-4">
                    <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 text-sm">
                        {placeToOpen}
                    </div>

                    <div className="grid gap-2">
                        {options.map((option) => (
                            <a
                                key={option.key}
                                href={option.href}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center justify-between rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm font-semibold hover:border-[var(--brand-yellow-dark)]"
                            >
                                <span>{option.label}</span>
                                <ExternalLink className="h-4 w-4" strokeWidth={2.1} />
                            </a>
                        ))}
                    </div>
                </div>

                <div className="flex justify-end border-t border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <button
                        type="button"
                        onClick={() => setPlaceToOpen('')}
                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.12em]"
                    >
                        Fermer
                    </button>
                </div>
            </Modal>
        </>
    );
}
