<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Cotations</title>
    <style>
        @page {
            size: a4 landscape;
            margin: 6mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1a1a1a;
        }

        h1 {
            margin: 0 0 2px;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .meta {
            margin: 0 0 6px;
            font-size: 9px;
            color: #555;
        }

        .page {
            page-break-after: always;
        }

        .page:last-child {
            page-break-after: auto;
        }

        .section-title {
            margin: 6px 0 3px;
            padding: 3px 7px;
            background: #facc51;
            color: #1a1a1a;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            text-align: center;
            vertical-align: middle;
        }

        .columns {
            width: 100%;
        }

        .col {
            display: inline-block;
            width: 49%;
            vertical-align: top;
        }

        .col + .col {
            margin-left: 1.5%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        table.grid-table {
            margin-bottom: 10px;
        }

        .transport-section {
            page-break-inside: avoid;
        }

        th, td {
            border: 1px solid #d8d8d8;
            padding: 4px 6px;
            text-align: center;
        }

        thead th {
            background: #f3f3f3;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        td.label, th.label {
            text-align: left;
            font-weight: bold;
        }

        .empty {
            padding: 6px;
            color: #888;
            font-style: italic;
            border: 1px solid #d8d8d8;
        }

        .no-data {
            padding: 10px;
            color: #888;
            font-style: italic;
        }

        /* Page céréales uniquement : mise en forme compacte pour tout faire tenir sur une page. */
        .page-cereals {
            font-size: 8.5px;
        }

        .page-cereals .meta {
            margin-bottom: 4px;
        }

        .page-cereals .harvest-section {
            page-break-inside: avoid;
            margin-bottom: 3px;
        }

        .page-cereals .section-title {
            margin: 2px 0 1px;
            padding: 2px 6px;
            font-size: 9.5px;
        }

        .page-cereals table.cereal-grid th,
        .page-cereals table.cereal-grid td {
            text-align: center;
        }

        .page-cereals table.cereal-grid td.label,
        .page-cereals table.cereal-grid th.label {
            text-align: left;
            font-weight: normal;
            color: #555;
            background: #fafafa;
        }

        .page-cereals table.cereal-grid tr.row-final td {
            font-weight: bold;
        }

        .page-cereals table.cereal-grid .group-start {
            border-left: 1.5px solid #999;
        }

        .page-cereals table {
            margin-bottom: 1px;
            font-size: 8px;
        }

        .page-cereals th,
        .page-cereals td {
            padding: 1px 4px;
            line-height: 1.15;
        }

        .page-cereals thead th {
            font-size: 7.5px;
        }

        .page-cereals .empty {
            padding: 3px 5px;
            font-size: 8px;
        }

        /* Paliers de densité : appliqués automatiquement selon le nombre de
           céréales pour que tout tienne sur une seule page (cf. exportPdf()). */
        .page-cereals.density-1 {
            font-size: 7.5px;
        }

        .page-cereals.density-1 .section-title {
            margin: 1px 0;
            padding: 1.5px 5px;
            font-size: 8.5px;
        }

        .page-cereals.density-1 table {
            font-size: 7px;
        }

        .page-cereals.density-1 th,
        .page-cereals.density-1 td {
            padding: 0.5px 3px;
            line-height: 1.05;
        }

        .page-cereals.density-1 thead th {
            font-size: 6.5px;
        }

        .page-cereals.density-2 {
            font-size: 6.5px;
        }

        .page-cereals.density-2 .section-title {
            margin: 0.5px 0;
            padding: 1px 4px;
            font-size: 7.5px;
        }

        .page-cereals.density-2 table {
            font-size: 6px;
        }

        .page-cereals.density-2 th,
        .page-cereals.density-2 td {
            padding: 0.5px 2px;
            line-height: 1;
        }

        .page-cereals.density-2 thead th {
            font-size: 5.5px;
        }
    </style>
</head>
<body>

{{-- Page 1 : céréales + transport (le carburant reste sur une page à part) --}}
<div class="page">
    <h1>Cotations céréales</h1>
    <p class="meta">Généré le {{ $generatedAt->format('d/m/Y à H:i') }}</p>

    <div class="page-cereals density-{{ $cerealDensity ?? 0 }}">
    @php
        $hasAnyCerealData = ($cerealHarvestTables['left']['has_data'] ?? false) || ($cerealHarvestTables['right']['has_data'] ?? false);
    @endphp

    @if ($hasAnyCerealData)
        @foreach (['left', 'right'] as $bucket)
            @php
                $table = $cerealHarvestTables[$bucket];
            @endphp

            <div class="harvest-section">
                <div class="section-title">Récolte {{ $table['year'] }}</div>

                @if ($table['has_data'])
                    <table class="cereal-grid">
                        <colgroup>
                            <col style="width: {{ $table['stub_width'] }}%">
                            @foreach ($table['cereal_groups'] as $cerealGroup)
                                @foreach ($cerealGroup['columns'] as $column)
                                    <col style="width: {{ $table['column_width'] }}%">
                                @endforeach
                            @endforeach
                        </colgroup>
                        <thead>
                            <tr>
                                <th class="label"></th>
                                @foreach ($table['cereal_groups'] as $cerealGroup)
                                    <th class="group-start" colspan="{{ count($cerealGroup['columns']) }}">{{ $cerealGroup['name'] }}</th>
                                @endforeach
                            </tr>
                            <tr>
                                <th class="label"></th>
                                @foreach ($table['cereal_groups'] as $cerealGroup)
                                    @foreach ($cerealGroup['columns'] as $column)
                                        <th class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['label'] }}</th>
                                    @endforeach
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="label">Matif</td>
                                @foreach ($table['cereal_groups'] as $cerealGroup)
                                    @foreach ($cerealGroup['columns'] as $column)
                                        <td class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['matif'] }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                            <tr>
                                <td class="label">Base</td>
                                @foreach ($table['cereal_groups'] as $cerealGroup)
                                    @foreach ($cerealGroup['columns'] as $column)
                                        <td class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['margin'] }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                            <tr class="row-final">
                                <td class="label">Prix final</td>
                                @foreach ($table['cereal_groups'] as $cerealGroup)
                                    @foreach ($cerealGroup['columns'] as $column)
                                        <td class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['final_price'] }}</td>
                                    @endforeach
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                @else
                    <div class="empty">Aucune échéance disponible pour cette récolte.</div>
                @endif
            </div>
        @endforeach
    @else
        <p class="no-data">Aucune cotation disponible.</p>
    @endif
    </div>

    {{-- Transport : enchaîné directement sous les céréales sur la même page.
         Mise en forme normale (non compactée) ; si le contenu déborde, le
         saut de page se fait automatiquement, sans contrainte forcée. --}}
    @forelse ($transportGrid['sections'] ?? [] as $section)
        <div class="transport-section">
            <div class="section-title">{{ $section['title'] ?: 'PRIX DES TRANSPORTS' }}</div>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th class="label">{{ $section['first_column_label'] ?: 'TRANSPORT' }}</th>
                        @foreach ($section['columns'] ?? [] as $column)
                            <th>{{ $column['label'] ?: 'Colonne' }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] ?? [] as $row)
                        <tr>
                            <td class="label">{{ $row['label'] ?: 'Ligne' }}</td>
                            @foreach ($section['columns'] ?? [] as $column)
                                @php
                                    $cellKey = $row['id'].'__'.$column['id'];
                                    $customText = trim((string) ($section['cells'][$cellKey]['text'] ?? ''));
                                    $referencePrice = $finalPriceByKey[$column['reference_key'] ?? ''] ?? null;
                                    $price = $referencePrice !== null
                                        ? (float) $referencePrice + (float) ($column['base'] ?? 0) + (float) ($row['base'] ?? 0)
                                        : null;
                                @endphp
                                <td>{{ $customText !== '' ? $customText : \App\Support\Cotations\CotationPdfFormatter::roundedPrice($price) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="no-data">Aucun tableau de transport configuré.</p>
    @endforelse
</div>

{{-- Page 2 : carburant --}}
<div class="page">
    <h1>Prix carburant</h1>
    <p class="meta">Généré le {{ $generatedAt->format('d/m/Y à H:i') }}</p>

    @php
        $fuelSectionsById = collect($fuelGrid['sections'] ?? [])->keyBy('id');
    @endphp

    <div class="columns">
        <div class="col">
            @if ($fuelSectionsById->has('fuel_grand_froid'))
                @include('cotations.partials.fuel-section', ['section' => $fuelSectionsById->get('fuel_grand_froid')])
            @endif
        </div>
        <div class="col">
            <div class="section-title">{{ $fuelGrid['gazole']['label'] ?: 'GAZOLE' }}</div>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th class="label"></th>
                        <th>HT</th>
                        <th>TTC</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="label">{{ $fuelGrid['gazole']['tranche'] ?: 'GAZOLE' }}</td>
                        @php
                            $gazoleText = $fuelGrid['gazole']['text'] ?? '';
                        @endphp
                        <td>{{ $gazoleText !== '' ? $gazoleText : \App\Support\Cotations\CotationPdfFormatter::price($fuelGrid['gazole']['computed_ht'] ?? null) }}</td>
                        <td>{{ $gazoleText !== '' ? $gazoleText : \App\Support\Cotations\CotationPdfFormatter::price($fuelGrid['gazole']['computed_ttc'] ?? null) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="columns">
        <div class="col">
            @if ($fuelSectionsById->has('gnr_agri'))
                @include('cotations.partials.fuel-section', ['section' => $fuelSectionsById->get('gnr_agri')])
            @endif
        </div>
        <div class="col">
            @if ($fuelSectionsById->has('gnr_taxe'))
                @include('cotations.partials.fuel-section', ['section' => $fuelSectionsById->get('gnr_taxe')])
            @endif
        </div>
    </div>
</div>

</body>
</html>
