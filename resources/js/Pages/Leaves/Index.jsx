import AppLayout from '@/Layouts/AppLayout';
import LeaveRequestForm from '@/Pages/Leaves/Components/LeaveRequestForm';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function LeavesIndex({
    users = [],
    leaveTypes = [],
    defaultTargetUserId = null,
    canRequestForOthers = false,
    myLeaveRequests = [],
    leaveRequestsToValidate = [],
    canValidateRequests = false,
    canDeleteLeaveRequests = false,
}) {
    const [modificationForms, setModificationForms] = useState({});
    const [showValidationHistory, setShowValidationHistory] = useState(false);

    const formatDateFr = (isoDate) => {
        if (!isoDate || typeof isoDate !== 'string') {
            return '-';
        }

        const match = isoDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) {
            return isoDate;
        }

        const [, year, month, day] = match;
        return `${day}-${month}-${year}`;
    };

    const formatLeaveStatus = (status) => {
        const normalized = String(status || '').toLowerCase();

        if (normalized === 'pending') {
            return 'En attente';
        }

        if (normalized === 'pending_user_confirmation') {
            return 'En attente de votre confirmation';
        }

        if (normalized === 'approved') {
            return 'Approuvé';
        }

        if (normalized === 'refused') {
            return 'Refusé';
        }

        return status;
    };

    const getValidatorFirstName = (request) => {
        const firstName = request?.proposed_by_user?.first_name
            || request?.validator_user?.first_name
            || request?.validator?.first_name
            || request?.proposed_by_first_name
            || request?.validator_first_name
            || null;

        if (firstName && String(firstName).trim() !== '') {
            return String(firstName).trim();
        }

        const fallbackName = request?.proposed_by_user?.name
            || request?.validator_user?.name
            || request?.validator?.name
            || request?.proposed_by_name
            || request?.validator_name
            || null;

        if (fallbackName && String(fallbackName).trim() !== '') {
            return String(fallbackName).trim();
        }

        return 'le valideur';
    };

    const proposedPeriodLabel = (request) => {
        const validatorName = getValidatorFirstName(request);
        return `Période proposée par ${validatorName}`;
    };

    const proposedMessageLabel = (request) => {
        const validatorName = getValidatorFirstName(request);
        return `Message de ${validatorName}`;
    };

    const getLeaveCardStyle = (status) => {
        const normalized = String(status || '').toLowerCase();

        if (normalized === 'approved') {
            return {
                backgroundColor: 'rgba(34, 197, 94, 0.10)',
                borderColor: '#22c55e',
            };
        }

        if (normalized === 'refused') {
            return {
                backgroundColor: 'rgba(239, 68, 68, 0.10)',
                borderColor: '#ef4444',
            };
        }

        return {
            backgroundColor: 'rgba(156, 163, 175, 0.10)',
            borderColor: '#9ca3af',
        };
    };

    const postApprove = (id) => {
        router.post(route('leaves.approve', id));
    };

    const postRefuse = (id) => {
        router.post(route('leaves.refuse', id));
    };

    const postAcceptModification = (id) => {
        router.post(route('leaves.accept_modification', id));
    };

    const postRefuseModification = (id) => {
        router.post(route('leaves.refuse_modification', id));
    };

    const initModificationForm = (request) => ({
        proposed_start_at: request.start_at || '',
        proposed_end_at: request.end_at || '',
        proposed_start_portion: request.proposed_start_portion || 'full_day',
        proposed_end_portion: request.proposed_end_portion || 'full_day',
        proposed_custom_start_time: request.proposed_custom_start_time || '',
        proposed_custom_end_time: request.proposed_custom_end_time || '',
        proposed_message: request.proposed_message || '',
        open: true,
    });

    const toggleModificationForm = (request) => {
        setModificationForms((prev) => {
            const current = prev[request.id];
            if (!current) {
                return {
                    ...prev,
                    [request.id]: initModificationForm(request),
                };
            }

            return {
                ...prev,
                [request.id]: {
                    ...current,
                    open: !current.open,
                },
            };
        });
    };

    const updateModificationForm = (id, field, value) => {
        setModificationForms((prev) => ({
            ...prev,
            [id]: {
                ...(prev[id] || { open: true }),
                [field]: value,
            },
        }));
    };

    const submitModification = (id) => {
        const form = modificationForms[id] || {};
        router.post(route('leaves.propose_modification', id), {
            proposed_start_at: form.proposed_start_at || null,
            proposed_end_at: form.proposed_end_at || null,
            proposed_start_portion: form.proposed_start_portion || 'full_day',
            proposed_end_portion: form.proposed_end_portion || 'full_day',
            proposed_custom_start_time: form.proposed_start_portion === 'custom' ? (form.proposed_custom_start_time || null) : null,
            proposed_custom_end_time: form.proposed_end_portion === 'custom' ? (form.proposed_custom_end_time || null) : null,
            proposed_message: form.proposed_message || null,
        });
    };

    const deleteRequest = (id) => {
        if (!window.confirm('Supprimer cette demande de congé ?')) {
            return;
        }

        router.delete(route('leaves.destroy', id));
    };

    const defaultValidationStatuses = ['pending', 'pending_user_confirmation'];
    const pendingLeaveRequestsToValidate = useMemo(
        () => leaveRequestsToValidate.filter((request) => (
            defaultValidationStatuses.includes(String(request.status || '').toLowerCase())
        )),
        [leaveRequestsToValidate],
    );
    const visibleLeaveRequestsToValidate = showValidationHistory
        ? leaveRequestsToValidate
        : pendingLeaveRequestsToValidate;

    return (
        <AppLayout title="Congés">
            <section className="mx-auto w-full max-w-5xl space-y-4 px-4 py-6 sm:px-6">
                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-5 sm:px-6 sm:py-6">
                    <LeaveRequestForm
                        users={users}
                        leaveTypes={leaveTypes}
                        defaultTargetUserId={defaultTargetUserId}
                        canRequestForOthers={canRequestForOthers}
                    />
                </div>

                <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-5 sm:px-6 sm:py-6">
                    <h2 className="text-lg font-bold text-[var(--app-text)]">Mes demandes</h2>
                    <div className="mt-3 space-y-3">
                        {myLeaveRequests.length === 0 ? (
                            <p className="text-sm text-[var(--app-muted)]">Aucune demande.</p>
                        ) : (
                            myLeaveRequests.map((request) => (
                                <div key={request.id} className="rounded-xl border p-3" style={getLeaveCardStyle(request.status)}>
                                    <p className="text-sm text-[var(--app-text)]">
                                        <span className="font-semibold">Utilisateur :</span> {request.target_label}
                                    </p>
                                    <p className="text-sm text-[var(--app-text)]">
                                        <span className="font-semibold">Du :</span> {formatDateFr(request.start_at)} <span className="font-semibold">au :</span> {formatDateFr(request.end_at)}
                                    </p>
                                    <p className="text-sm text-[var(--app-text)]">
                                        <span className="font-semibold">Statut :</span> {formatLeaveStatus(request.status)}
                                    </p>
                                    {request.message ? (
                                        <p className="text-sm text-[var(--app-text)]">
                                            <span className="font-semibold">Message :</span> {request.message}
                                        </p>
                                    ) : null}
                                    {request.status === 'pending_user_confirmation' ? (
                                        <div className="mt-2 rounded-lg border border-[var(--app-border)] bg-white/50 p-3">
                                            <p className="text-sm text-[var(--app-text)]">
                                                <span className="font-semibold">Période demandée :</span> {formatDateFr(request.start_at)} au {formatDateFr(request.end_at)}
                                            </p>
                                            <p className="text-sm text-[var(--app-text)]">
                                                <span className="font-semibold">{proposedPeriodLabel(request)} :</span> {formatDateFr(request.proposed_start_at)} au {formatDateFr(request.proposed_end_at)}
                                            </p>
                                            {request.proposed_message ? (
                                                <p className="text-sm text-[var(--app-text)]">
                                                    <span className="font-semibold">{proposedMessageLabel(request)} :</span> {request.proposed_message}
                                                </p>
                                            ) : null}
                                            <div className="mt-3 flex gap-2">
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                    onClick={() => postAcceptModification(request.id)}
                                                >
                                                    Accepter la modification
                                                </button>
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                    onClick={() => postRefuseModification(request.id)}
                                                >
                                                    Refuser la modification
                                                </button>
                                            </div>
                                        </div>
                                    ) : null}
                                    {canDeleteLeaveRequests ? (
                                        <div className="mt-3 flex gap-2">
                                            <button
                                                type="button"
                                                className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                onClick={() => deleteRequest(request.id)}
                                            >
                                                Supprimer
                                            </button>
                                        </div>
                                    ) : null}
                                </div>
                            ))
                        )}
                    </div>
                </div>

                {canValidateRequests ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] px-5 py-5 sm:px-6 sm:py-6">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-bold text-[var(--app-text)]">Demandes à valider</h2>
                            <button
                                type="button"
                                className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                onClick={() => setShowValidationHistory((prev) => !prev)}
                            >
                                {showValidationHistory ? "Masquer l'historique" : "Afficher l'historique"}
                            </button>
                        </div>
                        <div className="mt-3 space-y-3">
                            {visibleLeaveRequestsToValidate.length === 0 ? (
                                <p className="text-sm text-[var(--app-muted)]">Aucune demande à valider.</p>
                            ) : (
                                visibleLeaveRequestsToValidate.map((request) => (
                                    <div key={request.id} className="rounded-xl border p-3" style={getLeaveCardStyle(request.status)}>
                                        <p className="text-sm text-[var(--app-text)]">
                                            <span className="font-semibold">Utilisateur :</span> {request.target_label}
                                        </p>
                                        <p className="text-sm text-[var(--app-text)]">
                                            <span className="font-semibold">Du :</span> {formatDateFr(request.start_at)} <span className="font-semibold">au :</span> {formatDateFr(request.end_at)}
                                        </p>
                                        <p className="text-sm text-[var(--app-text)]">
                                            <span className="font-semibold">Statut :</span> {formatLeaveStatus(request.status)}
                                        </p>
                                        {request.message ? (
                                            <p className="text-sm text-[var(--app-text)]">
                                                <span className="font-semibold">Message :</span> {request.message}
                                            </p>
                                        ) : null}
                                        {request.status === 'pending_user_confirmation' ? (
                                            <div className="mt-2 rounded-lg border border-[var(--app-border)] bg-white/50 p-3">
                                                <p className="text-sm text-[var(--app-text)]">
                                                    <span className="font-semibold">{proposedPeriodLabel(request)} :</span> {formatDateFr(request.proposed_start_at)} au {formatDateFr(request.proposed_end_at)}
                                                </p>
                                                {request.proposed_message ? (
                                                    <p className="text-sm text-[var(--app-text)]">
                                                        <span className="font-semibold">{proposedMessageLabel(request)} :</span> {request.proposed_message}
                                                    </p>
                                                ) : null}
                                            </div>
                                        ) : null}
                                        <div className="mt-3 flex gap-2">
                                            <button
                                                type="button"
                                                className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                onClick={() => postApprove(request.id)}
                                            >
                                                Approuver
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                onClick={() => postRefuse(request.id)}
                                            >
                                                Refuser
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                onClick={() => toggleModificationForm(request)}
                                            >
                                                Modifier la période
                                            </button>
                                            {canDeleteLeaveRequests ? (
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                    onClick={() => deleteRequest(request.id)}
                                                >
                                                    Supprimer
                                                </button>
                                            ) : null}
                                        </div>
                                        {modificationForms[request.id]?.open ? (
                                            <div className="mt-3 rounded-lg border border-[var(--app-border)] bg-white/50 p-3">
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <label className="text-sm text-[var(--app-text)]">
                                                        Nouvelle date de début
                                                        <input
                                                            type="date"
                                                            className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                            value={modificationForms[request.id]?.proposed_start_at || ''}
                                                            onChange={(event) => updateModificationForm(request.id, 'proposed_start_at', event.target.value)}
                                                        />
                                                    </label>
                                                    <label className="text-sm text-[var(--app-text)]">
                                                        Nouvelle date de fin
                                                        <input
                                                            type="date"
                                                            className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                            value={modificationForms[request.id]?.proposed_end_at || ''}
                                                            onChange={(event) => updateModificationForm(request.id, 'proposed_end_at', event.target.value)}
                                                        />
                                                    </label>
                                                    <label className="text-sm text-[var(--app-text)]">
                                                        Début
                                                        <select
                                                            className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                            value={modificationForms[request.id]?.proposed_start_portion || 'full_day'}
                                                            onChange={(event) => updateModificationForm(request.id, 'proposed_start_portion', event.target.value)}
                                                        >
                                                            <option value="full_day">Journée entière</option>
                                                            <option value="morning">Matin</option>
                                                            <option value="afternoon">Après-midi</option>
                                                            <option value="custom">Personnaliser</option>
                                                        </select>
                                                    </label>
                                                    <label className="text-sm text-[var(--app-text)]">
                                                        Fin
                                                        <select
                                                            className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                            value={modificationForms[request.id]?.proposed_end_portion || 'full_day'}
                                                            onChange={(event) => updateModificationForm(request.id, 'proposed_end_portion', event.target.value)}
                                                        >
                                                            <option value="full_day">Journée entière</option>
                                                            <option value="morning">Matin</option>
                                                            <option value="afternoon">Après-midi</option>
                                                            <option value="custom">Personnaliser</option>
                                                        </select>
                                                    </label>
                                                    {modificationForms[request.id]?.proposed_start_portion === 'custom' ? (
                                                        <label className="text-sm text-[var(--app-text)]">
                                                            Heure début (custom)
                                                            <input
                                                                type="time"
                                                                className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                                value={modificationForms[request.id]?.proposed_custom_start_time || ''}
                                                                onChange={(event) => updateModificationForm(request.id, 'proposed_custom_start_time', event.target.value)}
                                                            />
                                                        </label>
                                                    ) : null}
                                                    {modificationForms[request.id]?.proposed_end_portion === 'custom' ? (
                                                        <label className="text-sm text-[var(--app-text)]">
                                                            Heure fin (custom)
                                                            <input
                                                                type="time"
                                                                className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                                value={modificationForms[request.id]?.proposed_custom_end_time || ''}
                                                                onChange={(event) => updateModificationForm(request.id, 'proposed_custom_end_time', event.target.value)}
                                                            />
                                                        </label>
                                                    ) : null}
                                                </div>
                                                <label className="mt-2 block text-sm text-[var(--app-text)]">
                                                    Message proposé (optionnel)
                                                    <textarea
                                                        rows={2}
                                                        className="mt-1 w-full rounded-lg border border-[var(--app-border)] px-2 py-1.5 text-sm"
                                                        value={modificationForms[request.id]?.proposed_message || ''}
                                                        onChange={(event) => updateModificationForm(request.id, 'proposed_message', event.target.value)}
                                                    />
                                                </label>
                                                <div className="mt-3">
                                                    <button
                                                        type="button"
                                                        className="rounded-lg border border-[var(--app-border)] px-3 py-1.5 text-sm font-medium text-[var(--app-text)]"
                                                        onClick={() => submitModification(request.id)}
                                                    >
                                                        Envoyer la proposition
                                                    </button>
                                                </div>
                                            </div>
                                        ) : null}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                ) : null}
            </section>
        </AppLayout>
    );
}
