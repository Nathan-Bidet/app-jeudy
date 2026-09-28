import { validatorIdentityLabel } from '@/Support/validationSummary';

/**
 * Décision individuelle d'un valideur, en badge.
 *
 * Même pastille que les badges d'état existants (HourSheetStatusBadge,
 * LeaveStatusBadge) : bordure et point de couleur, texte toujours explicite —
 * la couleur n'est jamais la seule information. Piloté par `entry.decision`,
 * la décision réellement enregistrée pour CE rang, et non par le statut global.
 */
const DECISION_STYLES = {
    approved: {
        dot: '#22c55e',
        className: 'border-[#22c55e] text-[#15803d] dark:text-[#4ade80]',
    },
    refused: {
        dot: '#ef4444',
        className: 'border-[#ef4444] text-[#b91c1c] dark:text-[#f87171]',
    },
    pending: {
        dot: '#eab308',
        className: 'border-[#eab308] text-[#a16207] dark:text-[#facc15]',
    },
};

const FALLBACK_LABELS = {
    approved: 'Validé',
    refused: 'Refusé',
    pending: 'En attente',
};

function decisionKey(decision) {
    return decision === 'approved' || decision === 'refused' ? decision : 'pending';
}

export function ValidatorDecisionBadge({ decision, label }) {
    const key = decisionKey(decision);
    const style = DECISION_STYLES[key];

    return (
        <span
            data-decision={key}
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full border bg-[var(--app-surface)] px-2 py-0.5 text-xs font-semibold ${style.className}`}
        >
            <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: style.dot }} aria-hidden="true" />
            {label || FALLBACK_LABELS[key]}
        </span>
    );
}

/**
 * Une ligne par valideur : « Valideur N : [nom] [badge] ». Le nom n'apparaît
 * que si le serveur l'a transmis (permission d'identité des valideurs).
 */
export function ValidatorDecisionRow({ entry, showLevelLabel = true }) {
    const identity = validatorIdentityLabel(entry);

    return (
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1" data-testid={`validator-${entry.level}`}>
            {showLevelLabel ? (
                <span className="shrink-0 font-semibold">Valideur {entry.level} :</span>
            ) : null}
            {identity ? (
                <span className="min-w-0 break-words text-[var(--app-text)]">{identity}</span>
            ) : null}
            <ValidatorDecisionBadge decision={entry.decision} label={entry.label} />
        </div>
    );
}

export default function ValidatorDecisions({ summary, className = '' }) {
    const entries = Array.isArray(summary) ? summary : [];

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className={`space-y-1 text-xs text-[var(--app-muted)] ${className}`}>
            {entries.map((entry) => (
                <ValidatorDecisionRow key={entry.level} entry={entry} />
            ))}
        </div>
    );
}
