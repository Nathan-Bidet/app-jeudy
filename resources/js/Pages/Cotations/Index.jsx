import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm } from '@inertiajs/react';
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    ChevronLeft,
    ChevronRight,
    FileDown,
    Fuel,
    GripVertical,
    History,
    Info,
    Italic,
    Pencil,
    RefreshCw,
    RotateCcw,
    Save,
    Settings2,
    Strikethrough,
    Trash2,
    Truck,
    Underline,
    X,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';

const BASE_CEREALS = [
    { code: 'ECO', name: 'Colza' },
    { code: 'EBM', name: 'Blé' },
    { code: 'EMA', name: 'Maïs' },
];

const DEFAULT_CEREAL_TABLE_LABELS = {
    maturity: 'Échéance',
    matif: 'MATIF',
    base: 'Base',
    final_price: 'Prix final',
};

const COTATION_TABLE_CLASS = 'w-full table-fixed text-[12px] leading-normal sm:text-sm';
const COTATION_HEADER_ROW_CLASS = 'text-xs font-black uppercase leading-4 tracking-[0.08em] text-[var(--app-muted)]';
const COTATION_HEADER_CELL_CLASS = 'px-1.5 py-2 sm:px-2.5';
const COTATION_BODY_CELL_CLASS = 'px-1.5 py-2 align-top sm:px-2.5';
const COTATION_VALUE_CLASS = 'text-xs font-medium leading-4 tabular-nums sm:text-sm sm:leading-5';
const COTATION_FINAL_VALUE_CLASS = 'text-xs font-black leading-4 tabular-nums sm:text-sm sm:leading-5';
const COTATION_ROW_LABEL_CLASS = 'text-xs font-black leading-4 sm:text-sm sm:leading-5';
const COTATION_HARVEST_TITLE_CLASS = 'border-b border-[var(--app-border)] bg-[#FACC51] px-3 py-2 text-[13px] font-black uppercase leading-4 tracking-[0.08em] text-[var(--color-black)]';
const TRANSPORT_HEADER_LABEL_CLASS = 'text-sm font-black leading-5';
const TRANSPORT_HEADER_INPUT_CLASS = 'text-sm font-black leading-5';

function CollapsibleSection({ title, titleEditor = null, icon: Icon, children, actions = null, titleClassName = 'text-lg', bodyClassName = 'mt-3', defaultOpen = false }) {
    const [isOpen, setIsOpen] = useState(defaultOpen);

    return (
        <section className="w-full max-w-full min-w-0 overflow-hidden rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                {titleEditor ? (
                    <div
                        role="button"
                        tabIndex={0}
                        onClick={() => setIsOpen((value) => !value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                setIsOpen((value) => !value);
                            }
                        }}
                        aria-expanded={isOpen}
                        className="flex min-w-0 flex-1 items-center justify-between gap-3 text-left"
                    >
                        <span className="flex min-w-0 max-w-full flex-1 items-center gap-2">
                            {Icon ? <Icon className="h-5 w-5 shrink-0 text-[var(--brand-brown)]" strokeWidth={2.3} /> : null}
                            <span className="min-w-0 max-w-full flex-1" onClick={(event) => event.stopPropagation()} onKeyDown={(event) => event.stopPropagation()}>
                                {titleEditor}
                            </span>
                        </span>
                        <ChevronRight
                            className={`h-5 w-5 shrink-0 text-[var(--app-muted)] transition-transform duration-200 ${isOpen ? 'rotate-90' : ''}`}
                            strokeWidth={2.4}
                        />
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={() => setIsOpen((value) => !value)}
                        aria-expanded={isOpen}
                        className="flex min-w-0 flex-1 items-center justify-between gap-3 text-left"
                    >
                        <span className="flex min-w-0 max-w-full flex-1 items-center gap-2">
                            {Icon ? <Icon className="h-5 w-5 shrink-0 text-[var(--brand-brown)]" strokeWidth={2.3} /> : null}
                            <span className={`${titleClassName} min-w-0 max-w-full break-words font-black uppercase tracking-[0.04em] [overflow-wrap:anywhere]`}>
                                {title}
                            </span>
                        </span>
                        <ChevronRight
                            className={`h-5 w-5 shrink-0 text-[var(--app-muted)] transition-transform duration-200 ${isOpen ? 'rotate-90' : ''}`}
                            strokeWidth={2.4}
                        />
                    </button>
                )}

                {actions ? (
                    <div className="shrink-0" onClick={(event) => event.stopPropagation()}>
                        {actions}
                    </div>
                ) : null}
            </div>

            {isOpen ? (
                <div className={`min-w-0 max-w-full ${bodyClassName}`}>
                    {children}
                </div>
            ) : null}
        </section>
    );
}

const RICH_TEXT_FONT_SIZES = [
    { label: 'Petit', px: 12 },
    { label: 'Normal', px: 14 },
    { label: 'Moyen', px: 18 },
    { label: 'Grand', px: 24 },
    { label: 'Très grand', px: 32 },
];

function ToolbarButton({ icon: Icon, label, onClick }) {
    return (
        <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
            aria-label={label}
            title={label}
            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:bg-[var(--app-surface-soft)]"
        >
            <Icon className="h-4 w-4" strokeWidth={2.3} />
        </button>
    );
}

function RichTextEditor({ value, onChange }) {
    const editorRef = useRef(null);
    const selectionRangeRef = useRef(null);
    const initializedRef = useRef(false);

    useEffect(() => {
        if (!initializedRef.current && editorRef.current) {
            editorRef.current.innerHTML = value || '';
            initializedRef.current = true;
        }
    }, [value]);

    const saveSelection = () => {
        const selection = window.getSelection();
        if (selection && selection.rangeCount > 0 && editorRef.current?.contains(selection.anchorNode)) {
            selectionRangeRef.current = selection.getRangeAt(0).cloneRange();
        }
    };

    const restoreSelection = () => {
        editorRef.current?.focus();
        const selection = window.getSelection();
        if (!selection || !selectionRangeRef.current) return;
        selection.removeAllRanges();
        selection.addRange(selectionRangeRef.current);
    };

    const emitChange = () => {
        onChange(editorRef.current?.innerHTML || '');
    };

    const runCommand = (command, arg = null) => {
        restoreSelection();
        document.execCommand(command, false, arg);
        saveSelection();
        emitChange();
    };

    const applyFontSize = (px) => {
        restoreSelection();
        document.execCommand('fontSize', false, '7');
        editorRef.current?.querySelectorAll('font[size="7"]').forEach((node) => {
            const span = document.createElement('span');
            span.style.fontSize = `${px}px`;
            span.innerHTML = node.innerHTML;
            node.replaceWith(span);
        });
        saveSelection();
        emitChange();
    };

    const applyColor = (command, color) => {
        restoreSelection();
        document.execCommand('styleWithCSS', false, true);
        const applied = document.execCommand(command, false, color);
        if (!applied && command === 'hiliteColor') {
            document.execCommand('backColor', false, color);
        }
        saveSelection();
        emitChange();
    };

    return (
        <div className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)]">
            <div className="flex flex-wrap items-center gap-1.5 border-b border-[var(--app-border)] p-2">
                <ToolbarButton icon={Bold} label="Gras" onClick={() => runCommand('bold')} />
                <ToolbarButton icon={Italic} label="Italique" onClick={() => runCommand('italic')} />
                <ToolbarButton icon={Underline} label="Souligné" onClick={() => runCommand('underline')} />
                <ToolbarButton icon={Strikethrough} label="Barré" onClick={() => runCommand('strikeThrough')} />
                <span className="mx-1 h-6 w-px bg-[var(--app-border)]" />
                <ToolbarButton icon={AlignLeft} label="Aligner à gauche" onClick={() => runCommand('justifyLeft')} />
                <ToolbarButton icon={AlignCenter} label="Centrer" onClick={() => runCommand('justifyCenter')} />
                <ToolbarButton icon={AlignRight} label="Aligner à droite" onClick={() => runCommand('justifyRight')} />
                <ToolbarButton icon={AlignJustify} label="Justifier" onClick={() => runCommand('justifyFull')} />
                <span className="mx-1 h-6 w-px bg-[var(--app-border)]" />
                <select
                    onMouseDown={saveSelection}
                    onChange={(event) => applyFontSize(Number(event.target.value))}
                    defaultValue=""
                    aria-label="Taille du texte"
                    className="h-9 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-sm font-semibold"
                >
                    <option value="" disabled>Taille</option>
                    {RICH_TEXT_FONT_SIZES.map((size) => (
                        <option key={size.px} value={size.px}>{size.label}</option>
                    ))}
                </select>
                <label className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-semibold" title="Couleur du texte">
                    Texte
                    <input
                        type="color"
                        onMouseDown={saveSelection}
                        onChange={(event) => applyColor('foreColor', event.target.value)}
                        className="h-6 w-6 cursor-pointer border-0 bg-transparent p-0"
                        aria-label="Couleur du texte"
                    />
                </label>
                <label className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 text-xs font-semibold" title="Couleur de surlignage">
                    Surlignage
                    <input
                        type="color"
                        onMouseDown={saveSelection}
                        onChange={(event) => applyColor('hiliteColor', event.target.value)}
                        className="h-6 w-6 cursor-pointer border-0 bg-transparent p-0"
                        aria-label="Couleur de surlignage"
                    />
                </label>
            </div>
            <div
                ref={editorRef}
                contentEditable
                suppressContentEditableWarning
                onInput={emitChange}
                onMouseUp={saveSelection}
                onKeyUp={saveSelection}
                className="min-h-[120px] w-full max-w-full min-w-0 p-3 text-sm leading-relaxed outline-none [overflow-wrap:anywhere]"
            />
        </div>
    );
}

function CerealInfoSection({ canView, isEditing, html, onChange }) {
    if (!canView) return null;

    if (isEditing) {
        return (
            <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
                <div className="mb-3 flex items-center gap-2">
                    <Info className="h-5 w-5 text-[var(--brand-brown)]" strokeWidth={2.3} />
                    <h2 className="text-lg font-black uppercase tracking-[0.04em]">Information</h2>
                </div>
                <RichTextEditor value={html} onChange={onChange} />
            </section>
        );
    }

    const isEmpty = !(html || '').replace(/<[^>]*>/g, '').trim();
    if (isEmpty) return null;

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div
                className="text-sm leading-relaxed [overflow-wrap:anywhere]"
                dangerouslySetInnerHTML={{ __html: html }}
            />
        </section>
    );
}

function formatDateTime(value) {
    if (!value) return 'Jamais';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    return new Intl.DateTimeFormat('fr-FR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') return '—';
    const normalized = String(value).replace(',', '.').replace(/[^\d.\-+]/g, '');
    const number = Number(normalized);
    if (!Number.isFinite(number)) return String(value);

    return `${new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(number)} €`;
}

function formatRoundedPrice(value) {
    if (value === null || value === undefined || value === '') return '—';
    const normalized = String(value).replace(',', '.').replace(/[^\d.\-+]/g, '');
    const number = Number(normalized);
    if (!Number.isFinite(number)) return String(value);

    return `${new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Math.round(number))} €`;
}

function formatMargin(value) {
    const number = parseDecimal(value);
    if (number === null) return '—';

    return `-${new Intl.NumberFormat('fr-FR', {
        maximumFractionDigits: 0,
    }).format(Math.abs(number))} €`;
}

function parseDecimal(value) {
    if (value === null || value === undefined || value === '') return null;
    const normalized = String(value).replace(',', '.').replace(/[^\d.\-+]/g, '');
    const number = Number(normalized);

    return Number.isFinite(number) ? number : null;
}

function normalizeMarginInput(value) {
    const digits = String(value ?? '').replace(/[^\d]/g, '');
    if (digits === '') return '';

    return String(Number(digits));
}

