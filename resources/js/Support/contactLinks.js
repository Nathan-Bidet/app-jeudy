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
 * Détecte si un numéro de téléphone générique (ex. le champ "phone" d'un
 * contact de transporteur externe, qui n'a pas de champ "Portable" dédié
 * contrairement à un personnel Jeudy) est un mobile français valide, une
 * fois normalisé. Ne modifie jamais la valeur enregistrée en base — sert
 * uniquement à la détection et à la construction du lien SMS.
 *
 * Accepte les écritures avec espaces/points/tirets/parenthèses, le "(0)" de
 * liaison après un préfixe international, et les préfixes +33/0033.
 * Rejette explicitement tout ce qui n'est pas un numéro complet (10 chiffres
 * nationaux commençant par 06/07, ou +33 suivi de 9 chiffres commençant par
 * 6/7) : pas de correspondance partielle sur la seule présence de "06"/"07".
 */
export function isFrenchMobileNumber(value) {
    let normalized = String(value || '').trim();
    if (!normalized) return false;

    // Retire espaces, points, tirets (mais pas encore les parenthèses : le
    // "(0)" doit d'abord être reconnu comme tel, pas juste supprimé).
    normalized = normalized.replace(/[\s.-]/g, '');

    // +33(0)6... / 0033(0)6... -> le "(0)" de liaison n'existe pas à
    // l'international, on le retire avant de traiter le préfixe.
    normalized = normalized.replace(/^(\+33|0033)\(0\)/, '$1');

    // 0033 (écriture internationale équivalente) -> +33.
    normalized = normalized.replace(/^0033/, '+33');

    // Toute parenthèse résiduelle (saisie non standard) est ignorée pour la
    // détection, sans jamais être considérée comme un numéro valide si la
    // structure ne correspond plus ensuite à un mobile complet.
    normalized = normalized.replace(/[()]/g, '');

    // National : exactement 10 chiffres, 06 ou 07.
    if (/^0[67]\d{8}$/.test(normalized)) return true;

    // International : +33 suivi de 6 ou 7 puis exactement 8 chiffres
    // (9 chiffres au total après l'indicatif, l'équivalent du 06/07 nationaux).
    if (/^\+33[67]\d{8}$/.test(normalized)) return true;

    return false;
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
