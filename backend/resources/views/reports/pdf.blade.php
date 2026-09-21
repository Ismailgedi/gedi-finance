<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 30px 34px 56px 34px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10.5px;
            color: #1a1a1a;
        }

        .brand {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            color: #0f172a;
        }

        .brand-sub {
            font-size: 9.5px;
            color: #64748b;
            margin-top: 2px;
        }

        .header-table {
            width: 100%;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }

        .header-table td {
            vertical-align: top;
        }

        .report-title {
            font-size: 15px;
            font-weight: 700;
            text-align: right;
            color: #0f172a;
        }

        .report-meta {
            font-size: 9px;
            color: #64748b;
            text-align: right;
            margin-top: 3px;
        }

        .filter-summary {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: 9.5px;
            color: #334155;
        }

        .filter-summary strong {
            color: #0f172a;
        }

        table.records {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        table.records thead {
            display: table-header-group;
        }

        table.records th {
            background: #0f172a;
            color: #ffffff;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 6px 8px;
            text-align: left;
        }

        table.records th.align-right {
            text-align: right;
        }

        table.records td {
            padding: 5px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 9.5px;
        }

        table.records td.align-right {
            text-align: right;
            font-family: 'DejaVu Sans Mono', monospace;
        }

        table.records tr:nth-child(even) td {
            background: #f8fafc;
        }

        .empty-state {
            padding: 20px;
            text-align: center;
            color: #94a3b8;
            border: 1px dashed #cbd5e1;
            border-radius: 4px;
            margin-bottom: 14px;
        }

        .totals-box {
            width: 260px;
            margin-left: auto;
            border: 1px solid #0f172a;
            border-radius: 4px;
        }

        .totals-box table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-box td {
            padding: 6px 10px;
            font-size: 10px;
        }

        .totals-box tr:not(:last-child) td {
            border-bottom: 1px solid #e2e8f0;
        }

        .totals-box tr:last-child td {
            font-weight: 700;
            background: #0f172a;
            color: #ffffff;
        }

        .totals-box td.amount {
            text-align: right;
            font-family: 'DejaVu Sans Mono', monospace;
        }

        .record-count {
            font-size: 9px;
            color: #94a3b8;
            margin-bottom: 6px;
        }
    </style>
</head>
<body>
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <div class="brand">GEDI FINANCE</div>
                <div class="brand-sub">Wholesale Business Management</div>
            </td>
            <td style="width: 40%;">
                <div class="report-title">{{ $title }}</div>
                @if (!empty($subtitle))
                    <div class="report-meta"><strong>{{ $subtitle }}</strong></div>
                @endif
                <div class="report-meta">Generated {{ $generatedAt }}</div>
            </td>
        </tr>
    </table>

    <div class="filter-summary">
        @foreach ($filterSummary as $label => $value)
            <strong>{{ $label }}:</strong> {{ $value }}@if (!$loop->last) &nbsp;&nbsp;&bull;&nbsp;&nbsp; @endif
        @endforeach
    </div>

    <div class="record-count">{{ count($rows) }} record{{ count($rows) === 1 ? '' : 's' }}</div>

    @if (count($rows) === 0)
        <div class="empty-state">No records match the selected filters.</div>
    @else
        <table class="records">
            <thead>
                <tr>
                    @foreach ($columns as $i => $column)
                        <th class="{{ ($align[$i] ?? 'left') === 'right' ? 'align-right' : '' }}">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_values($row) as $i => $cell)
                            @php $isMoney = ($align[$i] ?? 'left') === 'right'; @endphp
                            <td class="{{ $isMoney ? 'align-right' : '' }}">
                                {{ $isMoney ? '$' . number_format((float) $cell, 2) : $cell }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="totals-box">
        <table>
            @foreach ($totals as $label => $value)
                @php
                    $numeric = (float) $value;
                    $formatted = ($numeric < 0 ? '-$' : '$') . number_format(abs($numeric), 2);
                @endphp
                <tr>
                    <td>{{ $label }}</td>
                    <td class="amount">{{ $formatted }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont("DejaVu Sans", "normal");
            $size = 8;
            $text = "Page {PAGE_NUM} of {PAGE_COUNT}";
            $width = $fontMetrics->getTextWidth($text, $font, $size);
            $x = ($pdf->get_width() - $width) / 2;
            $y = $pdf->get_height() - 30;
            $pdf->page_text($x, $y, $text, $font, $size, array(0.4, 0.45, 0.55));

            $footerLeft = "Gedi Finance - Confidential business record";
            $pdf->page_text(34, $y, $footerLeft, $font, $size, array(0.4, 0.45, 0.55));
        }
    </script>
</body>
</html>
