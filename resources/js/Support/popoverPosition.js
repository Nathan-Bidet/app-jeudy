/**
 * Position d'un menu flottant (position: fixed) ancré à un bouton, qui reste
 * entièrement dans la zone visible : centré sur le bouton puis recalé
 * horizontalement, sous le bouton si la place suffit, sinon au-dessus, sinon
 * du côté le plus spacieux avec une hauteur maximale (défilement interne).
 *
 * @param {{left:number,right:number,top:number,bottom:number}} anchor rect du bouton (viewport)
 * @param {{width:number,height:number}} menu taille naturelle du menu
 * @param {{width:number,height:number}} viewport zone visible
 */
export function computePopoverPosition(anchor, menu, viewport, { margin = 8, gap = 4 } = {}) {
    const width = Math.min(menu.width, Math.max(0, viewport.width - margin * 2));

    const centered = anchor.left + (anchor.right - anchor.left) / 2 - width / 2;
    const left = Math.round(Math.min(Math.max(centered, margin), Math.max(margin, viewport.width - margin - width)));

    const spaceBelow = viewport.height - margin - (anchor.bottom + gap);
    const spaceAbove = anchor.top - gap - margin;

    let placement = 'bottom';
    if (menu.height > spaceBelow) {
        placement = menu.height <= spaceAbove || spaceAbove > spaceBelow ? 'top' : 'bottom';
    }

    const available = placement === 'bottom' ? spaceBelow : spaceAbove;
    const height = Math.min(menu.height, Math.max(0, available));
    const top = placement === 'bottom' ? anchor.bottom + gap : anchor.top - gap - height;

    return {
        left,
        top: Math.round(Math.max(margin, top)),
        width: Math.round(width),
        maxHeight: Math.round(Math.max(0, available)),
        placement,
    };
}
