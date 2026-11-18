<?php

namespace App\Services;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Menulis hasil ReportService (bentuk title/meta/columns/rows/summary/notes) menjadi file .xlsx.
 * Angka ditulis sebagai angka asli (bukan teks) dengan format ribuan, sehingga bisa dijumlah di Excel.
 * Memakai openspout/openspout (hemat memori, tanpa PhpSpreadsheet).
 */
class ReportExporter
{
    /** @param array<string, mixed> $report  @return string path file sementara (hapus setelah dikirim) */
    public function toFile(array $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sapore_report_').'.xlsx';

        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $report['title']), 0, 31) ?: 'Laporan');

        $bold = (new Style())->setFontBold();
        $head = (new Style())->setFontBold()->setBackgroundColor('E8E1D5');

        $writer->addRow(Row::fromValues(['SAPORÉ — '.$report['title']], (new Style())->setFontBold()->setFontSize(14)));
        foreach ($report['meta'] ?? [] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }
        $writer->addRow(Row::fromValues(['Dicetak: '.now()->format('d M Y H:i')]));
        $writer->addRow(Row::fromValues([]));

        $writer->addRow(Row::fromValues(array_map(fn ($c) => $c['label'], $report['columns']), $head));

        foreach ($report['rows'] as $row) {
            $writer->addRow(new Row(array_map(
                fn ($c) => $this->cell($c['type'], $row[$c['key']] ?? null),
                $report['columns']
            )));
        }

        if (! empty($report['summary'])) {
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(['Ringkasan'], $bold));
            foreach ($report['summary'] as $s) {
                $writer->addRow(new Row([Cell::fromValue($s['label']), $this->cell($s['type'], $s['value'], true)]));
            }
        }

        if (! empty($report['notes'])) {
            $writer->addRow(Row::fromValues([]));
            foreach ($report['notes'] as $note) {
                $writer->addRow(Row::fromValues([$note]));
            }
        }

        $writer->close();

        return $path;
    }

    private function cell(string $type, mixed $value, bool $bold = false): Cell
    {
        if ($value === null || $value === '') {
            return Cell::fromValue('-');
        }

        $style = match ($type) {
            'money' => (new Style())->setFormat('#,##0'),
            'qty' => (new Style())->setFormat('#,##0.00'),
            'pct' => (new Style())->setFormat('0.0"%"'),
            'int' => (new Style())->setFormat('#,##0'),
            default => new Style(),
        };
        if ($bold) {
            $style->setFontBold();
        }

        return match ($type) {
            'money', 'qty', 'pct', 'int' => Cell::fromValue((float) $value, $style),
            default => Cell::fromValue((string) $value, $style),
        };
    }
}
