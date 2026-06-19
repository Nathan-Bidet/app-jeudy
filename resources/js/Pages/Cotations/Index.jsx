import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm } from '@inertiajs/react';
import { Fuel, Pencil, RefreshCw, Save, Settings2, Trash2, Truck, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const BASE_CEREALS = [
    { code: 'ECO', name: 'Colza' },
    { code: 'EBM', name: 'Blé' },
    { code: 'EMA', name: 'Maïs' },
];

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
    return {
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
}

function normalizeTransportGrid(grid = {}) {
    const defaults = transportDefaultGrid();
    const columns = Array.isArray(grid.columns) && grid.columns.length ? grid.columns : defaults.columns;
    const rows = Array.isArray(grid.rows) && grid.rows.length ? grid.rows : defaults.rows;

    return {
        reference_key: grid.reference_key || '',
        columns: columns.map((column, index) => ({
            id: column.id || `col_${index + 1}`,
            label: column.label ?? `Colonne ${index + 1}`,
            reference_key: column.reference_key ?? grid.reference_key ?? '',
            base: column.base ?? 0,
        })),
        rows: rows.map((row, index) => ({
            id: row.id || `row_${index + 1}`,
            label: row.label ?? `Ligne ${index + 1}`,
            base: row.base ?? 0,
        })),
        cells: Object.fromEntries(Object.entries(grid.cells || {}).map(([key, cell]) => [
            key,
            { text: typeof cell === 'object' && cell !== null ? cell.text ?? '' : '' },
        ])),
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
        gazole: { id: 'gazole', tranche: 'GAZOLE', ttc: '', gap: 0, text: '' },
    };
}

function normalizeFuelGrid(grid = {}) {
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
            tranche: grid.gazole?.tranche ?? 'GAZOLE',
            ttc: grid.gazole?.ttc ?? grid.gazole?.ht ?? '',
            gap: grid.gazole?.gap ?? 0,
            text: grid.gazole?.text ?? '',
        },
    };
}

function MarketRow({ row, canManage, form, setManualPrice, deleteManualRow, options = [] }) {
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
                            className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-xs font-bold sm:text-sm"
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
                            className="w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-xs font-bold sm:text-sm"
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
                        <div className={`truncate ${COTATION_ROW_LABEL_CLASS}`}>{row.display_label || row.label || row.maturity_label || 'Échéance'}</div>
                    </>
                )}
                {!isMatifLine && canManage ? (
                    <div className="mt-1 text-[9px] font-black uppercase tracking-[0.04em] text-[var(--app-muted)] sm:text-[10px]">Manuel</div>
                ) : null}
            </td>
            <td className={`${COTATION_BODY_CELL_CLASS} text-center`}>
                {canManage && !isMatifLine ? (
                    <input
                        type="number"
                        step="0.0001"
                        min="0"
                        value={matifValue}
                        onChange={(event) => setManualPrice(row, 'manual_matif', event.target.value)}
                        className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
                    />
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
                            className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-1.5 py-1.5 text-center ${COTATION_VALUE_CLASS}`}
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
                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-[var(--app-muted)] hover:border-red-200 hover:bg-red-50 hover:text-red-700"
                        aria-label="Supprimer l'échéance"
                    >
                        <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                    </button>
                </td>
            ) : null}
        </tr>
    );
}

function HarvestBlock({ group, harvest, canManage, form, setManualPrice, addManualRow, deleteManualRow, options = [] }) {
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
        <div className="overflow-hidden rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)]">
            <div className={COTATION_HARVEST_TITLE_CLASS}>
                {title}
            </div>
            {displayedRows.length ? (
                <div>
                    <table className={COTATION_TABLE_CLASS}>
                        <colgroup>
                            <col className={canManage ? 'w-[32%]' : 'w-[34%]'} />
                            <col className={canManage ? 'w-[22%]' : 'w-[23%]'} />
                            <col className={canManage ? 'w-[17%]' : 'w-[18%]'} />
                            <col className={canManage ? 'w-[23%]' : 'w-[25%]'} />
                            {canManage ? <col className="w-[6%]" /> : null}
                        </colgroup>
                        <thead>
                            <tr className={COTATION_HEADER_ROW_CLASS}>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-left`}>Échéance</th>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-center`}>Matif</th>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-center`}>Base</th>
                                <th className={`${COTATION_HEADER_CELL_CLASS} text-right`}>Prix final</th>
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

