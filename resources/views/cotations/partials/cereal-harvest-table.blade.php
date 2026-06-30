<div class="harvest-section">
    <div class="section-title">Récolte {{ $table['year'] }}</div>

    @if ($table['has_data'])
        @php
            $cerealGroups = collect($table['cereal_groups'] ?? [])->values();
            $splitAt = (int) ceil(max(1, $cerealGroups->count()) / 2);
            $chunks = $cerealGroups->chunk($splitAt);
            $stubWidth = $table['stub_width'] ?? 6;
        @endphp

        @foreach ($chunks as $chunk)
            @php
                $visibleColumnCount = $chunk->sum(fn (array $cerealGroup): int => count($cerealGroup['columns'] ?? []));
                $columnWidth = $visibleColumnCount > 0 ? (100 - $stubWidth) / $visibleColumnCount : 0;
            @endphp

            @if ($visibleColumnCount > 0)
                <table class="cereal-grid">
                    <colgroup>
                        <col style="width: {{ $stubWidth }}%">
                        @foreach ($chunk as $cerealGroup)
                            @foreach ($cerealGroup['columns'] as $column)
                                <col style="width: {{ $columnWidth }}%">
                            @endforeach
                        @endforeach
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="label"></th>
                            @foreach ($chunk as $cerealGroup)
                                <th class="group-start cereal-name" colspan="{{ count($cerealGroup['columns']) }}">
                                    {{ \App\Support\Cotations\CotationPdfFormatter::text($cerealGroup['name']) }}
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="label"></th>
                            @foreach ($chunk as $cerealGroup)
                                @foreach ($cerealGroup['columns'] as $column)
                                    <th class="{{ $loop->first ? 'group-start' : '' }}">{{ \App\Support\Cotations\CotationPdfFormatter::text($column['label']) }}</th>
                                @endforeach
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($table['labels']['matif'] ?? 'MATIF') }}</td>
                            @foreach ($chunk as $cerealGroup)
                                @foreach ($cerealGroup['columns'] as $column)
                                    <td class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['matif'] }}</td>
                                @endforeach
                            @endforeach
                        </tr>
                        <tr>
                            <td class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($table['labels']['base'] ?? 'Base') }}</td>
                            @foreach ($chunk as $cerealGroup)
                                @foreach ($cerealGroup['columns'] as $column)
                                    <td class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['margin'] }}</td>
                                @endforeach
                            @endforeach
                        </tr>
                        <tr class="row-final">
                            <td class="label">{{ \App\Support\Cotations\CotationPdfFormatter::text($table['labels']['final_price'] ?? 'Prix final') }}</td>
                            @foreach ($chunk as $cerealGroup)
                                @foreach ($cerealGroup['columns'] as $column)
                                    <td class="{{ $loop->first ? 'group-start' : '' }}">{{ $column['final_price'] }}</td>
                                @endforeach
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            @endif
        @endforeach
    @else
        <div class="empty">Aucune échéance disponible pour cette récolte.</div>
    @endif
</div>
