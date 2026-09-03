import { describe, expect, it } from 'vitest';

import {
    checkedExtraLabels,
    computeDayTotals,
    dayHoursLabel,
    dayOvertimeLabel,
    defaultDayState,
    formatOvertime,
    overtimeMinutes,
    referenceMinutesForDate,
    referenceMinutesForDayIndex,
} from '@/Support/hoursWorkTime';

const NO_LEAVE = { morning: false, afternoon: false, fullDay: false };

/** Journée classique, avec une fin d'après-midi choisie pour viser un total. */
function dayWorkedUntil(afternoonEnd, overrides = {}) {
    return {
        ...defaultDayState(),
        afternoon_end: afternoonEnd,
        ...overrides,
    };
}

/** Raccourci : libellé du badge pour un jour de semaine donné. */
function labelFor(dayIndex, dayState) {
    return dayOvertimeLabel({ dayState, coverage: NO_LEAVE, dayIndex });
}

describe('durée normale de la journée', () => {
    it('vaut 8 h du lundi au jeudi et 7 h le vendredi', () => {
        expect(referenceMinutesForDayIndex(0)).toBe(480);
        expect(referenceMinutesForDayIndex(1)).toBe(480);
        expect(referenceMinutesForDayIndex(2)).toBe(480);
        expect(referenceMinutesForDayIndex(3)).toBe(480);
        expect(referenceMinutesForDayIndex(4)).toBe(420);
    });

    it('est dérivée des horaires préremplis, pas écrite en dur', () => {
        // Les mêmes horaires que ceux proposés à la saisie doivent donner
        // exactement la durée de référence : c'est ce qui garantit qu'une
        // journée laissée telle quelle n'affiche aucune heure supplémentaire.
        const { totalMinutes } = computeDayTotals(defaultDayState({ isFriday: true }), NO_LEAVE);

        expect(totalMinutes).toBe(referenceMinutesForDayIndex(4));
    });

    it('n\'en définit aucune pour le week-end', () => {
        expect(referenceMinutesForDayIndex(5)).toBeNull();
        expect(referenceMinutesForDayIndex(6)).toBeNull();
    });

    it('se déduit d\'une date ISO', () => {
        // 2026-08-31 est un lundi, 2026-09-04 un vendredi, 2026-09-05 un samedi.
        expect(referenceMinutesForDate('2026-08-31')).toBe(480);
        expect(referenceMinutesForDate('2026-09-04')).toBe(420);
        expect(referenceMinutesForDate('2026-09-05')).toBeNull();
        expect(referenceMinutesForDate('')).toBeNull();
        expect(referenceMinutesForDate('pas-une-date')).toBeNull();
    });
});

describe('calcul du dépassement', () => {
    it('ne retient que l\'écart positif', () => {
        expect(overtimeMinutes(540, 480)).toBe(60);
        expect(overtimeMinutes(480, 480)).toBe(0);
        expect(overtimeMinutes(450, 480)).toBe(0);
    });

    it('ne compare rien sans durée de référence', () => {
        expect(overtimeMinutes(600, null)).toBe(0);
    });
});

describe('format du libellé', () => {
    it('reste en heures et minutes, jamais en décimal', () => {
        expect(formatOvertime(30)).toBe('+30 min');
        expect(formatOvertime(59)).toBe('+59 min');
        expect(formatOvertime(60)).toBe('+1h00');
        expect(formatOvertime(75)).toBe('+1h15');
        expect(formatOvertime(105)).toBe('+1h45');
        expect(formatOvertime(120)).toBe('+2h00');
        expect(formatOvertime(150)).toBe('+2h30');
    });

    it('ne rend rien sans dépassement', () => {
        expect(formatOvertime(0)).toBeNull();
        expect(formatOvertime(-30)).toBeNull();
    });
});

