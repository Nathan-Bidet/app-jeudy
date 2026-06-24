{{-- Page carburant : utilisée à la fois par l'export PDF complet et par
     l'export PDF dédié au carburant, pour garantir un rendu strictement
     identique entre les deux. --}}
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
