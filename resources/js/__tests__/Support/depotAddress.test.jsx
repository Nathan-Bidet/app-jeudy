import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import PlaceActionsLink from '@/Components/PlaceActionsLink';
import { buildAprevoirTaskMessageLines } from '@/Support/aprevoirTaskMessage';
import { depotAddressForPlace, buildPlaceIndex, displayAddress, placeForMessage } from '@/Support/placeResolution';

// Format du serveur (reference.depot_place_map) : adresse + code pays par défaut.
const DEPOTS = {
    'Le Montet': '35 Route Départementale, 03240 Le Montet, FR',
    'Le Marché': 'Le Plan de Foire, 03240 Le Montet, FR',
    Sansadresse: '',
    Belge: 'Rue X, 1000 Bruxelles, BE',
};

describe('adresse du dépôt', () => {
    it('retire seulement le code pays par défaut', () => {
        expect(displayAddress('35 Route Départementale, 03240 Le Montet, FR')).toBe('35 Route Départementale, 03240 Le Montet');
        expect(displayAddress('Rue X, 1000 Bruxelles, BE')).toBe('Rue X, 1000 Bruxelles, BE');
        expect(displayAddress(null)).toBe('');
    });

    it('depotAddressForPlace : dépôt avec adresse, sans adresse, lieu libre', () => {
        const index = buildPlaceIndex(DEPOTS);
        expect(depotAddressForPlace('Le Montet', index)).toBe('35 Route Départementale, 03240 Le Montet');
        expect(depotAddressForPlace('Sansadresse', index)).toBeNull();
        expect(depotAddressForPlace('Ferme Dupont', index)).toBeNull();
    });
});

describe('messages e-mail / SMS', () => {
    it('remplace le dépôt par son adresse complète', () => {
        const lines = buildAprevoirTaskMessageLines({ date_label: 'lundi' }, { task: 'Livraison', loading_place: 'Le Montet' }, DEPOTS);
        expect(lines).toContain('Chargement: 35 Route Départementale, 03240 Le Montet');
        expect(lines.join('\n')).not.toMatch(/null|undefined/);
    });

    it('conserve le nom sans adresse, et un lieu libre tel quel', () => {
        expect(placeForMessage('Sansadresse', DEPOTS)).toBe('Sansadresse');
        expect(placeForMessage('Ferme Dupont', DEPOTS)).toBe('Ferme Dupont');
        expect(placeForMessage('Ferme **Dupont**', DEPOTS)).toBe('Ferme Dupont');
    });

    it('traite plusieurs lieux, dans l’ordre, et reste sur une ligne', () => {
        expect(placeForMessage('Le Marché\nFerme Dupont\nLe Montet', DEPOTS)).toBe(
            'Le Plan de Foire, 03240 Le Montet | Ferme Dupont | 35 Route Départementale, 03240 Le Montet',
        );
    });

    it('livraison : remplace le dépôt par son adresse, comme le chargement', () => {
        const lines = buildAprevoirTaskMessageLines(
            {},
            { task: 'T', loading_place: 'Le Montet', delivery_place: 'Le Marché' },
            DEPOTS,
        );
        expect(lines).toContain('Chargement: 35 Route Départementale, 03240 Le Montet');
        expect(lines).toContain('Livraison: Le Plan de Foire, 03240 Le Montet');
    });

    it('livraison : dépôt sans adresse, lieu libre et lieux multiples', () => {
        const lines = (delivery) => buildAprevoirTaskMessageLines({}, { task: 'T', delivery_place: delivery }, DEPOTS);

        expect(lines('Sansadresse')).toContain('Livraison: Sansadresse');
        expect(lines('Ferme Dupont')).toContain('Livraison: Ferme Dupont');
        expect(lines('Ferme Dupont\nLe Montet')).toContain('Livraison: Ferme Dupont | 35 Route Départementale, 03240 Le Montet');
        expect(lines('').join('\n')).not.toMatch(/Livraison|null|undefined/);
    });

    it('sans resolver, le message est identique à l’existant', () => {
        expect(buildAprevoirTaskMessageLines({}, { task: 'T', loading_place: 'Le Montet\nAilleurs' })).toContain('Chargement: Le Montet | Ailleurs');
    });
});

describe('affichage dans la liste (PlaceActionsLink)', () => {
    it('affiche l’adresse sous le nom pour un dépôt interne', () => {
        const { container } = render(<PlaceActionsLink text="Le Montet" placeResolver={DEPOTS} showDepotAddress />);
        const wrapper = container.querySelector('button').parentElement;

        expect(wrapper.children[0].textContent).toBe('Le Montet');
        expect(wrapper.children[1].textContent).toBe('35 Route Départementale, 03240 Le Montet');
    });

    it('n’affiche rien de plus pour un lieu libre, un dépôt sans adresse ou sans l’option', () => {
        const free = render(<PlaceActionsLink text="Ferme Dupont" placeResolver={DEPOTS} showDepotAddress />);
        expect(free.container.querySelector('button').parentElement.children).toHaveLength(1);

        const noAddr = render(<PlaceActionsLink text="Sansadresse" placeResolver={DEPOTS} showDepotAddress />);
        expect(noAddr.container.querySelector('button').parentElement.children).toHaveLength(1);

        const off = render(<PlaceActionsLink text="Le Montet" placeResolver={DEPOTS} />);
        expect(off.container.querySelector('button').parentElement.children).toHaveLength(1);
    });

    it('le même composant sert chargement et livraison (même rendu)', () => {
        const a = render(<PlaceActionsLink text="Le Marché" placeResolver={DEPOTS} showDepotAddress />);
        const b = render(<PlaceActionsLink text="Le Marché" placeResolver={DEPOTS} showDepotAddress />);

        expect(a.container.innerHTML).toBe(b.container.innerHTML);
        expect(a.container.textContent).toBe('Le MarchéLe Plan de Foire, 03240 Le Montet');
    });

    it('chaque lieu multiple porte sa propre adresse', () => {
        const { container } = render(<PlaceActionsLink text={'Le Marché\nFerme Dupont'} placeResolver={DEPOTS} showDepotAddress />);
        expect(container.textContent).toBe('Le MarchéLe Plan de Foire, 03240 Le MontetFerme Dupont');
    });
});
