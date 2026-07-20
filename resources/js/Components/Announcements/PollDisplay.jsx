import { useEffect, useState } from 'react';

export function PollResults({ results }) {
    const [openOptionIds, setOpenOptionIds] = useState([]);
    const [showOtherResponses, setShowOtherResponses] = useState(false);

    if (!results) return null;

    const toggleOptionRespondents = (optionId) => {
        const numericId = Number(optionId);
        setOpenOptionIds((previous) => (
            previous.includes(numericId)
                ? previous.filter((id) => id !== numericId)
                : [...previous, numericId]
        ));
    };

    return (
        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
            <div className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Réponses reçues</div>
            <div className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                <div className="rounded-lg bg-[var(--app-surface)] p-2">
                    <span className="block text-xs text-[var(--app-muted)]">Destinataires</span>
                    <strong>{results.recipient_count || 0}</strong>
                </div>
                <div className="rounded-lg bg-[var(--app-surface)] p-2">
                    <span className="block text-xs text-[var(--app-muted)]">Réponses</span>
                    <strong>{results.response_count || 0}</strong>
                </div>
            </div>

            <div className="mt-3 space-y-2">
                {(results.options || []).map((option) => {
                    const isOpen = openOptionIds.includes(Number(option.id));

                    return (
                        <div key={option.id} className="rounded-lg bg-[var(--app-surface)] p-2">
                            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-sm">
                                <span className="break-words font-semibold">{option.label}</span>
                                <span className="text-xs text-[var(--app-muted)]">
                                    {option.votes} vote{option.votes > 1 ? 's' : ''} · {option.percentage}%
                                </span>
                            </div>
                            <div className="mt-2 h-2 overflow-hidden rounded-full bg-gray-200">
                                <div
                                    className="h-full rounded-full bg-[var(--brand-yellow-dark)]"
                                    style={{ width: `${Math.min(Number(option.percentage) || 0, 100)}%` }}
                                />
                            </div>
                            <button
                                type="button"
                                onClick={() => toggleOptionRespondents(option.id)}
                                className="mt-2 text-xs font-semibold text-[var(--brand-brown)] hover:underline"
                            >
                                {isOpen ? 'Masquer les répondants' : 'Voir les répondants'}
                            </button>
                            {isOpen ? (
                                <div className="mt-2 max-h-32 overflow-y-auto rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-2">
                                    {(option.respondents || []).length ? option.respondents.map((user) => (
                                        <p key={user.id} className="text-sm">{user.name}</p>
                                    )) : (
                                        <p className="text-xs text-[var(--app-muted)]">Aucun répondant</p>
                                    )}
                                </div>
                            ) : null}
                        </div>
                    );
                })}
            </div>

            {(results.other_responses || []).length ? (
                <div className="mt-3">
                    <button
                        type="button"
                        onClick={() => setShowOtherResponses((value) => !value)}
                        className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)] hover:text-[var(--brand-brown)]"
                    >
                        {showOtherResponses ? `Masquer les réponses ${results.other_label || 'Autre'}` : `Réponses ${results.other_label || 'Autre'} (${results.other_responses.length})`}
                    </button>
                    {showOtherResponses ? (
                        <div className="mt-2 max-h-44 space-y-2 overflow-y-auto">
                            {results.other_responses.map((response) => (
                                <div key={`${response.user_id}-${response.responded_at}`} className="rounded-lg bg-[var(--app-surface)] p-2 text-sm">
                                    <div className="font-semibold">{response.user_name}</div>
                                    <div className="mt-1 whitespace-pre-wrap break-words">{response.text}</div>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            ) : null}

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <div className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Ont répondu</div>
                    <div className="mt-2 max-h-40 overflow-y-auto rounded-lg bg-[var(--app-surface)] p-2">
                        {(results.responded_users || []).length ? results.responded_users.map((user) => (
                            <p key={user.id} className="text-sm">{user.name}</p>
                        )) : <p className="text-xs text-[var(--app-muted)]">Aucune réponse pour le moment.</p>}
                    </div>
                </div>
                <div>
                    <div className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">En attente</div>
                    <div className="mt-2 max-h-40 overflow-y-auto rounded-lg bg-[var(--app-surface)] p-2">
                        {(results.pending_users || []).length ? results.pending_users.map((user) => (
                            <p key={user.id} className="text-sm">{user.name}</p>
                        )) : <p className="text-xs text-[var(--app-muted)]">Tout le monde a répondu.</p>}
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * Rendu unique du sondage d'une annonce, partagé par la page Annonces,
 * le widget d'accueil, le centre de notifications et le modal de lecture.
 *
 * variant="full" active le vote et l'affichage des résultats (nécessite
 * onSubmitResponse) ; variant="basic" (défaut) n'affiche que le titre, le
 * type et les réponses possibles, sans interaction.
 */
export default function PollDisplay({ poll, variant = 'basic', onSubmitResponse, responseProcessing = false, errors = {} }) {
    const currentResponse = poll?.current_response;
    const [selectedOptionIds, setSelectedOptionIds] = useState([]);
    const [useOther, setUseOther] = useState(false);
    const [otherText, setOtherText] = useState('');

    useEffect(() => {
        const nextSelectedIds = currentResponse?.selected_option_ids || [];
        const nextOtherText = currentResponse?.other_text || '';
        setSelectedOptionIds(nextSelectedIds.map(Number));
        setUseOther(nextOtherText.trim() !== '');
        setOtherText(nextOtherText);
    }, [poll?.id, currentResponse?.responded_at]);

    if (!poll) return null;

    const canRespond = variant === 'full' && Boolean(poll.can_respond) && typeof onSubmitResponse === 'function';

    const toggleOption = (optionId) => {
        const numericId = Number(optionId);
        if (poll.poll_type === 'single') {
            setSelectedOptionIds([numericId]);
            setUseOther(false);
            return;
        }

        setSelectedOptionIds((previous) => (
            previous.includes(numericId)
                ? previous.filter((id) => id !== numericId)
                : [...previous, numericId]
        ));
    };

    const toggleOther = (checked) => {
        setUseOther(checked);
        if (checked && poll.poll_type === 'single') {
            setSelectedOptionIds([]);
        }
        if (!checked) {
            setOtherText('');
        }
    };

    const submit = () => {
        onSubmitResponse({
            selected_option_ids: selectedOptionIds,
            other_text: useOther ? otherText : '',
        });
    };

    return (
        <div className="min-w-0 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
            <div className="text-xs font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">Sondage</div>
            {poll.title ? (
                <div className="mt-1 break-words text-base font-bold text-[var(--app-text)]">{poll.title}</div>
            ) : null}
            <div className="mt-1 font-semibold">
                {poll.poll_type === 'multiple' ? 'Choix multiples' : 'Choix unique'}
            </div>

            {canRespond ? (
                <div className="mt-3 space-y-2">
                    {(poll.options || []).map((option) => (
                        <label key={option.id} className="flex cursor-pointer items-start gap-2 rounded-lg bg-[var(--app-surface)] px-3 py-2">
                            <input
                                type={poll.poll_type === 'multiple' ? 'checkbox' : 'radio'}
                                name={`announcement-poll-${poll.id}`}
                                checked={selectedOptionIds.includes(Number(option.id))}
                                onChange={() => toggleOption(option.id)}
                                className="mt-0.5 shrink-0"
                            />
                            <span className="break-words">{option.label}</span>
                        </label>
                    ))}

                    {poll.allow_other ? (
                        <div className="rounded-lg bg-[var(--app-surface)] px-3 py-2">
                            <label className="flex cursor-pointer items-center gap-2">
                                <input
                                    type={poll.poll_type === 'multiple' ? 'checkbox' : 'radio'}
                                    name={`announcement-poll-${poll.id}`}
                                    checked={useOther}
                                    onChange={(event) => toggleOther(event.target.checked)}
                                />
                                <span className="break-words">{poll.other_label || 'Autre'}</span>
                            </label>
                            {useOther ? (
                                <textarea
                                    value={otherText}
                                    onChange={(event) => setOtherText(event.target.value)}
                                    rows={3}
                                    className="mt-2 w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-sm"
                                    placeholder="Votre réponse..."
                                />
                            ) : null}
                        </div>
                    ) : null}

                    {currentResponse ? (
                        <p className="text-xs font-semibold text-emerald-700">Votre réponse a été enregistrée.</p>
                    ) : null}
                    {errors.selected_option_ids ? (
                        <p className="text-xs font-semibold text-red-600">{errors.selected_option_ids}</p>
                    ) : null}
                    {errors.other_text ? (
                        <p className="text-xs font-semibold text-red-600">{errors.other_text}</p>
                    ) : null}

                    <button
                        type="button"
                        onClick={submit}
                        disabled={responseProcessing}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-2 text-xs font-black uppercase tracking-[0.08em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        {currentResponse ? 'Enregistrer ma réponse' : 'Répondre'}
                    </button>
                </div>
            ) : (
                <ul className="mt-2 list-disc space-y-1 pl-5">
                    {(poll.options || []).map((option) => (
                        <li key={option.id} className="break-words">{option.label}</li>
                    ))}
                    {poll.allow_other ? <li className="break-words">{poll.other_label || 'Autre'} activé</li> : null}
                </ul>
            )}

            {poll.results ? <PollResults results={poll.results} /> : null}
        </div>
    );
}
