@if ($section)
    <div class="section-title">{{ $section['label'] ?: 'Section' }}</div>
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
                    <td class="label">{{ $row['tranche'] ?: 'Tranche' }}</td>
                    <td>{{ $rowText !== '' ? $rowText : \App\Support\Cotations\CotationPdfFormatter::price($row['computed_ht'] ?? null) }}</td>
                    <td>{{ $rowText !== '' ? $rowText : \App\Support\Cotations\CotationPdfFormatter::price($row['computed_ttc'] ?? null) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
