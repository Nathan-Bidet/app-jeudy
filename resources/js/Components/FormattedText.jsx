import { renderFormattedHtml } from '@/Support/textFormatting';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

export default function FormattedText({
    as: Tag = 'span',
    text,
    multiline = false,
    className = '',
}) {
    const rootRef = useRef(null);
    const popupRef = useRef(null);
    const [phoneActions, setPhoneActions] = useState(null);
    const [popupPosition, setPopupPosition] = useState(null);

    useEffect(() => {
        if (!phoneActions) return undefined;

        const onPointerDown = (event) => {
            const target = event.target;
            if (!rootRef.current?.contains(target) && !popupRef.current?.contains(target)) {
                setPhoneActions(null);
                setPopupPosition(null);
            }
        };

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                setPhoneActions(null);
                setPopupPosition(null);
            }
        };

        const onResize = () => {
            setPhoneActions(null);
            setPopupPosition(null);
        };

        const onScroll = () => {
            setPhoneActions(null);
            setPopupPosition(null);
        };

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        window.addEventListener('resize', onResize);
        window.addEventListener('scroll', onScroll, true);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('resize', onResize);
            window.removeEventListener('scroll', onScroll, true);
        };
    }, [phoneActions]);

    useLayoutEffect(() => {
        if (!phoneActions || !popupRef.current) return;

        const popupRect = popupRef.current.getBoundingClientRect();
        const anchorRect = phoneActions.anchorRect;
        const anchorPoint = phoneActions.anchorPoint;
        const margin = 12;
        const offset = 8;

        let x = anchorPoint?.x ?? (anchorRect.left + (anchorRect.width / 2));
        const minX = margin + (popupRect.width / 2);
        const maxX = window.innerWidth - margin - (popupRect.width / 2);
        x = Math.min(Math.max(x, minX), maxX);

        const anchorBottom = Math.max(anchorRect.bottom, anchorPoint?.y ?? anchorRect.bottom);
        let y = anchorBottom + offset;
        if (y + popupRect.height > window.innerHeight - margin) {
            const topCandidate = anchorRect.top - popupRect.height - offset;
            if (topCandidate >= margin) {
                y = topCandidate;
            } else {
                y = Math.max(margin, window.innerHeight - margin - popupRect.height);
            }
        }

        setPopupPosition({
            left: Math.round(x),
            top: Math.round(y),
        });
    }, [phoneActions]);

    return (
        <>
            <Tag
                ref={rootRef}
                className={className}
                onClick={(event) => {
                    const eventTarget = event.target instanceof Element
                        ? event.target
                        : event.target?.parentElement;
                    const anchor = eventTarget?.closest?.('a.app-autolink[data-phone]');
                    if (!anchor || !rootRef.current?.contains(anchor)) return;

                    event.preventDefault();

                    const rect = anchor.getBoundingClientRect();
                    const number = anchor.getAttribute('data-phone');
                    if (!number) return;

                    const clickedX = Number.isFinite(event.clientX)
                        ? event.clientX
                        : rect.left + (rect.width / 2);
                    const clickedY = Number.isFinite(event.clientY)
                        ? event.clientY
                        : rect.bottom;

                    setPhoneActions({
                        number,
                        anchorPoint: {
                            x: clickedX,
                            y: clickedY,
                        },
                        anchorRect: {
                            left: rect.left,
                            top: rect.top,
                            right: rect.right,
                            bottom: rect.bottom,
                            width: rect.width,
                            height: rect.height,
                        },
                    });
                    setPopupPosition(null);
                }}
                dangerouslySetInnerHTML={{
                    __html: renderFormattedHtml(text, { multiline }),
                }}
            />

            {phoneActions ? createPortal(
                <div
                    ref={popupRef}
                    className="app-phone-actions"
                    style={{
                        left: `${popupPosition?.left ?? Math.round(phoneActions.anchorPoint?.x ?? (phoneActions.anchorRect.left + (phoneActions.anchorRect.width / 2)))}px`,
                        top: `${popupPosition?.top ?? Math.round(Math.max(phoneActions.anchorRect.bottom, phoneActions.anchorPoint?.y ?? phoneActions.anchorRect.bottom) + 8)}px`,
                    }}
                >
                    <a
                        href={`tel:${phoneActions.number}`}
                        className="app-phone-actions__btn"
                        onClick={() => {
                            setPhoneActions(null);
                            setPopupPosition(null);
                        }}
                    >
                        Appeler
                    </a>
                    <a
                        href={`sms:${phoneActions.number}`}
                        className="app-phone-actions__btn app-phone-actions__btn--sms"
                        onClick={() => {
                            setPhoneActions(null);
                            setPopupPosition(null);
                        }}
                    >
                        SMS
                    </a>
                </div>
            , document.body) : null}
        </>
    );
}
