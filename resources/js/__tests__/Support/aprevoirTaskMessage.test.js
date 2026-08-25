import { describe, expect, it } from 'vitest';
import { buildAprevoirTaskMessageLines, buildAprevoirTaskSubject, pickAprevoirSmsNumber } from '@/Support/aprevoirTaskMessage';

function baseGroup(overrides = {}) {
    return { date: '2026-08-20', date_label: '20-08-2026', ...overrides };
}

function baseTask(overrides = {}) {
    return {
        task: 'Livraison engrais',
        fin_label: null,
        loading_place: null,
        delivery_place: null,
        comment: null,
        vehicle: null,
        remorque: null,
        ...overrides,
    };
}

describe('buildAprevoirTaskMessageLines', () => {
    it('includes date, task text, end date, truck, remorque, loading/delivery and comment, in order', () => {
        const lines = buildAprevoirTaskMessageLines(
            baseGroup(),
            baseTask({
                fin_label: '22-08-2026',
                loading_place: 'Silo Nord',
                delivery_place: 'Exploitation Dupont',
                comment: 'Prévenir avant arrivée',
                vehicle: { name: 'Camion 1', registration: 'AA-123-BB' },
                remorque: { name: 'Remorque 1', registration: 'CC-456-DD' },
            }),
        );

        expect(lines).toEqual([
            '20-08-2026',
            'Fin: 22-08-2026',
            'Livraison engrais',
            'Chargement: Silo Nord',
            'Livraison: Exploitation Dupont',
            'Camion: Camion 1 • AA-123-BB',
            'Remorque: Remorque 1 • CC-456-DD',
            'Commentaire: Prévenir avant arrivée',
        ]);
    });

    it('omits any field that is empty rather than showing a placeholder', () => {
        const lines = buildAprevoirTaskMessageLines(baseGroup(), baseTask());
        expect(lines).toEqual(['20-08-2026', 'Livraison engrais']);
    });

    it('falls back to group.date when date_label is absent', () => {
        const lines = buildAprevoirTaskMessageLines(baseGroup({ date_label: null }), baseTask());
        expect(lines[0]).toBe('2026-08-20');
    });

    it('strips ** / ~~ markers and collapses multi-line fields to a single readable line', () => {
        const lines = buildAprevoirTaskMessageLines(
            baseGroup(),
            baseTask({ task: '**Urgent** livraison', comment: 'Ligne 1\nLigne 2' }),
        );
        expect(lines).toContain('Urgent livraison');
        expect(lines).toContain('Commentaire: Ligne 1 | Ligne 2');
    });

    it('reflects the currently passed task/group, not a stale copy', () => {
        const group = baseGroup();
        const first = buildAprevoirTaskMessageLines(group, baseTask({ comment: 'Ancien' }));
        const second = buildAprevoirTaskMessageLines(group, baseTask({ comment: 'Nouveau' }));

        expect(first).toContain('Commentaire: Ancien');
        expect(second).toContain('Commentaire: Nouveau');
        expect(second).not.toContain('Commentaire: Ancien');
    });
});

describe('pickAprevoirSmsNumber', () => {
    it('uses the explicit "Portable" field when present (Jeudy staff), even if a generic phone is also set', () => {
        expect(pickAprevoirSmsNumber({ type: 'user', mobile_phone: '0612345678', phone: '0102030405' })).toBe('0612345678');
    });

    it('falls back to the generic phone field when it is a recognizable French mobile (external transporter/depot contact)', () => {
        expect(pickAprevoirSmsNumber({ type: 'transporter', mobile_phone: null, phone: '06 77 27 03 03' })).toBe('06 77 27 03 03');
        expect(pickAprevoirSmsNumber({ type: 'transporter', mobile_phone: null, phone: '07 12 34 56 78' })).toBe('07 12 34 56 78');
        expect(pickAprevoirSmsNumber({ type: 'depot', mobile_phone: null, phone: '+33 6 77 27 03 03' })).toBe('+33 6 77 27 03 03');
    });

    it('does not use the generic phone field when it is a landline', () => {
        expect(pickAprevoirSmsNumber({ type: 'transporter', mobile_phone: null, phone: '01 23 45 67 89' })).toBeNull();
    });

    it('returns null when there is no mobile-capable number at all (including no assignee)', () => {
        expect(pickAprevoirSmsNumber({ type: 'transporter', mobile_phone: null, phone: null })).toBeNull();
        expect(pickAprevoirSmsNumber({ type: 'none' })).toBeNull();
        expect(pickAprevoirSmsNumber(null)).toBeNull();
    });
});

describe('buildAprevoirTaskSubject', () => {
    it('builds "<module> - <date> - <début de la tâche>"', () => {
        const subject = buildAprevoirTaskSubject('Engrais / Tourteaux', baseGroup(), baseTask({ task: 'Livraison engrais' }));
        expect(subject).toBe('Engrais / Tourteaux - 20-08-2026 - Livraison engrais');
    });

    it('truncates a long task text to 80 characters', () => {
        const longTask = 'A'.repeat(120);
        const subject = buildAprevoirTaskSubject('Engrais / Tourteaux', baseGroup(), baseTask({ task: longTask }));
        expect(subject.endsWith('A'.repeat(80))).toBe(true);
    });
});
