<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Renders one of ReportExportService's report shapes (title/subtitle/
 * filterSummary/columns/align/rows/totals) as a single professional
 * worksheet - GEDI FINANCE header, applied-filters line, a bold/frozen
 * column header row, the data table with real currency-formatted number
 * cells (never text like "1250" or a formula-breaking "1.25E+03"), and a
 * totals block. One reusable export class serves every report type
 * (transactions/sales/purchases and their customer/supplier statement
 * variants), exactly like the single Blade template it replaces served
 * every PDF report type - the report-shape data itself is unchanged, only
 * how it's rendered.
 */
class BusinessReportExport implements FromArray, WithEvents, WithTitle
{
    /**
     * @param  array<string>  $columns
     * @param  array<string>  $align
     * @param  array<int, array<string, string>>  $rows
     * @param  array<string, string>  $totals
     * @param  array<string, string>  $filterSummary
     */
    public function __construct(
        private readonly string $title,
        private readonly ?string $subtitle,
        private readonly string $generatedAt,
        private readonly array $filterSummary,
        private readonly array $columns,
        private readonly array $align,
        private readonly array $rows,
        private readonly array $totals,
    ) {}

    /**
     * FromArray requires this method, but the sheet is built entirely in
     * the AfterSheet event below (a report layout with a header block,
     * filter line and totals section doesn't fit the "array of rows ->
     * one heading row" shape FromArray/WithHeadings assume).
     */
    public function array(): array
    {
        return [];
    }

    public function title(): string
    {
        $base = $this->subtitle
            ? preg_replace('/^(Customer|Supplier): /', '', $this->subtitle)
            : $this->title;

        // Excel forbids : \ / ? * [ ] in a sheet name and caps it at 31
        // characters.
        $safe = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string) $base);
        $safe = trim(preg_replace('/\s+/', ' ', $safe));

        return $safe !== '' ? mb_substr($safe, 0, 31) : 'Report';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $this->build($event);
            },
        ];
    }

    private function build(AfterSheet $event): void
    {
        $sheet = $event->sheet->getDelegate();
        $columnCount = max(count($this->columns), 1);
        $lastCol = Coordinate::stringFromColumnIndex($columnCount);

        $row = 1;

        $sheet->setCellValue("A{$row}", 'GEDI FINANCE');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(16);
        $row++;

        $sheet->setCellValue("A{$row}", 'Wholesale Business Management');
        $sheet->getStyle("A{$row}")->getFont()->setSize(10)->getColor()->setRGB('64748B');
        $row += 2;

        $sheet->setCellValue("A{$row}", $this->title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
        $row++;

        if ($this->subtitle) {
            $sheet->setCellValue("A{$row}", $this->subtitle);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $sheet->setCellValue("A{$row}", "Generated: {$this->generatedAt}");
        $sheet->getStyle("A{$row}")->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $row += 2;

        $filterLine = collect($this->filterSummary)
            ->map(fn ($value, $label) => "{$label}: {$value}")
            ->implode('   |   ');
        $sheet->setCellValue("A{$row}", $filterLine);
        $sheet->getStyle("A{$row}")->getFont()->setSize(9)->setItalic(true);
        $sheet->mergeCells("A{$row}:{$lastCol}{$row}");
        $row += 2;

        $headerRow = $row;
        foreach ($this->columns as $i => $column) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$row}", $column);
        }
        $headerRange = "A{$row}:{$lastCol}{$row}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFont()->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle($headerRange)->getFill()->getStartColor()->setRGB('0F172A');
        $row++;

        foreach ($this->rows as $dataRow) {
            $values = array_values($dataRow);
            foreach ($values as $i => $value) {
                $col = Coordinate::stringFromColumnIndex($i + 1);
                $isMoney = ($this->align[$i] ?? 'left') === 'right';

                if ($isMoney && $value !== '') {
                    $sheet->setCellValue("{$col}{$row}", (float) $value);
                    $sheet->getStyle("{$col}{$row}")->getNumberFormat()
                        ->setFormatCode('$#,##0.00;[RED]-$#,##0.00');
                } else {
                    $sheet->setCellValue("{$col}{$row}", $value);
                }
            }
            $row++;
        }

        $lastDataRow = $row - 1;

        if (count($this->rows) === 0) {
            $sheet->setCellValue("A{$row}", 'No records match the selected filters.');
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
            $sheet->getStyle("A{$row}")->getFont()->getColor()->setRGB('94A3B8');
            $row++;
        } else {
            // Bold column headers + a real data range is what makes
            // "freeze panes" and "auto-filter" meaningful, per the
            // requested workbook design.
            $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$lastDataRow}");
            $sheet->freezePane('A'.($headerRow + 1));
        }

        $row++;

        $totalsValueCol = Coordinate::stringFromColumnIndex($columnCount);
        foreach ($this->totals as $label => $value) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            $sheet->setCellValue("{$totalsValueCol}{$row}", (float) $value);
            $sheet->getStyle("{$totalsValueCol}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("{$totalsValueCol}{$row}")->getNumberFormat()
                ->setFormatCode('$#,##0.00;[RED]-$#,##0.00');
            $row++;
        }

        $row++;
        $sheet->setCellValue("A{$row}", 'Gedi Finance - Confidential business record');
        $sheet->getStyle("A{$row}")->getFont()->setSize(8)->setItalic(true);
        $sheet->getStyle("A{$row}")->getFont()->getColor()->setRGB('94A3B8');

        for ($i = 1; $i <= $columnCount; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }
}
