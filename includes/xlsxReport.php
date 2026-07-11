<?php
declare(strict_types=1);

/**
 * Dynabase XLSX report writer.
 *
 * Reports are rendered with PhpSpreadsheet instead of manually assembling
 * OOXML worksheet files. This prevents Microsoft Excel from repairing or
 * discarding worksheet XML when a workbook is opened.
 *
 * Install once from the backend directory:
 *   composer require phpoffice/phpspreadsheet
 */

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dynabaseAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($dynabaseAutoload)) {
    require_once $dynabaseAutoload;
}

const DYNABASE_XLSX_STYLE_DEFAULT = 0;
const DYNABASE_XLSX_STYLE_TITLE = 1;
const DYNABASE_XLSX_STYLE_SUBTITLE = 2;
const DYNABASE_XLSX_STYLE_SECTION = 3;
const DYNABASE_XLSX_STYLE_HEADER = 4;
const DYNABASE_XLSX_STYLE_BODY = 5;
const DYNABASE_XLSX_STYLE_BODY_ALT = 6;
const DYNABASE_XLSX_STYLE_CENTER = 7;
const DYNABASE_XLSX_STYLE_NUMBER = 8;
const DYNABASE_XLSX_STYLE_DATE = 9;
const DYNABASE_XLSX_STYLE_RATE_A = 10;
const DYNABASE_XLSX_STYLE_RATE_B = 11;
const DYNABASE_XLSX_STYLE_RATE_C = 12;
const DYNABASE_XLSX_STYLE_RATE_D = 13;
const DYNABASE_XLSX_STYLE_TOTAL = 14;
const DYNABASE_XLSX_STYLE_PERCENT = 15;
const DYNABASE_XLSX_STYLE_MUTED = 16;
const DYNABASE_XLSX_STYLE_WRAP = 17;
const DYNABASE_XLSX_STYLE_KPI_LABEL = 18;
const DYNABASE_XLSX_STYLE_KPI_VALUE = 19;
const DYNABASE_XLSX_STYLE_LOCATION = 20;

function dynabaseXlsxColumnLetter(int $column): string
{
    $column = max(1, $column);
    $letters = '';
    while ($column > 0) {
        $mod = ($column - 1) % 26;
        $letters = chr(65 + $mod) . $letters;
        $column = intdiv($column - 1, 26);
    }
    return $letters;
}

function dynabaseXlsxCellReference(int $row, int $column): string
{
    return dynabaseXlsxColumnLetter($column) . max(1, $row);
}

function dynabaseXlsxSafeSheetName(string $name, array $used = []): string
{
    $name = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name));
    $name = mb_substr($name !== '' ? $name : 'Sheet', 0, 31, 'UTF-8');
    $base = $name;
    $counter = 2;
    $usedLower = array_map(static fn (string $value): string => mb_strtolower($value, 'UTF-8'), $used);

    while (in_array(mb_strtolower($name, 'UTF-8'), $usedLower, true)) {
        $suffix = ' ' . $counter;
        $name = mb_substr($base, 0, max(1, 31 - mb_strlen($suffix, 'UTF-8')), 'UTF-8') . $suffix;
        $counter++;
    }

    return $name;
}

function dynabaseXlsxCleanText(mixed $value): string
{
    $text = (string) $value;

    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    // XML 1.0 does not permit these control characters. Legacy database text
    // can contain them after copy/paste, so strip them before export.
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
}

function dynabaseXlsxDateValue(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        $date = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);

        return ExcelDate::dateTimeToExcel($date);
    } catch (Throwable) {
        return null;
    }
}

function dynabaseXlsxBorderStyle(): array
{
    return [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'D8E2EA'],
            ],
        ],
    ];
}