function normalizeSignedNumberInput(value) {
    const normalized = String(value ?? '').replace(',', '.').replace(/[^\d.\-+]/g, '');
    if (normalized === '' || normalized === '-' || normalized === '+') return normalized;

    return normalized;
}

function clientHash() {
    return Array.from({ length: 40 }, () => Math.floor(Math.random() * 16).toString(16)).join('');
}

function rowDraftKey(row) {
    return row.form_key || row.manual_id || row.identity_hash || `${row.product_code}-${row.harvest_year}-${row.maturity_year}-${row.maturity_month || ''}-${row.label || row.maturity_label || ''}`;
}

function transportReferenceKey(row) {
    if (row?.identity_hash) return `identity:${row.identity_hash}`;
    if (row?.manual_id) return `manual:${row.manual_id}`;
    if (row?.form_key) return `draft:${row.form_key}`;

    return `line:${row?.product_code || ''}:${row?.harvest_year || ''}:${row?.display_label || row?.label || row?.maturity_label || ''}`;
}

function findManualDraft(rows, row) {
    const key = rowDraftKey(row);

    return rows.find((item) => rowDraftKey(item) === key)
        || rows.find((item) => item.manual_id && item.manual_id === row.manual_id)
        || rows.find((item) => item.identity_hash && item.identity_hash === row.identity_hash)
        || null;
}

function lineTypeFor(row) {
    return row?.line_type || (row?.market_identity_hash ? 'matif' : 'custom');
}

function transportDefaultGrid() {
    const defaultSection = {
        id: 'transport_1',
        title: 'PRIX DES TRANSPORTS',
        first_column_label: 'TRANSPORT',
        reference_key: '',
        columns: [
            { id: 'col_1', label: 'Colonne 1', reference_key: '', base: 0 },
            { id: 'col_2', label: 'Colonne 2', reference_key: '', base: 0 },
        ],
        rows: [
            { id: 'row_1', label: 'Ligne 1', base: 0 },
            { id: 'row_2', label: 'Ligne 2', base: 0 },
        ],
        cells: {},
    };

    return {
        ...defaultSection,
        sections: [defaultSection],
    };
}

function normalizeTransportSection(section = {}, index = 0) {
    const defaults = transportDefaultGrid();
    const source = section || {};
    const columns = Array.isArray(source.columns) && source.columns.length ? source.columns : defaults.columns;
    const rows = Array.isArray(source.rows) && source.rows.length ? source.rows : defaults.rows;

    return {
        id: source.id || `transport_${index + 1}`,
        title: String(source.title || '').trim() || defaults.title,
        first_column_label: String(source.first_column_label || '').trim() || defaults.first_column_label,
        reference_key: source.reference_key || '',
        columns: columns.map((column, index) => ({
            id: column.id || `col_${index + 1}`,
            label: column.label ?? `Colonne ${index + 1}`,
            reference_key: column.reference_key ?? source.reference_key ?? '',
            base: column.base ?? 0,
        })),
        rows: rows.map((row, index) => ({
            id: row.id || `row_${index + 1}`,
            label: row.label ?? `Ligne ${index + 1}`,
            base: row.base ?? 0,
        })),
        cells: Object.fromEntries(Object.entries(source.cells || {}).map(([key, cell]) => [
            key,
            { text: typeof cell === 'object' && cell !== null ? cell.text ?? '' : '' },
        ])),
    };
}

function normalizeTransportGrid(grid = {}) {
    grid = grid || {};
    const rawSections = Array.isArray(grid.sections) && grid.sections.length ? grid.sections : [grid];
    const sections = rawSections.map((section, index) => normalizeTransportSection(section, index));
    const firstSection = sections[0] || normalizeTransportSection({}, 0);

    return {
        ...firstSection,
        sections,
    };
}

function fuelDefaultGrid() {
    return {
        vat_rate: 20,
        sections: [
            {
                id: 'fuel_grand_froid',
                label: 'FUEL GRAND FROID',
                rows: [
                    { id: 'fgf_1', tranche: '0 à 999 L', ht: '', gap: 0, text: '' },
                    { id: 'fgf_2', tranche: '1 000 à 1 999 L', ht: '', gap: 0, text: '' },
                    { id: 'fgf_3', tranche: '2 000 L et +', ht: '', gap: 0, text: '' },
                ],
            },
            {
                id: 'gnr_agri',
                label: 'GNR AGRI Enregistré',
                rows: [
                    { id: 'gnra_1', tranche: '0 à 999 L', ht: '', gap: 0, text: '' },
                    { id: 'gnra_2', tranche: '1 000 à 1 999 L', ht: '', gap: 0, text: '' },
                    { id: 'gnra_3', tranche: '2 000 L et +', ht: '', gap: 0, text: '' },
                ],
            },
            {
                id: 'gnr_taxe',
                label: 'GNR Taxé',
                rows: [
                    { id: 'gnrt_1', tranche: '0 à 999 L', ht: '', gap: 0, text: '' },
                    { id: 'gnrt_2', tranche: '1 000 à 1 999 L', ht: '', gap: 0, text: '' },
                    { id: 'gnrt_3', tranche: '2 000 L et +', ht: '', gap: 0, text: '' },
                ],
            },
        ],
        gnr_tax: { ht: '', ttc: '' },
        gazole: { id: 'gazole', label: 'GAZOLE', tranche: 'GAZOLE', ttc: '', gap: 0, text: '' },
    };
}

function normalizeFuelGrid(grid = {}) {
    grid = grid || {};
    const defaults = fuelDefaultGrid();
    const sections = Array.isArray(grid.sections) && grid.sections.length ? grid.sections : defaults.sections;

    return {
        vat_rate: grid.vat_rate ?? defaults.vat_rate,
        sections: sections.map((section, sectionIndex) => ({
            id: section.id || `section_${sectionIndex + 1}`,
            label: section.label ?? `Section ${sectionIndex + 1}`,
            rows: (Array.isArray(section.rows) && section.rows.length ? section.rows : defaults.sections[sectionIndex]?.rows || defaults.sections[0].rows).map((row, rowIndex) => ({
                id: row.id || `row_${rowIndex + 1}`,
                tranche: row.tranche ?? `Tranche ${rowIndex + 1}`,
                ht: row.ht ?? '',
                gap: row.gap ?? 0,
                text: row.text ?? '',
            })),
        })),
        gnr_tax: {
            ht: grid.gnr_tax?.ht ?? '',
            ttc: grid.gnr_tax?.ttc ?? '',
        },
        gazole: {
            id: grid.gazole?.id || 'gazole',
            label: grid.gazole?.label ?? 'GAZOLE',
            tranche: grid.gazole?.tranche ?? 'GAZOLE',
            ttc: grid.gazole?.ttc ?? grid.gazole?.ht ?? '',
            gap: grid.gazole?.gap ?? 0,
            text: grid.gazole?.text ?? '',
        },
    };
}

