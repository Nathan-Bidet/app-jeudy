/**
 * Rendu d'une entrée de `validation_summary` (congés et heures).
 *
 * Le serveur n'ajoute `entry.validator` qu'aux lecteurs ayant la permission
 * conges_heures.validators_identity.view : sans elle, la clé est absente et
 * l'affichage reste anonyme (« Validé », « Refusé », « En attente »).
 */

const STATE_SUFFIXES = {
    inactive: ' (compte désactivé)',
    deleted: ' (compte supprimé)',
};

export function validatorIdentityLabel(entry) {
    const validator = entry?.validator;
    const name = typeof validator?.name === 'string' ? validator.name.trim() : '';

    if (name === '') {
        return null;
    }

    return `${name}${STATE_SUFFIXES[validator?.state] ?? ''}`;
}

export function validationEntryText(entry) {
    const identity = validatorIdentityLabel(entry);
    const decision = entry?.label ?? '';

    return identity ? `${identity} — ${decision}` : decision;
}
