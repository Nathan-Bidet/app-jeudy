import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { objectToFormData, router } from '@inertiajs/core';
import '@testing-library/jest-dom';

/**
 * Envoi des téléphones depuis l'édition d'une fiche annuaire.
 *
 * Régression : retirer le dernier téléphone puis enregistrer affichait
 * « Fiche enregistrée. » mais le numéro restait en base. Le formulaire part en
 * FormData (forceFormData) et un tableau vide n'y produit aucune entrée : la
 * clé directory_phones disparaissait, le serveur la croyait inchangée.
 * Ces tests passent par le vrai useForm d'Inertia et la vraie sérialisation.
 */

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        Head: () => null,
        Link: ({ children, href }) => <a href={href}>{children}</a>,
    };
});

const { default: DirectoryEdit } = await import('@/Pages/Directory/Edit');

function renderEdit(phones) {
    return render(
        <DirectoryEdit
            profile={{ id: 7, email: 'jean@example.test', directory_phones: phones }}
            sectors={[]}
            depots={[]}
            managers={[]}
            permissions={{ can_manage_all_fields: false, can_manage_directory_fields: false }}
            routes={{ show: '/annuaire/7', update: '/annuaire/7' }}
            field_access={{ identity_contact: { phone: true, mobile_phone: true, internal_number: true } }}
        />,
    );
}

function submitAndCapture() {
    fireEvent.click(screen.getByRole('button', { name: /Enregistrer/ }));

    expect(router.put).toHaveBeenCalledTimes(1);
    const [url, data, options] = router.put.mock.calls[0];

    // Même sérialisation que router.visit : forceIndicesArrayFormatInFormData
    // est actif par défaut, d'où le format « indices ».
    return { url, data, options, formData: objectToFormData(data, new FormData(), null, 'indices') };
}

beforeEach(() => {
    vi.spyOn(router, 'put').mockImplementation(() => {});
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('Édition annuaire — téléphones', () => {
    it('retirer le seul téléphone envoie explicitement une liste vide', () => {
        renderEdit([{ label: 'TEST', number: '0629073760' }]);

        fireEvent.click(screen.getByRole('button', { name: /Retirer/ }));
        const { data, options, formData } = submitAndCapture();

        expect(options.forceFormData).toBe(true);
        expect(data.directory_phones).toBeNull();
        // La clé doit exister dans la requête, avec une valeur vide.
        expect(formData.has('directory_phones')).toBe(true);
        expect(formData.get('directory_phones')).toBe('');
        expect([...formData.keys()].some((key) => key.startsWith('directory_phones['))).toBe(false);
    });

    it('retirer un téléphone parmi plusieurs envoie la liste restante', () => {
        renderEdit([
            { label: 'Perso', number: '06 11 11 11 11' },
            { label: 'TEST', number: '0629073760' },
        ]);

        fireEvent.click(screen.getAllByRole('button', { name: /Retirer/ })[1]);
        const { formData } = submitAndCapture();

        expect(formData.get('directory_phones[0][label]')).toBe('Perso');
        expect(formData.get('directory_phones[0][number]')).toBe('06 11 11 11 11');
        expect(formData.has('directory_phones[1][number]')).toBe(false);
    });

    it('sans modification, la liste existante est renvoyée telle quelle', () => {
        renderEdit([{ label: 'Perso', number: '06 11 11 11 11' }]);

        const { formData } = submitAndCapture();

        expect(formData.get('directory_phones[0][label]')).toBe('Perso');
        expect(formData.get('directory_phones[0][number]')).toBe('06 11 11 11 11');
    });
});
