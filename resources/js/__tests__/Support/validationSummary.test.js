import { describe, expect, it } from 'vitest';
import { validatorIdentityLabel } from '@/Support/validationSummary';

describe('validationSummary', () => {
    it('reste anonyme quand le serveur ne transmet pas de valideur', () => {
        expect(validatorIdentityLabel({ level: 1, decision: null, label: 'En attente' })).toBeNull();
    });

    it('rend le nom du valideur', () => {
        expect(validatorIdentityLabel({ validator: { name: 'Bruno Carré', state: 'active' } })).toBe('Bruno Carré');
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
        expect(validatorIdentityLabel({ validator: null })).toBeNull();
    });
});
