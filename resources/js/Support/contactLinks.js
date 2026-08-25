import { stripTextMarkers } from '@/Support/textFormatting';

/**
 * Primitives partagées de construction de liens tel:/sms:/mailto:, extraites
 * de resources/js/Components/Ldt/EntryCard.jsx (Livre du travail) afin
 * d'être réutilisées ailleurs (ex. Tâches/Engrais) sans dupliquer le format
 * d'URL ni l'encodage. encodeURIComponent gère nativement les accents,
 * apostrophes, espaces et retours à la ligne : aucun traitement manuel
 * supplémentaire n'est nécessaire.
 */

export function toTelHref(number) {
    const raw = (number || '').toString().trim();
    if (!raw) return null;
    return `tel:${raw.replace(/[^\d+]/g, '')}`;
}

export function toSmsHref(number) {
    const raw = (number || '').toString().trim();
    if (!raw) return null;
    return `sms:${raw.replace(/[^\d+]/g, '')}`;
}

export function toMailHref(address) {
    const email = String(address || '').trim();
    if (!email) return null;
    return `mailto:${encodeURIComponent(email)}`;
}

/**
 * sms:<numéro>?body=<message encodé> — même format que le Livre du travail
 * (pas de &body=, compatible iOS/Android/desktop sans détection de plateforme).
 */
export function buildSmsHref(number, lines) {
    const base = toSmsHref(number);
    if (!base) return null;
    return `${base}?body=${encodeURIComponent((lines || []).join('\n'))}`;
}

/**
 * mailto:<adresse>?subject=<objet encodé>&body=<message encodé> — même
 * format que le Livre du travail.
 *
 * Contrairement à buildSmsHref (qui renvoie null sans numéro, l'action SMS
 * reste alors indisponible), buildMailHref ne renvoie JAMAIS null : l'objet
 * et le corps ne doivent pas dépendre de la présence d'une adresse. Sans
 * adresse, le lien mailto: est construit avec un destinataire vide
 * (`mailto:?subject=...&body=...`) — l'application de messagerie s'ouvre en
 * rédaction avec le contenu de la tâche déjà prérempli, et l'utilisateur
 * choisit lui-même le destinataire.
 */
export function buildMailHref(address, subject, lines) {
    const email = String(address || '').trim();
    const recipient = email ? encodeURIComponent(email) : '';
    return `mailto:${recipient}?subject=${encodeURIComponent(subject || '')}&body=${encodeURIComponent((lines || []).join('\n'))}`;
}

/**
 * Aplati un champ potentiellement multi-lignes en une seule ligne lisible
 * dans un SMS (les retours à la ligne y rendent le message confus) — même
 * comportement que singleLineSmsText() dans EntryCard.jsx.
 */
export function singleLineText(value) {
    return stripTextMarkers(value)
        .replace(/\r\n|\r|\n/g, ' | ')
        .replace(/\s{2,}/g, ' ')
        .trim();
}