function CerealCard({ group, canManage, form, setManualPrice, addManualRow, deleteManualRow, optionGroup }) {
    const leftHarvest = group?.harvests?.left || {};
    const rightHarvest = group?.harvests?.right || {};
    const leftOptions = optionGroup?.harvests?.left?.rows || [];
    const rightOptions = optionGroup?.harvests?.right?.rows || [];

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-xl font-black uppercase tracking-[0.04em]">{group.name || 'Céréale'}</h2>
                {group.code ? <span className="text-xs font-black uppercase tracking-[0.12em] text-[var(--app-muted)]">{group.code}</span> : null}
            </div>
            <div className="mt-3 grid gap-3 xl:grid-cols-2">
                <HarvestBlock group={group} harvest={leftHarvest} canManage={canManage} form={form} setManualPrice={setManualPrice} addManualRow={addManualRow} deleteManualRow={deleteManualRow} options={leftOptions} />
                <HarvestBlock group={group} harvest={rightHarvest} canManage={canManage} form={form} setManualPrice={setManualPrice} addManualRow={addManualRow} deleteManualRow={deleteManualRow} options={rightOptions} />
            </div>
        </section>
    );
}

function ManualSection({ title, icon: Icon, rows = [], canManage, form, setFormSetting }) {
    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                {Icon ? <Icon className="h-5 w-5 text-[var(--brand-brown)]" strokeWidth={2.3} /> : null}
                <h2 className="text-lg font-black uppercase tracking-[0.04em]">{title}</h2>
            </div>

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
        </section>
    );
}

