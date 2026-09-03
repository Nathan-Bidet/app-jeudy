import { useEffect, useRef, useState } from 'react';
import { X, Check, Ban, Pencil, ChevronDown } from 'lucide-react';

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function apiFetch(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
            ...(options.headers || {}),
        },
        credentials: 'same-origin',
        ...options,
    });
    return response;
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const [year, month, day] = dateStr.split('-');
    return `${day}/${month}/${year}`;
}

function formatDateTime(isoStr) {
    if (!isoStr) return '—';
    const d = new Date(isoStr);
    return d.toLocaleDateString('fr-FR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
}

function portionLabel(portion) {
    if (!portion || portion === 'full') return 'journée entière';
    if (portion === 'morning') return 'matin';
    if (portion === 'afternoon') return 'après-midi';
    return portion;
}

function periodLabel(data, prefix = '') {
    const start = data[`${prefix}start_at`];
    const end = data[`${prefix}end_at`];
    const startPortion = data[`${prefix}start_portion`];
    const endPortion = data[`${prefix}end_portion`];
    const customStart = data[`${prefix}custom_start_time`];
    const customEnd = data[`${prefix}custom_end_time`];

    if (!start) return '—';

    if (customStart && customEnd) {
        return `${formatDate(start)} ${customStart} → ${formatDate(end || start)} ${customEnd}`;
    }

    if (start === end || !end) {
        return `${formatDate(start)} (${portionLabel(startPortion)})`;
    }

    return `${formatDate(start)} (${portionLabel(startPortion)}) → ${formatDate(end)} (${portionLabel(endPortion)})`;
}

function Row({ label, value }) {
    if (!value && value !== 0) return null;
    return (
        <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
            <dt className="min-w-[140px] text-xs text-[var(--app-muted)]">{label}</dt>
            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}

function StatusBadge({ status, statusLabel }) {
    const map = {
        pending: { label: 'En attente de validation', cls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300' },
        pending_validator_2: { label: 'En attente de validation', cls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300' },
        approved: { label: 'Validé', cls: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' },
        refused: { label: 'Refusé', cls: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' },
        pending_user_confirmation: { label: 'Modification proposée', cls: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' },
    };
    const fallback = { label: status, cls: 'bg-gray-100 text-gray-700' };
    const { cls } = map[status] || fallback;
    // Le libellé du serveur est global (« En attente de validation », « Validé »,
    // « Refusé ») : il prime, sauf pour la contre-proposition qui n'est pas un
    // état de validation.
    const label = status === 'pending_user_confirmation'
        ? map[status].label
        : (statusLabel || (map[status] || fallback).label);
    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${cls}`}>
            {label}
        </span>
    );
}

function ActionError({ message }) {
    if (!message) return null;
    return <p className="mt-2 text-xs text-red-500">{message}</p>;
}

export default function LeaveRequestDetailModal({ leaveRequestId, onClose }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [actionLoading, setActionLoading] = useState(false);
    const [actionError, setActionError] = useState(null);
    const [showModifyForm, setShowModifyForm] = useState(false);
    const [modifyForm, setModifyForm] = useState({
        proposed_start_at: '',
        proposed_end_at: '',
        proposed_start_portion: 'full',
        proposed_end_portion: 'full',
        proposed_message: '',
    });
    const overlayRef = useRef(null);

    useEffect(() => {
        setLoading(true);
        setError(null);
        apiFetch(`/leaves/${leaveRequestId}`)
            .then(async (res) => {
                if (res.status === 403) { setError('Vous n\'avez pas accès à cette demande.'); return; }
                if (res.status === 404) { setError('Cette demande est introuvable.'); return; }
                if (!res.ok) { setError('Impossible de charger la demande.'); return; }
                const json = await res.json();
                setData(json);
            })
            .catch(() => setError('Impossible de charger la demande.'))
            .finally(() => setLoading(false));
    }, [leaveRequestId]);

    useEffect(() => {
        const onKey = (e) => { if (e.key === 'Escape') onClose(); };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    useEffect(() => {
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = prev; };
    }, []);

    const handleAction = async (url, method = 'POST', body = null) => {
        setActionLoading(true);
        setActionError(null);
        try {
            const res = await apiFetch(url, {
                method,
                body: body ? JSON.stringify(body) : undefined,
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                setActionError(json.message || 'Une erreur est survenue.');
                return;
            }
            const updated = await apiFetch(`/leaves/${leaveRequestId}`);
            if (updated.ok) setData(await updated.json());
            setShowModifyForm(false);
        } catch {
            setActionError('Une erreur est survenue.');
        } finally {
            setActionLoading(false);
        }
    };

    const handleModifySubmit = (e) => {
        e.preventDefault();
        handleAction(`/leaves/${leaveRequestId}/propose-modification`, 'POST', modifyForm);
    };

    return (
        <div
            ref={overlayRef}
            className="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
            onClick={(e) => { if (e.target === overlayRef.current) onClose(); }}
        >
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/50 backdrop-blur-[2px]" aria-hidden="true" />

            {/* Panel */}
            <div
                role="dialog"
                aria-modal="true"
                className="relative z-10 w-full overflow-y-auto rounded-t-2xl border border-[var(--app-border)] bg-[var(--app-surface)] shadow-2xl sm:max-w-lg sm:rounded-2xl"
                style={{ maxHeight: 'min(90vh, 700px)' }}
            >
                {/* Header */}
                <div className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-4">
                    <h2 className="text-base font-semibold">Demande de congé</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-[var(--app-muted)] hover:bg-[var(--app-surface-soft)]"
                        aria-label="Fermer"
                    >
                        <X className="h-4 w-4" />
                    </button>
                </div>

                <div className="p-5">
                    {loading && (
                        <div className="flex items-center justify-center py-10 text-sm text-[var(--app-muted)]">
                            Chargement…
                        </div>
                    )}

                    {error && (
                        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400">
                            {error}
                        </div>
                    )}

                    {data && !loading && (
                        <>
                            <div className="mb-4 flex items-center gap-3">
                                <StatusBadge status={data.status} statusLabel={data.status_label} />
                            </div>

                            <dl className="space-y-3">
                                <Row label="Demandeur" value={data.requester_label} />
                                {!data.requester_is_target && (
                                    <Row label="Bénéficiaire" value={data.target_label} />
                                )}
                                {/* Détail rang par rang : outil de travail des
                                    valideurs et de l'administration. Le serveur
                                    ne le renvoie pas au demandeur, à qui le
                                    badge d'état suffit. */}
                                {data?.can_see_validation_detail
                                    ? (data.validation_summary || []).map((entry) => (
                                        <Row
                                            key={entry.level}
                                            label={`Valideur ${entry.level}`}
                                            value={entry.label}
                                        />
                                    ))
                                    : null}
                                <Row label="Type de congé" value={data.leave_type_label} />
                                <Row label="Période demandée" value={periodLabel(data)} />
                                {data.message && <Row label="Message" value={data.message} />}
                            </dl>

                            {data.status === 'pending_user_confirmation' && (
                                <div className="mt-4 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-900/20">
                                    <p className="mb-2 text-xs font-semibold text-blue-700 dark:text-blue-400">
                                        Modification proposée{data.proposed_by_label ? ` par ${data.proposed_by_label}` : ''}
                                    </p>
                                    <dl className="space-y-2">
                                        <Row label="Nouvelle période" value={periodLabel(data, 'proposed_')} />
                                        {data.proposed_message && <Row label="Message" value={data.proposed_message} />}
                                    </dl>
                                </div>
                            )}

                            <div className="mt-4 border-t border-[var(--app-border)] pt-4">
                                <dl className="space-y-2">
                                    <Row label="Créée le" value={formatDateTime(data.created_at)} />
                                    <Row label="Mise à jour" value={formatDateTime(data.updated_at)} />
                                </dl>
                            </div>

                            {/* Action buttons */}
                            <div className="mt-5 space-y-2">
                                <ActionError message={actionError} />

                                {data.permissions?.can_approve && (
                                    <button
                                        type="button"
                                        onClick={() => handleAction(`/leaves/${leaveRequestId}/approve`)}
                                        disabled={actionLoading}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                                    >
                                        <Check className="h-4 w-4" />
                                        {actionLoading ? 'Traitement…' : 'Accepter'}
                                    </button>
                                )}

                                {data.permissions?.can_propose_modification && !showModifyForm && (
                                    <button
                                        type="button"
                                        onClick={() => setShowModifyForm(true)}
                                        disabled={actionLoading}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] px-4 py-2.5 text-sm font-medium hover:bg-[var(--app-surface-soft)] disabled:opacity-50"
                                    >
                                        <Pencil className="h-4 w-4" />
                                        Proposer une modification
                                    </button>
                                )}

                                {showModifyForm && (
                                    <form
                                        onSubmit={handleModifySubmit}
                                        className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-4 space-y-3"
                                    >
                                        <p className="text-xs font-semibold text-[var(--app-muted)]">Nouvelle période proposée</p>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <label className="mb-1 block text-xs text-[var(--app-muted)]">Début</label>
                                                <input
                                                    type="date"
                                                    required
                                                    value={modifyForm.proposed_start_at}
                                                    onChange={(e) => setModifyForm((f) => ({ ...f, proposed_start_at: e.target.value }))}
                                                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-1.5 text-sm"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-xs text-[var(--app-muted)]">Fin</label>
                                                <input
                                                    type="date"
                                                    required
                                                    value={modifyForm.proposed_end_at}
                                                    onChange={(e) => setModifyForm((f) => ({ ...f, proposed_end_at: e.target.value }))}
                                                    className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-1.5 text-sm"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-xs text-[var(--app-muted)]">Message (optionnel)</label>
                                            <textarea
                                                value={modifyForm.proposed_message}
                                                onChange={(e) => setModifyForm((f) => ({ ...f, proposed_message: e.target.value }))}
                                                rows={2}
                                                className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-1.5 text-sm"
                                            />
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() => setShowModifyForm(false)}
                                                className="flex-1 rounded-xl border border-[var(--app-border)] px-3 py-2 text-xs font-medium hover:bg-[var(--app-surface)]"
                                            >
                                                Annuler
                                            </button>
                                            <button
                                                type="submit"
                                                disabled={actionLoading}
                                                className="flex-1 rounded-xl bg-[#F1BF0C] px-3 py-2 text-xs font-semibold text-black hover:brightness-95 disabled:opacity-50"
                                            >
                                                {actionLoading ? 'Envoi…' : 'Envoyer'}
                                            </button>
                                        </div>
                                    </form>
                                )}

                                {data.permissions?.can_refuse && (
                                    <button
                                        type="button"
                                        onClick={() => handleAction(`/leaves/${leaveRequestId}/refuse`)}
                                        disabled={actionLoading}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-100 disabled:opacity-50 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30"
                                    >
                                        <Ban className="h-4 w-4" />
                                        {actionLoading ? 'Traitement…' : 'Refuser'}
                                    </button>
                                )}

                                {data.permissions?.can_accept_modification && (
                                    <button
                                        type="button"
                                        onClick={() => handleAction(`/leaves/${leaveRequestId}/accept-modification`)}
                                        disabled={actionLoading}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-50"
                                    >
                                        <Check className="h-4 w-4" />
                                        {actionLoading ? 'Traitement…' : 'Accepter la modification'}
                                    </button>
                                )}

                                {data.permissions?.can_refuse_modification && (
                                    <button
                                        type="button"
                                        onClick={() => handleAction(`/leaves/${leaveRequestId}/refuse-modification`)}
                                        disabled={actionLoading}
                                        className="flex w-full items-center justify-center gap-2 rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-100 disabled:opacity-50 dark:border-red-800 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30"
                                    >
                                        <Ban className="h-4 w-4" />
                                        {actionLoading ? 'Traitement…' : 'Refuser la modification'}
                                    </button>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
