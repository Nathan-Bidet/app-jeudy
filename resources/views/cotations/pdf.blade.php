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
                            <h1 class="pdf-title">'.e($title).'</h1>
                            '.($subtitle ? '<div class="pdf-subtitle">'.e($subtitle).'</div>' : '').'
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

    $wantedTransportTitles = [
        'cereales tarif de vente aux eleveurs',
        'produits lamines tarif de vente aux eleveurs',
        'contrat laminage achat cereales',
    ];
    $normalizeTransportTitle = static function (string $title): string {
        $normalized = mb_strtolower($title, 'UTF-8');
        $normalized = strtr($normalized, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        ]);
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? '');
    };
    $transportSections = collect($transportGrid['sections'] ?? [])->filter(function (array $section) use ($wantedTransportTitles, $normalizeTransportTitle): bool {
        $normalized = $normalizeTransportTitle((string) ($section['title'] ?? ''));
        return in_array($normalized, $wantedTransportTitles, true);
    })->values();
@endphp

{{-- Page 1 : céréales récolte gauche --}}
<div class="page page-cereals">
    {!! $renderHeader('Cotations céréales - Récolte '.($cerealHarvestTables['left']['year'] ?? '')) !!}

    @if (trim((string) ($cerealInfoHtml ?? '')) !== '')
        <div class="information-block">{!! $cerealInfoHtml !!}</div>
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
