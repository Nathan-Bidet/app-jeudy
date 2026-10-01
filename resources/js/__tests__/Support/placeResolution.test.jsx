import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { buildPlaceIndex, resolvePlace } from '@/Support/placeResolution';

// Données réelles : l'adresse de « Le Marché » et « Le Silo » contient la ville « Le Montet ».
const DEPOTS = {
    'Le Marché': 'Le Plan de Foire, 03240 Le Montet',
    'Le Montet': '35 Route Départementale, 03240 Le Montet',
    'Le Silo': 'D22, 03240 Le Montet',
    Cérilly: '24 rue de Daumin, 03350 Cérilly',
};
const index = buildPlaceIndex(DEPOTS);

describe('resolvePlace', () => {
    it.each(['Le Montet', 'le montet', 'LE  MONTET'])('« %s » reste « Le Montet » (adresse propre au dépôt)', (value) => {
        expect(resolvePlace(value, index)).toEqual({ label: 'Le Montet', destination: '35 Route Départementale, 03240 Le Montet' });
    });

    it('« Le Marché » et « Le Silo » restent distincts de « Le Montet »', () => {
        expect(resolvePlace('Le Marché', index).label).toBe('Le Marché');
        expect(resolvePlace('Le Marche', index).label).toBe('Le Marché');
        expect(resolvePlace('Le Silo', index).label).toBe('Le Silo');
    });

    it('une adresse exacte de dépôt s’affiche sous le nom du dépôt', () => {
        expect(resolvePlace('Le Plan de Foire, 03240 Le Montet', index).label).toBe('Le Marché');
    });

    it('n’utilise aucune correspondance partielle : lieu libre inchangé', () => {
        expect(resolvePlace('03240 Le Montet', index)).toEqual({ label: '03240 Le Montet', destination: '03240 Le Montet' });
        expect(resolvePlace('Le Mont', index).label).toBe('Le Mont');
        expect(resolvePlace('Ferme Dupont', index).label).toBe('Ferme Dupont');
    });
});

describe('PlaceActionsLink', () => {
    const labels = () => screen.getAllByRole('button').map((b) => b.textContent);

    it('affiche chaque lieu multiple dans l’ordre, sans substitution', () => {
        render(<PlaceActionsLink text={'Le Montet\nLe Marché\nFerme Dupont\nCérilly'} placeResolver={DEPOTS} />);

        expect(labels()).toEqual(['Le Montet', 'Le Marché', 'Ferme Dupont', 'Cérilly']);
    });

    it('ouvre l’itinéraire du dépôt réellement sélectionné', () => {
        render(<PlaceActionsLink text="Le Montet" placeResolver={DEPOTS} />);
        fireEvent.click(screen.getByRole('button', { name: 'Le Montet' }));

        expect(screen.getByText('35 Route Départementale, 03240 Le Montet')).toBeTruthy();
        expect(screen.queryByText('Le Plan de Foire, 03240 Le Montet')).toBeNull();
    });

    it('affiche le même résultat pour chargement et livraison', () => {
        const { container } = render(
            <>
                <PlaceActionsLink text="Le Montet" placeResolver={DEPOTS} />
                <PlaceActionsLink text="Le Montet" placeResolver={DEPOTS} />
            </>,
        );

        expect([...container.querySelectorAll('button')].map((b) => b.textContent)).toEqual(['Le Montet', 'Le Montet']);
    });
});
