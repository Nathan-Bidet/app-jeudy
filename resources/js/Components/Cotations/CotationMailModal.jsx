import Modal from '@/Components/Modal';
import { Loader2, Send, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function xsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function parseRecipients(value) {
    return String(value || '')
        .split(/[\s,;]+/)
        .map((item) => item.trim())
        .filter(Boolean);
}

/**
 * Fenêtre de composition du message d'information des cotations : le corps
 * (déjà assaini côté serveur) est celui du bloc « Information », affiché en
 * aperçu, et l'e-mail n'est envoyé qu'après validation explicite.
 */
export default function CotationMailModal({ show, draftUrl, sendUrl, onClose, onSent, onCancel }) {
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const [draft, setDraft] = useState(null);
    const [recipients, setRecipients] = useState('');
    const [subject, setSubject] = useState('');
    const recipientsRef = useRef(null);

    useEffect(() => {
        if (!show) return undefined;

        let cancelled = false;
        setLoading(true);
        setError('');
        setDraft(null);
        setRecipients('');

        fetch(draftUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data?.message || 'Impossible de préparer le message.');
                if (cancelled) return;
                setDraft(data);
                setSubject(data.subject || '');
                window.setTimeout(() => recipientsRef.current?.focus(), 50);
            })
            .catch((exception) => {
                if (!cancelled) setError(exception?.message || 'Impossible de préparer le message.');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });

        return () => {
            cancelled = true;
        };
    }, [show, draftUrl]);

    const cancel = () => {
        if (sending) return;
        onCancel?.();
        onClose();
    };

    const submit = async (event) => {
        event.preventDefault();
        if (sending) return;

        const list = parseRecipients(recipients);
        if (list.length === 0) {
            setError('Renseignez au moins un destinataire.');
            return;
        }

        setSending(true);
        setError('');

        try {
            const response = await fetch(sendUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ to: list, subject }),
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const firstValidation = data?.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(firstValidation || data?.message || "L'e-mail n'a pas pu être envoyé.");
            }

            onSent?.(data?.sent ?? list.length);
            onClose();
        } catch (exception) {
            setError(exception?.message || "L'e-mail n'a pas pu être envoyé.");
        } finally {
            setSending(false);
        }
    };

    const isEmpty = Boolean(draft?.is_empty);
    const canSend = !loading && !sending && draft && !isEmpty;

    return (
        <Modal show={show} onClose={cancel} maxWidth="2xl" closeable={!sending}>
            <form onSubmit={submit} className="rounded-lg bg-[var(--app-surface)] text-[var(--app-text)]">
                <div className="flex items-center justify-between gap-3 border-b border-[var(--app-border)] px-4 py-3">
                    <h2 className="text-sm font-black uppercase tracking-[0.1em]">Nouveau message</h2>
                    <button
                        type="button"
                        onClick={cancel}
                        disabled={sending}
                        aria-label="Fermer sans envoyer"
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--app-muted)] hover:bg-[var(--app-surface-soft)] disabled:opacity-60"
                    >
                        <X className="h-4 w-4" strokeWidth={2.3} />
                    </button>
                </div>

                <div className="space-y-3 px-4 py-4">
                    <label className="block">
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">À</span>
                        <input
                            ref={recipientsRef}
                            type="text"
                            inputMode="email"
                            autoComplete="off"
                            value={recipients}
                            onChange={(event) => setRecipients(event.target.value)}
                            placeholder="adresse@exemple.fr (séparées par une virgule)"
                            aria-label="Destinataires"
                            className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Objet</span>
                        <input
                            type="text"
                            maxLength={200}
                            value={subject}
                            onChange={(event) => setSubject(event.target.value)}
                            aria-label="Objet du message"
                            className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                        />
                    </label>

                    <div>
                        <span className="mb-1 block text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Message</span>
                        <div className="max-h-[40vh] overflow-y-auto rounded-lg border border-[var(--app-border)] bg-white p-3 text-sm leading-relaxed text-black [overflow-wrap:anywhere]">
                            {loading ? (
                                <span className="inline-flex items-center gap-2 text-[var(--app-muted)]">
                                    <Loader2 className="h-4 w-4 animate-spin" /> Préparation du message…
                                </span>
                            ) : isEmpty ? (
                                <span className="font-semibold text-[var(--app-muted)]">
                                    Le message d'information est vide : renseignez-le dans « Modifier les cotations » avant d'envoyer.
                                </span>
                            ) : draft ? (
                                <div dangerouslySetInnerHTML={{ __html: draft.body_html }} />
                            ) : null}
                        </div>
                    </div>

                    {error ? (
                        <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700">
                            {error}
                        </div>
                    ) : null}
                </div>

                <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--app-border)] px-4 py-3">
                    <button
                        type="button"
                        onClick={cancel}
                        disabled={sending}
                        className="inline-flex items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:opacity-60"
                    >
                        Annuler
                    </button>
                    <button
                        type="submit"
                        disabled={!canSend}
                        aria-label="Envoyer le message d'information par e-mail"
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        {sending ? <Loader2 className="h-3.5 w-3.5 animate-spin" strokeWidth={2.3} /> : <Send className="h-3.5 w-3.5" strokeWidth={2.3} />}
                        {sending ? 'Envoi…' : 'Envoyer'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
