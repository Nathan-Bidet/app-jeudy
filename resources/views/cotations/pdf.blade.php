<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Cotations</title>
    <style>
@include('cotations.partials.pdf-styles')
    </style>
</head>
<body>

@php
    $renderHeader = function (string $title, ?string $subtitle = null) use ($generatedAt, $lastRefreshAt, $logoPath): string {
        $logoHtml = is_string($logoPath ?? null) && file_exists($logoPath)
            ? '<img class="pdf-logo" src="'.e($logoPath).'" alt="Logo Jeudy">'
            : '<strong>JEUDY</strong>';

        return '
            <div class="pdf-header">
                <table class="pdf-header-table">
                    <tr>
                        <td class="pdf-logo-cell">'.$logoHtml.'</td>
                        <td class="pdf-title-cell">
                            <h1 class="pdf-title">'.e(\App\Support\Cotations\CotationPdfFormatter::text($title)).'</h1>
                            '.($subtitle ? '<div class="pdf-subtitle">'.e(\App\Support\Cotations\CotationPdfFormatter::text($subtitle)).'</div>' : '').'
                        </td>
                        <td class="pdf-meta-cell">
                            <div><span class="pdf-meta-label">Généré :</span> '.e($generatedAt->format('d/m/Y à H:i')).'</div>
                            <div><span class="pdf-meta-label">Cours :</span> '.e($lastRefreshAt ? $lastRefreshAt->format('d/m/Y à H:i') : 'Non disponible').'</div>
                        </td>
                    </tr>
                </table>
            </div>
        ';
    };

    $transportSections = collect($transportGrid['sections'] ?? [])->values();
@endphp

{{-- Page 1 : céréales récolte gauche --}}
<div class="page page-cereals">
    {!! $renderHeader('Cotations céréales - Récolte '.($cerealHarvestTables['left']['year'] ?? '')) !!}

    @if (trim((string) ($cerealInfoHtml ?? '')) !== '')
        <div class="information-block">{!! \App\Support\Cotations\CotationPdfFormatter::html($cerealInfoHtml) !!}</div>
    @endif

    @include('cotations.partials.cereal-harvest-table', ['table' => $cerealHarvestTables['left']])
</div>

{{-- Page 2 : céréales récolte droite --}}
<div class="page page-cereals">
    {!! $renderHeader('Cotations céréales - Récolte '.($cerealHarvestTables['right']['year'] ?? '')) !!}
    @include('cotations.partials.cereal-harvest-table', ['table' => $cerealHarvestTables['right']])
</div>

{{-- Page 3 : transports --}}
<div class="page transport-page">
    {!! $renderHeader('Tarifs transports') !!}

    @forelse ($transportSections as $section)
        <div class="transport-section">
            <div class="section-title">{{ \App\Support\Cotations\CotationPdfFormatter::text($section['title'] ?: 'PRIX DES TRANSPORTS') }}</div>
            <table class="grid-table">
                <thead>
                    <tr>
                        <th class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($section['first_column_label'] ?: 'TRANSPORT') }}</th>
                        @foreach ($section['columns'] ?? [] as $column)
                            <th>{{ \App\Support\Cotations\CotationPdfFormatter::text($column['label'] ?: 'Colonne') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] ?? [] as $row)
                        <tr>
                            <td class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($row['label'] ?: 'Ligne') }}</td>
                            @foreach ($section['columns'] ?? [] as $column)
                                @php
                                    $cellKey = $row['id'].'__'.$column['id'];
                                    $customText = trim((string) ($section['cells'][$cellKey]['text'] ?? ''));
                                    $referencePrice = $finalPriceByKey[$column['reference_key'] ?? ''] ?? null;
                                    $price = $referencePrice !== null
                                        ? (float) $referencePrice + (float) ($column['base'] ?? 0) + (float) ($row['base'] ?? 0)
                                        : null;
                                @endphp
                                <td>{{ $customText !== '' ? \App\Support\Cotations\CotationPdfFormatter::text($customText) : \App\Support\Cotations\CotationPdfFormatter::roundedPrice($price) }}</td>
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

{{-- Page 4 : carburants --}}
<div class="page fuel-page">
    {!! $renderHeader('Prix carburants') !!}

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
            <div class="section-title">{{ \App\Support\Cotations\CotationPdfFormatter::text($fuelGrid['gazole']['label'] ?: 'GAZOLE') }}</div>
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
                        <td class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($fuelGrid['gazole']['tranche'] ?: 'GAZOLE') }}</td>
                        @php
                            $gazoleText = $fuelGrid['gazole']['text'] ?? '';
                        @endphp
                        <td>{{ $gazoleText !== '' ? \App\Support\Cotations\CotationPdfFormatter::text($gazoleText) : \App\Support\Cotations\CotationPdfFormatter::price($fuelGrid['gazole']['computed_ht'] ?? null) }}</td>
                        <td>{{ $gazoleText !== '' ? \App\Support\Cotations\CotationPdfFormatter::text($gazoleText) : \App\Support\Cotations\CotationPdfFormatter::price($fuelGrid['gazole']['computed_ttc'] ?? null) }}</td>
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
