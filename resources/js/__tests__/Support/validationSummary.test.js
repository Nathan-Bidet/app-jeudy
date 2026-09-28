import { describe, expect, it } from 'vitest';
import { validationEntryText, validatorIdentityLabel } from '@/Support/validationSummary';

describe('validationSummary', () => {
    it('reste anonyme quand le serveur ne transmet pas de valideur', () => {
        const entry = { level: 1, decision: null, label: 'En attente' };

        expect(validatorIdentityLabel(entry)).toBeNull();
        expect(validationEntryText(entry)).toBe('En attente');
    });

    it('préfixe la décision du nom du valideur', () => {
        const entry = { level: 2, label: 'Validé', validator: { name: 'Bruno Carré', state: 'active' } };

        expect(validationEntryText(entry)).toBe('Bruno Carré — Validé');
    });

    it('signale un compte désactivé ou supprimé', () => {
        expect(validatorIdentityLabel({ validator: { name: 'Alice Blanchet', state: 'inactive' } }))
            .toBe('Alice Blanchet (compte désactivé)');
        expect(validatorIdentityLabel({ validator: { name: 'Alice Blanchet', state: 'deleted' } }))
            .toBe('Alice Blanchet (compte supprimé)');
    });

    it('ignore un nom vide ou mal formé', () => {
        expect(validatorIdentityLabel({ validator: { name: '   ', state: 'active' } })).toBeNull();
        expect(validatorIdentityLabel({ validator: { name: 42 } })).toBeNull();
        expect(validationEntryText({ label: 'Refusé', validator: null })).toBe('Refusé');
    });
});