function dynabaseXlsxStyleDefinitions(): array
{
    $font = ['name' => 'Aptos', 'size' => 10, 'color' => ['rgb' => '17324A']];
    $border = dynabaseXlsxBorderStyle();
    $body = array_replace_recursive($border, [
        'font' => $font,
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
    ]);

    return [
        DYNABASE_XLSX_STYLE_DEFAULT => [
            'font' => $font,
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP],
        ],
        DYNABASE_XLSX_STYLE_TITLE => [
            'font' => ['name' => 'Aptos Display', 'size' => 20, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '143A5A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ],
        DYNABASE_XLSX_STYLE_SUBTITLE => [
            'font' => ['name' => 'Aptos', 'size' => 9, 'color' => ['rgb' => 'DDEAF5']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '143A5A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ],
        DYNABASE_XLSX_STYLE_SECTION => [
            'font' => ['name' => 'Aptos Display', 'size' => 12, 'bold' => true, 'color' => ['rgb' => '17324A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '2F78BC']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ],
        DYNABASE_XLSX_STYLE_HEADER => array_replace_recursive($border, [
            'font' => ['name' => 'Aptos', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2F78BC']],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'wrapText' => true,
            ],
        ]),
        DYNABASE_XLSX_STYLE_BODY => $body,
        DYNABASE_XLSX_STYLE_BODY_ALT => array_replace_recursive($body, [
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F6F9FC']],
        ]),
        DYNABASE_XLSX_STYLE_CENTER => array_replace_recursive($body, [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]),
        DYNABASE_XLSX_STYLE_NUMBER => array_replace_recursive($body, [
            'numberFormat' => ['formatCode' => '#,##0'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_DATE => array_replace_recursive($body, [
            'numberFormat' => ['formatCode' => 'dd mmm yyyy'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_RATE_A => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos', 'size' => 9, 'bold' => true, 'color' => ['rgb' => '0E766E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDF3E9']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_RATE_B => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos', 'size' => 9, 'bold' => true, 'color' => ['rgb' => '2E6FAF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DCEEFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_RATE_C => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos', 'size' => 9, 'bold' => true, 'color' => ['rgb' => '9A6500']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFEECF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_RATE_D => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos', 'size' => 9, 'bold' => true, 'color' => ['rgb' => 'B42336']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFE1E4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_TOTAL => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos Display', 'size' => 12, 'bold' => true, 'color' => ['rgb' => '17324A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
            'numberFormat' => ['formatCode' => '#,##0'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_PERCENT => array_replace_recursive($body, [
            'numberFormat' => ['formatCode' => '0.0%'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_MUTED => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos', 'size' => 9, 'color' => ['rgb' => '71859A']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_WRAP => array_replace_recursive($body, [
            'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]),
        DYNABASE_XLSX_STYLE_KPI_LABEL => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos', 'size' => 9, 'bold' => true, 'color' => ['rgb' => '2E6FAF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_KPI_VALUE => array_replace_recursive($body, [
            'font' => ['name' => 'Aptos Display', 'size' => 15, 'bold' => true, 'color' => ['rgb' => '17324A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EAF4FB']],
            'numberFormat' => ['formatCode' => '#,##0'],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]),
        DYNABASE_XLSX_STYLE_LOCATION => array_replace_recursive($border, [
            'font' => ['name' => 'Aptos', 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0E766E']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]),
    ];
}

function dynabaseXlsxWriteCell(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
    string $coordinate,
    mixed $cell,
    array $styles
): void {
    $styleId = DYNABASE_XLSX_STYLE_DEFAULT;
    $type = null;
    $formula = null;
    $value = $cell;

    if (is_array($cell) && array_key_exists('value', $cell)) {
        $value = $cell['value'];
        $styleId = (int) ($cell['style'] ?? DYNABASE_XLSX_STYLE_DEFAULT);
        $type = $cell['type'] ?? null;
        $formula = $cell['formula'] ?? null;
    }

    if ($formula !== null && trim((string) $formula) !== '') {
        $formulaValue = trim((string) $formula);
        $worksheet->setCellValue($coordinate, str_starts_with($formulaValue, '=') ? $formulaValue : '=' . $formulaValue);
    } elseif ($type === 'date') {
        $serial = dynabaseXlsxDateValue($value);
        if ($serial === null) {
            $worksheet->setCellValueExplicit($coordinate, '', DataType::TYPE_STRING);
        } else {
            $worksheet->setCellValueExplicit($coordinate, $serial, DataType::TYPE_NUMERIC);
        }
    } elseif ($type === 'number') {
        $worksheet->setCellValueExplicit($coordinate, is_numeric($value) ? (float) $value : 0, DataType::TYPE_NUMERIC);
    } elseif ($type === 'boolean' || is_bool($value)) {
        $worksheet->setCellValueExplicit($coordinate, (bool) $value, DataType::TYPE_BOOL);
    } elseif ($value === null) {
        $worksheet->setCellValue($coordinate, null);
    } else {
        // Explicit string values also prevent user-entered text beginning with
        // =, +, - or @ from becoming an executable Excel formula.
        $worksheet->setCellValueExplicit($coordinate, dynabaseXlsxCleanText($value), DataType::TYPE_STRING);
    }

    $worksheet->getStyle($coordinate)->applyFromArray($styles[$styleId] ?? $styles[DYNABASE_XLSX_STYLE_DEFAULT]);
}

function dynabaseWriteXlsx(string $path, array $sheets, array $properties = []): void
{
    if ($sheets === []) {
        throw new RuntimeException('The Excel report has no worksheets.', 500);
    }

    if (!class_exists(Spreadsheet::class) || !class_exists(Xlsx::class)) {
        throw new RuntimeException(
            'PhpSpreadsheet is required for Excel reports. Run "composer require phpoffice/phpspreadsheet" in the backend folder, then restart PHP/Apache.',
            500
        );
    }

    $spreadsheet = new Spreadsheet();
    $styles = dynabaseXlsxStyleDefinitions();
    $usedNames = [];

    try {
        foreach (array_values($sheets) as $sheetIndex => $sheetDefinition) {
            $worksheet = $sheetIndex === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet($sheetIndex);

            $sheetName = dynabaseXlsxSafeSheetName(
                (string) ($sheetDefinition['name'] ?? ('Sheet ' . ($sheetIndex + 1))),
                $usedNames
            );
            $usedNames[] = $sheetName;
            $worksheet->setTitle($sheetName);
            $worksheet->setShowGridlines(!empty($sheetDefinition['showGridLines']));

            $rows = is_array($sheetDefinition['rows'] ?? null) ? $sheetDefinition['rows'] : [];
            foreach (array_values($rows) as $rowIndex => $row) {
                $excelRow = $rowIndex + 1;
                foreach (array_values(is_array($row) ? $row : [$row]) as $columnIndex => $cell) {
                    dynabaseXlsxWriteCell(
                        $worksheet,
                        dynabaseXlsxCellReference($excelRow, $columnIndex + 1),
                        $cell,
                        $styles
                    );
                }
            }

            foreach (array_values($sheetDefinition['widths'] ?? []) as $columnIndex => $width) {
                $worksheet->getColumnDimension(dynabaseXlsxColumnLetter($columnIndex + 1))
                    ->setWidth(max(4, min(60, (float) $width)));
            }

            foreach (($sheetDefinition['rowHeights'] ?? []) as $rowNumber => $height) {
                $worksheet->getRowDimension((int) $rowNumber)->setRowHeight((float) $height);
            }

            foreach (array_values(array_filter($sheetDefinition['merges'] ?? [])) as $merge) {
                $worksheet->mergeCells((string) $merge);
            }

            $freezeRows = max(0, (int) ($sheetDefinition['freezeRows'] ?? 0));
            $freezeColumns = max(0, (int) ($sheetDefinition['freezeColumns'] ?? 0));
            if ($freezeRows > 0 || $freezeColumns > 0) {
                $worksheet->freezePane(dynabaseXlsxCellReference($freezeRows + 1, $freezeColumns + 1));
            }

            $autoFilter = trim((string) ($sheetDefinition['autoFilter'] ?? ''));
            if ($autoFilter !== '') {
                $worksheet->setAutoFilter($autoFilter);
            }

            $pageSetup = $worksheet->getPageSetup();
            $pageSetup->setOrientation(!empty($sheetDefinition['landscape'])
                ? PageSetup::ORIENTATION_LANDSCAPE
                : PageSetup::ORIENTATION_PORTRAIT);
            $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
            $pageSetup->setFitToPage(true);
            $pageSetup->setFitToWidth(1);
            $pageSetup->setFitToHeight(0);

            $worksheet->getPageMargins()
                ->setLeft(0.25)
                ->setRight(0.25)
                ->setTop(0.55)
                ->setBottom(0.55)
                ->setHeader(0.2)
                ->setFooter(0.2);

            $worksheet->setSelectedCell('A1');
        }

        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->getProperties()
            ->setCreator((string) ($properties['creator'] ?? 'Dynabase'))
            ->setLastModifiedBy((string) ($properties['creator'] ?? 'Dynabase'))
            ->setTitle((string) ($properties['title'] ?? 'Dynabase Report'))
            ->setSubject((string) ($properties['subject'] ?? 'Project intelligence report'))
            ->setCompany('Lambert Electromec');

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
    } finally {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}

function dynabaseOutputXlsx(string $filename, array $sheets, array $properties = []): never
{
    $safeFilename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'dynabase-report.xlsx';
    if (!str_ends_with(strtolower($safeFilename), '.xlsx')) {
        $safeFilename .= '.xlsx';
    }

    $temporary = tempnam(sys_get_temp_dir(), 'dynabase-xlsx-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary report file.', 500);
    }

    try {
        dynabaseWriteXlsx($temporary, $sheets, $properties);

        // Prevent notices, whitespace or a previous JSON response from being
        // prepended to the ZIP payload and corrupting the downloaded workbook.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . (string) filesize($temporary));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        header('X-Content-Type-Options: nosniff');

        $stream = fopen($temporary, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open the generated Excel report.', 500);
        }
        fpassthru($stream);
        fclose($stream);
    } finally {
        @unlink($temporary);
    }

    exit;
}
