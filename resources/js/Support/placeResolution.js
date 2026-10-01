import { stripTextMarkers } from '@/Support/textFormatting';

/**
 * Résolution d'un lieu de tâche (texte enregistré) vers un dépôt connu.
 *
 * Le resolver est la table `{ nom du dépôt: adresse }` fournie par le serveur.
 * La correspondance est EXACTE (après normalisation casse/accents/ponctuation) :
 * jamais de recherche partielle, sinon un nom comme « Le Montet » (ville de
 * l'adresse de « Le Marché ») serait confondu avec un autre dépôt.
 */
export function normalizePlaceValue(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLocaleLowerCase('fr')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
}

export function buildPlaceIndex(placeResolver) {
    const byName = new Map();
    const byAddress = new Map();

    if (!placeResolver || typeof placeResolver !== 'object') {
        return { byName, byAddress };
    }

    Object.entries(placeResolver).forEach(([key, value]) => {
        const name = String(key || '').trim();
        const address = String(value || '').trim();
        const normalizedName = normalizePlaceValue(name);
        const normalizedAddress = normalizePlaceValue(address);

        if (!normalizedName || !address) return;

        const entry = { name, address };
        if (!byName.has(normalizedName)) byName.set(normalizedName, entry);
        if (normalizedAddress && !byAddress.has(normalizedAddress)) byAddress.set(normalizedAddress, entry);
    });

    return { byName, byAddress };
}

/**
 * @returns {{ label: string, destination: string }} libellé à afficher et
 * destination de navigation. Un lieu libre est renvoyé tel quel.
 */
export function resolvePlace(place, index) {
    const raw = String(place || '').trim();
    if (!raw) return { label: raw, destination: raw };

    const normalized = normalizePlaceValue(raw);
    const entry = index?.byName?.get(normalized) || index?.byAddress?.get(normalized);

    if (!entry) return { label: raw, destination: raw };

    return { label: entry.name, destination: entry.address };
}

/**
 * Adresse telle qu'affichée/envoyée : l'adresse de la fiche dépôt, sans le code
 * pays par défaut « FR » (ajouté en fin d'adresse par le serveur pour la navigation).
 */
export function displayAddress(address) {
    return String(address || '')
        .replace(/,\s*FR\s*$/i, '')
        .trim();
}

/**
 * Adresse complète du dépôt interne correspondant à un lieu, ou null pour un
 * lieu libre / un dépôt sans adresse connue.
 */
export function depotAddressForPlace(place, index) {
    const raw = String(place || '').trim();
    if (!raw) return null;

    const normalized = normalizePlaceValue(raw);
    const entry = index?.byName?.get(normalized) || index?.byAddress?.get(normalized);
    const address = displayAddress(entry?.address);

    return address || null;
}

/**
 * Lieu(x) de chargement pour un message e-mail/SMS : chaque dépôt interne est
 * remplacé par son adresse ; sans adresse (ou lieu libre), le texte d'origine
 * est conservé. Retourne un texte sur une seule ligne (même format que
 * singleLineText, lieux séparés par « | »).
 */
export function loadingPlaceForMessage(value, placeResolver) {
    const index = buildPlaceIndex(placeResolver);

    return String(value ?? '')
        .split(/\r\n|\r|\n/)
        .map((line) => stripTextMarkers(line).trim())
        .filter(Boolean)
        .map((line) => depotAddressForPlace(line, index) || line)
        .join(' | ')
        .replace(/\s{2,}/g, ' ')
        .trim();
}
