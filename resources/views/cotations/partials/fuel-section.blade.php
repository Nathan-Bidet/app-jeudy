@if ($section)
    <div class="section-title">{{ \App\Support\Cotations\CotationPdfFormatter::text($section['label'] ?: 'Section') }}</div>
    <table class="grid-table">
        <thead>
            <tr>
                <th class="label"></th>
                <th>HT</th>
                <th>TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($section['rows'] ?? [] as $row)
                @php
                    $rowText = trim((string) ($row['text'] ?? ''));
                @endphp
                <tr>
                    <td class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($row['tranche'] ?: 'Tranche') }}</td>
                    <td>{{ $rowText !== '' ? \App\Support\Cotations\CotationPdfFormatter::text($rowText) : \App\Support\Cotations\CotationPdfFormatter::price($row['computed_ht'] ?? null) }}</td>
                    <td>{{ $rowText !== '' ? \App\Support\Cotations\CotationPdfFormatter::text($rowText) : \App\Support\Cotations\CotationPdfFormatter::price($row['computed_ttc'] ?? null) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
