import { isFrenchMobileNumber, singleLineText } from '@/Support/contactLinks';
import { stripTextMarkers } from '@/Support/textFormatting';

/**
 * Numéro à utiliser pour l'action SMS d'un destinataire de tâche (group.
 * assignee), sans jamais créer ni dupliquer de champ :
 * 1. Personnel Jeudy avec un champ "Portable" (assignee.mobile_phone)
 *    renseigné -> ce numéro, inchangé (comportement existant).
 * 2. Sinon, contact externe (transporteur/dépôt) sans champ portable dédié :
 *    son téléphone générique (assignee.phone) est utilisé UNIQUEMENT si la
 *    normalisation le reconnaît comme un mobile français complet — jamais
 *    un fixe, jamais une correspondance partielle.
 * 3. Sinon, aucun numéro exploitable pour le SMS.
 *
 * @param {object|null} assignee - group.assignee tel qu'envoyé par le serveur
 * @returns {string|null}
 */
export function pickAprevoirSmsNumber(assignee) {
    const mobile = String(assignee?.mobile_phone || '').trim();
    if (mobile) {
        return mobile;
    }

    const generic = String(assignee?.phone || '').trim();
    if (generic && isFrenchMobileNumber(generic)) {
        return generic;
    }

    return null;
}

/**
 * Construit le contenu SMS/e-mail d'une tâche Tâches/Engrais/À prévoir, au
 * même format que le Livre du travail (resources/js/Components/Ldt/
 * EntryCard.jsx : date, texte de la tâche, Chargement/Livraison, transport,
 * commentaire — une ligne par information, rien de dupliqué). Toujours
 * reconstruit depuis `group`/`task` tels que passés en prop (jamais une
 * copie mémorisée), donc reflète l'état actuellement affiché.
 *
 * @param {object|null} group - le groupe (date + destinataire) de la tâche
 * @param {object|null} task - la tâche affichée
 * @returns {string[]} lignes du message, prêtes à être jointes par '\n'
 */
export function buildAprevoirTaskMessageLines(group, task) {
    const lines = [];

    const dateLabel = String(group?.date_label || group?.date || '').trim();
    if (dateLabel) {
        lines.push(dateLabel);
    }

    const finLabel = String(task?.fin_label || '').trim();
    if (finLabel) {
        lines.push(`Fin: ${finLabel}`);
    }

    const taskText = stripTextMarkers(task?.task).trim();
    if (taskText) {
        lines.push(taskText);
    }

    const loading = singleLineText(task?.loading_place);
    if (loading) {
        lines.push(`Chargement: ${loading}`);
    }

    const delivery = singleLineText(task?.delivery_place);
    if (delivery) {
        lines.push(`Livraison: ${delivery}`);
    }

    const vehicleLabel = [task?.vehicle?.name, task?.vehicle?.registration].filter(Boolean).join(' • ');
    if (vehicleLabel) {
        lines.push(`Camion: ${vehicleLabel}`);
    }

    const remorqueLabel = [task?.remorque?.name, task?.remorque?.registration].filter(Boolean).join(' • ');
    if (remorqueLabel) {
        lines.push(`Remorque: ${remorqueLabel}`);
    }

    const comment = singleLineText(task?.comment);
    if (comment) {
        lines.push(`Commentaire: ${comment}`);
    }

    return lines;
}

/**
 * Objet d'e-mail explicite, même format que le Livre du travail
 * (`${module} - ${date} - ${début de la tâche}`).
 */
export function buildAprevoirTaskSubject(moduleTitle, group, task) {
    const dateLabel = String(group?.date_label || group?.date || '').trim();
    const taskText = stripTextMarkers(task?.task).trim().slice(0, 80);

    return [moduleTitle || 'Tâche', dateLabel, taskText].filter(Boolean).join(' - ');
}
