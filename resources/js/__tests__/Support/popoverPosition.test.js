import { describe, expect, it } from 'vitest';
import { computePopoverPosition } from '@/Support/popoverPosition';

const menu = { width: 240, height: 100 };
const viewport = { width: 1000, height: 700 };
const rect = (left, top, w = 60, h = 24) => ({ left, top, right: left + w, bottom: top + h });
const inside = (p, vp = viewport) =>
    p.left >= 8 && p.left + p.width <= vp.width - 8 && p.top >= 8 && p.top + Math.min(menu.height, p.maxHeight) <= vp.height - 8;

describe('computePopoverPosition', () => {
    it('centre le menu sous le bouton quand la place suffit', () => {
        const p = computePopoverPosition(rect(470, 100), menu, viewport);
        expect(p).toMatchObject({ placement: 'bottom', left: 380, top: 128 });
    });

    it('s’aligne vers la droite près du bord gauche', () => {
        const p = computePopoverPosition(rect(0, 100), menu, viewport);
        expect(p.left).toBe(8);
        expect(inside(p)).toBe(true);
    });

    it('s’aligne vers la gauche près du bord droit', () => {
        const p = computePopoverPosition(rect(940, 100), menu, viewport);
        expect(p.left + p.width).toBe(viewport.width - 8);
        expect(inside(p)).toBe(true);
    });

    it('s’ouvre vers le haut quand il n’y a pas assez de place en dessous', () => {
        const p = computePopoverPosition(rect(470, 650), menu, viewport);
        expect(p.placement).toBe('top');
        expect(p.top + menu.height).toBeLessThanOrEqual(650 - 4);
        expect(inside(p)).toBe(true);
    });

    it('réduit la largeur sur un écran étroit (mobile)', () => {
        const vp = { width: 200, height: 600 };
        const p = computePopoverPosition(rect(140, 100), menu, vp);
        expect(p.width).toBe(184);
        expect(p.left).toBe(8);
        expect(inside(p, vp)).toBe(true);
    });

    it('limite la hauteur (défilement interne) quand aucun côté ne suffit', () => {
        const vp = { width: 400, height: 150 };
        const p = computePopoverPosition(rect(100, 60), menu, vp);
        expect(p.maxHeight).toBeLessThan(menu.height);
        expect(p.top + p.maxHeight).toBeLessThanOrEqual(vp.height - 8);
        expect(p.top).toBeGreaterThanOrEqual(8);
    });
});
