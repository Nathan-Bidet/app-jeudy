import { Check } from 'lucide-react';

function prettyKey(raw) {
    return String(raw || '')
        .replace(/^auto_detected\.?/i, '')
        .replace(/[._]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function collectIndicatorLabels(value, labels, path = '') {
    if (path.toLowerCase().startsWith('auto_detected')) {
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((item) => collectIndicatorLabels(item, labels, path));
        return;
    }

    if (value && typeof value === 'object') {
        Object.entries(value).forEach(([key, child]) => {
            const nextPath = path ? `${path}.${key}` : key;
            collectIndicatorLabels(child, labels, nextPath);
        });
        return;
    }

    if (value === null || value === undefined || value === '' || value === false) {
        return;
    }

    if (value === true) {
        const label = prettyKey(path);
        if (label) labels.add(label);
        return;
    }

    const keyLabel = prettyKey(path);
    const valueLabel = String(value).trim();
    if (!valueLabel) return;

    labels.add(keyLabel ? `${keyLabel}: ${valueLabel}` : valueLabel);
}

export function indicatorLabelsFromPayload(indicators) {
    const labels = new Set();
    collectIndicatorLabels(indicators, labels);
    return Array.from(labels);
}

export function PointedIndicatorCell({ pointed }) {
    if (pointed) {
        return (
            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-emerald-500 text-white">
                <Check className="h-4 w-4" strokeWidth={2.6} />
            </span>
        );
    }

    return <span className="text-[var(--app-muted)]">—</span>;
}

export function DirectIndicatorCell({ isDirect }) {
    if (isDirect) {
        return (
            <span className="inline-flex min-w-6 justify-center rounded-md border border-emerald-300 bg-emerald-50 px-1 py-0.5 text-xs font-bold text-emerald-700">
                D
            </span>
        );
    }

    return <span className="text-[var(--app-muted)]">—</span>;
}

export function BoursagriIndicatorCell({ isBoursagri }) {
    if (isBoursagri) {
        return (
            <span className="inline-flex min-w-6 justify-center rounded-md border border-amber-300 bg-amber-50 px-1 py-0.5 text-xs font-bold text-amber-700">
                B
            </span>
        );
    }

    return <span className="text-[var(--app-muted)]">—</span>;
}

export function IndicatorsInline({ indicators = [] }) {
    const labels = indicatorLabelsFromPayload(indicators);

    if (!labels.length) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap items-center gap-1">
            {labels.map((label) => (
                <span
                    key={label}
                    className="inline-flex rounded-md border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.05em] text-[var(--app-muted)]"
                    title={label}
                >
                    {label}
                </span>
            ))}
        </div>
    );
}

