import { singleLineText } from '@/Support/contactLinks';
import { stripTextMarkers } from '@/Support/textFormatting';

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
