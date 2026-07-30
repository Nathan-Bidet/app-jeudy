import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';

import Modal from '@/Components/Modal';

/**
 * Régression : le modal "Répondre au nom de..." (enfant), ouvert au-dessus
 * du modal "Réponses" (parent) depuis la page d'une annonce, fermait aussi
 * le parent au moindre clic à l'intérieur de l'enfant (sélection d'une
 * option, Annuler, validation...).
 *
 * Cause réelle : le composant Modal partagé fixait `id="modal"` en dur sur
 * le <Dialog> HeadlessUI. HeadlessUI utilise cet id comme identité dans une
 * pile globale (stackMachines) pour déterminer quel dialogue est "au premier
 * plan" et doit seul réagir aux clics extérieurs/à Escape. Avec un id
 * identique sur les deux dialogues, chacun se croyait au premier plan en
 * permanence : un clic à l'intérieur de l'enfant (donc "extérieur" au panel
 * du parent) déclenchait aussi la fermeture du parent.
 *
 * Le premier bloc de tests reproduit fidèlement ce bug avec une copie
 * minimale de l'ancien composant (id en dur) — preuve du mécanisme. Le
 * second bloc vérifie que le Modal actuel (id généré automatiquement par
 * HeadlessUI, unique par instance) isole correctement les deux dialogues.
 */

function LegacyModalWithHardcodedId({ children, show, onClose }) {
    return (
        <Transition show={show} leave="duration-200">
            <Dialog as="div" id="modal" className="fixed inset-0 z-50" onClose={onClose}>
                <TransitionChild enter="duration-0" enterFrom="opacity-0" enterTo="opacity-100" leave="duration-0">
                    <div className="fixed inset-0 bg-gray-500/75" />
                </TransitionChild>
                <TransitionChild enter="duration-0" enterFrom="opacity-0" enterTo="opacity-100" leave="duration-0">
                    <DialogPanel className="relative mx-auto w-full max-w-lg bg-white">{children}</DialogPanel>
                </TransitionChild>
            </Dialog>
        </Transition>
    );
}

function StackedLegacyModals({ onParentClose, onChildClose }) {
    return (
        <>
            <LegacyModalWithHardcodedId show onClose={onParentClose}>
                <p>Réponses (parent)</p>
            </LegacyModalWithHardcodedId>
            <LegacyModalWithHardcodedId show onClose={onChildClose}>
                <button type="button">Oui</button>
            </LegacyModalWithHardcodedId>
        </>
    );
}

function StackedModals({ onParentClose, onChildClose, childZIndexClass }) {
    return (
        <>
            <Modal show onClose={onParentClose} maxWidth="2xl">
                <p>Réponses (parent)</p>
            </Modal>
            <Modal show onClose={onChildClose} maxWidth="lg" zIndexClass={childZIndexClass}>
                <button type="button">Oui</button>
            </Modal>
        </>
    );
}

beforeEach(() => {
    global.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

afterEach(() => {
    cleanup();
});

describe('Reproduction du bug (id="modal" en dur sur les deux dialogues)', () => {
    it('closes the parent when clicking inside the child, because both dialogs share the same stack identity', () => {
        const onParentClose = vi.fn();
        const onChildClose = vi.fn();
        render(<StackedLegacyModals onParentClose={onParentClose} onChildClose={onChildClose} />);

        // HeadlessUI détecte les clics extérieurs via pointerdown/pointerup
        // (capture), pas via mousedown/click classiques.
        const button = screen.getByRole('button', { name: 'Oui' });
        fireEvent.pointerDown(button);
        fireEvent.pointerUp(button);

        expect(onParentClose).toHaveBeenCalled();
    });
});

describe('Modal — isolation parent/enfant (id unique par instance)', () => {
    it('does not close the parent when clicking inside the child modal', () => {
        const onParentClose = vi.fn();
        const onChildClose = vi.fn();
        render(<StackedModals onParentClose={onParentClose} onChildClose={onChildClose} />);

        const button = screen.getByRole('button', { name: 'Oui' });
        fireEvent.pointerDown(button);
        fireEvent.pointerUp(button);

        expect(onParentClose).not.toHaveBeenCalled();
    });

    it('Escape closes only the topmost (child) dialog', () => {
        const onParentClose = vi.fn();
        const onChildClose = vi.fn();
        render(<StackedModals onParentClose={onParentClose} onChildClose={onChildClose} />);

        fireEvent.keyDown(document, { key: 'Escape', code: 'Escape' });

        expect(onChildClose).toHaveBeenCalledTimes(1);
        expect(onParentClose).not.toHaveBeenCalled();
    });

    it('gives the two dialogs distinct DOM ids', () => {
        render(<StackedModals onParentClose={vi.fn()} onChildClose={vi.fn()} />);

        // { hidden: true } : le parent est marqué aria-hidden pendant que
        // l'enfant est ouvert (comportement HeadlessUI standard — le fond
        // reste visuellement bloqué et inerte tant que l'enfant est là).
        const dialogs = screen.getAllByRole('dialog', { hidden: true });
        expect(dialogs).toHaveLength(2);
        expect(dialogs[0].id).not.toBe('');
        expect(dialogs[1].id).not.toBe('');
        expect(dialogs[0].id).not.toBe(dialogs[1].id);
    });

    it('marks the parent inert (aria-hidden) while the child is open, keeping its background visually blocked', () => {
        render(<StackedModals onParentClose={vi.fn()} onChildClose={vi.fn()} />);

        const [parentDialog] = screen.getAllByRole('dialog', { hidden: true });
        expect(parentDialog.closest('[aria-hidden="true"]')).toBeTruthy();
    });

    it('both dialogs are rendered simultaneously (parent background stays visible/blocked behind the child)', () => {
        render(<StackedModals onParentClose={vi.fn()} onChildClose={vi.fn()} />);

        expect(screen.getByText('Réponses (parent)')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Oui' })).toBeInTheDocument();
    });
});
