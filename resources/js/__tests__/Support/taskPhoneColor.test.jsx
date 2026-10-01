import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import FormattedText from '@/Components/FormattedText';
import RuleFormModal from '@/Components/TaskFormatting/RuleFormModal';
import { renderFormattedHtml } from '@/Support/textFormatting';
import { adaptiveTaskStyle, PHONE_COLOR_VAR } from '@/Support/taskColorStyle';

describe('adaptiveTaskStyle — couleur des numéros de téléphone', () => {
    it('expose la couleur de la règle via une variable CSS héritée', () => {
        const style = adaptiveTaskStyle({ text_color: '#111111', bg_color: '#166534', phone_color: '#FACC15' });

        expect(style[PHONE_COLOR_VAR]).toBe('#FACC15');
        expect(style.color).toBe('#111111');
        expect(style.backgroundColor).toBe('#166534');
    });

    it('ne définit rien pour une ancienne règle (Auto) ou une valeur invalide', () => {
        expect(adaptiveTaskStyle({ text_color: '#111111' })[PHONE_COLOR_VAR]).toBeUndefined();
        expect(adaptiveTaskStyle({ phone_color: null })[PHONE_COLOR_VAR]).toBeUndefined();
        expect(adaptiveTaskStyle({ phone_color: 'url(x)' })[PHONE_COLOR_VAR]).toBeUndefined();
    });
});

describe('liens du texte des tâches', () => {
    it('ne marque data-phone que sur les numéros, pas sur les e-mails', () => {
        const html = renderFormattedHtml('Appeler 06 12 34 56 78 ou jean@example.com', { multiline: false });
        const root = document.createElement('div');
        root.innerHTML = html;

        const phone = root.querySelector('a[href^="tel:"]');
        const mail = root.querySelector('a[href^="mailto:"]');

        expect(phone.getAttribute('data-phone')).toBe('0612345678');
        expect(mail.hasAttribute('data-phone')).toBe(false);
    });

    it('le CSS ne colore que a.app-autolink[data-phone] avec la variable, avec repli sur les couleurs historiques', () => {
        const css = readFileSync('resources/css/app.css', 'utf8');

        expect(css).toMatch(/\.app-autolink\[data-phone\]\s*\{\s*color:\s*var\(--app-phone-color, #282727\)/);
        expect(css).toMatch(/html\.theme-dark \.app-autolink\[data-phone\]\s*\{\s*color:\s*var\(--app-phone-color, #f2e4cf\)/);
        expect(css).toMatch(/html\.theme-dark \.ldt-page \.app-autolink\[data-phone\]\s*\{\s*color:\s*var\(--app-phone-color, rgb\(141, 88, 26\)\)/);
        expect(css).not.toMatch(/\.app-autolink\s*\{[^}]*--app-phone-color/);
    });

    it('garde le numéro cliquable (lien tel:) dans un conteneur portant la variable', () => {
        const { container } = render(
            <div style={adaptiveTaskStyle({ phone_color: '#FACC15' })}>
                <FormattedText text="Tel 06 12 34 56 78" />
            </div>,
        );

        const link = container.querySelector('a.app-autolink[data-phone]');
        expect(link.getAttribute('href')).toBe('tel:0612345678');
        expect(container.firstChild.style.getPropertyValue(PHONE_COLOR_VAR)).toBe('#FACC15');
    });
});

describe('RuleFormModal', () => {
    const makeForm = (data = {}) => ({
        data: {
            name: 'Test', scope: 'both', match_type: 'contains', pattern: 'x',
            text_color: '', bg_color: '#166534', phone_color: '#FACC15',
            is_active: true, applies_to_a_prevoir: true, applies_to_ldt: true, description: '',
            ...data,
        },
        errors: {},
        setData: () => {},
        processing: false,
    });

    it('propose le champ, recharge la valeur et montre un numéro dans l’aperçu', () => {
        render(
            <RuleFormModal
                show
                mode="edit"
                form={makeForm()}
                scopes={['both']}
                matchTypes={['contains']}
                canManage
                onClose={() => {}}
                onSubmit={() => {}}
            />,
        );

        expect(screen.getByLabelText('Couleur des numéros de téléphone').value).toBe('#FACC15');

        const link = document.querySelector('a.app-autolink[data-phone]');
        expect(link.textContent).toBe('06 12 34 56 78');
        expect(link.parentElement.closest('div').style.getPropertyValue(PHONE_COLOR_VAR)).toBe('#FACC15');
    });

    it('affiche l’erreur de validation serveur', () => {
        const form = makeForm();
        form.errors = { phone_color: 'The phone color field format is invalid.' };

        render(
            <RuleFormModal show mode="create" form={form} scopes={['both']} matchTypes={['contains']} canManage onClose={() => {}} onSubmit={() => {}} />,
        );

        expect(screen.getByText('The phone color field format is invalid.')).toBeTruthy();
    });
});
