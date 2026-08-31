import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

/**
 * Chaîne de soumission du formulaire Maintenance.
 *
 * Ces tests existent parce qu'un bug a échappé à toute la suite backend :
 * `form.transform(...)` ne retourne rien dans l'adaptateur React d'Inertia, si
 * bien que le chaînage `.transform(...).post(...)` levait un TypeError avant
 * même l'envoi. Aucun test PHP ne pouvait le voir, la requête ne partant
 * jamais du navigateur.
 */

const routeMock = vi.fn((name, params) => {
    const id = typeof params === 'object' ? params?.id ?? '' : params ?? '';
    return `/${String(name).replace(/\./g, '/')}${id ? `/${id}` : ''}`;
});

globalThis.route = routeMock;

vi.mock('@/Layouts/AppLayout', () => ({
    default: ({ header, children }) => (
        <div>
            {header}
            {children}
        </div>
    ),
}));

/**
 * Reproduit fidèlement le contrat de useForm côté React : transform() est un
 * setter qui ne retourne rien. Tout chaînage sur son retour doit donc échouer
 * ici comme dans le navigateur.
 */
function createFormStub() {
    const stub = {
        data: {},
        errors: {},
        processing: false,
        transformed: null,
        setData: vi.fn((key, value) => {
            stub.data[key] = value;
        }),
        setDefaults: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
        transform: vi.fn((callback) => {
            stub.transformed = callback;
            // Volontairement sans valeur de retour, comme @inertiajs/react.
        }),
        post: vi.fn(),
        put: vi.fn(),
    };

    return stub;
}

let formStub;

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        Head: ({ children }) => <>{children}</>,
        router: { get: vi.fn(), patch: vi.fn(), delete: vi.fn(), post: vi.fn(), put: vi.fn() },
        useForm: () => formStub,
    };
});

const { default: MaintenanceIndex } = await import('@/Pages/Maintenance/Index');

function renderPage(permissions = { can_create: true }) {
    formStub = createFormStub();

    return render(
        <MaintenanceIndex
            groups={[]}
            meta={{ count_tasks: 0, count_groups: 0 }}
            filters={{}}
            reference={{ assignee_users: [], depots: [], depot_place_map: {}, place_suggestions: [] }}
            permissions={permissions}
        />,
    );
}

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('création d’une tâche Maintenance', () => {
    it('envoie réellement le POST au clic sur « Créer la tâche »', () => {
        renderPage();

        fireEvent.click(screen.getByRole('button', { name: /nouvelle tâche/i }));

        formStub.data = { date: '2026-09-10', task: 'Révision compresseur' };

        fireEvent.click(screen.getByRole('button', { name: /créer la tâche/i }));

        expect(formStub.post).toHaveBeenCalledTimes(1);
        expect(formStub.post.mock.calls[0][0]).toContain('maintenance/tasks/store');
    });

    it('déclare l’origine via transform sans chaîner sur son retour', () => {
        renderPage();

        fireEvent.click(screen.getByRole('button', { name: /nouvelle tâche/i }));
        fireEvent.click(screen.getByRole('button', { name: /créer la tâche/i }));

        expect(formStub.transform).toHaveBeenCalled();
        expect(formStub.transformed).toBeTypeOf('function');
        expect(formStub.transformed({ task: 'X' })).toEqual({ task: 'X', origin: 'creation' });
    });

    it('soumet une demande quand l’utilisateur ne peut que demander', () => {
        renderPage({ can_create: false, can_request: true });

        fireEvent.click(screen.getByRole('button', { name: /demander une tâche/i }));
        fireEvent.click(screen.getByRole('button', { name: /envoyer la demande/i }));

        expect(formStub.post).toHaveBeenCalledTimes(1);
        expect(formStub.transformed({})).toEqual({ origin: 'request' });
    });
});