function MarketRow({ row, canManage, form, setManualPrice, deleteManualRow, options = [], finalPriceOptions = [] }) {
    const draft = findManualDraft(form.data.manual_prices || [], row) || row;
    const lineType = lineTypeFor(draft);
    const isMatifLine = lineType === 'matif';
    const selectedOption = isMatifLine ? options.find((option) => option.identity_hash === draft.market_identity_hash) : null;
    const matifValue = isMatifLine ? (selectedOption?.matif ?? draft.matif ?? row.matif ?? '') : (draft.manual_matif ?? row.manual_matif ?? '');
    const rawMarginValue = draft.margin ?? row.margin ?? '';
    const marginValue = rawMarginValue === '' || rawMarginValue === null || rawMarginValue === undefined
        ? ''
        : String(Math.abs(parseDecimal(rawMarginValue) ?? 0));
    const matifNumber = parseDecimal(matifValue);
    const marginNumber = Math.abs(parseDecimal(marginValue) ?? 0);
    const finalPrice = matifNumber !== null ? matifNumber - marginNumber : null;
    const finalPriceReferenceOptions = finalPriceOptions.filter((option) => option.product_code !== (draft.product_code || row.product_code));

    return (
        <tr className="border-t border-[var(--app-border)]">
            <td className={COTATION_BODY_CELL_CLASS}>
                {canManage ? (
                    <div className="grid gap-1.5">
                        <input
                            type="text"
                            value={draft.display_label ?? draft.label ?? draft.maturity_label ?? ''}
                            onChange={(event) => setManualPrice(row, {
                                display_label: event.target.value,
                            })}
                            placeholder="Nom personnalisé"
                            className="w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-xs font-bold sm:text-sm"
                        />
                        <select
                            value={isMatifLine ? draft.market_identity_hash || '' : 'custom'}
                            onChange={(event) => {
                                const option = options.find((item) => item.identity_hash === event.target.value);
                                setManualPrice(row, option ? {
                                    line_type: 'matif',
                                    market_identity_hash: option.identity_hash,
                                    contract_code: option.contract_code || '',
                                    maturity_label: option.maturity_label || option.label || '',
                                    maturity_month: option.maturity_month ?? '',
                                    maturity_year: option.maturity_year ?? row.harvest_year ?? '',
                                    harvest_year: option.harvest_year ?? row.harvest_year ?? '',
                                    manual_matif: '',
                                    matif: option.matif,
                                    has_euronext: true,
                                } : {
                                    line_type: 'custom',
                                    market_identity_hash: '',
                                    contract_code: '',
                                    maturity_label: draft.display_label || draft.maturity_label || '',
                                    maturity_month: '',
                                    maturity_year: draft.maturity_year || row.harvest_year || '',
                                    manual_matif: draft.manual_matif || '',
                                    matif: '',
                                    has_euronext: false,
                                });
                            }}
                            className="w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-xs font-bold sm:text-sm"
                        >
                            <option value="custom">Option personnalisée</option>
                            {options.map((option) => (
                                <option key={option.identity_hash} value={option.identity_hash}>
                                    {option.label} - {formatPrice(option.matif)}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : (
                    <>
                        <div className={`${COTATION_ROW_LABEL_CLASS} break-words [overflow-wrap:anywhere]`}>{row.display_label || row.label || row.maturity_label || 'Échéance'}</div>
                    </>
                )}
                {!isMatifLine && canManage ? (
                    <div className="mt-1 text-[9px] font-black uppercase tracking-[0.04em] text-[var(--app-muted)] sm:text-[10px]">Manuel</div>
                ) : null}
            </td>
            <td className={`${COTATION_BODY_CELL_CLASS} text-center`}>
                {canManage && !isMatifLine ? (
                    <div className="grid gap-1">
                        <input
                            type="number"
                            step="0.0001"
                            min="0"
                            value={matifValue}
                            onChange={(event) => setManualPrice(row, 'manual_matif', event.target.value)}
                            className={`w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
                        />
                        <select
                            value=""
                            onChange={(event) => {
                                const option = finalPriceReferenceOptions.find((item) => item.key === event.target.value);
                                if (!option) return;
                                setManualPrice(row, 'manual_matif', option.final_price);
                            }}
                            className="w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-1 py-1 text-[10px] font-semibold"
                        >
                            <option value="">Prix final existant</option>
                            {finalPriceReferenceOptions.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.label} - {formatPrice(option.final_price)}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : (
                    <span className={COTATION_VALUE_CLASS}>{formatPrice(matifValue)}</span>
                )}
            </td>
            <td className={`${COTATION_BODY_CELL_CLASS} text-center`}>
                {canManage ? (
                    <div className="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-0.5">
                        <span className={`${COTATION_VALUE_CLASS} text-[var(--app-muted)]`}>-</span>
                        <input
                            type="number"
                            step="1"
                            min="0"
                            inputMode="numeric"
                            value={marginValue}
                            onChange={(event) => setManualPrice(row, 'margin', normalizeMarginInput(event.target.value))}
                            className={`w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
                        />
                    </div>
                ) : (
                    <span className={COTATION_VALUE_CLASS}>{formatMargin(marginValue)}</span>
                )}
            </td>
            <td className={`${COTATION_BODY_CELL_CLASS} text-right`}>
                <span className={COTATION_FINAL_VALUE_CLASS}>{formatPrice(finalPrice)}</span>
            </td>
            {canManage ? (
                <td className={`${COTATION_BODY_CELL_CLASS} text-right`}>
                    <button
                        type="button"
                        onClick={() => deleteManualRow(row)}
                        className="inline-flex h-6 w-6 items-center justify-center rounded-lg border border-transparent text-[var(--app-muted)] hover:border-red-200 hover:bg-red-50 hover:text-red-700 sm:h-8 sm:w-8"
                        aria-label="Supprimer l'échéance"
                    >
                        <Trash2 className="h-3.5 w-3.5 sm:h-4 sm:w-4" strokeWidth={2.2} />
                    </button>
                </td>
            ) : null}
        </tr>
    );
}

function HeaderLabel({ value, fallback, align = 'left', canManage, onChange }) {
    if (!canManage) return value || fallback;

    return (
        <input
            type="text"
            value={value || fallback}
            onChange={(event) => onChange(event.target.value)}
            className={`w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 py-1 text-xs font-black uppercase tracking-[0.04em] text-[var(--app-text)] ${align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'}`}
        />
    );
}

function HarvestBlock({ group, harvest, canManage, form, setManualPrice, addManualRow, deleteManualRow, options = [], finalPriceOptions = [], tableLabels = DEFAULT_CEREAL_TABLE_LABELS, setTableLabel }) {
    const deletedIds = form.data.deleted_manual_price_ids || [];
    const persistedRows = (harvest?.rows || []).filter((row) => !deletedIds.includes(Number(row.manual_id)));
    const localRows = (form.data.manual_prices || []).filter((row) => (
        row.is_new
        && row.product_code === group?.code
        && Number(row.harvest_year) === Number(harvest?.year)
    ));
    const rows = [...persistedRows, ...localRows];
    const displayedRows = rows;
    const title = harvest?.year ? `Récolte ${harvest.year}` : 'Récolte';

    return (
        <div className="w-full max-w-full min-w-0 overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]">
            <div className={COTATION_HARVEST_TITLE_CLASS}>
                {title}
            </div>
            {displayedRows.length ? (
                <div className="w-full max-w-full overflow-hidden">
                    <table className={COTATION_TABLE_CLASS}>
                        <colgroup>
                            <col className={canManage ? 'w-[31%]' : 'w-[34%]'} />
                            <col className={canManage ? 'w-[21%]' : 'w-[23%]'} />
                            <col className={canManage ? 'w-[17%]' : 'w-[18%]'} />
                            <col className={canManage ? 'w-[23%]' : 'w-[25%]'} />
                            {canManage ? <col className="w-[8%]" /> : null}
                        </colgroup>
                        <thead>
                            <tr className={COTATION_HEADER_ROW_CLASS}>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-left`}>
                                    <HeaderLabel value={tableLabels.maturity} fallback={DEFAULT_CEREAL_TABLE_LABELS.maturity} canManage={canManage} onChange={(value) => setTableLabel?.('maturity', value)} />
                                </th>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-center`}>
                                    <HeaderLabel value={tableLabels.matif} fallback={DEFAULT_CEREAL_TABLE_LABELS.matif} align="center" canManage={canManage} onChange={(value) => setTableLabel?.('matif', value)} />
                                </th>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-center`}>
                                    <HeaderLabel value={tableLabels.base} fallback={DEFAULT_CEREAL_TABLE_LABELS.base} align="center" canManage={canManage} onChange={(value) => setTableLabel?.('base', value)} />
                                </th>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-right`}>
                                    <HeaderLabel value={tableLabels.final_price} fallback={DEFAULT_CEREAL_TABLE_LABELS.final_price} align="right" canManage={canManage} onChange={(value) => setTableLabel?.('final_price', value)} />
                                </th>
                                {canManage ? <th className={`${COTATION_HEADER_CELL_CLASS} text-right`} /> : null}
                            </tr>
                        </thead>
                        <tbody>
                            {displayedRows.map((row, index) => (
                                <MarketRow
                                    key={`${rowDraftKey(row)}-${index}`}
                                    row={row}
                                    canManage={canManage}
                                    form={form}
                                    setManualPrice={setManualPrice}
                                    deleteManualRow={deleteManualRow}
                                    options={options}
                                    finalPriceOptions={finalPriceOptions}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="px-3 py-4 text-sm text-[var(--app-muted)]">Aucune échéance disponible.</div>
            )}
            {canManage ? (
                <div className="border-t border-[var(--app-border)] px-3 py-2">
                    <button
                        type="button"
                        onClick={() => addManualRow(group, harvest)}
                        className="w-full rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--brand-brown)]"
                    >
                        Ajouter une échéance
                    </button>
                </div>
            ) : null}
        </div>
    );
}

function CerealCard({ group, canManage, form, setManualPrice, addManualRow, deleteManualRow, optionGroup, customCereal = null, setCustomCereal, setCerealLabel, setCerealTableLabel, cerealLabels = {}, tableLabels = DEFAULT_CEREAL_TABLE_LABELS, finalPriceOptions = [], dragHandle = null, defaultOpen = false }) {
    const leftHarvest = group?.harvests?.left || {};
    const rightHarvest = group?.harvests?.right || {};
    const leftOptions = optionGroup?.harvests?.left?.rows || [];
    const rightOptions = optionGroup?.harvests?.right?.rows || [];
    const baseCereal = BASE_CEREALS.find((cereal) => cereal.code === group?.code);
    const canEditCustomCereal = Boolean(canManage && customCereal);
    const canEditCerealTitle = Boolean(canManage && (customCereal || baseCereal));
    const titleEditor = canEditCerealTitle ? (
        <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
            <input
                type="text"
                value={customCereal ? (customCereal.name ?? '') : (cerealLabels[group.code] ?? group.name ?? '')}
                onChange={(event) => {
                    if (customCereal) setCustomCereal(customCereal, 'name', event.target.value);
                    else setCerealLabel(group.code, event.target.value);
                }}
                placeholder="Nom personnalisé"
                className="w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-xl font-black uppercase tracking-[0.04em] text-[var(--app-text)] sm:max-w-[28rem]"
            />
            {baseCereal ? (
                <span className="shrink-0 text-xs font-semibold normal-case tracking-normal text-[var(--app-muted)]">
                    Céréale MATIF d'origine : {baseCereal.name}
                </span>
            ) : null}
        </div>
    ) : null;
    const actions = canEditCustomCereal ? (
        <select
            value={customCereal.base_product_code ?? 'EBM'}
            onChange={(event) => setCustomCereal(customCereal, 'base_product_code', event.target.value)}
            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm font-bold sm:w-44"
            aria-label="Céréale de base"
        >
            {BASE_CEREALS.map((base) => (
                <option key={base.code} value={base.code}>Basée sur {base.name}</option>
            ))}
        </select>
    ) : null;
    const headerActions = dragHandle || actions ? (
        <div className="flex flex-wrap items-center gap-2">
            {dragHandle}
            {actions}
        </div>
    ) : null;

    return (
        <CollapsibleSection title={group.name || 'Céréale'} titleEditor={titleEditor} actions={headerActions} titleClassName="text-xl" bodyClassName="mt-3" defaultOpen={defaultOpen}>
            <div className="grid w-full max-w-full min-w-0 grid-cols-1 gap-3 xl:grid-cols-2">
                <HarvestBlock group={group} harvest={leftHarvest} canManage={canManage} form={form} setManualPrice={setManualPrice} addManualRow={addManualRow} deleteManualRow={deleteManualRow} options={leftOptions} finalPriceOptions={finalPriceOptions} tableLabels={tableLabels} setTableLabel={(key, value) => setCerealTableLabel(group.code, key, value)} />
                <HarvestBlock group={group} harvest={rightHarvest} canManage={canManage} form={form} setManualPrice={setManualPrice} addManualRow={addManualRow} deleteManualRow={deleteManualRow} options={rightOptions} finalPriceOptions={finalPriceOptions} tableLabels={tableLabels} setTableLabel={(key, value) => setCerealTableLabel(group.code, key, value)} />
            </div>
        </CollapsibleSection>
    );
}

function ManualSection({ title, icon: Icon, rows = [], canManage, form, setFormSetting }) {
    return (
        <CollapsibleSection title={title} icon={Icon}>
            <div className="space-y-2">
                {rows.map((row) => {
                    const draft = form.data.settings.find((item) => Number(item.id) === Number(row.id)) || row;

                    return (
                        <div key={row.id} className="rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <div className="font-bold">{row.label}</div>
                                    {row.note && !canManage ? <div className="mt-1 text-xs text-[var(--app-muted)]">{row.note}</div> : null}
                                </div>

                                {canManage ? (
                                    <div className="grid grid-cols-[minmax(0,8rem)_3rem] items-center gap-2">
                                        <input
                                            type="number"
                                            step="0.0001"
                                            min="0"
                                            value={draft.value ?? ''}
                                            onChange={(event) => setFormSetting(row.id, 'value', event.target.value)}
                                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-right text-sm font-bold"
                                        />
                                        <span className="text-sm font-bold text-[var(--app-muted)]">{row.unit || ''}</span>
                                    </div>
                                ) : (
                                    <div className="text-right">
                                        <div className="text-xl font-black">{formatPrice(row.value)}</div>
                                        <div className="text-xs font-bold text-[var(--app-muted)]">{row.unit}</div>
                                    </div>
                                )}
                            </div>

                            {canManage ? (
                                <input
                                    type="text"
                                    value={draft.note ?? ''}
                                    onChange={(event) => setFormSetting(row.id, 'note', event.target.value)}
                                    placeholder="Note interne optionnelle"
                                    className="mt-2 w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm"
                                />
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </CollapsibleSection>
    );
}

function TransportGridTable({ grid, canManage, finalPriceOptions, setTransportSection, canRemove, onDuplicate, onRemove }) {
    const columns = grid.columns || [];
    const rows = grid.rows || [];
    const cells = grid.cells || {};
    const sectionTitle = String(grid.title || '').trim() || 'PRIX DES TRANSPORTS';
    const firstColumnLabel = String(grid.first_column_label || '').trim() || 'TRANSPORT';
    const optionsByKey = Object.fromEntries(finalPriceOptions.map((option) => [option.key, option]));
    const cellKey = (rowId, columnId) => `${rowId}__${columnId}`;
    const renderCompactTransportLabel = (value, fallback) => {
        const label = String(value || fallback || '').trim();
        if (!label) return fallback;

        const greaterThanMatch = label.match(/^(.*?)\s+(>.*)$/);
        const parts = greaterThanMatch
            ? [...greaterThanMatch[1].split(/\s+/).filter(Boolean), greaterThanMatch[2]]
            : label.split(/\s+/).filter(Boolean);

        return (
            <>
                <span className="hidden sm:inline">{label}</span>
                <span className="inline sm:hidden">
                    {parts.map((part, index) => (
                        <Fragment key={`${part}-${index}`}>
                            {index > 0 ? <br /> : null}
                            {part}
                        </Fragment>
                    ))}
                </span>
            </>
        );
    };
    const setGridField = (field, value) => setTransportSection({
        ...grid,
        [field]: value,
    });

    const setColumn = (columnId, field, value) => setTransportSection({
        ...grid,
        columns: columns.map((column) => (column.id === columnId ? { ...column, [field]: value } : column)),
    });
    const setRow = (rowId, field, value) => setTransportSection({
        ...grid,
        rows: rows.map((row) => (row.id === rowId ? { ...row, [field]: value } : row)),
    });
    const setCellText = (rowId, columnId, text) => {
        const key = cellKey(rowId, columnId);
        setTransportSection({
            ...grid,
            cells: {
                ...cells,
                [key]: {
                    ...(cells[key] || {}),
                    text,
                },
            },
        });
    };
    const addColumn = () => setTransportSection({
        ...grid,
        columns: [...columns, { id: `col_${Date.now().toString(36)}`, label: `Colonne ${columns.length + 1}`, reference_key: '', base: 0 }],
    });
    const addRow = () => setTransportSection({
        ...grid,
        rows: [...rows, { id: `row_${Date.now().toString(36)}`, label: `Ligne ${rows.length + 1}`, base: 0 }],
    });
    const removeColumn = (columnId) => {
        if (columns.length <= 1) return;
        setTransportSection({ ...grid, columns: columns.filter((column) => column.id !== columnId) });
    };
    const removeRow = (rowId) => {
        if (rows.length <= 1) return;
        setTransportSection({ ...grid, rows: rows.filter((row) => row.id !== rowId) });
    };
    const titleEditor = canManage ? (
        <input
            type="text"
            value={grid.title ?? ''}
            onChange={(event) => setGridField('title', event.target.value)}
            placeholder="PRIX DES TRANSPORTS"
            className="w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-lg font-black uppercase tracking-[0.04em] text-[var(--app-text)] sm:max-w-[28rem]"
        />
    ) : null;

    return (
        <CollapsibleSection title={sectionTitle} titleEditor={titleEditor} icon={Truck}>
            {canManage ? (
                <div className="mb-3 flex flex-wrap gap-2">
                    <button type="button" onClick={onDuplicate} className="rounded-lg border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-[11px] font-black uppercase tracking-[0.08em] text-[var(--color-black)]">
                        Dupliquer la section
                    </button>
                    {canRemove ? (
                        <button type="button" onClick={onRemove} className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-[11px] font-black uppercase tracking-[0.08em] text-red-700">
                            Supprimer la section
                        </button>
                    ) : null}
                    <button type="button" onClick={addColumn} className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-[11px] font-black uppercase tracking-[0.08em]">
                        Ajouter une colonne
                    </button>
                    <button type="button" onClick={addRow} className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-[11px] font-black uppercase tracking-[0.08em]">
                        Ajouter une ligne
                    </button>
                </div>
            ) : null}

            <div
                className="w-full max-w-full overflow-x-auto overscroll-x-contain"
                style={{
                    '--transport-first-col': canManage ? '8rem' : '7rem',
                }}
            >
                <table className={`${COTATION_TABLE_CLASS.replace('table-fixed', 'table-auto sm:table-fixed')} min-w-max border-separate border-spacing-0 sm:min-w-full`}>
                    <thead>
                        <tr className={COTATION_HEADER_ROW_CLASS}>
                            <th className={`sticky left-0 z-30 w-[var(--transport-first-col)] min-w-[var(--transport-first-col)] max-w-[var(--transport-first-col)] rounded-tl-xl border border-[var(--app-border)] bg-[#FACC51] shadow-[2px_0_0_var(--app-border)] sm:w-auto sm:min-w-0 sm:max-w-none ${COTATION_HEADER_CELL_CLASS} whitespace-nowrap text-left text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`}>
                                {canManage ? (
                                    <input
                                        type="text"
                                        value={grid.first_column_label ?? ''}
                                        onChange={(event) => setGridField('first_column_label', event.target.value)}
                                        placeholder="TRANSPORT"
                                        className={`w-full min-w-0 rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-left uppercase tracking-[0.04em] ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                    />
                                ) : (
                                    firstColumnLabel
                                )}
                            </th>
                            {columns.map((column, index) => (
                                <th
                                    key={column.id}
                                    className={`${canManage ? 'min-w-[13rem]' : 'min-w-[4.75rem]'} border-y border-r border-[var(--app-border)] bg-[#FACC51] sm:min-w-[11rem] ${COTATION_HEADER_CELL_CLASS} whitespace-nowrap text-center text-[var(--color-black)] ${index === columns.length - 1 ? 'rounded-tr-xl' : ''}`}
                                >
                                    {canManage ? (
                                        <div className="grid gap-1">
                                            <input
                                                type="text"
                                                value={column.label ?? ''}
                                                onChange={(event) => setColumn(column.id, 'label', event.target.value)}
                                                placeholder={`Colonne ${index + 1}`}
                                                className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-center uppercase tracking-[0.04em] ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                            />
                                            <select
                                                value={column.reference_key || ''}
                                                onChange={(event) => setColumn(column.id, 'reference_key', event.target.value)}
                                                className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-center text-[11px] font-medium normal-case tracking-normal"
                                            >
                                                <option value="">Prix final</option>
                                                {finalPriceOptions.map((option) => (
                                                    <option key={option.key} value={option.key}>
                                                        {option.label} - {formatPrice(option.final_price)}
                                                    </option>
                                                ))}
                                            </select>
                                            <div className="grid grid-cols-[minmax(0,1fr)_2rem] gap-1">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    value={column.base ?? ''}
                                                    onChange={(event) => setColumn(column.id, 'base', normalizeSignedNumberInput(event.target.value))}
                                                    aria-label="Base colonne"
                                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => removeColumn(column.id)}
                                                    disabled={columns.length <= 1}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] disabled:opacity-30"
                                                    aria-label="Supprimer la colonne"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className={`${TRANSPORT_HEADER_LABEL_CLASS} whitespace-nowrap`}>{column.label || `Colonne ${index + 1}`}</div>
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIndex) => (
                            <tr key={row.id}>
                                <th className={`sticky left-0 z-20 w-[var(--transport-first-col)] min-w-[var(--transport-first-col)] max-w-[var(--transport-first-col)] border-x border-b border-[var(--app-border)] bg-[var(--app-surface-soft)] shadow-[2px_0_0_var(--app-border)] sm:w-auto sm:min-w-[10rem] sm:max-w-none ${COTATION_BODY_CELL_CLASS} whitespace-normal break-words text-left font-black ${rowIndex === rows.length - 1 ? 'rounded-bl-xl' : ''}`}>
                                    {canManage ? (
                                        <div className="grid gap-1">
                                            <input
                                                type="text"
                                                value={row.label ?? ''}
                                                onChange={(event) => setRow(row.id, 'label', event.target.value)}
                                                placeholder={`Ligne ${rowIndex + 1}`}
                                                className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                            />
                                            <div className="grid grid-cols-[minmax(0,1fr)_2rem] gap-1">
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    value={row.base ?? ''}
                                                    onChange={(event) => setRow(row.id, 'base', normalizeSignedNumberInput(event.target.value))}
                                                    aria-label="Base ligne"
                                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => removeRow(row.id)}
                                                    disabled={rows.length <= 1}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] disabled:opacity-30"
                                                    aria-label="Supprimer la ligne"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className={`${TRANSPORT_HEADER_LABEL_CLASS} whitespace-normal break-words`}>
                                            {renderCompactTransportLabel(row.label, `Ligne ${rowIndex + 1}`)}
                                        </div>
                                    )}
                                </th>
                                {columns.map((column, columnIndex) => {
                                    const referencePrice = optionsByKey[column.reference_key]?.final_price ?? null;
                                    const price = referencePrice !== null
                                        ? Number(referencePrice) + (parseDecimal(column.base) ?? 0) + (parseDecimal(row.base) ?? 0)
                                        : null;
                                    const customText = String(cells[cellKey(row.id, column.id)]?.text ?? '').trim();

                                    return (
                                        <td
                                            key={`${row.id}-${column.id}`}
                                            className={`min-w-[4.75rem] border-b border-r border-[var(--app-border)] sm:min-w-0 ${COTATION_BODY_CELL_CLASS} whitespace-nowrap text-center ${rowIndex === rows.length - 1 && columnIndex === columns.length - 1 ? 'rounded-br-xl' : ''}`}
                                        >
                                            {canManage ? (
                                                <div className="grid gap-1">
                                                    <span className={COTATION_VALUE_CLASS}>{customText ? customText : formatRoundedPrice(price)}</span>
                                                    <input
                                                        type="text"
                                                        value={cells[cellKey(row.id, column.id)]?.text ?? ''}
                                                        onChange={(event) => setCellText(row.id, column.id, event.target.value)}
                                                        placeholder="Texte personnalisé"
                                                        className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center text-[11px] font-medium"
                                                    />
                                                </div>
                                            ) : (
                                                <span className={COTATION_VALUE_CLASS}>{customText ? customText : formatRoundedPrice(price)}</span>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </CollapsibleSection>
    );
}

function TransportGridSection({ grid, canManage, finalPriceOptions, setTransportGrid }) {
    const normalized = normalizeTransportGrid(grid);
    const sections = normalized.sections || [];

    const updateSections = (nextSections) => {
        const firstSection = nextSections[0] || normalizeTransportSection({}, 0);
        setTransportGrid({
            ...firstSection,
            sections: nextSections,
        });
    };
    const updateSection = (sectionId, nextSection) => updateSections(
        sections.map((section) => (section.id === sectionId ? normalizeTransportSection(nextSection) : section)),
    );
    const duplicateSection = (section) => {
        const now = Date.now().toString(36);
        const cloneId = `transport_${now}`;
        const cloned = normalizeTransportSection({
            ...section,
            id: cloneId,
            title: `${section.title || 'PRIX DES TRANSPORTS'} (copie)`,
            columns: (section.columns || []).map((column, index) => ({
                ...column,
                id: `col_${now}_${index + 1}`,
            })),
            rows: (section.rows || []).map((row, index) => ({
                ...row,
                id: `row_${now}_${index + 1}`,
            })),
            cells: Object.fromEntries(Object.entries(section.cells || {}).map(([key, cell]) => {
                const [rowId, columnId] = key.split('__');
                const rowIndex = (section.rows || []).findIndex((row) => row.id === rowId);
                const columnIndex = (section.columns || []).findIndex((column) => column.id === columnId);

                if (rowIndex < 0 || columnIndex < 0) {
                    return [key, cell];
                }

                return [`row_${now}_${rowIndex + 1}__col_${now}_${columnIndex + 1}`, { ...cell }];
            })),
        }, sections.length);

        const currentIndex = sections.findIndex((item) => item.id === section.id);
        const insertIndex = currentIndex >= 0 ? currentIndex + 1 : sections.length;
        updateSections([
            ...sections.slice(0, insertIndex),
            cloned,
            ...sections.slice(insertIndex),
        ]);
    };
    const removeSection = (sectionId) => {
        if (sections.length <= 1) return;
        updateSections(sections.filter((section) => section.id !== sectionId));
    };

    return (
        <div className="grid w-full max-w-full min-w-0 gap-4">
            {sections.map((section) => (
                <TransportGridTable
                    key={section.id}
                    grid={section}
                    canManage={canManage}
                    finalPriceOptions={finalPriceOptions}
                    setTransportSection={(nextSection) => updateSection(section.id, nextSection)}
                    canRemove={sections.length > 1}
                    onDuplicate={() => duplicateSection(section)}
                    onRemove={() => removeSection(section.id)}
                />
            ))}
        </div>
    );
}

function FuelGridSection({
    grid,
    canManage,
    canEdit = false,
    setFuelGrid,
    onStartEditing,
    onCancelEditing,
    onSave,
    processing = false,
    canViewHistory = false,
    isViewingHistory = false,
    historyVersion = null,
    historyHasOlder = false,
    historyHasNewer = false,
    historyLoading = false,
    historyError = '',
    onOpenHistory,
    onCloseHistory,
    onShowOlderHistory,
    onShowNewerHistory,
    canExportPdf = false,
    exportPdfUrl = '',
}) {
    const sections = grid.sections || [];
    const vatRate = parseDecimal(grid.vat_rate) ?? 20;

    const setSection = (sectionId, field, value) => setFuelGrid({
        ...grid,
        sections: sections.map((section) => (section.id === sectionId ? { ...section, [field]: value } : section)),
    });
    const setSectionRow = (sectionId, rowId, field, value) => setFuelGrid({
        ...grid,
        sections: sections.map((section) => (section.id === sectionId ? {
            ...section,
            rows: (section.rows || []).map((row) => (row.id === rowId ? { ...row, [field]: value } : row)),
        } : section)),
    });
    const addSectionRow = (sectionId) => setFuelGrid({
        ...grid,
        sections: sections.map((section) => (section.id === sectionId ? {
            ...section,
            rows: [
                ...(section.rows || []),
                { id: `row_${Date.now().toString(36)}`, tranche: `Tranche ${(section.rows || []).length + 1}`, ht: '', gap: 0, text: '' },
            ],
        } : section)),
    });
    const removeSectionRow = (sectionId, rowId) => setFuelGrid({
        ...grid,
        sections: sections.map((section) => {
            if (section.id !== sectionId || (section.rows || []).length <= 1) return section;

            return { ...section, rows: section.rows.filter((row) => row.id !== rowId) };
        }),
    });
    const setGazole = (field, value) => setFuelGrid({
        ...grid,
        gazole: {
            ...(grid.gazole || {}),
            [field]: value,
        },
    });
    const displayHt = (ht) => {
        const number = parseDecimal(ht);
        return number === null ? '—' : formatPrice(number);
    };
    const displayTtc = (ht) => {
        const number = parseDecimal(ht);
        return number === null ? '—' : formatPrice(number * (1 + (vatRate / 100)));
    };
    const ttcFromHt = (ht) => {
        const number = parseDecimal(ht);
        return number === null ? null : number * (1 + (vatRate / 100));
    };
    const htFromTtc = (ttc) => {
        const number = parseDecimal(ttc);
        const divisor = 1 + (vatRate / 100);
        return number === null || divisor === 0 ? null : number / divisor;
    };
    const setGnrTaxHt = (value) => {
        const ht = parseDecimal(value);
        setFuelGrid({
            ...grid,
            gnr_tax: {
                ht: value,
                ttc: ht === null ? '' : String(ht * (1 + (vatRate / 100))),
            },
        });
    };
    const setGnrTaxTtc = (value) => {
        const ht = htFromTtc(value);
        setFuelGrid({
            ...grid,
            gnr_tax: {
                ht: ht === null ? '' : String(ht),
                ttc: value,
            },
        });
    };
    const setVatRate = (value) => {
        const nextVatRate = parseDecimal(value) ?? 0;
        const taxHt = parseDecimal(grid.gnr_tax?.ht);
        setFuelGrid({
            ...grid,
            vat_rate: value,
            gnr_tax: {
                ...(grid.gnr_tax || {}),
                ttc: taxHt === null ? (grid.gnr_tax?.ttc ?? '') : String(taxHt * (1 + (nextVatRate / 100))),
            },
        });
    };
    const computedRowsFor = (rows = [], initialPrevious = null, forcedFirstHt = null) => {
        let previous = initialPrevious;

        return rows.map((row, index) => {
            const manualHt = parseDecimal(row.ht);
            const gap = parseDecimal(row.gap) ?? 0;
            const ht = index === 0 && forcedFirstHt !== null
                ? forcedFirstHt
                : (manualHt !== null ? manualHt : (previous !== null ? previous - gap : null));
            previous = ht;

            return { ...row, computed_ht: ht, is_forced_ht: index === 0 && forcedFirstHt !== null };
        });
    };
    let lastComputedHt = null;
    let gnrAgriFirstHt = null;
    const fuelSectionModels = sections.map((section) => {
        const taxHt = parseDecimal(grid.gnr_tax?.ht) ?? 0;
        const forcedFirstHt = section.id === 'gnr_taxe' && gnrAgriFirstHt !== null
            ? gnrAgriFirstHt + taxHt
            : null;
        const computedRows = computedRowsFor(section.rows || [], lastComputedHt, forcedFirstHt);
        if (section.id === 'gnr_agri' && computedRows.length) {
            gnrAgriFirstHt = computedRows[0].computed_ht;
        }
        lastComputedHt = computedRows.length ? computedRows[computedRows.length - 1].computed_ht : lastComputedHt;

        return { ...section, computedRows };
    });
    const fuelSectionsById = fuelSectionModels.reduce((carry, section) => ({
        ...carry,
        [section.id]: section,
    }), {});
    const gazole = grid.gazole || {};
    const gazoleGap = parseDecimal(gazole.gap) ?? 0;
    const gazoleTtc = parseDecimal(gazole.ttc);
    const gazoleHtFromManualTtc = htFromTtc(gazole.ttc);
    const gazoleHt = gazoleHtFromManualTtc !== null ? gazoleHtFromManualTtc : (lastComputedHt !== null ? lastComputedHt - gazoleGap : null);
    const gazoleDisplayedTtc = gazoleTtc !== null ? gazoleTtc : ttcFromHt(gazoleHt);
    const gazoleCustomText = String(gazole.text ?? '').trim();
    const fuelTableColumnCount = canManage ? 4 : 3;
    const fuelHeaderSpacerClass = `w-[8.5rem] ${COTATION_HEADER_CELL_CLASS}`;
    const fuelValueHeaderClass = `w-[5.25rem] border-y border-r border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`;
    const fuelBodyCellClass = `border-b border-r border-[var(--app-border)] ${COTATION_BODY_CELL_CLASS} text-center`;
    const renderFuelSectionTable = (section, extraRows = null) => (
        <div className="overflow-x-auto">
            <table className={`${COTATION_TABLE_CLASS} w-full table-fixed border-separate border-spacing-0`}>
                <thead>
                    <tr className={COTATION_HEADER_ROW_CLASS}>
                        <th className={fuelHeaderSpacerClass} aria-hidden="true"></th>
                        {canManage ? (
                            <th className={`w-[4.75rem] rounded-tl-xl border-y border-l border-r border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`}>
                                Écart
                            </th>
                        ) : null}
                        <th className={`${canManage ? '' : 'rounded-tl-xl border-l '} ${fuelValueHeaderClass}`}>
                            HT
                        </th>
                        <th className={`rounded-tr-xl ${fuelValueHeaderClass}`}>
                            TTC
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th colSpan={fuelTableColumnCount} className={`rounded-tl-xl border-x border-t border-b border-[var(--app-border)] bg-[#FACC51] ${COTATION_BODY_CELL_CLASS} text-left text-[var(--color-black)]`}>
                            {canManage ? (
                                <input
                                    type="text"
                                    value={section.label ?? ''}
                                    onChange={(event) => setSection(section.id, 'label', event.target.value)}
                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-left uppercase tracking-[0.04em] ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                />
                            ) : (
                                <div className={TRANSPORT_HEADER_LABEL_CLASS}>{section.label}</div>
                            )}
                        </th>
                    </tr>
                    {extraRows}
                    {(section.computedRows || []).map((row, rowIndex) => {
                        const customText = String(row.text ?? '').trim();
                        const isLastRow = rowIndex === (section.computedRows || []).length - 1 && !canManage;

                        return (
                            <tr key={`${section.id}-${row.id}`}>
                                <th className={`w-[8.5rem] border-x border-b border-[var(--app-border)] bg-[var(--app-surface)] ${COTATION_BODY_CELL_CLASS} text-left ${isLastRow ? 'rounded-bl-xl' : ''}`}>
                                    {canManage ? (
                                        <div className="grid grid-cols-[minmax(0,1fr)_2rem] gap-1">
                                            <input
                                                type="text"
                                                value={row.tranche ?? ''}
                                                onChange={(event) => setSectionRow(section.id, row.id, 'tranche', event.target.value)}
                                                className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => removeSectionRow(section.id, row.id)}
                                                disabled={(section.rows || []).length <= 1}
                                                className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] text-[var(--app-muted)] disabled:opacity-30"
                                                aria-label="Supprimer la tranche"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" strokeWidth={2.2} />
                                            </button>
                                        </div>
                                    ) : (
                                        <div className={TRANSPORT_HEADER_LABEL_CLASS}>{row.tranche}</div>
                                    )}
                                </th>
                                {canManage ? (
                                    <td className={fuelBodyCellClass}>
                                        {row.is_forced_ht ? (
                                            <span className={COTATION_VALUE_CLASS}>—</span>
                                        ) : (
                                            <input
                                                type="number"
                                                step="0.0001"
                                                value={row.gap ?? ''}
                                                onChange={(event) => setSectionRow(section.id, row.id, 'gap', normalizeSignedNumberInput(event.target.value))}
                                                className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                                            />
                                        )}
                                    </td>
                                ) : null}
                                <td className={fuelBodyCellClass}>
                                    {canManage ? (
                                        <div className="grid gap-1">
                                            <span className={COTATION_VALUE_CLASS}>{customText || displayHt(row.computed_ht)}</span>
                                            {row.is_forced_ht ? null : (
                                                <input
                                                    type="number"
                                                    step="0.0001"
                                                    value={row.ht ?? ''}
                                                    onChange={(event) => setSectionRow(section.id, row.id, 'ht', event.target.value)}
                                                    placeholder="HT de base"
                                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                                                />
                                            )}
                                            <input
                                                type="text"
                                                value={row.text ?? ''}
                                                onChange={(event) => setSectionRow(section.id, row.id, 'text', event.target.value)}
                                                placeholder="Texte personnalisé"
                                                className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center text-[11px] font-medium"
                                            />
                                        </div>
                                    ) : (
                                        <span className={COTATION_VALUE_CLASS}>{customText || displayHt(row.computed_ht)}</span>
                                    )}
                                </td>
                                <td className={`${fuelBodyCellClass} ${isLastRow ? 'rounded-br-xl' : ''}`}>
                                    <span className={COTATION_VALUE_CLASS}>{customText || displayTtc(row.computed_ht)}</span>
                                </td>
                            </tr>
                        );
                    })}
                    {canManage ? (
                        <tr>
                            <td colSpan={fuelTableColumnCount} className="rounded-b-xl border-x border-b border-[var(--app-border)] px-3 py-2">
                                <button
                                    type="button"
                                    onClick={() => addSectionRow(section.id)}
                                    className="w-full rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--brand-brown)]"
                                >
                                    Ajouter une tranche
                                </button>
                            </td>
                        </tr>
                    ) : null}
                </tbody>
            </table>
        </div>
    );
    const renderGazoleTable = () => (
        <div className="overflow-x-auto">
            <table className={`${COTATION_TABLE_CLASS} w-full table-fixed border-separate border-spacing-0`}>
                <thead>
                    <tr className={COTATION_HEADER_ROW_CLASS}>
                        <th className={fuelHeaderSpacerClass} aria-hidden="true"></th>
                        {canManage ? (
                            <th className={`w-[4.75rem] rounded-tl-xl border-y border-l border-r border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`}>
                                Écart
                            </th>
                        ) : null}
                        <th className={`${canManage ? '' : 'rounded-tl-xl border-l '} ${fuelValueHeaderClass}`}>
                            HT
                        </th>
                        <th className={`rounded-tr-xl ${fuelValueHeaderClass}`}>
                            TTC
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th colSpan={fuelTableColumnCount} className={`rounded-tl-xl border-x border-t border-b border-[var(--app-border)] bg-[#FACC51] ${COTATION_BODY_CELL_CLASS} text-left text-[var(--color-black)]`}>
                            {canManage ? (
                                <input
                                    type="text"
                                    value={gazole.label ?? ''}
                                    onChange={(event) => setGazole('label', event.target.value)}
                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-left uppercase tracking-[0.04em] ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                />
                            ) : (
                                <div className={TRANSPORT_HEADER_LABEL_CLASS}>{gazole.label || 'GAZOLE'}</div>
                            )}
                        </th>
                    </tr>
                    <tr>
                        <th className={`w-[8.5rem] rounded-bl-xl border-x border-b border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_BODY_CELL_CLASS} text-left font-black`}>
                            {canManage ? (
                                <input
                                    type="text"
                                    value={gazole.tranche ?? ''}
                                    onChange={(event) => setGazole('tranche', event.target.value)}
                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 ${TRANSPORT_HEADER_INPUT_CLASS}`}
                                />
                            ) : (
                                <div className={TRANSPORT_HEADER_LABEL_CLASS}>{gazole.tranche || 'GAZOLE'}</div>
                            )}
                        </th>
                        {canManage ? (
                            <td className={fuelBodyCellClass}>
                                <input
                                    type="number"
                                    step="0.0001"
                                    value={gazole.gap ?? ''}
                                    onChange={(event) => setGazole('gap', normalizeSignedNumberInput(event.target.value))}
                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                                />
                            </td>
                        ) : null}
                        <td className={fuelBodyCellClass}>
                            <span className={COTATION_VALUE_CLASS}>{gazoleCustomText || displayHt(gazoleHt)}</span>
                        </td>
                        <td className={`${fuelBodyCellClass} rounded-br-xl`}>
                            {canManage ? (
                                <div className="grid gap-1">
                                    <span className={COTATION_VALUE_CLASS}>{gazoleCustomText || formatPrice(gazoleDisplayedTtc)}</span>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0"
                                        value={gazole.ttc ?? ''}
                                        onChange={(event) => setGazole('ttc', event.target.value)}
                                        placeholder="TTC gazole"
                                        className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                                    />
                                    <input
                                        type="text"
                                        value={gazole.text ?? ''}
                                        onChange={(event) => setGazole('text', event.target.value)}
                                        placeholder="Texte personnalisé"
                                        className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center text-[11px] font-medium"
                                    />
                                </div>
                            ) : (
                                <span className={COTATION_VALUE_CLASS}>{gazoleCustomText || formatPrice(gazoleDisplayedTtc)}</span>
                            )}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
    const gnrTaxConfigRow = canManage ? (
        <tr key="gnr-tax-config">
            <th className={`border-x border-b border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_BODY_CELL_CLASS} text-left`}>
                <div className={TRANSPORT_HEADER_LABEL_CLASS}>Taxe GNR</div>
            </th>
            <td className={fuelBodyCellClass}>
                <span className={COTATION_VALUE_CLASS}>—</span>
            </td>
            <td className={fuelBodyCellClass}>
                <input
                    type="number"
                    step="0.0001"
                    min="0"
                    value={grid.gnr_tax?.ht ?? ''}
                    onChange={(event) => setGnrTaxHt(event.target.value)}
                    placeholder="Taxe HT"
                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                />
            </td>
            <td className={fuelBodyCellClass}>
                <input
                    type="number"
                    step="0.0001"
                    min="0"
                    value={grid.gnr_tax?.ttc ?? ''}
                    onChange={(event) => setGnrTaxTtc(event.target.value)}
                    placeholder="Taxe TTC"
                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                />
            </td>
        </tr>
    ) : null;

    const fuelActions = isViewingHistory ? (
        <div className="flex flex-wrap items-center gap-2">
            <button
                type="button"
                onClick={onShowOlderHistory}
                disabled={!historyHasOlder}
                className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:opacity-40"
            >
                <ChevronLeft className="h-3.5 w-3.5" strokeWidth={2.3} />
                Plus ancienne
            </button>
            <button
                type="button"
                onClick={onCloseHistory}
                className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--color-black)]"
            >
                <RotateCcw className="h-3.5 w-3.5" strokeWidth={2.3} />
                Présent
            </button>
            <button
                type="button"
                onClick={onShowNewerHistory}
                disabled={!historyHasNewer}
                className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:opacity-40"
            >
                Plus récente
                <ChevronRight className="h-3.5 w-3.5" strokeWidth={2.3} />
            </button>
        </div>
    ) : canEdit && canManage ? (
        <div className="flex flex-wrap gap-2">
            <button
                type="button"
                onClick={onCancelEditing}
                disabled={processing}
                className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:opacity-60"
            >
                <X className="h-3.5 w-3.5" strokeWidth={2.3} />
                Annuler
            </button>
            <button
                type="button"
                onClick={onSave}
                disabled={processing}
                className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--color-black)] disabled:opacity-60"
            >
                <Save className="h-3.5 w-3.5" strokeWidth={2.3} />
                Enregistrer
            </button>
        </div>
    ) : (
        <div className="flex flex-wrap gap-2">
            {canEdit ? (
                <button
                    type="button"
                    onClick={onStartEditing}
                    disabled={processing}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:opacity-60"
                >
                    <Pencil className="h-3.5 w-3.5" strokeWidth={2.3} />
                    Modifier le carburant
                </button>
            ) : null}
            {canViewHistory ? (
                <button
                    type="button"
                    onClick={onOpenHistory}
                    disabled={historyLoading}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] disabled:opacity-60"
                >
                    <History className="h-3.5 w-3.5" strokeWidth={2.3} />
                    {historyLoading ? 'Chargement...' : 'Historique'}
                </button>
            ) : null}
            {canExportPdf ? (
                <a
                    href={exportPdfUrl}
                    download
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em]"
                >
                    <FileDown className="h-3.5 w-3.5" strokeWidth={2.3} />
                    Export PDF
                </a>
            ) : null}
        </div>
    );

    return (
        <CollapsibleSection title="Prix carburant" icon={Fuel} actions={fuelActions}>
            {isViewingHistory ? (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-[var(--brand-yellow-dark)] bg-[var(--brand-yellow-light)] px-3 py-2 text-xs font-black uppercase tracking-[0.06em] text-[var(--color-black)]">
                    <History className="h-4 w-4" strokeWidth={2.3} />
                    <span>
                        Version historique du {formatDateTime(historyVersion?.created_at)}
                        {historyVersion?.created_by_name ? ` par ${historyVersion.created_by_name}` : ''}
                    </span>
                </div>
            ) : null}
            {historyError ? (
                <div className="mb-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
                    {historyError}
                </div>
            ) : null}

            {canManage ? (
                <label className="mb-3 block max-w-[12rem]">
                    <span className="mb-1 block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">TVA (%)</span>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={grid.vat_rate ?? 20}
                        onChange={(event) => setVatRate(normalizeSignedNumberInput(event.target.value))}
                        className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
                    />
                </label>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                {fuelSectionsById.fuel_grand_froid ? renderFuelSectionTable(fuelSectionsById.fuel_grand_froid) : null}
                {renderGazoleTable()}
            </div>

            <div className="h-10" aria-hidden="true"></div>

            <div className="grid gap-4 lg:grid-cols-2">
                {fuelSectionsById.gnr_agri ? renderFuelSectionTable(fuelSectionsById.gnr_agri) : null}
                {fuelSectionsById.gnr_taxe ? renderFuelSectionTable(fuelSectionsById.gnr_taxe, gnrTaxConfigRow) : null}
            </div>
        </CollapsibleSection>
    );
}

function HarvestSettings({ rows = [], canManage, form, setFormSetting }) {
    if (!canManage || rows.length === 0) return null;

    return (
        <CollapsibleSection title="Années affichées" icon={Settings2}>
            <div className="grid gap-3 sm:grid-cols-2">
                {rows.map((row) => {
                    const draft = form.data.settings.find((item) => Number(item.id) === Number(row.id)) || row;

                    return (
                        <label key={row.id} className="block rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3">
                            <span className="mb-1 block text-[11px] font-black uppercase tracking-[0.08em] text-[var(--app-muted)]">
                                {row.label}
                            </span>
                            <input
                                type="number"
                                min="2020"
                                max="2100"
                                step="1"
                                value={draft.value ?? ''}
                                onChange={(event) => setFormSetting(row.id, 'value', event.target.value)}
                                className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm font-bold"
                            />
                        </label>
                    );
                })}
            </div>
        </CollapsibleSection>
    );
}

function flattenSettings(manualSettings = {}) {
    const settings = manualSettings || {};

    return [...(settings.display || []), ...(settings.transport || []), ...(settings.fuel || [])].map((row) => ({
        id: row.id,
        value: row.value ?? '',
        note: row.note ?? '',
    }));
}

function flattenMarketRows(groups = []) {
    const safeGroups = Array.isArray(groups) ? groups : [];

    return safeGroups.flatMap((group) => ['left', 'right'].flatMap((bucket) => (
        (group?.harvests?.[bucket]?.rows || []).map((row) => ({
            identity_hash: row.identity_hash,
            market_identity_hash: row.market_identity_hash,
            line_type: lineTypeFor(row),
            manual_id: row.manual_id,
            product_code: row.product_code || group.code,
            product_name: row.product_name || group.name,
            product_sort: row.product_sort || group.sort || 999,
            contract_code: row.code || '',
            display_label: row.display_label ?? row.label ?? '',
            maturity_label: row.maturity_label || row.label || '',
            maturity_month: row.maturity_month ?? '',
            maturity_year: row.maturity_year ?? row.harvest_year ?? '',
            harvest_year: row.harvest_year ?? group?.harvests?.[bucket]?.year ?? '',
            matif: row.matif ?? '',
            manual_matif: row.manual_matif ?? (lineTypeFor(row) !== 'matif' && !row.has_euronext ? row.matif ?? '' : ''),
            margin: row.margin ?? '',
            sort_order: row.sort ?? 0,
            has_euronext: Boolean(row.has_euronext),
        }))
    )));
}

function flattenCustomCereals(rows = []) {
    const safeRows = Array.isArray(rows) ? rows : [];

    return safeRows.map((row) => ({
        id: row.id,
        code: row.code,
        name: row.name ?? '',
        base_product_code: row.base_product_code ?? 'EBM',
        sort_order: row.sort_order ?? 100,
    }));
}

function normalizeCerealLabels(labels = {}) {
    return Object.fromEntries(BASE_CEREALS.map((cereal) => [
        cereal.code,
        String(labels?.[cereal.code] ?? cereal.name),
    ]));
}

function normalizeCerealTableLabelSet(labels = {}) {
    return {
        ...DEFAULT_CEREAL_TABLE_LABELS,
        ...(labels || {}),
    };
}

function normalizeCerealTableLabels(labels = {}) {
    return Object.fromEntries(Object.entries(labels || {})
        .filter(([, value]) => value && typeof value === 'object' && !Array.isArray(value))
        .map(([code, value]) => [code, normalizeCerealTableLabelSet(value)]));
}

function cerealTableLabelsFor(labels = {}, code = '') {
    return normalizeCerealTableLabelSet(labels?.[code] || {});
}

function normalizeCerealOrder(order = [], groups = []) {
    const groupCodes = (Array.isArray(groups) ? groups : [])
        .map((group) => group?.code)
        .filter(Boolean);
    const knownCodes = new Set(groupCodes);
    const orderedCodes = (Array.isArray(order) ? order : [])
        .filter((code) => knownCodes.has(code));

    return [...orderedCodes, ...groupCodes.filter((code) => !orderedCodes.includes(code))];
}

export default function CotationsIndex({
    manualSettings = {},
    transportGrid = transportDefaultGrid(),
    fuelGrid = fuelDefaultGrid(),
    marketData: initialMarketData = { groups: [], fetched_at: null },
    cerealLabels = {},
    cerealTableLabels = {},
    permissions = {},
    routes = {},
}) {
    const [marketData, setMarketData] = useState(initialMarketData || { groups: [], fetched_at: null });
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState('');
    const [isEditing, setIsEditing] = useState(false);
    const [isFuelEditing, setIsFuelEditing] = useState(false);
    const [draggedCerealCode, setDraggedCerealCode] = useState(null);
    const [lastAddedCerealCode, setLastAddedCerealCode] = useState(null);
    const canViewCereals = Boolean(permissions?.can_view_cereals);
    const canViewFuel = Boolean(permissions?.can_view_fuel);
    const canManage = Boolean(permissions?.can_manage);
    const canManageFuel = Boolean(permissions?.can_manage_fuel);
    const canViewFuelHistory = Boolean(permissions?.can_view_fuel_history);
    const [fuelHistoryVersions, setFuelHistoryVersions] = useState([]);
    const [fuelHistoryLoading, setFuelHistoryLoading] = useState(false);
    const [fuelHistoryError, setFuelHistoryError] = useState('');
    const [fuelHistoryIndex, setFuelHistoryIndex] = useState(null);
    const form = useForm({
        settings: flattenSettings(manualSettings),
        manual_prices: flattenMarketRows(initialMarketData?.groups || []),
        custom_cereals: flattenCustomCereals(initialMarketData?.custom_cereals || []),
        cereal_order: normalizeCerealOrder(initialMarketData?.cereal_order || [], initialMarketData?.groups || []),
        cereal_labels: normalizeCerealLabels(initialMarketData?.cereal_labels || cerealLabels),
        cereal_table_labels: normalizeCerealTableLabels(initialMarketData?.cereal_table_labels || cerealTableLabels),
        cereal_info_html: initialMarketData?.cereal_info_html || '',
        transport_grid: normalizeTransportGrid(transportGrid),
        fuel_grid: normalizeFuelGrid(fuelGrid),
        deleted_manual_price_ids: [],
    });

    useEffect(() => {
        form.setData('settings', flattenSettings(manualSettings));
    }, [manualSettings]);

    useEffect(() => {
        setMarketData(initialMarketData || { groups: [], fetched_at: null });
        if (!isEditing) {
            form.setData('manual_prices', flattenMarketRows(initialMarketData?.groups || []));
            form.setData('custom_cereals', flattenCustomCereals(initialMarketData?.custom_cereals || []));
            form.setData('cereal_order', normalizeCerealOrder(initialMarketData?.cereal_order || [], initialMarketData?.groups || []));
            form.setData('cereal_labels', normalizeCerealLabels(initialMarketData?.cereal_labels || cerealLabels));
            form.setData('cereal_table_labels', normalizeCerealTableLabels(initialMarketData?.cereal_table_labels || cerealTableLabels));
            form.setData('cereal_info_html', initialMarketData?.cereal_info_html || '');
        }
    }, [initialMarketData]);

    useEffect(() => {
        if (!isEditing && !isFuelEditing) {
            form.setData('transport_grid', normalizeTransportGrid(transportGrid));
            form.setData('fuel_grid', normalizeFuelGrid(fuelGrid));
        }
    }, [transportGrid, fuelGrid]);

    useEffect(() => {
        if (!lastAddedCerealCode) return undefined;

        const timeout = window.setTimeout(() => {
            document.querySelector(`[data-cereal-code="${lastAddedCerealCode}"]`)?.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
        }, 50);

        return () => window.clearTimeout(timeout);
    }, [lastAddedCerealCode]);

    const fetchMarketData = async ({ initial = false } = {}) => {
        if (!canViewCereals || !routes.market_data) return;
        setError('');
        if (initial) setLoading(true);
        else setRefreshing(true);

        try {
            const response = await fetch(routes.market_data, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok || !data?.ok) {
                throw new Error(data?.message || 'Cours indisponibles.');
            }

            setMarketData(data);
        } catch (exception) {
            setError(exception?.message || 'Cours indisponibles.');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    useEffect(() => {
        fetchMarketData({ initial: true });
        const interval = window.setInterval(() => fetchMarketData(), 60000);

        return () => window.clearInterval(interval);
    }, [routes.market_data, canViewCereals]);

    const setFormSetting = (id, field, value) => {
        form.setData('settings', form.data.settings.map((row) => (
            Number(row.id) === Number(id) ? { ...row, [field]: value } : row
        )));
    };

    const setManualPrice = (row, field, value) => {
        const key = rowDraftKey(row);
        const currentRows = form.data.manual_prices || [];
        const existing = findManualDraft(currentRows, row);
        const updates = typeof field === 'object' ? field : { [field]: value };
        const nextRow = {
            identity_hash: row.identity_hash,
            market_identity_hash: row.market_identity_hash,
            line_type: lineTypeFor(row),
            manual_id: row.manual_id,
            form_key: row.form_key,
            product_code: row.product_code,
            product_name: row.product_name,
            product_sort: row.product_sort || 999,
            contract_code: row.code || row.contract_code || '',
            display_label: row.display_label || row.label || '',
            maturity_label: row.maturity_label || row.label || '',
            maturity_month: row.maturity_month ?? '',
            maturity_year: row.maturity_year ?? row.harvest_year ?? '',
            harvest_year: row.harvest_year ?? '',
            matif: row.matif ?? '',
            manual_matif: row.manual_matif ?? (lineTypeFor(row) !== 'matif' && !row.has_euronext ? row.matif ?? '' : ''),
            margin: row.margin ?? '',
            sort_order: row.sort_order ?? row.sort ?? 0,
            has_euronext: Boolean(row.has_euronext),
            is_new: Boolean(row.is_new),
            ...(existing || {}),
            ...updates,
        };

        if (!existing) {
            form.setData('manual_prices', [...currentRows, nextRow]);
            return;
        }

        form.setData('manual_prices', currentRows.map((item) => (
            rowDraftKey(item) === key ? nextRow : item
        )));
    };

    const addManualRow = (group, harvest) => {
        if (!group?.code || !harvest?.year) return;

        const now = Date.now();
        form.setData('manual_prices', [
            ...(form.data.manual_prices || []),
            {
                form_key: `manual-${group.code}-${harvest.year}-${now}`,
                identity_hash: clientHash(),
                market_identity_hash: '',
                line_type: 'custom',
                product_code: group.code,
                product_name: group.name,
                product_sort: group.sort || 999,
                contract_code: '',
                display_label: '',
                maturity_label: '',
                maturity_month: '',
                maturity_year: harvest.year,
                harvest_year: harvest.year,
                matif: '',
                manual_matif: '',
                margin: '',
                sort_order: form.data.manual_prices?.length || 0,
                has_euronext: false,
                is_new: true,
            },
        ]);
    };

    const setCerealInfoHtml = (html) => {
        form.setData('cereal_info_html', html);
    };

    const setCustomCereal = (cereal, field, value) => {
        const key = cereal.form_key || cereal.code || cereal.id;
        form.setData('custom_cereals', (form.data.custom_cereals || []).map((item) => (
            (item.form_key || item.code || item.id) === key ? { ...item, [field]: value } : item
        )));
    };

    const setCerealLabel = (code, value) => {
        form.setData('cereal_labels', {
            ...(form.data.cereal_labels || {}),
            [code]: value,
        });
    };

    const setCerealTableLabel = (code, key, value) => {
        form.setData('cereal_table_labels', {
            ...(form.data.cereal_table_labels || {}),
            [code]: {
                ...cerealTableLabelsFor(form.data.cereal_table_labels || {}, code),
                [key]: value,
            },
        });
    };

    const addCustomCereal = () => {
        const now = Date.now().toString(36).toUpperCase();
        const code = `C${now}`.slice(0, 16);
        form.setData({
            ...form.data,
            custom_cereals: [
                ...(form.data.custom_cereals || []),
                {
                    form_key: `custom-${now}`,
                    code,
                    name: 'Nouvelle céréale',
                    base_product_code: 'EBM',
                    sort_order: 100 + (form.data.custom_cereals || []).length,
                },
            ],
            cereal_order: [...(form.data.cereal_order || []), code],
        });
        setLastAddedCerealCode(code);
    };

    const deleteManualRow = (row) => {
        if (!window.confirm('Supprimer cette échéance ?')) return;

        const key = rowDraftKey(row);
        const deletedIds = form.data.deleted_manual_price_ids || [];

        form.setData({
            ...form.data,
            manual_prices: (form.data.manual_prices || []).filter((item) => rowDraftKey(item) !== key),
            deleted_manual_price_ids: row.manual_id
                ? [...new Set([...deletedIds, Number(row.manual_id)])]
                : deletedIds,
        });
    };

    const setTransportGrid = (grid) => {
        form.setData('transport_grid', normalizeTransportGrid(grid));
    };

    const setFuelGrid = (grid) => {
        form.setData('fuel_grid', normalizeFuelGrid(grid));
    };

    const resetDrafts = (data = marketData) => {
        form.setData({
            settings: flattenSettings(manualSettings),
            manual_prices: flattenMarketRows(data?.groups || []),
            custom_cereals: flattenCustomCereals(data?.custom_cereals || []),
            cereal_order: normalizeCerealOrder(data?.cereal_order || [], data?.groups || []),
            cereal_labels: normalizeCerealLabels(data?.cereal_labels || cerealLabels),
            cereal_table_labels: normalizeCerealTableLabels(data?.cereal_table_labels || cerealTableLabels),
            cereal_info_html: data?.cereal_info_html || '',
            transport_grid: normalizeTransportGrid(transportGrid),
            fuel_grid: normalizeFuelGrid(fuelGrid),
            deleted_manual_price_ids: [],
        });
    };

    const startEditing = () => {
        resetDrafts();
        setIsFuelEditing(false);
        setIsEditing(true);
        setLastAddedCerealCode(null);
    };

    const cancelEditing = () => {
        resetDrafts();
        setLastAddedCerealCode(null);
        setIsEditing(false);
    };

    const saveSettings = () => {
        form.put(routes.settings_update, {
            preserveScroll: true,
            onSuccess: () => setIsEditing(false),
        });
    };

    const resetFuelDraft = () => {
        form.setData('fuel_grid', normalizeFuelGrid(fuelGrid));
    };

    const startFuelEditing = () => {
        resetDrafts();
        setIsEditing(false);
        setIsFuelEditing(true);
        setFuelHistoryIndex(null);
    };

    const cancelFuelEditing = () => {
        resetFuelDraft();
        setIsFuelEditing(false);
    };

    const saveFuelSettings = () => {
        form.put(routes.fuel_settings_update, {
            preserveScroll: true,
            onSuccess: () => setIsFuelEditing(false),
        });
    };

    const openFuelHistory = async () => {
        if (!canViewFuelHistory || !routes.fuel_history) return;

        setIsFuelEditing(false);
        setFuelHistoryLoading(true);
        setFuelHistoryError('');

        try {
            const response = await fetch(routes.fuel_history, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();

            if (!response.ok) {
                throw new Error('Impossible de charger l\'historique du carburant.');
            }

            const versions = data?.versions || [];
            setFuelHistoryVersions(versions);
            setFuelHistoryIndex(versions.length ? 0 : null);
            if (!versions.length) {
                setFuelHistoryError('Aucune version historique disponible.');
            }
        } catch (exception) {
            setFuelHistoryError(exception?.message || 'Impossible de charger l\'historique du carburant.');
        } finally {
            setFuelHistoryLoading(false);
        }
    };

    const closeFuelHistory = () => {
        setFuelHistoryIndex(null);
        setFuelHistoryError('');
    };

    const showOlderFuelVersion = () => {
        setFuelHistoryIndex((current) => {
            if (current === null) return fuelHistoryVersions.length ? 0 : null;
            return Math.min(current + 1, fuelHistoryVersions.length - 1);
        });
    };

    const showNewerFuelVersion = () => {
        setFuelHistoryIndex((current) => {
            if (current === null) return null;
            if (current <= 0) return null;
            return current - 1;
        });
    };

    const isViewingFuelHistory = fuelHistoryIndex !== null && Boolean(fuelHistoryVersions[fuelHistoryIndex]);
    const activeFuelHistoryVersion = isViewingFuelHistory ? fuelHistoryVersions[fuelHistoryIndex] : null;
    const displayedFuelGrid = activeFuelHistoryVersion ? activeFuelHistoryVersion.fuel_grid : form.data.fuel_grid;

    const moveCereal = (sourceCode, targetCode) => {
        if (!sourceCode || !targetCode || sourceCode === targetCode) return;

        const currentOrder = normalizeCerealOrder(form.data.cereal_order || [], cerealGroups);
        const sourceIndex = currentOrder.indexOf(sourceCode);
        const targetIndex = currentOrder.indexOf(targetCode);

        if (sourceIndex < 0 || targetIndex < 0) return;

        const nextOrder = [...currentOrder];
        const [moved] = nextOrder.splice(sourceIndex, 1);
        nextOrder.splice(targetIndex, 0, moved);
        form.setData('cereal_order', nextOrder);
    };

    const handleCerealDrop = (targetCode) => {
        moveCereal(draggedCerealCode, targetCode);
        setDraggedCerealCode(null);
    };

    const cerealGroups = useMemo(() => {
        const groups = marketData?.groups || [];
        if (isEditing) {
            const customCerealsByCode = Object.fromEntries((form.data.custom_cereals || []).map((cereal) => [cereal.code, cereal]));
            const mergedGroups = groups.map((group) => {
                const customCereal = customCerealsByCode[group.code];

                return customCereal ? {
                    ...group,
                    name: customCereal.name || group.name,
                    base_product_code: customCereal.base_product_code || group.base_product_code,
                    sort: customCereal.sort_order || group.sort,
                } : {
                    ...group,
                    name: form.data.cereal_labels?.[group.code] || group.name,
                };
            });
            const existingCodes = new Set(mergedGroups.map((group) => group.code));
            const customGroups = (form.data.custom_cereals || [])
                .filter((cereal) => !existingCodes.has(cereal.code))
                .map((cereal) => ({
                    code: cereal.code,
                    name: cereal.name,
                    sort: cereal.sort_order || 100,
                    base_product_code: cereal.base_product_code,
                    harvests: {
                        left: { year: marketData?.harvest_years?.left, rows: [] },
                        right: { year: marketData?.harvest_years?.right, rows: [] },
                    },
                }));

            const defaultSortedGroups = [...mergedGroups, ...customGroups].sort((left, right) => ((left.sort || 0) - (right.sort || 0)) || String(left.name).localeCompare(String(right.name)));
            const order = normalizeCerealOrder(form.data.cereal_order || [], defaultSortedGroups);
            const orderIndex = Object.fromEntries(order.map((code, index) => [code, index]));

            return [...defaultSortedGroups].sort((left, right) => (orderIndex[left.code] ?? 9999) - (orderIndex[right.code] ?? 9999));
        }

        return groups.filter((group) => (
            (group?.harvests?.left?.rows || []).length > 0
            || (group?.harvests?.right?.rows || []).length > 0
        ));
    }, [marketData, isEditing, form.data.custom_cereals, form.data.cereal_order, form.data.cereal_labels]);
    const optionGroups = useMemo(() => marketData?.options || [], [marketData]);
    const optionGroupsByCode = useMemo(() => {
        const byCode = Object.fromEntries(optionGroups.map((group) => [group.code, group]));
        (form.data.custom_cereals || []).forEach((cereal) => {
            const base = byCode[cereal.base_product_code];
            if (base) {
                byCode[cereal.code] = { ...base, code: cereal.code, name: cereal.name, base_product_code: cereal.base_product_code };
            }
        });

        return byCode;
    }, [optionGroups, form.data.custom_cereals]);
    const finalPriceOptions = useMemo(() => {
        const marketRows = flattenMarketRows(marketData?.groups || []);
        const draftRows = isEditing ? (form.data.manual_prices || []) : [];
        const rowsByKey = new Map();

        [...marketRows, ...draftRows].forEach((row) => {
            rowsByKey.set(transportReferenceKey(row), row);
        });

        return Array.from(rowsByKey.values())
            .map((row) => {
                const matif = lineTypeFor(row) === 'matif'
                    ? parseDecimal(row.matif)
                    : parseDecimal(row.manual_matif ?? row.matif);
                if (matif === null) return null;

                const base = Math.abs(parseDecimal(row.margin) ?? 0);
                const finalPrice = matif - base;
                const labelParts = [
                    row.product_name || row.product_code || 'Céréale',
                    row.harvest_year ? `Récolte ${row.harvest_year}` : '',
                    row.display_label || row.label || row.maturity_label || 'Échéance',
                ].filter(Boolean);

                return {
                    key: transportReferenceKey(row),
                    product_code: row.product_code,
                    label: labelParts.join(' — '),
                    final_price: finalPrice,
                };
            })
            .filter(Boolean);
    }, [isEditing, form.data.manual_prices, marketData]);

    const header = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 className="text-[22px] leading-none font-black uppercase tracking-[0.06em]">Cotations</h1>
                {canViewCereals ? (
                    <p className="mt-1 text-sm text-[var(--app-muted)]">
                        Dernière actualisation serveur : {formatDateTime(marketData?.fetched_at)}
                    </p>
                ) : null}
            </div>

            <div className="flex flex-wrap gap-2">
                {canViewCereals ? (
                    <button
                        type="button"
                        onClick={() => fetchMarketData()}
                        disabled={refreshing}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--color-black)] disabled:opacity-60"
                    >
                        <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} strokeWidth={2.3} />
                        Actualiser
                    </button>
                ) : null}
                {canManage && routes.export_pdf ? (
                    <a
                        href={routes.export_pdf}
                        download
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em]"
                    >
                        <FileDown className="h-3.5 w-3.5" strokeWidth={2.3} />
                        Export PDF
                    </a>
                ) : null}
                {canManage && !isEditing && !isFuelEditing ? (
                    <button
                        type="button"
                        onClick={startEditing}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em]"
                    >
                        <Pencil className="h-3.5 w-3.5" strokeWidth={2.3} />
                        Modifier les cotations
                    </button>
                ) : null}
            </div>
        </div>
    );

    return (
        <AppLayout title="Cotations" header={header}>
            <Head title="Cotations" />

            <div className="w-full max-w-full min-w-0 space-y-5 overflow-x-hidden">
                {error ? (
                    <div className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {error}
                    </div>
                ) : null}

                {!canViewCereals && !canViewFuel ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm font-semibold text-[var(--app-muted)]">
                        Vous n'avez pas accès à cette page.
                    </div>
                ) : null}

                {canViewCereals ? (
                    <>
                        <CerealInfoSection
                            canView={canViewCereals}
                            isEditing={isEditing}
                            html={form.data.cereal_info_html}
                            onChange={setCerealInfoHtml}
                        />

                        <HarvestSettings
                            rows={manualSettings.display || []}
                            canManage={isEditing}
                            form={form}
                            setFormSetting={setFormSetting}
                        />

                        {loading ? (
                            <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)]">
                                Chargement des cours...
                            </div>
                        ) : cerealGroups.length ? (
                            <div className="grid w-full max-w-full min-w-0 grid-cols-1 gap-4">
                                {isEditing ? (
                                    <button
                                        type="button"
                                        onClick={addCustomCereal}
                                        className="w-full rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--brand-brown)]"
                                    >
                                        Ajouter une céréale personnalisée
                                    </button>
                                ) : null}
                                {cerealGroups.map((group, index) => (
                                    <div
                                        key={`${group.code || group.name}-${index}`}
                                        data-cereal-code={group.code || ''}
                                        className="min-w-0"
                                        onDragOver={(event) => {
                                            if (!isEditing) return;
                                            event.preventDefault();
                                        }}
                                        onDrop={() => handleCerealDrop(group.code)}
                                    >
                                        <CerealCard
                                            group={group}
                                            canManage={isEditing}
                                            form={form}
                                            setManualPrice={setManualPrice}
                                            addManualRow={addManualRow}
                                            deleteManualRow={deleteManualRow}
                                            optionGroup={optionGroupsByCode[group.code]}
                                            customCereal={(form.data.custom_cereals || []).find((cereal) => cereal.code === group.code) || null}
                                            setCustomCereal={setCustomCereal}
                                            setCerealLabel={setCerealLabel}
                                            setCerealTableLabel={setCerealTableLabel}
                                            cerealLabels={form.data.cereal_labels || {}}
                                            tableLabels={cerealTableLabelsFor(form.data.cereal_table_labels || {}, group.code)}
                                            finalPriceOptions={finalPriceOptions}
                                            defaultOpen={Boolean(group.code) && group.code === lastAddedCerealCode}
                                            dragHandle={isEditing ? (
                                                <button
                                                    type="button"
                                                    draggable
                                                    onTouchStart={() => setDraggedCerealCode(group.code)}
                                                    onTouchMove={(event) => event.preventDefault()}
                                                    onTouchEnd={(event) => {
                                                        const touch = event.changedTouches?.[0];
                                                        const target = touch ? document.elementFromPoint(touch.clientX, touch.clientY)?.closest('[data-cereal-code]') : null;
                                                        handleCerealDrop(target?.getAttribute('data-cereal-code'));
                                                    }}
                                                    onDragStart={(event) => {
                                                        setDraggedCerealCode(group.code);
                                                        event.dataTransfer.effectAllowed = 'move';
                                                        event.dataTransfer.setData('text/plain', group.code || '');
                                                    }}
                                                    onDragEnd={() => setDraggedCerealCode(null)}
                                                    className="inline-flex h-10 w-10 touch-none cursor-grab items-center justify-center rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)] active:cursor-grabbing"
                                                    aria-label={`Déplacer ${group.name || 'céréale'}`}
                                                >
                                                    <GripVertical className="h-5 w-5" strokeWidth={2.3} />
                                                </button>
                                            ) : null}
                                        />
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)]">
                                Aucun cours disponible pour le moment.
                            </div>
                        )}

                        <TransportGridSection
                            grid={form.data.transport_grid}
                            canManage={isEditing}
                            finalPriceOptions={finalPriceOptions}
                            setTransportGrid={setTransportGrid}
                        />
                    </>
                ) : null}

                {canViewFuel ? (
                    <FuelGridSection
                        grid={displayedFuelGrid}
                        canManage={isFuelEditing && !isViewingFuelHistory}
                        canEdit={canManageFuel && !isEditing && !isViewingFuelHistory}
                        setFuelGrid={setFuelGrid}
                        onStartEditing={startFuelEditing}
                        onCancelEditing={cancelFuelEditing}
                        onSave={saveFuelSettings}
                        processing={form.processing}
                        canViewHistory={canViewFuelHistory}
                        isViewingHistory={isViewingFuelHistory}
                        historyVersion={activeFuelHistoryVersion}
                        historyHasOlder={fuelHistoryIndex !== null && fuelHistoryIndex < fuelHistoryVersions.length - 1}
                        historyHasNewer={fuelHistoryIndex !== null}
                        historyLoading={fuelHistoryLoading}
                        historyError={fuelHistoryError}
                        onOpenHistory={openFuelHistory}
                        onCloseHistory={closeFuelHistory}
                        onShowOlderHistory={showOlderFuelVersion}
                        onShowNewerHistory={showNewerFuelVersion}
                        canExportPdf={canManageFuel && Boolean(routes.export_fuel_pdf)}
                        exportPdfUrl={routes.export_fuel_pdf}
                    />
                ) : null}

                {canManage && isEditing ? (
                    <div className="sticky bottom-4 z-10 flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            onClick={cancelEditing}
                            disabled={form.processing}
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-4 py-3 text-xs font-black uppercase tracking-[0.12em] shadow-lg shadow-black/10 disabled:opacity-60"
                        >
                            <X className="h-4 w-4" strokeWidth={2.4} />
                            Annuler
                        </button>
                        <button
                            type="button"
                            onClick={saveSettings}
                            disabled={form.processing}
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-4 py-3 text-xs font-black uppercase tracking-[0.12em] text-[var(--color-black)] shadow-lg shadow-black/10 disabled:opacity-60"
                        >
                            <Save className="h-4 w-4" strokeWidth={2.4} />
                            Enregistrer
                        </button>
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