function TransportGridSection({ grid, canManage, finalPriceOptions, setTransportGrid }) {
    const columns = grid.columns || [];
    const rows = grid.rows || [];
    const cells = grid.cells || {};
    const optionsByKey = Object.fromEntries(finalPriceOptions.map((option) => [option.key, option]));
    const cellKey = (rowId, columnId) => `${rowId}__${columnId}`;

    const setColumn = (columnId, field, value) => setTransportGrid({
        ...grid,
        columns: columns.map((column) => (column.id === columnId ? { ...column, [field]: value } : column)),
    });
    const setRow = (rowId, field, value) => setTransportGrid({
        ...grid,
        rows: rows.map((row) => (row.id === rowId ? { ...row, [field]: value } : row)),
    });
    const setCellText = (rowId, columnId, text) => {
        const key = cellKey(rowId, columnId);
        setTransportGrid({
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
    const addColumn = () => setTransportGrid({
        ...grid,
        columns: [...columns, { id: `col_${Date.now().toString(36)}`, label: `Colonne ${columns.length + 1}`, reference_key: '', base: 0 }],
    });
    const addRow = () => setTransportGrid({
        ...grid,
        rows: [...rows, { id: `row_${Date.now().toString(36)}`, label: `Ligne ${rows.length + 1}`, base: 0 }],
    });
    const removeColumn = (columnId) => {
        if (columns.length <= 1) return;
        setTransportGrid({ ...grid, columns: columns.filter((column) => column.id !== columnId) });
    };
    const removeRow = (rowId) => {
        if (rows.length <= 1) return;
        setTransportGrid({ ...grid, rows: rows.filter((row) => row.id !== rowId) });
    };

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Truck className="h-5 w-5 text-[var(--brand-brown)]" strokeWidth={2.3} />
                <h2 className="text-lg font-black uppercase tracking-[0.04em]">Prix des transports</h2>
            </div>

            {canManage ? (
                <div className="mb-3 flex flex-wrap gap-2">
                    <button type="button" onClick={addColumn} className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-[11px] font-black uppercase tracking-[0.08em]">
                        Ajouter une colonne
                    </button>
                    <button type="button" onClick={addRow} className="rounded-lg border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-[11px] font-black uppercase tracking-[0.08em]">
                        Ajouter une ligne
                    </button>
                </div>
            ) : null}

            <div className="overflow-x-auto">
                <table className={`${COTATION_TABLE_CLASS} min-w-full border-separate border-spacing-0`}>
                    <thead>
                        <tr className={COTATION_HEADER_ROW_CLASS}>
                            <th className={`sticky left-0 z-[1] rounded-tl-xl border border-[var(--app-border)] bg-[#FACC51] ${COTATION_HEADER_CELL_CLASS} text-left text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`}>
                                Transport
                            </th>
                            {columns.map((column, index) => (
                                <th
                                    key={column.id}
                                    className={`min-w-[11rem] border-y border-r border-[var(--app-border)] bg-[#FACC51] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${index === columns.length - 1 ? 'rounded-tr-xl' : ''}`}
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
                                        <div className={TRANSPORT_HEADER_LABEL_CLASS}>{column.label || `Colonne ${index + 1}`}</div>
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIndex) => (
                            <tr key={row.id}>
                                <th className={`sticky left-0 z-[1] min-w-[10rem] border-x border-b border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_BODY_CELL_CLASS} text-left font-black ${rowIndex === rows.length - 1 ? 'rounded-bl-xl' : ''}`}>
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
                                        <div className={TRANSPORT_HEADER_LABEL_CLASS}>{row.label || `Ligne ${rowIndex + 1}`}</div>
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
                                            className={`border-b border-r border-[var(--app-border)] ${COTATION_BODY_CELL_CLASS} text-center ${rowIndex === rows.length - 1 && columnIndex === columns.length - 1 ? 'rounded-br-xl' : ''}`}
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
        </section>
    );
}

function FuelGridSection({ grid, canManage, setFuelGrid }) {
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
    const fuelValueHeaderClass = `w-[5.25rem] border-y border-r border-[var(--app-border)] bg-[#FACC51] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`;
    const fuelBodyCellClass = `border-b border-r border-[var(--app-border)] ${COTATION_BODY_CELL_CLASS} text-center`;
    const renderFuelSectionTable = (section, extraRows = null) => (
        <div className="overflow-x-auto">
            <table className={`${COTATION_TABLE_CLASS} w-full table-fixed border-separate border-spacing-0`}>
                <thead>
                    <tr className={COTATION_HEADER_ROW_CLASS}>
                        <th className={fuelHeaderSpacerClass} aria-hidden="true"></th>
                        {canManage ? (
                            <th className={`w-[4.75rem] rounded-tl-xl border-y border-l border-r border-[var(--app-border)] bg-[#FACC51] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`}>
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
                        <th colSpan={fuelTableColumnCount} className={`rounded-tl-xl border-x border-t border-b border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_BODY_CELL_CLASS} text-center`}>
                            {canManage ? (
                                <input
                                    type="text"
                                    value={section.label ?? ''}
                                    onChange={(event) => setSection(section.id, 'label', event.target.value)}
                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1.5 text-center uppercase tracking-[0.04em] ${TRANSPORT_HEADER_INPUT_CLASS}`}
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
                            <th className={`w-[4.75rem] rounded-tl-xl border-y border-l border-r border-[var(--app-border)] bg-[#FACC51] ${COTATION_HEADER_CELL_CLASS} text-center text-[var(--color-black)] ${TRANSPORT_HEADER_LABEL_CLASS}`}>
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
                        <th className={`w-[8.5rem] rounded-bl-xl rounded-tl-xl border-x border-t border-b border-[var(--app-border)] bg-[var(--app-surface-soft)] ${COTATION_BODY_CELL_CLASS} text-left font-black`}>
                            <div className={TRANSPORT_HEADER_LABEL_CLASS}>{gazole.tranche || 'GAZOLE'}</div>
                        </th>
                        {canManage ? (
                            <td className={`border-t ${fuelBodyCellClass}`}>
                                <input
                                    type="number"
                                    step="0.0001"
                                    value={gazole.gap ?? ''}
                                    onChange={(event) => setGazole('gap', normalizeSignedNumberInput(event.target.value))}
                                    className={`w-full rounded-lg border border-[var(--app-border)] bg-[var(--app-surface)] px-2 py-1 text-center ${COTATION_VALUE_CLASS}`}
                                />
                            </td>
                        ) : null}
                        <td className={`border-t ${fuelBodyCellClass}`}>
                            {canManage ? (
                                <div className="grid gap-1">
                                    <span className={COTATION_VALUE_CLASS}>{gazoleCustomText || displayHt(gazoleHt)}</span>
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
                                <span className={COTATION_VALUE_CLASS}>{gazoleCustomText || displayHt(gazoleHt)}</span>
                            )}
                        </td>
                        <td className={`border-t ${fuelBodyCellClass} rounded-br-xl`}>
                            <span className={COTATION_VALUE_CLASS}>{gazoleCustomText || formatPrice(gazoleDisplayedTtc)}</span>
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

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Fuel className="h-5 w-5 text-[var(--brand-brown)]" strokeWidth={2.3} />
                <h2 className="text-lg font-black uppercase tracking-[0.04em]">Prix carburant</h2>
            </div>

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
        </section>
    );
}

function HarvestSettings({ rows = [], canManage, form, setFormSetting }) {
    if (!canManage || rows.length === 0) return null;

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Settings2 className="h-5 w-5 text-[var(--brand-brown)]" strokeWidth={2.3} />
                <h2 className="text-lg font-black uppercase tracking-[0.04em]">Années affichées</h2>
            </div>

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
        </section>
    );
}

function CustomCerealSection({ canManage, form, setCustomCereal, addCustomCereal }) {
    if (!canManage) return null;

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2">
                <Settings2 className="h-5 w-5 text-[var(--brand-brown)]" strokeWidth={2.3} />
                <h2 className="text-lg font-black uppercase tracking-[0.04em]">Céréales personnalisées</h2>
            </div>

            <div className="space-y-2">
                {(form.data.custom_cereals || []).map((cereal) => (
                    <div key={cereal.form_key || cereal.code || cereal.id} className="grid gap-2 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] p-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                        <input
                            type="text"
                            value={cereal.name ?? ''}
                            onChange={(event) => setCustomCereal(cereal, 'name', event.target.value)}
                            placeholder="Nom personnalisé"
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm font-bold"
                        />
                        <select
                            value={cereal.base_product_code ?? 'EBM'}
                            onChange={(event) => setCustomCereal(cereal, 'base_product_code', event.target.value)}
                            className="w-full rounded-xl border border-[var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-sm font-bold"
                        >
                            {BASE_CEREALS.map((base) => (
                                <option key={base.code} value={base.code}>{base.name}</option>
                            ))}
                        </select>
                    </div>
                ))}
            </div>

            <button
                type="button"
                onClick={addCustomCereal}
                className="mt-3 w-full rounded-xl border border-dashed border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--brand-brown)]"
            >
                Ajouter une céréale personnalisée
            </button>
        </section>
    );
}

function flattenSettings(manualSettings = {}) {
    return [...(manualSettings.display || []), ...(manualSettings.transport || []), ...(manualSettings.fuel || [])].map((row) => ({
        id: row.id,
        value: row.value ?? '',
        note: row.note ?? '',
    }));
}

function flattenMarketRows(groups = []) {
    return groups.flatMap((group) => ['left', 'right'].flatMap((bucket) => (
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
    return rows.map((row) => ({
        id: row.id,
        code: row.code,
        name: row.name ?? '',
        base_product_code: row.base_product_code ?? 'EBM',
        sort_order: row.sort_order ?? 100,
    }));
}

export default function CotationsIndex({
    manualSettings = {},
    transportGrid = transportDefaultGrid(),
    fuelGrid = fuelDefaultGrid(),
    marketData: initialMarketData = { groups: [], fetched_at: null },
    permissions = {},
    routes = {},
}) {
    const [marketData, setMarketData] = useState(initialMarketData || { groups: [], fetched_at: null });
    const [loading, setLoading] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState('');
    const [isEditing, setIsEditing] = useState(false);
    const canManage = Boolean(permissions?.can_manage);
    const form = useForm({
        settings: flattenSettings(manualSettings),
        manual_prices: flattenMarketRows(initialMarketData?.groups || []),
        custom_cereals: flattenCustomCereals(initialMarketData?.custom_cereals || []),
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
        }
    }, [initialMarketData]);

    useEffect(() => {
        if (!isEditing) {
            form.setData('transport_grid', normalizeTransportGrid(transportGrid));
            form.setData('fuel_grid', normalizeFuelGrid(fuelGrid));
        }
    }, [transportGrid, fuelGrid]);

    const fetchMarketData = async ({ initial = false } = {}) => {
        if (!routes.market_data) return;
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
    }, [routes.market_data]);

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

    const setCustomCereal = (cereal, field, value) => {
        const key = cereal.form_key || cereal.code || cereal.id;
        form.setData('custom_cereals', (form.data.custom_cereals || []).map((item) => (
            (item.form_key || item.code || item.id) === key ? { ...item, [field]: value } : item
        )));
    };

    const addCustomCereal = () => {
        const now = Date.now().toString(36).toUpperCase();
        form.setData('custom_cereals', [
            ...(form.data.custom_cereals || []),
            {
                form_key: `custom-${now}`,
                code: `C${now}`.slice(0, 16),
                name: '',
                base_product_code: 'EBM',
                sort_order: 100 + (form.data.custom_cereals || []).length,
            },
        ]);
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
            transport_grid: normalizeTransportGrid(transportGrid),
            fuel_grid: normalizeFuelGrid(fuelGrid),
            deleted_manual_price_ids: [],
        });
    };

    const startEditing = () => {
        resetDrafts();
        setIsEditing(true);
    };

    const cancelEditing = () => {
        resetDrafts();
        setIsEditing(false);
    };

    const saveSettings = () => {
        form.put(routes.settings_update, {
            preserveScroll: true,
            onSuccess: () => setIsEditing(false),
        });
    };

    const cerealGroups = useMemo(() => {
        const groups = marketData?.groups || [];
        if (isEditing) {
            const existingCodes = new Set(groups.map((group) => group.code));
            const customGroups = (form.data.custom_cereals || [])
                .filter((cereal) => cereal.name && !existingCodes.has(cereal.code))
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

            return [...groups, ...customGroups].sort((left, right) => ((left.sort || 0) - (right.sort || 0)) || String(left.name).localeCompare(String(right.name)));
        }

        return groups.filter((group) => (
            (group?.harvests?.left?.rows || []).length > 0
            || (group?.harvests?.right?.rows || []).length > 0
        ));
    }, [marketData, isEditing, form.data.custom_cereals]);
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
        const rows = isEditing
            ? (form.data.manual_prices || [])
            : flattenMarketRows(marketData?.groups || []);

        return rows
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
                    label: labelParts.join(' - '),
                    final_price: finalPrice,
                };
            })
            .filter(Boolean);
    }, [isEditing, form.data.manual_prices, marketData]);

    const header = (
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 className="text-[22px] leading-none font-black uppercase tracking-[0.06em]">Cotations</h1>
                <p className="mt-1 text-sm text-[var(--app-muted)]">
                    Dernière actualisation serveur : {formatDateTime(marketData?.fetched_at)}
                </p>
            </div>

            <div className="flex flex-wrap gap-2">
                <button
                    type="button"
                    onClick={() => fetchMarketData()}
                    disabled={refreshing}
                    className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--brand-yellow-dark)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em] text-[var(--color-black)] disabled:opacity-60"
                >
                    <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} strokeWidth={2.3} />
                    Actualiser
                </button>
                {canManage && !isEditing ? (
                    <button
                        type="button"
                        onClick={startEditing}
                        className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-[var(--app-border)] bg-[var(--app-surface-soft)] px-3 py-2 text-xs font-black uppercase tracking-[0.1em]"
                    >
                        <Pencil className="h-3.5 w-3.5" strokeWidth={2.3} />
                        Modifier
                    </button>
                ) : null}
            </div>
        </div>
    );

    return (
        <AppLayout title="Cotations" header={header}>
            <Head title="Cotations" />

            <div className="space-y-5">
                {error ? (
                    <div className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                        {error}
                    </div>
                ) : null}

                <HarvestSettings
                    rows={manualSettings.display || []}
                    canManage={isEditing}
                    form={form}
                    setFormSetting={setFormSetting}
                />

                <CustomCerealSection
                    canManage={isEditing}
                    form={form}
                    setCustomCereal={setCustomCereal}
                    addCustomCereal={addCustomCereal}
                />

                {loading ? (
                    <div className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 text-sm text-[var(--app-muted)]">
                        Chargement des cours...
                    </div>
                ) : cerealGroups.length ? (
                    <div className="grid gap-4">
                        {cerealGroups.map((group, index) => (
                            <CerealCard
                                key={`${group.name}-${index}`}
                                group={group}
                                canManage={isEditing}
                                form={form}
                                setManualPrice={setManualPrice}
                                addManualRow={addManualRow}
                                deleteManualRow={deleteManualRow}
                                optionGroup={optionGroupsByCode[group.code]}
                            />
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

                <FuelGridSection
                    grid={form.data.fuel_grid}
                    canManage={isEditing}
                    setFuelGrid={setFuelGrid}
                />

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
