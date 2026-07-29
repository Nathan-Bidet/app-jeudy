import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

/**
 * Orchestration du modal "Répondre au nom de" / "Modifier la réponse de",
 * partagée par toutes les pages qui affichent un sondage d'annonce avec ses
 * résultats (page détaillée des annonces, page d'accueil, centre de
 * notifications) — même endpoint (annonces.poll-response-for), même
 * dérivation de la réponse existante à partir des résultats déjà chargés,
 * pour ne jamais dupliquer cette logique par page.
 *
 * @param {Array<{id: number|string, poll?: {results?: object}}>} items -
 *   toute donnée exposant un `poll.results` avec un `id` d'annonce : une
 *   liste d'annonces (page Annonces/Accueil) ou une liste construite à partir
 *   de notifications (centre de notifications). Recalculé à chaque
 *   rafraîchissement des props, donc toujours basé sur l'état le plus
 *   récent connu au moment de l'ouverture/de la validation du modal.
 */
export default function useAnswerPollOnBehalf(items) {
    const [target, setTarget] = useState(null); // { announcementId, userId } | null
    const [processing, setProcessing] = useState(false);

    const context = useMemo(() => {
        if (!target) return null;

        const item = (items || []).find((candidate) => Number(candidate?.id) === Number(target.announcementId));
        const results = item?.poll?.results;
        if (!results) return null;

        const respondedEntry = (results.responded_users || [])
            .find((candidate) => Number(candidate.id) === Number(target.userId));
        const pendingEntry = (results.pending_users || [])
            .find((candidate) => Number(candidate.id) === Number(target.userId));
        const user = respondedEntry || pendingEntry;
        if (!user) return null;

        const selectedOptionIds = (results.options || [])
            .filter((option) => (option.respondents || []).some((respondent) => Number(respondent.id) === Number(target.userId)))
            .map((option) => Number(option.id));
        const otherEntry = (results.other_responses || [])
            .find((candidate) => Number(candidate.user_id) === Number(target.userId));

        return {
            announcement: item,
            poll: item.poll,
            user,
            response: respondedEntry ? {
                selected_option_ids: selectedOptionIds,
                other_text: otherEntry ? otherEntry.text : '',
                responded_at: respondedEntry.responded_at,
            } : null,
        };
    }, [target, items]);

    const open = (announcementId, user) => {
        setTarget({ announcementId, userId: user.id });
    };

    const close = () => {
        if (processing) return;
        setTarget(null);
    };

    const submit = (payload) => {
        if (!context) return;

        setProcessing(true);
        router.post(
            route('annonces.poll-response-for', [context.announcement.id, context.user.id]),
            {
                ...payload,
                expected_exists: Boolean(context.response),
                expected_selected_option_ids: context.response?.selected_option_ids ?? [],
                expected_other_text: context.response?.other_text ?? '',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setTarget(null),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return { context, processing, open, close, submit };
}
