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
@include('cotations.partials.fuel-page', ['fuelGrid' => $fuelGrid, 'generatedAt' => $generatedAt])

</body>
</html>
