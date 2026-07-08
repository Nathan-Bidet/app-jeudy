import Modal from '@/Components/Modal';
import AppLayout from '@/Layouts/AppLayout';
import TitleCaps from '@/Layouts/AppShell/TitleCaps';
import { Head } from '@inertiajs/react';
import {
    AlertCircle,
    CheckCircle2,
    FileSpreadsheet,
    FileText,
    History,
    Eye,
    Loader2,
    Pencil,
    Plus,
    Search,
    Settings,
    Trash2,
    Upload,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const EXISTING_COLUMN_OPTIONS = [
    { value: '', label: 'Aucune correspondance' },
    { value: 'name', label: 'Nom / raison sociale' },
    { value: 'address', label: 'Adresse' },
    { value: 'postal_code', label: 'Code postal' },
    { value: 'city', label: 'Ville' },
    { value: 'phone', label: 'Téléphone' },
    { value: 'email', label: 'Email' },
    { value: 'reference', label: 'Référence' },
    { value: 'comment', label: 'Commentaire' },
];

const CLEANING_OPTIONS = [
    { key: 'trim_spaces', label: 'Normaliser les espaces' },
    { key: 'empty_na', label: 'Vider les N/A' },
    { key: 'remove_useless_zeroes', label: 'Supprimer les 0 inutiles' },
    { key: 'empty_parasites', label: 'Vider les valeurs parasites' },
];

const FORMAT_OPTIONS = [
    { value: '', label: 'Aucun modèle' },
    { value: 'postal_code_00000', label: 'Code postal 00000' },
    { value: 'phone_fr', label: 'Téléphone FR' },
    { value: 'email', label: 'Email' },
    { value: 'date_fr', label: 'Date jj/mm/aaaa' },
    { value: 'uppercase', label: 'Majuscules' },
];

function classNames(...values) {
    return values.filter(Boolean).join(' ');
}

function formatNumber(value) {
    return new Intl.NumberFormat('fr-FR').format(Number(value || 0));
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('fr-FR', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

function warningCategoryLabel(category) {
    return {
        automatic_correction: 'Corrigé automatiquement',
        ignored: 'Ignoré',
        intervention: 'Intervention utilisateur',
    }[category] || category || '-';
}

function statusBadgeClass(statusLabel = '') {
    if (statusLabel.includes('Échec')) {
        return 'border-red-200 bg-red-50 text-red-700';
    }

    if (statusLabel.includes('avertissements') || statusLabel.includes('Intervention')) {
        return 'border-amber-200 bg-amber-50 text-amber-800';
    }

    if (statusLabel.includes('Succès')) {
        return 'border-green-200 bg-green-50 text-green-700';
    }

    return 'border-blue-200 bg-blue-50 text-blue-700';
}

function httpErrorMessage(error, fallback = "L'import du fichier n'a pas pu être terminé.") {
    const status = error?.response?.status;
    const statusText = error?.response?.statusText;
    const responseErrors = error?.response?.data?.errors || {};
    const serverMessage = error?.response?.data?.message;
    const serverError = error?.response?.data?.error;

    if (serverMessage) {
        return serverMessage;
    }

    if (responseErrors.file?.[0]) {
        return responseErrors.file[0];
    }

    if (responseErrors.columns?.[0]) {
        return responseErrors.columns[0];
    }

    if (serverError?.detail) {
        return serverError.detail;
    }

    if (status) {
        return `Erreur HTTP ${status}${statusText ? ` ${statusText}` : ''} : ${fallback}`;
    }

    return fallback;
}

function importFailureMessage(data) {
    const diagnostics = data.diagnostics || data.error?.diagnostics || data.stats?.diagnostics;
    const baseMessage = data.message || data.error?.detail || "L'import du fichier n'a pas pu être terminé.";

    if (!diagnostics) {
        return baseMessage;
    }

    const details = [];
    if (diagnostics.current_line && diagnostics.total_lines) {
        details.push(`ligne ${diagnostics.current_line} / ${diagnostics.total_lines}`);
    } else if (diagnostics.current_line) {
        details.push(`ligne ${diagnostics.current_line}`);
    }

    if (diagnostics.memory_peak_mb) {
        details.push(`mémoire max ${diagnostics.memory_peak_mb} Mo`);
    }

    if (diagnostics.elapsed_seconds) {
        details.push(`${diagnostics.elapsed_seconds} s`);
    }

    return details.length > 0 ? `${baseMessage} (${details.join(' · ')})` : baseMessage;
}

function ImportProgressNotice({ notice, onClose, onDetail, onReport }) {
    if (!notice) {
        return null;
    }

    const isSuccess = notice.status === 'success';
    const isError = notice.status === 'error';
    const isRunning = notice.status === 'running';
    const Icon = isRunning ? Loader2 : isSuccess ? CheckCircle2 : AlertCircle;

    return (
        <div className="fixed bottom-4 right-4 z-50 w-[calc(100vw-2rem)] max-w-sm rounded-2xl border border-[var(--app-border)] bg-white p-4 shadow-xl">
            <div className="flex items-start gap-3">
                <span className={classNames(
                    'mt-0.5 inline-flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full',
                    isSuccess && 'bg-green-50 text-green-700',
                    isError && 'bg-amber-50 text-amber-700',
                    isRunning && 'bg-blue-50 text-blue-700',
                )}>
                    <Icon className={classNames('h-4 w-4', isRunning && 'animate-spin')} strokeWidth={2.2} />
                </span>

                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-3">
                        <p className="text-sm font-semibold text-gray-900">{notice.title}</p>
                        {!isRunning && (
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded-full p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                aria-label="Fermer"
                            >
                                <X className="h-4 w-4" strokeWidth={2.2} />
                            </button>
                        )}
                    </div>
                    <p className="mt-1 whitespace-pre-line text-sm text-gray-600">{notice.message}</p>
                    {notice.reportJobId && (
                        <button
                            type="button"
                            onClick={() => onReport(notice.reportJobId)}
                            className="mt-3 inline-flex items-center rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                        >
                            Voir le rapport
                        </button>
                    )}
                    {notice.errorContext?.row && (
                        <button
                            type="button"
                            onClick={onDetail}
                            className="mt-3 inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-100"
                        >
                            Détail
                        </button>
                    )}

                    <div className="mt-3">
                        <div className="h-2 overflow-hidden rounded-full bg-gray-100">
                            <div
                                className={classNames(
                                    'h-full rounded-full transition-all',
                                    isSuccess && 'bg-green-600',
                                    isError && 'bg-amber-500',
                                    isRunning && 'bg-blue-600',
                                )}
                                style={{ width: `${notice.progress}%` }}
                            />
                        </div>
                        <p className="mt-1 text-right text-xs font-semibold text-gray-500">
                            {notice.progress} %
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

function buildInitialColumnConfig(columns = []) {
    return columns.map((column) => ({
        index: column.index,
        source_name: column.source_name,
        import: true,
        application_name: column.suggested_name || column.source_name || '',
        existing_column: '',
        clean_values: true,
        cleaning_rules: ['trim_spaces', 'empty_na', 'remove_useless_zeroes', 'empty_parasites'],
        format_model: '',
    }));
}

function ImportModal({ open, onClose, onNotice, onImportStarted }) {
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [columnsConfig, setColumnsConfig] = useState([]);
    const [identificationColumn, setIdentificationColumn] = useState('');
    const [referenceColumn, setReferenceColumn] = useState('');
    const [errors, setErrors] = useState({});
    const [reading, setReading] = useState(false);
    const [importing, setImporting] = useState(false);

    const importedColumns = useMemo(
        () => columnsConfig.filter((column) => column.import),
        [columnsConfig],
    );

    const reset = () => {
        setFile(null);
        setPreview(null);
        setColumnsConfig([]);
        setIdentificationColumn('');
        setReferenceColumn('');
        setErrors({});
        setReading(false);
        setImporting(false);
    };

    const close = () => {
        if (reading || importing) {
            return;
        }

        reset();
        onClose();
    };

    const updateColumn = (index, patch) => {
        setColumnsConfig((current) => current.map((column) => (
            column.index === index ? { ...column, ...patch } : column
        )));
    };

    const toggleCleaningRule = (columnIndex, ruleKey) => {
        setColumnsConfig((current) => current.map((column) => {
            if (column.index !== columnIndex) {
                return column;
            }

            const currentRules = new Set(column.cleaning_rules || []);
            if (currentRules.has(ruleKey)) {
                currentRules.delete(ruleKey);
            } else {
                currentRules.add(ruleKey);
            }

            return {
                ...column,
                cleaning_rules: Array.from(currentRules),
            };
        }));
    };

    const readHeader = async () => {
        if (!file) {
            setErrors({ file: 'Sélectionnez un fichier Excel avant de lire l’en-tête.' });
            return;
        }

        setReading(true);
        setErrors({});
        onNotice({
            status: 'running',
            title: 'Lecture du fichier',
            message: "Lecture de l'en-tête et préparation de l'aperçu.",
            progress: 35,
        });

        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await window.axios.post(route('task.tiers.preview-header'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            const data = response.data;

            setPreview(data);
            setColumnsConfig(buildInitialColumnConfig(data.columns || []));
            setIdentificationColumn(data.columns?.[0] ? String(data.columns[0].index) : '');
            setReferenceColumn(data.columns?.[0] ? String(data.columns[0].index) : '');
            onNotice({
                status: 'success',
                title: 'En-tête lu',
                message: `${data.columns?.length || 0} colonne(s) détectée(s).`,
                progress: 100,
            });
        } catch (error) {
            const responseErrors = error?.response?.data?.errors || {};
            setErrors(responseErrors);
            onNotice({
                status: 'error',
                title: 'Lecture impossible',
                message: httpErrorMessage(error, "L'en-tête du fichier n'a pas pu être lu."),
                progress: 0,
            });
        } finally {
            setReading(false);
        }
    };

    const importFile = async () => {
        if (!preview || columnsConfig.length === 0) {
            return;
        }

        if (!file) {
            setErrors({ file: 'Sélectionnez un fichier Excel avant de lancer l’import.' });
            return;
        }

        setImporting(true);
        setErrors({});
        onClose();
        onNotice({
            status: 'running',
            title: "Préparation de l'import",
            message: 'Validation du fichier et de la configuration.',
            progress: 10,
        });

        const formData = new FormData();
        formData.append('file', file);
        formData.append('original_filename', preview.file?.name || file.name);
        formData.append('columns', JSON.stringify(columnsConfig));
        formData.append('identification_column', identificationColumn || '');
        formData.append('reference_column', referenceColumn || '');

        try {
            const response = await window.axios.post(route('task.tiers.import'), formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            const data = response.data || {};

            onNotice({
                status: 'running',
                title: 'Import en attente',
                message: data.message || "L'import a été placé dans la file de traitement.",
                progress: data.progress || 0,
            });
            onImportStarted(data.import_job_id);
            reset();
            onClose();
        } catch (error) {
            const responseErrors = error?.response?.data?.errors || {};
            setErrors(responseErrors);
            onNotice({
                status: 'error',
                title: "Lancement de l'import impossible",
                message: httpErrorMessage(error),
                progress: 0,
            });
        } finally {
            setImporting(false);
        }
    };

    return (
        <Modal show={open} onClose={close} maxWidth="2xl" closeable={!reading && !importing}>
            <div className="border-b border-gray-100 px-5 py-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-black uppercase tracking-[0.06em] text-gray-900">
                            Importer un fichier Excel
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            Préparation de l'import uniquement : lecture de l'en-tête, mapping et règles.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={close}
                        disabled={reading || importing}
                        className="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50"
                        aria-label="Fermer"
                    >
                        <X className="h-5 w-5" strokeWidth={2.2} />
                    </button>
                </div>
            </div>

            <div className="max-h-[calc(100vh-12rem)] space-y-5 overflow-y-auto px-5 py-5">
                <section className="rounded-2xl border border-gray-200 p-4">
                    <div className="flex items-center gap-2">
                        <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">
                            1
                        </span>
                        <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">Fichier</h3>
                    </div>

                    <div className="mt-4 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                        <label className="block">
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Fichier Excel
                            </span>
                            <input
                                type="file"
                                accept=".xlsx,.xls,.csv,.ods"
                                onChange={(event) => {
                                    setFile(event.target.files?.[0] || null);
                                    setPreview(null);
                                    setColumnsConfig([]);
                                    setErrors({});
                                }}
                                className="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700"
                            />
                        </label>

                        <button
                            type="button"
                            onClick={readHeader}
                            disabled={reading || !file}
                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-black disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {reading ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSpreadsheet className="h-4 w-4" />}
                            Lire l’en-tête
                        </button>
                    </div>

                    {errors.file && <p className="mt-2 text-sm text-red-600">{errors.file[0]}</p>}
                </section>

                {preview && (
                    <>
                        <section className="rounded-2xl border border-gray-200 p-4">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">
                                    2
                                </span>
                                <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">
                                    Colonnes détectées
                                </h3>
                            </div>

                            <div className="mt-4 space-y-4">
                                {columnsConfig.map((column) => (
                                    <div key={column.index} className="rounded-xl border border-gray-100 bg-gray-50 p-3">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <p className="text-sm font-semibold text-gray-900">
                                                    {column.source_name}
                                                </p>
                                                <p className="text-xs text-gray-500">Colonne {column.index + 1}</p>
                                            </div>

                                            <label className="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                                                <input
                                                    type="checkbox"
                                                    checked={column.import}
                                                    onChange={(event) => updateColumn(column.index, { import: event.target.checked })}
                                                    className="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                                />
                                                Importer cette colonne
                                            </label>
                                        </div>

                                        <div className="mt-3 grid gap-3 md:grid-cols-3">
                                            <label className="block">
                                                <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Nom dans l’application
                                                </span>
                                                <input
                                                    type="text"
                                                    value={column.application_name}
                                                    onChange={(event) => updateColumn(column.index, { application_name: event.target.value })}
                                                    disabled={!column.import}
                                                    className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                />
                                            </label>

                                            <label className="block">
                                                <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Colonne existante
                                                </span>
                                                <select
                                                    value={column.existing_column}
                                                    onChange={(event) => updateColumn(column.index, { existing_column: event.target.value })}
                                                    disabled={!column.import}
                                                    className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                >
                                                    {EXISTING_COLUMN_OPTIONS.map((option) => (
                                                        <option key={option.value} value={option.value}>{option.label}</option>
                                                    ))}
                                                </select>
                                            </label>

                                            <label className="block">
                                                <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    Modèle / format
                                                </span>
                                                <select
                                                    value={column.format_model}
                                                    onChange={(event) => updateColumn(column.index, { format_model: event.target.value })}
                                                    disabled={!column.import}
                                                    className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100 disabled:text-gray-400"
                                                >
                                                    {FORMAT_OPTIONS.map((option) => (
                                                        <option key={option.value} value={option.value}>{option.label}</option>
                                                    ))}
                                                </select>
                                            </label>
                                        </div>

                                        <div className="mt-3">
                                            <label className="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                                                <input
                                                    type="checkbox"
                                                    checked={column.clean_values}
                                                    onChange={(event) => updateColumn(column.index, { clean_values: event.target.checked })}
                                                    disabled={!column.import}
                                                    className="rounded border-gray-300 text-gray-900 focus:ring-gray-900 disabled:opacity-50"
                                                />
                                                Nettoyer les valeurs
                                            </label>

                                            {column.clean_values && column.import && (
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {CLEANING_OPTIONS.map((option) => (
                                                        <label
                                                            key={option.key}
                                                            className="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-semibold text-gray-700"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={(column.cleaning_rules || []).includes(option.key)}
                                                                onChange={() => toggleCleaningRule(column.index, option.key)}
                                                                className="rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                                            />
                                                            {option.label}
                                                        </label>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>

                        <section className="rounded-2xl border border-gray-200 p-4">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">
                                    3
                                </span>
                                <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">
                                    Identification des lignes
                                </h3>
                            </div>

                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                                <label className="block">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Colonne d’identification principale
                                    </span>
                                    <select
                                        value={identificationColumn}
                                        onChange={(event) => setIdentificationColumn(event.target.value)}
                                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"
                                    >
                                        <option value="">Aucune pour le moment</option>
                                        {importedColumns.map((column) => (
                                            <option key={column.index} value={String(column.index)}>{column.source_name}</option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Cette colonne pourra contenir des doublons.
                                    </p>
                                </label>

                                <label className="block">
                                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Colonne de référence prochains imports
                                    </span>
                                    <select
                                        value={referenceColumn}
                                        onChange={(event) => setReferenceColumn(event.target.value)}
                                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"
                                    >
                                        <option value="">Aucune pour le moment</option>
                                        {importedColumns.map((column) => (
                                            <option key={column.index} value={String(column.index)}>{column.source_name}</option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-xs text-gray-500">
                                        Cette référence servira plus tard pour comparer avec les données déjà en base.
                                    </p>
                                </label>
                            </div>
                        </section>

                        <section className="rounded-2xl border border-gray-200 p-4">
                            <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">
                                Aperçu des premières lignes
                            </h3>

                            <div className="mt-3 overflow-x-auto rounded-xl border border-gray-100">
                                <table className="min-w-full divide-y divide-gray-100 text-left text-xs">
                                    <thead className="bg-gray-50 text-gray-500">
                                        <tr>
                                            {(preview.columns || []).map((column) => (
                                                <th key={column.index} className="whitespace-nowrap px-3 py-2 font-semibold">
                                                    {column.source_name}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100 bg-white">
                                        {(preview.preview || []).map((row, rowIndex) => (
                                            <tr key={`row-${rowIndex}`}>
                                                {row.map((cell, cellIndex) => (
                                                    <td key={`cell-${rowIndex}-${cellIndex}`} className="whitespace-nowrap px-3 py-2 text-gray-700">
                                                        {cell || <span className="text-gray-300">-</span>}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </>
                )}
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={close}
                    disabled={reading || importing}
                    className="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={importFile}
                    disabled={!preview || importing || reading}
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--app-primary)] px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {importing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                    Importer
                </button>
            </div>
        </Modal>
    );
}

function ImportErrorDetailModal({ state, onClose, onResolve }) {
    const [values, setValues] = useState([]);
    const [processingAction, setProcessingAction] = useState(null);

    useEffect(() => {
        setValues((state?.context?.values || []).map((item) => ({
            index: item.index,
            label: item.label,
            value: item.value ?? '',
        })));
    }, [state]);

    if (!state?.context?.row) {
        return null;
    }

    const updateValue = (index, value) => {
        setValues((current) => current.map((item) => (
            item.index === index ? { ...item, value } : item
        )));
    };

    const submit = async (action) => {
        setProcessingAction(action);
        await onResolve(action, values);
        setProcessingAction(null);
    };

    return (
        <Modal show={Boolean(state?.context?.row)} onClose={onClose} maxWidth="2xl" closeable={!processingAction}>
            <div className="border-b border-gray-100 px-5 py-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-black uppercase tracking-[0.06em] text-gray-900">
                            Détail erreur import
                        </h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Ligne {state.context.row}
                            {state.error?.column ? ` — colonne ${state.error.column}` : ''}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={Boolean(processingAction)}
                        className="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50"
                        aria-label="Fermer"
                    >
                        <X className="h-5 w-5" strokeWidth={2.2} />
                    </button>
                </div>
            </div>

            <div className="max-h-[calc(100vh-12rem)] space-y-5 overflow-y-auto px-5 py-5">
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-semibold">Message d’erreur</p>
                    <p className="mt-1">{state.error?.detail || 'Erreur non détaillée.'}</p>
                </div>

                <section>
                    <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">
                        Valeurs de la ligne
                    </h3>
                    <div className="mt-3 grid gap-3 md:grid-cols-2">
                        {values.map((item) => (
                            <label key={item.index} className="block">
                                <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {item.label}
                                </span>
                                <input
                                    type="text"
                                    value={item.value}
                                    onChange={(event) => updateValue(item.index, event.target.value)}
                                    disabled={Boolean(processingAction)}
                                    className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100"
                                />
                            </label>
                        ))}
                    </div>
                </section>
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={() => submit('skip')}
                    disabled={Boolean(processingAction)}
                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                >
                    {processingAction === 'skip' && <Loader2 className="h-4 w-4 animate-spin" />}
                    Passer
                </button>
                <button
                    type="button"
                    onClick={() => submit('import')}
                    disabled={Boolean(processingAction)}
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--app-primary)] px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:brightness-95 disabled:opacity-50"
                >
                    {processingAction === 'import' ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                    Importer
                </button>
            </div>
        </Modal>
    );
}

function ImportReportModal({ reportState, onClose }) {
    const report = reportState?.report;
    const importItem = reportState?.import;

    if (!report) {
        return null;
    }

    const summary = report.summary || {};
    const technical = report.technical || {};
    const warnings = report.warnings || [];
    const warningGroups = [
        ['automatic_correction', 'Corrigés automatiquement'],
        ['ignored', 'Ignorés'],
        ['intervention', 'Intervention utilisateur'],
    ];

    return (
        <Modal show={Boolean(report)} onClose={onClose} maxWidth="5xl">
            <div className="border-b border-gray-100 px-5 py-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-black uppercase tracking-[0.06em] text-gray-900">
                            Rapport d’import
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            {formatDateTime(report.imported_at || report.generated_at || importItem?.date)} · {report.file?.name || importItem?.file || '-'}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                        aria-label="Fermer"
                    >
                        <X className="h-5 w-5" strokeWidth={2.2} />
                    </button>
                </div>
            </div>

            <div className="max-h-[calc(100vh-10rem)] space-y-5 overflow-y-auto px-5 py-5">
                <div className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Statut</p>
                        <span className={classNames('mt-2 inline-flex rounded-full border px-3 py-1 text-xs font-bold', statusBadgeClass(report.status_label))}>
                            {report.status_label || '-'}
                        </span>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Utilisateur</p>
                        <p className="mt-2 text-sm font-semibold text-gray-900">{report.user?.name || importItem?.user || '-'}</p>
                    </div>
                    <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Durée</p>
                        <p className="mt-2 text-sm font-semibold text-gray-900">{technical.duration_human || '-'}</p>
                    </div>
                </div>

                <section>
                    <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">Résumé</h3>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            ['Lignes analysées', summary.analyzed_rows],
                            ['Lignes importées', summary.imported_rows],
                            ['Lignes mises à jour', summary.updated_rows],
                            ['Lignes ignorées', summary.ignored_rows],
                            ['Doublons détectés', summary.duplicates_count],
                            ['Corrections auto', summary.automatic_corrections],
                            ['Erreurs', summary.errors_count],
                            ['Lignes vides', summary.empty_rows],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-gray-100 bg-white p-3 shadow-sm">
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{label}</p>
                                <p className="mt-1 text-xl font-black text-gray-900">{formatNumber(value)}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <section>
                    <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">Statistiques techniques</h3>
                    <div className="mt-3 grid gap-3 md:grid-cols-4">
                        {[
                            ['Lignes / seconde', technical.average_rows_per_second],
                            ['Lecture fichier', technical.file_read_seconds ? `${technical.file_read_seconds} s` : null],
                            ['Nettoyage', technical.cleaning_seconds ? `${technical.cleaning_seconds} s` : null],
                            ['Insertion SQL', technical.insert_seconds ? `${technical.insert_seconds} s` : null],
                            ['Dernière ligne', technical.last_processed_line],
                            ['Mémoire max', technical.memory_peak_mb ? `${technical.memory_peak_mb} Mo` : null],
                            ['Lots traités', technical.batches_processed],
                            ['Taille des lots', technical.batch_size],
                        ].map(([label, value]) => (
                            <div key={label} className="rounded-xl border border-gray-100 bg-gray-50 p-3">
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{label}</p>
                                <p className="mt-1 text-sm font-bold text-gray-900">{value || '-'}</p>
                            </div>
                        ))}
                    </div>
                </section>

                <section>
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">Avertissements</h3>
                        <p className="text-xs font-semibold text-gray-500">{formatNumber(warnings.length)} élément(s)</p>
                    </div>

                    {warningGroups.map(([category, title]) => {
                        const items = warnings.filter((warning) => warning.category === category);

                        if (items.length === 0) {
                            return null;
                        }

                        return (
                            <details key={category} className="mt-3 rounded-xl border border-gray-200 bg-white" open={category === 'intervention'}>
                                <summary className="cursor-pointer px-4 py-3 text-sm font-bold text-gray-900">
                                    {title} · {formatNumber(items.length)}
                                </summary>
                                <div className="overflow-x-auto border-t border-gray-100">
                                    <table className="min-w-full divide-y divide-gray-100 text-left text-xs">
                                        <thead className="bg-gray-50 text-gray-500">
                                            <tr>
                                                <th className="whitespace-nowrap px-3 py-2 font-semibold">Ligne</th>
                                                <th className="whitespace-nowrap px-3 py-2 font-semibold">Colonne</th>
                                                <th className="whitespace-nowrap px-3 py-2 font-semibold">Valeur d’origine</th>
                                                <th className="whitespace-nowrap px-3 py-2 font-semibold">Valeur corrigée</th>
                                                <th className="whitespace-nowrap px-3 py-2 font-semibold">Type</th>
                                                <th className="whitespace-nowrap px-3 py-2 font-semibold">Action</th>
                                                <th className="px-3 py-2 font-semibold">Message</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-100">
                                            {items.map((warning, index) => (
                                                <tr key={`${category}-${warning.row}-${index}`}>
                                                    <td className="whitespace-nowrap px-3 py-2 font-semibold text-gray-900">{warning.row || '-'}</td>
                                                    <td className="whitespace-nowrap px-3 py-2 text-gray-700">{warning.column || '-'}</td>
                                                    <td className="whitespace-nowrap px-3 py-2 text-gray-700">{warning.original_value ?? '-'}</td>
                                                    <td className="whitespace-nowrap px-3 py-2 text-gray-700">{warning.corrected_value ?? '-'}</td>
                                                    <td className="whitespace-nowrap px-3 py-2 text-gray-700">{warningCategoryLabel(warning.category)}</td>
                                                    <td className="whitespace-nowrap px-3 py-2 text-gray-700">{warning.action || '-'}</td>
                                                    <td className="min-w-[16rem] px-3 py-2 text-gray-700">{warning.message || '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        );
                    })}

                    {warnings.length === 0 && (
                        <p className="mt-3 rounded-xl border border-gray-100 bg-gray-50 p-4 text-sm text-gray-500">
                            Aucun avertissement enregistré pour cet import.
                        </p>
                    )}
                </section>
            </div>
        </Modal>
    );
}

function TiersRowModal({ state, columns, processing, onClose, onSubmit }) {
    const [values, setValues] = useState({});

    useEffect(() => {
        setValues(state?.values || {});
    }, [state]);

    if (!state) {
        return null;
    }

    const updateValue = (key, value) => {
        setValues((current) => ({ ...current, [key]: value }));
    };

    return (
        <Modal show={Boolean(state)} onClose={onClose} maxWidth="3xl" closeable={!processing}>
            <div className="border-b border-gray-100 px-5 py-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-black uppercase tracking-[0.06em] text-gray-900">
                            {state.mode === 'create' ? 'Ajouter une ligne' : 'Modifier la ligne'}
                        </h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={processing}
                        className="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50"
                        aria-label="Fermer"
                    >
                        <X className="h-5 w-5" strokeWidth={2.2} />
                    </button>
                </div>
            </div>

            <div className="max-h-[calc(100vh-12rem)] overflow-y-auto px-5 py-5">
                <div className="grid gap-3 md:grid-cols-2">
                    {columns.map((column) => (
                        <label key={column.key} className="block">
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {column.label}
                            </span>
                            <input
                                type="text"
                                value={values[column.key] ?? ''}
                                onChange={(event) => updateValue(column.key, event.target.value)}
                                disabled={processing}
                                className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100"
                            />
                        </label>
                    ))}
                </div>
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onClose}
                    disabled={processing}
                    className="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={() => onSubmit(values)}
                    disabled={processing}
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--app-primary)] px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:brightness-95 disabled:opacity-50"
                >
                    {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                    Enregistrer
                </button>
            </div>
        </Modal>
    );
}

function TiersColumnsModal({ open, columns, processing, onClose, onSaveColumn, onDeleteColumn }) {
    const [editingKey, setEditingKey] = useState('');
    const [label, setLabel] = useState('');
    const [format, setFormat] = useState('');

    const startCreate = () => {
        setEditingKey('');
        setLabel('');
        setFormat('');
    };

    const startEdit = (column) => {
        setEditingKey(column.key);
        setLabel(column.label || '');
        setFormat(column.format || '');
    };

    const submit = async () => {
        await onSaveColumn({
            key: editingKey,
            label,
            format,
        });
        startCreate();
    };

    return (
        <Modal show={open} onClose={onClose} maxWidth="4xl" closeable={!processing}>
            <div className="border-b border-gray-100 px-5 py-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2 className="text-lg font-black uppercase tracking-[0.06em] text-gray-900">
                            Gérer les colonnes
                        </h2>
                        <p className="mt-1 text-sm text-gray-500">
                            La suppression d’une colonne supprime les données associées.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={processing}
                        className="rounded-full p-2 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50"
                        aria-label="Fermer"
                    >
                        <X className="h-5 w-5" strokeWidth={2.2} />
                    </button>
                </div>
            </div>

            <div className="max-h-[calc(100vh-12rem)] space-y-5 overflow-y-auto px-5 py-5">
                <section className="rounded-xl border border-gray-200 p-4">
                    <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">
                        {editingKey ? 'Modifier la colonne' : 'Nouvelle colonne'}
                    </h3>
                    <div className="mt-3 grid gap-3 md:grid-cols-[1fr_220px_auto] md:items-end">
                        <label className="block">
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">Nom</span>
                            <input
                                type="text"
                                value={label}
                                onChange={(event) => setLabel(event.target.value)}
                                disabled={processing}
                                className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100"
                            />
                        </label>
                        <label className="block">
                            <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">Type / format</span>
                            <select
                                value={format}
                                onChange={(event) => setFormat(event.target.value)}
                                disabled={processing}
                                className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100"
                            >
                                {FORMAT_OPTIONS.map((option) => (
                                    <option key={option.value} value={option.value}>{option.label}</option>
                                ))}
                            </select>
                        </label>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={submit}
                                disabled={processing || label.trim() === ''}
                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--app-primary)] px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:brightness-95 disabled:opacity-50"
                            >
                                {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                                {editingKey ? 'Renommer' : 'Créer'}
                            </button>
                            {editingKey && (
                                <button
                                    type="button"
                                    onClick={startCreate}
                                    disabled={processing}
                                    className="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Nouveau
                                </button>
                            )}
                        </div>
                    </div>
                </section>

                <section>
                    <h3 className="text-sm font-black uppercase tracking-wide text-gray-900">Colonnes existantes</h3>
                    <div className="mt-3 overflow-x-auto rounded-xl border border-gray-100">
                        <table className="min-w-full divide-y divide-gray-100 text-left text-sm">
                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">Nom</th>
                                    <th className="px-4 py-3 font-semibold">Clé</th>
                                    <th className="px-4 py-3 font-semibold">Format</th>
                                    <th className="px-4 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 bg-white">
                                {columns.map((column) => (
                                    <tr key={column.key}>
                                        <td className="whitespace-nowrap px-4 py-3 font-semibold text-gray-900">{column.label}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-500">{column.key}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-700">{column.format || '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => startEdit(column)}
                                                disabled={processing}
                                                className="mr-2 inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                                Modifier
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => onDeleteColumn(column)}
                                                disabled={processing}
                                                className="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-50"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                                Supprimer
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </Modal>
    );
}

function DeleteTiersDataModal({ open, processing, onClose, onConfirm }) {
    const [confirmation, setConfirmation] = useState('');
    const isConfirmed = confirmation.trim().toUpperCase() === 'SUPPRIMER';

    useEffect(() => {
        if (open) {
            setConfirmation('');
        }
    }, [open]);

    return (
        <Modal show={open} onClose={onClose} maxWidth="lg" closeable={!processing}>
            <div className="border-b border-red-100 px-5 py-4">
                <div className="flex items-start gap-3">
                    <span className="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-red-50 text-red-700">
                        <AlertCircle className="h-5 w-5" strokeWidth={2.2} />
                    </span>
                    <div>
                        <h2 className="text-lg font-black uppercase tracking-[0.06em] text-gray-900">
                            Supprimer les données Tiers
                        </h2>
                        <p className="mt-1 text-sm text-gray-600">
                            Cette action supprimera toutes les lignes et toutes les colonnes Tiers. Cette action est irréversible.
                        </p>
                    </div>
                </div>
            </div>

            <div className="space-y-4 px-5 py-5">
                <p className="text-sm text-gray-700">
                    L’historique des imports, les rapports et les logs admin seront conservés.
                </p>
                <label className="block">
                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                        Tapez SUPPRIMER pour confirmer
                    </span>
                    <input
                        type="text"
                        value={confirmation}
                        onChange={(event) => setConfirmation(event.target.value)}
                        disabled={processing}
                        className="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm disabled:bg-gray-100"
                    />
                </label>
            </div>

            <div className="flex flex-col-reverse gap-2 border-t border-gray-100 px-5 py-4 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onClose}
                    disabled={processing}
                    className="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                >
                    Annuler
                </button>
                <button
                    type="button"
                    onClick={onConfirm}
                    disabled={processing || !isConfirmed}
                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {processing && <Loader2 className="h-4 w-4 animate-spin" />}
                    Supprimer définitivement
                </button>
            </div>
        </Modal>
    );
}

function ImportedDataTable({
    tableState,
    loading,
    canUpdate,
    searchTerm,
    onSearchChange,
    onPageChange,
    onRefresh,
    onAddRow,
    onEditRow,
    onDeleteRow,
    onManageColumns,
}) {
    const columns = tableState.columns || [];
    const rows = tableState.rows || [];
    const pagination = tableState.pagination || {
        current_page: 1,
        last_page: 1,
        total: 0,
        from: null,
        to: null,
    };
    const hasRows = rows.length > 0;

    return (
        <section className="rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-sm">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-3">
                    <span className="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)]">
                        <FileSpreadsheet className="h-5 w-5" strokeWidth={2.2} />
                    </span>
                    <div>
                        <h2 className="text-sm font-black uppercase tracking-wide text-[var(--app-ink)]">
                            Données importées
                        </h2>
                        <p className="mt-1 text-sm text-[var(--app-muted)]">
                            {pagination.total > 0
                                ? `${formatNumber(pagination.from)} à ${formatNumber(pagination.to)} sur ${formatNumber(pagination.total)} lignes`
                                : 'Aucune donnée importée pour le moment.'}
                        </p>
                    </div>
                </div>
                <div className="flex flex-1 flex-wrap items-center justify-end gap-2">
                    <div className="relative min-w-[220px] flex-1 sm:max-w-xs">
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" strokeWidth={2.2} />
                        <input
                            type="search"
                            value={searchTerm}
                            onChange={(event) => onSearchChange(event.target.value)}
                            placeholder="Rechercher..."
                            className="h-10 w-full rounded-xl border border-gray-200 bg-white pl-9 pr-9 text-sm font-semibold text-gray-700 placeholder:text-gray-400 focus:border-[var(--app-primary)] focus:ring-[var(--app-primary)]"
                        />
                        {searchTerm !== '' && (
                            <button
                                type="button"
                                onClick={() => onSearchChange('')}
                                className="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                                aria-label="Effacer la recherche"
                            >
                                <X className="h-3.5 w-3.5" strokeWidth={2.2} />
                            </button>
                        )}
                    </div>
                    {canUpdate && (
                        <>
                            <button
                                type="button"
                                onClick={onManageColumns}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                            >
                                <Settings className="h-4 w-4" />
                                Gérer les colonnes
                            </button>
                            <button
                                type="button"
                                onClick={onAddRow}
                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-[var(--app-primary)] px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:brightness-95"
                            >
                                <Plus className="h-4 w-4" />
                                Ajouter une ligne
                            </button>
                        </>
                    )}
                    <button
                        type="button"
                        onClick={onRefresh}
                        disabled={loading}
                        className="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                    >
                        {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <FileSpreadsheet className="h-4 w-4" />}
                        Actualiser
                    </button>
                </div>
            </div>

            <div className="mt-4 overflow-x-auto rounded-xl border border-gray-100">
                <table className="min-w-full divide-y divide-gray-100 text-left text-sm">
                    <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            {columns.map((column) => (
                                <th key={column.key} className="whitespace-nowrap px-4 py-3 font-semibold">
                                    {column.label}
                                </th>
                            ))}
                            {canUpdate && (
                                <th className="sticky right-0 whitespace-nowrap bg-gray-50 px-4 py-3 text-right font-semibold">
                                    Actions
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 bg-white">
                        {rows.map((row) => (
                            <tr key={row.id}>
                                {columns.map((column) => (
                                    <td key={`${row.id}-${column.key}`} className="whitespace-nowrap px-4 py-3 text-gray-700">
                                        {row.values?.[column.key] !== '' && row.values?.[column.key] !== null && row.values?.[column.key] !== undefined
                                            ? String(row.values[column.key])
                                            : <span className="text-gray-300">-</span>}
                                    </td>
                                ))}
                                {canUpdate && (
                                    <td className="sticky right-0 whitespace-nowrap bg-white px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            onClick={() => onEditRow(row)}
                                            className="mr-2 inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                        >
                                            <Pencil className="h-3.5 w-3.5" />
                                            Modifier
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => onDeleteRow(row)}
                                            className="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-100"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                            Supprimer
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}

                        {!hasRows && (
                            <tr>
                                <td colSpan={Math.max(columns.length + (canUpdate ? 1 : 0), 1)} className="px-4 py-8 text-center text-sm text-gray-500">
                                    {loading
                                        ? 'Chargement des données...'
                                        : searchTerm.trim() !== ''
                                            ? 'Aucune donnée ne correspond à votre recherche.'
                                            : 'Aucune ligne importée.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm font-semibold text-gray-500">
                    Page {formatNumber(pagination.current_page)} / {formatNumber(pagination.last_page || 1)}
                </p>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => onPageChange(Math.max(1, pagination.current_page - 1))}
                        disabled={loading || pagination.current_page <= 1}
                        className="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Précédente
                    </button>
                    <button
                        type="button"
                        onClick={() => onPageChange(Math.min(pagination.last_page || 1, pagination.current_page + 1))}
                        disabled={loading || pagination.current_page >= (pagination.last_page || 1)}
                        className="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Suivante
                    </button>
                </div>
            </div>
        </section>
    );
}

export default function TaskTiersIndex({ permissions = {} }) {
    const initialRecordsSearch = useMemo(() => {
        if (typeof window === 'undefined') {
            return '';
        }

        return new URLSearchParams(window.location.search).get('search') || '';
    }, []);
    const [notice, setNotice] = useState(null);
    const [importModalOpen, setImportModalOpen] = useState(false);
    const [pausedImport, setPausedImport] = useState(null);
    const [errorDetailOpen, setErrorDetailOpen] = useState(false);
    const [activeImportJobId, setActiveImportJobId] = useState(null);
    const [importHistory, setImportHistory] = useState([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [reportState, setReportState] = useState(null);
    const [recordsPage, setRecordsPage] = useState(1);
    const [recordsSearch, setRecordsSearch] = useState(initialRecordsSearch);
    const [debouncedRecordsSearch, setDebouncedRecordsSearch] = useState(initialRecordsSearch.trim());
    const [recordsLoading, setRecordsLoading] = useState(false);
    const [rowModalState, setRowModalState] = useState(null);
    const [columnsModalOpen, setColumnsModalOpen] = useState(false);
    const [deleteDataModalOpen, setDeleteDataModalOpen] = useState(false);
    const [mutationProcessing, setMutationProcessing] = useState(false);
    const [recordsState, setRecordsState] = useState({
        columns: [],
        rows: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            total: 0,
            from: null,
            to: null,
        },
    });

    const loadRecords = async (page = recordsPage, search = debouncedRecordsSearch) => {
        setRecordsLoading(true);
        try {
            const response = await window.axios.get(route('task.tiers.records'), {
                params: { page, search },
            });
            setRecordsState(response.data || {
                columns: [],
                rows: [],
                pagination: {
                    current_page: page,
                    last_page: 1,
                    total: 0,
                    from: null,
                    to: null,
                },
            });
            setRecordsPage(response.data?.pagination?.current_page || page);
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Données indisponibles',
                message: httpErrorMessage(error, "Les données importées n'ont pas pu être chargées."),
                progress: 0,
            });
        } finally {
            setRecordsLoading(false);
        }
    };

    const loadImportHistory = async () => {
        if (!permissions.can_import) {
            return;
        }

        setHistoryLoading(true);
        try {
            const response = await window.axios.get(route('task.tiers.import.history'));
            setImportHistory(response.data?.imports || []);
        } catch {
            setImportHistory([]);
        } finally {
            setHistoryLoading(false);
        }
    };

    const openImportReport = async (jobId) => {
        if (!jobId) {
            return;
        }

        try {
            const response = await window.axios.get(route('task.tiers.import.report', jobId));
            setReportState(response.data || null);
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Rapport indisponible',
                message: httpErrorMessage(error, "Le rapport d'import n'a pas pu être récupéré."),
                progress: 0,
            });
        }
    };

    const openCreateRow = () => {
        const values = {};
        (recordsState.columns || []).forEach((column) => {
            values[column.key] = '';
        });
        setRowModalState({ mode: 'create', values });
    };

    const openEditRow = (row) => {
        setRowModalState({
            mode: 'edit',
            id: row.id,
            values: row.values || {},
        });
    };

    const submitRow = async (values) => {
        if (!rowModalState) {
            return;
        }

        setMutationProcessing(true);
        try {
            if (rowModalState.mode === 'create') {
                await window.axios.post(route('task.tiers.records.store'), { values });
            } else {
                await window.axios.put(route('task.tiers.records.update', rowModalState.id), { values });
            }

            setRowModalState(null);
            await loadRecords(recordsPage);
            setNotice({
                status: 'success',
                title: rowModalState.mode === 'create' ? 'Ligne créée' : 'Ligne mise à jour',
                message: 'Les données ont été enregistrées.',
                progress: 100,
            });
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Enregistrement impossible',
                message: httpErrorMessage(error, "La ligne n'a pas pu être enregistrée."),
                progress: 0,
            });
        } finally {
            setMutationProcessing(false);
        }
    };

    const deleteRow = async (row) => {
        if (!window.confirm('Supprimer cette ligne ? Cette action est définitive.')) {
            return;
        }

        setMutationProcessing(true);
        try {
            await window.axios.delete(route('task.tiers.records.destroy', row.id));
            await loadRecords(recordsPage);
            setNotice({
                status: 'success',
                title: 'Ligne supprimée',
                message: 'La ligne a été supprimée.',
                progress: 100,
            });
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Suppression impossible',
                message: httpErrorMessage(error, "La ligne n'a pas pu être supprimée."),
                progress: 0,
            });
        } finally {
            setMutationProcessing(false);
        }
    };

    const saveColumn = async ({ key, label, format }) => {
        setMutationProcessing(true);
        try {
            if (key) {
                await window.axios.put(route('task.tiers.columns.update', key), { label, format });
            } else {
                await window.axios.post(route('task.tiers.columns.store'), { label, format });
            }

            await loadRecords(recordsPage);
            setNotice({
                status: 'success',
                title: key ? 'Colonne mise à jour' : 'Colonne créée',
                message: 'La structure du tableau a été mise à jour.',
                progress: 100,
            });
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Colonne non enregistrée',
                message: httpErrorMessage(error, "La colonne n'a pas pu être enregistrée."),
                progress: 0,
            });
        } finally {
            setMutationProcessing(false);
        }
    };

    const deleteColumn = async (column) => {
        if (!window.confirm(`Supprimer la colonne "${column.label}" ? Toutes les données associées seront supprimées.`)) {
            return;
        }

        setMutationProcessing(true);
        try {
            await window.axios.delete(route('task.tiers.columns.destroy', column.key));
            await loadRecords(recordsPage);
            setNotice({
                status: 'success',
                title: 'Colonne supprimée',
                message: 'La colonne et ses données associées ont été supprimées.',
                progress: 100,
            });
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Suppression impossible',
                message: httpErrorMessage(error, "La colonne n'a pas pu être supprimée."),
                progress: 0,
            });
        } finally {
            setMutationProcessing(false);
        }
    };

    useEffect(() => {
        loadImportHistory();
    }, [permissions.can_import]);

    useEffect(() => {
        const timeout = window.setTimeout(() => {
            setDebouncedRecordsSearch(recordsSearch.trim());
        }, 300);

        return () => {
            window.clearTimeout(timeout);
        };
    }, [recordsSearch]);

    useEffect(() => {
        if (recordsPage !== 1) {
            setRecordsPage(1);
            return;
        }

        loadRecords(1, debouncedRecordsSearch);
    }, [debouncedRecordsSearch]);

    useEffect(() => {
        loadRecords(recordsPage, debouncedRecordsSearch);
    }, [recordsPage]);

    useEffect(() => {
        if (!activeImportJobId) {
            return undefined;
        }

        let cancelled = false;

        const applyStatus = (data) => {
            const status = data.status || 'pending';
            const currentLine = data.current_line;
            const totalLines = data.total_lines;
            const lineMessage = currentLine && totalLines
                ? `${data.message || 'Import en cours...'} Ligne ${currentLine} / ${totalLines}`
                : data.message || 'Import en cours...';

            if (status === 'completed') {
                const stats = data.stats || {};
                const hasWarnings = stats.status === 'warning';

                setNotice({
                    status: hasWarnings ? 'error' : 'success',
                    title: hasWarnings ? 'Import terminé avec des avertissements' : 'Import terminé avec succès',
                    message: data.message || `${stats.imported_rows || 0} ligne(s) importée(s).`,
                    progress: data.progress || 100,
                    reportJobId: data.import_job_id,
                });
                setPausedImport(null);
                setActiveImportJobId(null);
                loadImportHistory();
                loadRecords(recordsPage);
                return;
            }

            if (status === 'failed') {
                setNotice({
                    status: 'error',
                    title: 'Import échoué',
                    message: importFailureMessage(data),
                    progress: data.progress || 0,
                    reportJobId: data.import_job_id,
                });
                setActiveImportJobId(null);
                loadImportHistory();
                loadRecords(recordsPage);
                return;
            }

            if (status === 'waiting_user') {
                const nextPausedImport = {
                    jobId: data.import_job_id,
                    error: data.error,
                    context: data.context,
                };

                setPausedImport(nextPausedImport);
                setNotice({
                    status: 'error',
                    title: 'Import en attente de correction',
                    message: data.message || data.error?.detail || 'Une ligne nécessite une correction.',
                    errorContext: data.context,
                    progress: data.progress || 0,
                    reportJobId: data.import_job_id,
                });
                setActiveImportJobId(null);
                loadImportHistory();
                loadRecords(recordsPage);
                return;
            }

            setNotice({
                status: 'running',
                title: status === 'pending' ? 'Import en attente' : 'Import en cours',
                message: lineMessage,
                progress: data.progress || 0,
            });
        };

        const poll = async () => {
            try {
                const response = await window.axios.get(route('task.tiers.import.status', activeImportJobId));

                if (!cancelled) {
                    applyStatus(response.data || {});
                }
            } catch (error) {
                if (!cancelled) {
                    setNotice({
                        status: 'error',
                        title: 'Suivi import indisponible',
                        message: httpErrorMessage(error, "Le statut de l'import n'a pas pu être récupéré."),
                        progress: 0,
                    });
                    setActiveImportJobId(null);
                }
            }
        };

        poll();
        const interval = window.setInterval(poll, 2000);

        return () => {
            cancelled = true;
            window.clearInterval(interval);
        };
    }, [activeImportJobId]);

    const resolveImportError = async (action, editedValues) => {
        if (!pausedImport?.jobId || !pausedImport?.context?.row) {
            return;
        }

        setErrorDetailOpen(false);
        setNotice({
            status: 'running',
            title: action === 'skip' ? 'Ligne ignorée' : 'Import de la ligne corrigée',
            message: 'Reprise de l’import à partir de la ligne suivante.',
            progress: 35,
        });

        const payload = { action };

        if (action === 'import') {
            const correctedRow = [];
            editedValues.forEach((item) => {
                correctedRow[item.index] = item.value;
            });
            payload.corrected_row = correctedRow;
        }

        try {
            const response = await window.axios.post(route('task.tiers.import.resolve', pausedImport.jobId), payload);
            const data = response.data || {};

            setPausedImport(null);
            setNotice({
                status: 'running',
                title: 'Import relancé',
                message: data.message || 'Reprise du traitement en arrière-plan.',
                progress: data.progress || 0,
            });
            setActiveImportJobId(data.import_job_id || pausedImport.jobId);
        } catch (error) {
            const serverError = error?.response?.data?.error;
            const errorContext = error?.response?.data?.context;

            if (errorContext?.row) {
                setPausedImport((current) => ({
                    ...current,
                    error: serverError,
                    context: errorContext,
                }));
            }

            setNotice({
                status: 'error',
                title: 'Import échoué',
                message: httpErrorMessage(error),
                errorContext,
                progress: 0,
            });
        }
    };

    const deleteAllData = async () => {
        setMutationProcessing(true);
        try {
            const response = await window.axios.delete(route('task.tiers.data.destroy'));
            setDeleteDataModalOpen(false);
            setRecordsPage(1);
            await loadRecords(1);
            loadImportHistory();

            setNotice({
                status: 'success',
                title: 'Données Tiers supprimées',
                message: response.data?.message || 'Toutes les lignes et colonnes ont été supprimées.',
                progress: 100,
            });
        } catch (error) {
            setNotice({
                status: 'error',
                title: 'Suppression impossible',
                message: httpErrorMessage(error, "Les données Tiers n'ont pas pu être supprimées."),
                progress: 0,
            });
        } finally {
            setMutationProcessing(false);
        }
    };

    const pageHeader = (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 className="text-[22px] leading-none">
                <TitleCaps text="Tiers" />
            </h1>

            <div className="flex flex-wrap items-center gap-2">
                {permissions.can_import && (
                    <button
                        type="button"
                        onClick={loadImportHistory}
                        className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        <History className="h-4 w-4" strokeWidth={2.2} />
                        Historique des imports
                    </button>
                )}

                {permissions.can_delete_data && (
                    <button
                        type="button"
                        onClick={() => setDeleteDataModalOpen(true)}
                        className="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                    >
                        <Trash2 className="h-4 w-4" strokeWidth={2.2} />
                        Supprimer les données
                    </button>
                )}

                <button
                    type="button"
                    onClick={() => setImportModalOpen(true)}
                    disabled={!permissions.can_import}
                    className="inline-flex items-center gap-2 rounded-xl bg-[var(--app-primary)] px-4 py-2 text-sm font-semibold text-black shadow-sm transition hover:brightness-95 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Upload className="h-4 w-4" strokeWidth={2.2} />
                    Importer un fichier Excel
                </button>
            </div>
        </div>
    );

    return (
        <AppLayout title="Tiers" header={pageHeader}>
            <Head title="Tiers" />

            <ImportedDataTable
                tableState={recordsState}
                loading={recordsLoading}
                canUpdate={permissions.can_update}
                searchTerm={recordsSearch}
                onSearchChange={setRecordsSearch}
                onPageChange={setRecordsPage}
                onRefresh={() => loadRecords(recordsPage, debouncedRecordsSearch)}
                onAddRow={openCreateRow}
                onEditRow={openEditRow}
                onDeleteRow={deleteRow}
                onManageColumns={() => setColumnsModalOpen(true)}
            />

            {permissions.can_import && (
                <section className="mt-5 rounded-2xl border border-[var(--app-border)] bg-[var(--app-surface)] p-6 shadow-sm">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3">
                            <span className="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-[var(--app-border)] bg-[var(--app-surface-soft)] text-[var(--app-muted)]">
                                <FileText className="h-5 w-5" strokeWidth={2.2} />
                            </span>
                            <div>
                                <h2 className="text-sm font-black uppercase tracking-wide text-[var(--app-ink)]">
                                    Historique des imports
                                </h2>
                                <p className="mt-1 text-sm text-[var(--app-muted)]">
                                    Consultez les derniers imports et leurs rapports détaillés.
                                </p>
                            </div>
                        </div>
                        <button
                            type="button"
                            onClick={loadImportHistory}
                            disabled={historyLoading}
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
                        >
                            {historyLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <History className="h-4 w-4" />}
                            Actualiser
                        </button>
                    </div>

                    <div className="mt-4 overflow-x-auto rounded-xl border border-gray-100">
                        <table className="min-w-full divide-y divide-gray-100 text-left text-sm">
                            <thead className="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th className="whitespace-nowrap px-4 py-3 font-semibold">Date</th>
                                    <th className="whitespace-nowrap px-4 py-3 font-semibold">Utilisateur</th>
                                    <th className="whitespace-nowrap px-4 py-3 font-semibold">Fichier</th>
                                    <th className="whitespace-nowrap px-4 py-3 font-semibold">Lignes</th>
                                    <th className="whitespace-nowrap px-4 py-3 font-semibold">Durée</th>
                                    <th className="whitespace-nowrap px-4 py-3 font-semibold">Statut</th>
                                    <th className="whitespace-nowrap px-4 py-3 text-right font-semibold">Rapport</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 bg-white">
                                {importHistory.map((item) => (
                                    <tr key={item.id}>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-700">{formatDateTime(item.date)}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-700">{item.user || '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3 font-semibold text-gray-900">{item.file || '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-700">{formatNumber(item.rows)}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-gray-700">{item.duration || '-'}</td>
                                        <td className="whitespace-nowrap px-4 py-3">
                                            <span className={classNames('inline-flex rounded-full border px-2.5 py-1 text-xs font-bold', statusBadgeClass(item.status_label))}>
                                                {item.status_label || item.status}
                                            </span>
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right">
                                            <button
                                                type="button"
                                                onClick={() => openImportReport(item.id)}
                                                className="inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                            >
                                                <Eye className="h-3.5 w-3.5" strokeWidth={2.2} />
                                                Voir
                                            </button>
                                        </td>
                                    </tr>
                                ))}

                                {importHistory.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-6 text-center text-sm text-gray-500">
                                            Aucun import enregistré pour le moment.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}

            <ImportModal
                open={importModalOpen}
                onClose={() => setImportModalOpen(false)}
                onNotice={setNotice}
                onImportStarted={(jobId) => {
                    setActiveImportJobId(jobId);
                    setPausedImport(null);
                    setErrorDetailOpen(false);
                }}
            />
            <ImportErrorDetailModal
                state={errorDetailOpen ? pausedImport : null}
                onClose={() => setErrorDetailOpen(false)}
                onResolve={resolveImportError}
            />
            <ImportProgressNotice
                notice={notice}
                onClose={() => setNotice(null)}
                onDetail={() => setErrorDetailOpen(true)}
                onReport={openImportReport}
            />
            <ImportReportModal
                reportState={reportState}
                onClose={() => setReportState(null)}
            />
            <TiersRowModal
                state={rowModalState}
                columns={recordsState.columns || []}
                processing={mutationProcessing}
                onClose={() => setRowModalState(null)}
                onSubmit={submitRow}
            />
            <TiersColumnsModal
                open={columnsModalOpen}
                columns={recordsState.columns || []}
                processing={mutationProcessing}
                onClose={() => setColumnsModalOpen(false)}
                onSaveColumn={saveColumn}
                onDeleteColumn={deleteColumn}
            />
            <DeleteTiersDataModal
                open={deleteDataModalOpen}
                processing={mutationProcessing}
                onClose={() => setDeleteDataModalOpen(false)}
                onConfirm={deleteAllData}
            />
        </AppLayout>
    );
}