describe('badge d\'une journée', () => {
    it('lundi : rien à 8 h, +30 min à 8 h 30, +1h00 à 9 h', () => {
        expect(labelFor(0, dayWorkedUntil('18:00'))).toBeNull();
        expect(labelFor(0, dayWorkedUntil('18:30'))).toBe('+30 min');
        expect(labelFor(0, dayWorkedUntil('19:00'))).toBe('+1h00');
    });

    it('mercredi : +1h45 pour une journée de 9 h 45', () => {
        expect(labelFor(2, dayWorkedUntil('19:45'))).toBe('+1h45');
    });

    it('vendredi : rien à 7 h, +30 min à 7 h 30, +1h00 à 8 h', () => {
        expect(labelFor(4, dayWorkedUntil('17:00'))).toBeNull();
        expect(labelFor(4, dayWorkedUntil('17:30'))).toBe('+30 min');
        expect(labelFor(4, dayWorkedUntil('18:00'))).toBe('+1h00');
    });

    it('ne rend rien sous la durée normale', () => {
        expect(labelFor(0, dayWorkedUntil('17:30'))).toBeNull();
        expect(labelFor(4, dayWorkedUntil('16:00'))).toBeNull();
    });

    it('ne rend rien sur une journée non travaillée', () => {
        const notWorked = {
            ...dayWorkedUntil('21:00'),
            is_not_worked: true,
        };

        expect(labelFor(0, notWorked)).toBeNull();
    });

    it('ne rend rien le week-end, faute de durée de référence', () => {
        expect(labelFor(5, dayWorkedUntil('20:00'))).toBeNull();
        expect(labelFor(6, dayWorkedUntil('20:00'))).toBeNull();
    });

    it('suit la journée continue, qui n\'est qu\'une seule plage', () => {
        // 08:00 → 18:00 d'affilée : 10 h, soit 2 h de plus qu'un lundi normal.
        const continuous = {
            ...defaultDayState(),
            is_continuous_day: true,
            morning_start: '08:00',
            afternoon_end: '18:00',
        };

        expect(labelFor(0, continuous)).toBe('+2h00');

        // Une demi-journée continue reste sous la durée normale.
        expect(labelFor(0, { ...continuous, afternoon_end: '12:00' })).toBeNull();
    });

    it('ne compte pas les demi-journées de congé comme des heures supplémentaires', () => {
        // Matin en congé : seule l'après-midi compte, on reste sous la normale.
        const afternoonOnly = dayWorkedUntil('19:00');

        expect(dayOvertimeLabel({
            dayState: afternoonOnly,
            coverage: { morning: true, afternoon: false, fullDay: false },
            dayIndex: 0,
        })).toBeNull();
    });

    it('recalcule à chaque changement d\'horaire', () => {
        // C'est exactement ce que fait la page à chaque frappe : même état,
        // une seule valeur changée, un libellé qui suit.
        let day = dayWorkedUntil('18:00');
        expect(labelFor(0, day)).toBeNull();

        day = { ...day, afternoon_end: '19:00' };
        expect(labelFor(0, day)).toBe('+1h00');

        day = { ...day, afternoon_end: '19:15' };
        expect(labelFor(0, day)).toBe('+1h15');

        day = { ...day, afternoon_end: '18:00' };
        expect(labelFor(0, day)).toBeNull();
    });

    it('accepte un total déjà calculé, sans le recalculer', () => {
        // Cas des cartes en lecture : le total vient du serveur.
        expect(dayOvertimeLabel({
            dayState: { is_not_worked: false },
            coverage: NO_LEAVE,
            totalMinutes: 525,
            workDate: '2026-08-31',
        })).toBe('+45 min');
    });
});

describe('horaires d\'une journée', () => {
    const sheet = {
        morning_start: '08:00',
        morning_end: '12:00',
        afternoon_start: '14:00',
        afternoon_end: '19:00',
        is_continuous_day: false,
    };

    it('rend les deux demi-journées', () => {
        expect(dayHoursLabel(sheet)).toBe('08:00 - 12:00 / 14:00 - 19:00');
    });

    it('rend la journée continue comme une plage unique', () => {
        expect(dayHoursLabel({ ...sheet, is_continuous_day: true }))
            .toBe('08:00 - 19:00 (journée continue)');
    });

    it('annonce les demi-journées couvertes par un congé', () => {
        expect(dayHoursLabel(sheet, { coverage: { morning: true, afternoon: false } }))
            .toBe('Congé / Congé / 14:00 - 19:00');
        expect(dayHoursLabel(sheet, { coverage: { morning: false, afternoon: true } }))
            .toBe('08:00 - 12:00 / Congé / Congé');
    });

    it('ignore la journée continue quand un congé couvre une demi-journée', () => {
        // Le module n'autorise pas la journée continue dans ce cas : la mise en
        // forme doit suivre la même règle.
        expect(dayHoursLabel({ ...sheet, is_continuous_day: true }, { coverage: { morning: true, afternoon: false } }))
            .toBe('Congé / Congé / 14:00 - 19:00');
    });

    it('affiche les emplacements vides par défaut, et les tait sur demande', () => {
        const afternoonOnly = { ...sheet, morning_start: null, morning_end: null };

        // Écran du salarié : la couverture de congé est connue, l'emplacement
        // vide reste visible.
        expect(dayHoursLabel(afternoonOnly)).toBe('--:-- - --:-- / 14:00 - 19:00');

        // File de validation : pas de plage inexistante affichée.
        expect(dayHoursLabel(afternoonOnly, { omitEmpty: true })).toBe('14:00 - 19:00');
    });

    it('ne rend rien quand aucune plage n\'est renseignée', () => {
        expect(dayHoursLabel({}, { omitEmpty: true })).toBeNull();
    });
});

describe('cases particulières', () => {
    it('ne retient que les cases cochées, dans l\'ordre du formulaire', () => {
        expect(checkedExtraLabels({
            has_breakfast_before_5: false,
            has_lunch: true,
            has_dinner_after_21: false,
            has_long_night: true,
        })).toEqual(['Déjeuner', 'Nuit']);
    });

    it('renvoie une liste vide quand rien n\'est coché', () => {
        expect(checkedExtraLabels({})).toEqual([]);
        expect(checkedExtraLabels(null)).toEqual([]);
    });
});
