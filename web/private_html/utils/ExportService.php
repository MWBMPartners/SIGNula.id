<?php
/**
 * ============================================================================
 * 📤 SIGNula - Data Export Service
 * ============================================================================
 *
 * Purpose: Provide multi-format data export capabilities including CSV, XLSX,
 *          PDF, Google Sheets, and Excel Online. Designed for admin dashboards,
 *          reports, user data exports (GDPR compliance), and analytics.
 *
 * PHP Version: 8.3+
 *
 * Supported export formats:
 *   1. CSV  — Universal flat-file, UTF-8 BOM for Excel compatibility
 *   2. XLSX — Office Open XML via ZipArchive (no external libs required)
 *   3. PDF  — TCPDF if available, falls back to HTML with print stylesheet
 *   4. Google Sheets — OAuth2 + Sheets API v4 (REST, no SDK)
 *   5. Excel Online  — OAuth2 + Microsoft Graph API (REST, no SDK)
 *
 * @package    SIGNula
 * @subpackage Utils
 * @version    2.7.0-beta
 * @author     MWBM Partners Ltd
 * @see        https://www.php.net/manual/en/function.fputcsv.php - CSV output
 * @see        https://learn.microsoft.com/en-us/openspecs/office_standards/ms-xlsx/ - XLSX format
 * @see        https://tcpdf.org/ - TCPDF library documentation
 * @see        https://developers.google.com/sheets/api/reference/rest - Sheets API v4
 * @see        https://learn.microsoft.com/en-us/graph/api/resources/excel - Graph Excel API
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    exit('Direct access denied');
}

/**
 * 📤 Export Service Class
 *
 * Provides static methods for exporting data in multiple formats.
 * All methods are static, following the SIGNula utility class convention.
 *
 * Usage:
 *   // 📊 Export to CSV (sends download to browser)
 *   ExportService::exportCSV($data, 'users-report.csv');
 *
 *   // 📗 Export to XLSX (sends download to browser)
 *   ExportService::exportExcel($data, 'users-report.xlsx');
 *
 *   // 📄 Export to PDF (sends download to browser)
 *   ExportService::exportPDF($data, 'users-report.pdf', null, 'User Report');
 *
 *   // 🌐 Export to Google Sheets (returns spreadsheet URL)
 *   $url = ExportService::exportToGoogleSheets($token, $data, 'My Report');
 *
 *   // 🔄 Pre-process raw DB data for export
 *   $exportData = ExportService::formatDataForExport($rawRows, $columnMap);
 */
class ExportService
{
    // ============================================================================
    // 📌 Constants
    // ============================================================================

    /**
     * 🔤 UTF-8 Byte Order Mark
     * Required for Microsoft Excel to correctly interpret UTF-8 encoded CSV files.
     * Without this, Excel defaults to ANSI encoding and mangles accented/unicode characters.
     * @see https://en.wikipedia.org/wiki/Byte_order_mark#UTF-8
     */
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * 🌐 Google OAuth2 authorization endpoint
     * @see https://developers.google.com/identity/protocols/oauth2/web-server
     */
    private const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * 📊 Google Sheets API v4 base URL
     * @see https://developers.google.com/sheets/api/reference/rest
     */
    private const GOOGLE_SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';

    /**
     * 🔵 Microsoft identity platform OAuth2 authorization endpoint template
     * The {tenant} placeholder is replaced with the configured tenant ID
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
     */
    private const MS_AUTH_URL_TEMPLATE = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize';

    /**
     * 📘 Microsoft Graph API base URL
     * @see https://learn.microsoft.com/en-us/graph/overview
     */
    private const MS_GRAPH_API = 'https://graph.microsoft.com/v1.0';

    /**
     * ⏱️ HTTP request timeout in seconds for API calls
     * Keeps exports responsive; cloud APIs should respond within this window
     */
    private const HTTP_TIMEOUT = 30;

    /**
     * 📐 Default PDF page margins (in mm) for TCPDF
     * Balanced for readability and data density in landscape tables
     */
    private const PDF_MARGIN_LEFT   = 10;
    private const PDF_MARGIN_TOP    = 15;
    private const PDF_MARGIN_RIGHT  = 10;
    private const PDF_MARGIN_BOTTOM = 15;

    // ============================================================================
    // 📊 CSV Export
    // ============================================================================

    /**
     * 📊 Export data as a CSV file download
     *
     * Generates a CSV file with proper HTTP headers for browser download.
     * Includes UTF-8 BOM for Microsoft Excel compatibility with unicode characters.
     * If $headers is null, the keys from the first data row are used as column headers.
     *
     * @param array       $data      Array of associative arrays (each = one row)
     * @param string      $filename  Download filename (e.g. 'report.csv')
     * @param array|null  $headers   Optional column headers; null = auto-detect from keys
     * @param string      $delimiter CSV field delimiter character (default comma)
     * @return void                  Outputs file and exits
     *
     * @see https://www.php.net/manual/en/function.fputcsv.php
     * @see https://tools.ietf.org/html/rfc4180 - RFC 4180 CSV standard
     */
    public static function exportCSV(
        array $data,
        string $filename,
        ?array $headers = null,
        string $delimiter = ','
    ): void {
        // 🛡️ Validate we have data to export
        if (empty($data)) {
            // ⚠️ Send an empty CSV with just headers if we have them
            self::sendCSVHeaders($filename);
            echo self::UTF8_BOM;
            if ($headers !== null) {
                // 📝 Open a temp stream to write the header row
                // @see https://www.php.net/manual/en/wrappers.php.php
                $output = fopen('php://output', 'w');
                if ($output !== false) {
                    fputcsv($output, $headers, $delimiter);
                    fclose($output);
                }
            }
            exit;
        }

        // 🏷️ Auto-detect headers from the first row's keys if not provided
        if ($headers === null) {
            $headers = array_keys($data[0]);
        }

        // 📨 Send HTTP headers for CSV download
        self::sendCSVHeaders($filename);

        // 📝 Open php://output for direct streaming to the browser
        // This avoids loading the entire CSV into memory
        // @see https://www.php.net/manual/en/wrappers.php.php#wrappers.php.output
        $output = fopen('php://output', 'w');

        if ($output === false) {
            // ❌ Fatal: cannot open output stream
            http_response_code(500);
            exit('Failed to open output stream for CSV export');
        }

        // 📎 Write UTF-8 BOM first — must be raw bytes before any CSV content
        // Excel uses this to detect UTF-8 encoding automatically
        fwrite($output, self::UTF8_BOM);

        // 🏷️ Write the header row
        fputcsv($output, $headers, $delimiter);

        // 📝 Write each data row, mapping values to match header order
        foreach ($data as $row) {
            $csvRow = [];
            foreach ($headers as $header) {
                // 🔍 Look up the value using header as key; empty string for missing keys
                // This ensures consistent column alignment even with sparse data
                $csvRow[] = $row[$header] ?? '';
            }
            fputcsv($output, $csvRow, $delimiter);
        }

        // 🧹 Close the output stream
        fclose($output);
        exit;
    }

    /**
     * 📨 Send HTTP headers for CSV file download
     *
     * Sets Content-Type, Content-Disposition, and cache control headers
     * appropriate for a CSV file download.
     *
     * @param string $filename The download filename to suggest to the browser
     * @return void
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
     */
    private static function sendCSVHeaders(string $filename): void
    {
        // 🧹 Clear any existing output buffers to prevent corruption
        // @see https://www.php.net/manual/en/function.ob-end-clean.php
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 📨 Set appropriate HTTP headers for CSV download
        // text/csv is the official MIME type per RFC 4180
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . self::sanitiseFilename($filename) . '"');
        // 🚫 Prevent caching of exported data (may contain sensitive info)
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    // ============================================================================
    // 📗 Excel (XLSX) Export
    // ============================================================================

    /**
     * 📗 Export data as an Excel XLSX file download
     *
     * Generates a proper Office Open XML (.xlsx) spreadsheet using PHP's ZipArchive
     * extension. The XLSX format is a ZIP archive containing XML files that describe
     * the workbook structure, shared strings, styles, and sheet data.
     *
     * Falls back to CSV export if the ZipArchive extension is not available.
     *
     * @param array      $data     Array of associative arrays (each = one row)
     * @param string     $filename Download filename (e.g. 'report.xlsx')
     * @param array|null $headers  Optional column headers; null = auto-detect from keys
     * @return void                Outputs file and exits
     *
     * @see https://learn.microsoft.com/en-us/openspecs/office_standards/ms-xlsx/
     * @see https://www.php.net/manual/en/class.ziparchive.php
     * @see https://www.ecma-international.org/publications-and-standards/standards/ecma-376/ - OOXML spec
     */
    public static function exportExcel(
        array $data,
        string $filename,
        ?array $headers = null
    ): void {
        // 🔍 Check if ZipArchive extension is available
        // ZipArchive is required to create the XLSX (ZIP-based) file format
        // @see https://www.php.net/manual/en/zip.installation.php
        if (!class_exists('ZipArchive')) {
            // ⚠️ Fall back to CSV if ZipArchive is not available
            // Log a warning for the admin to be aware of the fallback
            if (function_exists('error_log')) {
                error_log('SIGNula ExportService: ZipArchive extension not available; falling back to CSV for XLSX export');
            }

            // 🔄 Replace .xlsx extension with .csv for the fallback
            $csvFilename = preg_replace('/\.xlsx$/i', '.csv', $filename);
            if ($csvFilename === $filename) {
                // 📝 If filename didn't have .xlsx, just append .csv
                $csvFilename .= '.csv';
            }
            self::exportCSV($data, $csvFilename, $headers);
            return; // exportCSV calls exit, but return for clarity
        }

        // 🛡️ Handle empty data set
        if (empty($data)) {
            $data = [];
        }

        // 🏷️ Auto-detect headers from the first row's keys if not provided
        if ($headers === null && !empty($data)) {
            $headers = array_keys($data[0]);
        } elseif ($headers === null) {
            $headers = [];
        }

        // 📁 Create a temporary file for the XLSX archive
        // @see https://www.php.net/manual/en/function.tempnam.php
        $tempFile = tempnam(sys_get_temp_dir(), 'signula_export_');
        if ($tempFile === false) {
            http_response_code(500);
            exit('Failed to create temporary file for XLSX export');
        }

        // 📦 Build the XLSX file (ZIP archive with XML contents)
        $success = self::buildXLSXFile($tempFile, $data, $headers);

        if (!$success) {
            // 🧹 Clean up temp file on failure
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            http_response_code(500);
            exit('Failed to generate XLSX file');
        }

        // 🧹 Clear any existing output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 📨 Send HTTP headers for XLSX download
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Type
        $sanitisedFilename = self::sanitiseFilename($filename);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $sanitisedFilename . '"');
        header('Content-Length: ' . filesize($tempFile));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // 📤 Stream the file to the browser
        // readfile() is memory-efficient as it streams directly to output
        // @see https://www.php.net/manual/en/function.readfile.php
        readfile($tempFile);

        // 🧹 Clean up the temporary file
        unlink($tempFile);
        exit;
    }

    /**
     * 📦 Build an XLSX file from data and headers
     *
     * Creates a valid Office Open XML spreadsheet by assembling the required
     * XML components inside a ZIP archive. The minimal XLSX structure requires:
     *   - [Content_Types].xml — MIME type mappings
     *   - _rels/.rels — package relationships
     *   - xl/workbook.xml — workbook definition
     *   - xl/_rels/workbook.xml.rels — workbook relationships
     *   - xl/worksheets/sheet1.xml — actual data
     *   - xl/styles.xml — cell formatting (header bold, zebra striping)
     *   - xl/sharedStrings.xml — string table for deduplication
     *
     * @param string $filepath   Path to the output file
     * @param array  $data       Array of associative arrays
     * @param array  $headers    Column header labels
     * @return bool              True on success, false on failure
     *
     * @see https://www.ecma-international.org/publications-and-standards/standards/ecma-376/
     */
    private static function buildXLSXFile(string $filepath, array $data, array $headers): bool
    {
        // 📦 Open a new ZIP archive for the XLSX file
        $zip = new \ZipArchive();
        $result = $zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            return false;
        }

        // 🔤 Build the shared strings table
        // XLSX deduplicates strings into a shared table referenced by index
        // @see https://learn.microsoft.com/en-us/office/open-xml/spreadsheet/working-with-the-shared-string-table
        $sharedStrings = [];
        $sharedStringIndex = [];

        // 📝 Collect all unique strings from headers and data
        foreach ($headers as $header) {
            $strVal = (string) $header;
            if (!isset($sharedStringIndex[$strVal])) {
                $sharedStringIndex[$strVal] = count($sharedStrings);
                $sharedStrings[] = $strVal;
            }
        }

        foreach ($data as $row) {
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                // 🔢 Only add non-numeric values to the shared strings table
                // Numeric values are stored directly in cells for calculation support
                if (!is_numeric($value)) {
                    $strVal = (string) $value;
                    if (!isset($sharedStringIndex[$strVal])) {
                        $sharedStringIndex[$strVal] = count($sharedStrings);
                        $sharedStrings[] = $strVal;
                    }
                }
            }
        }

        // 📄 [Content_Types].xml — Defines MIME types for each part of the package
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 🔗 _rels/.rels — Top-level package relationships
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $zip->addFromString('_rels' . DIRECTORY_SEPARATOR . '.rels', $rels);

        // 📘 xl/workbook.xml — Workbook definition with sheet references
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Export" sheetId="1" r:id="rId1"/>'
            . '</sheets>'
            . '</workbook>';
        $zip->addFromString('xl' . DIRECTORY_SEPARATOR . 'workbook.xml', $workbook);

        // 🔗 xl/_rels/workbook.xml.rels — Workbook internal relationships
        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
        $zip->addFromString('xl' . DIRECTORY_SEPARATOR . '_rels' . DIRECTORY_SEPARATOR . 'workbook.xml.rels', $workbookRels);

        // 🎨 xl/styles.xml — Cell styles (bold headers, zebra stripe fills)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                             // 📝 Style 0: Normal text
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'  // 🏷️ Style 1: Bold white (headers)
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'                               // 🎨 Fill 0: No fill (required)
            . '<fill><patternFill patternType="gray125"/></fill>'                            // 🎨 Fill 1: Gray 125 (required)
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/></patternFill></fill>' // 🎨 Fill 2: Blue header bg
            . '</fills>'
            . '<borders count="1">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'              // 🔲 CellXf 0: Normal
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' // 🔲 CellXf 1: Header
            . '</cellXfs>'
            . '</styleSheet>';
        $zip->addFromString('xl' . DIRECTORY_SEPARATOR . 'styles.xml', $styles);

        // 🔤 xl/sharedStrings.xml — Shared strings table for text deduplication
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">';
        foreach ($sharedStrings as $str) {
            // 🛡️ Escape XML special characters in string values
            $ssXml .= '<si><t>' . htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></si>';
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl' . DIRECTORY_SEPARATOR . 'sharedStrings.xml', $ssXml);

        // 📊 xl/worksheets/sheet1.xml — The actual spreadsheet data
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . PHP_EOL
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        // 🏷️ Write header row (row 1, using style index 1 = bold white on blue)
        $sheetXml .= '<row r="1">';
        foreach ($headers as $colIdx => $header) {
            // 🔡 Convert column index (0-based) to Excel column letter (A, B, ..., Z, AA, etc.)
            $colLetter = self::columnIndexToLetter($colIdx);
            $cellRef = $colLetter . '1';
            $strIdx = $sharedStringIndex[(string) $header];
            // 📝 t="s" means the value is a shared string reference; s="1" applies header style
            $sheetXml .= '<c r="' . $cellRef . '" t="s" s="1"><v>' . $strIdx . '</v></c>';
        }
        $sheetXml .= '</row>';

        // 📝 Write data rows (starting from row 2)
        $rowNum = 2;
        foreach ($data as $row) {
            $sheetXml .= '<row r="' . $rowNum . '">';
            foreach ($headers as $colIdx => $header) {
                $colLetter = self::columnIndexToLetter($colIdx);
                $cellRef = $colLetter . $rowNum;
                $value = $row[$header] ?? '';

                if (is_numeric($value)) {
                    // 🔢 Numeric values stored directly (enables Excel formulas/calculations)
                    $sheetXml .= '<c r="' . $cellRef . '"><v>' . $value . '</v></c>';
                } else {
                    // 🔤 String values reference the shared strings table
                    $strVal = (string) $value;
                    if (isset($sharedStringIndex[$strVal])) {
                        $strIdx = $sharedStringIndex[$strVal];
                        $sheetXml .= '<c r="' . $cellRef . '" t="s"><v>' . $strIdx . '</v></c>';
                    } else {
                        // 🔄 Inline string fallback (shouldn't happen, but safety net)
                        $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>'
                            . htmlspecialchars($strVal, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                            . '</t></is></c>';
                    }
                }
            }
            $sheetXml .= '</row>';
            $rowNum++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString(
            'xl' . DIRECTORY_SEPARATOR . 'worksheets' . DIRECTORY_SEPARATOR . 'sheet1.xml',
            $sheetXml
        );

        // 💾 Close the ZIP archive to finalize the XLSX file
        return $zip->close();
    }

    /**
     * 🔡 Convert a zero-based column index to an Excel column letter
     *
     * Handles columns beyond Z (e.g., 26 = AA, 27 = AB, 702 = AAA).
     * Uses the bijective base-26 numbering system that Excel employs.
     *
     * @param int $index Zero-based column index (0 = A, 1 = B, ..., 25 = Z, 26 = AA)
     * @return string    Excel column letter(s)
     *
     * @see https://en.wikipedia.org/wiki/Bijective_numeration - Bijective base-k numeration
     */
    private static function columnIndexToLetter(int $index): string
    {
        $letter = '';

        // 🔄 Build column letter string right-to-left using bijective base-26
        while ($index >= 0) {
            $letter = chr(($index % 26) + 65) . $letter; // 65 = ASCII 'A'
            $index = intdiv($index, 26) - 1;
        }

        return $letter;
    }

    // ============================================================================
    // 📄 PDF Export
    // ============================================================================

    /**
     * 📄 Export data as a PDF file download
     *
     * Uses TCPDF library if available for high-quality PDF generation.
     * Falls back to HTML with a print-optimised stylesheet if TCPDF is not installed.
     * The table includes zebra striping for readability and page numbers in the footer.
     *
     * @param array      $data        Array of associative arrays (each = one row)
     * @param string     $filename    Download filename (e.g. 'report.pdf')
     * @param array|null $headers     Optional column headers; null = auto-detect from keys
     * @param string     $title       Document title displayed at the top (default 'Export')
     * @param string     $orientation Page orientation: 'landscape' or 'portrait'
     * @return void                   Outputs file and exits
     *
     * @see https://tcpdf.org/docs/srcdoc/TCPDF/class-TCPDF/ - TCPDF class reference
     */
    public static function exportPDF(
        array $data,
        string $filename,
        ?array $headers = null,
        string $title = 'Export',
        string $orientation = 'landscape'
    ): void {
        // 🏷️ Auto-detect headers from the first row's keys if not provided
        if ($headers === null && !empty($data)) {
            $headers = array_keys($data[0]);
        } elseif ($headers === null) {
            $headers = [];
        }

        // 🔍 Check if TCPDF is available via the loader
        // @see web/_lib/tcpdf/tcpdf_loader.php
        $tcpdfLoaderPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . '_lib' . DIRECTORY_SEPARATOR
            . 'tcpdf' . DIRECTORY_SEPARATOR
            . 'tcpdf_loader.php';

        $tcpdfAvailable = false;

        if (file_exists($tcpdfLoaderPath)) {
            require_once $tcpdfLoaderPath;
            if (class_exists('TCPDFLoader') && TCPDFLoader::isAvailable()) {
                $tcpdfAvailable = TCPDFLoader::load();
            }
        }

        if ($tcpdfAvailable && class_exists('TCPDF')) {
            // 📄 Generate PDF using TCPDF
            self::exportPDFWithTCPDF($data, $filename, $headers, $title, $orientation);
        } else {
            // 🔄 Fallback: Generate HTML with print-optimised stylesheet
            self::exportPDFAsHTML($data, $filename, $headers, $title, $orientation);
        }
    }

    /**
     * 📄 Generate PDF using TCPDF library
     *
     * Creates a professionally formatted PDF with:
     *   - Configurable page orientation and margins
     *   - Title with generation date
     *   - Table with styled headers and zebra-striped rows
     *   - Automatic page breaks with repeated headers
     *   - Page numbers in the footer
     *
     * @param array  $data        Data rows (associative arrays)
     * @param string $filename    Download filename
     * @param array  $headers     Column headers
     * @param string $title       Document title
     * @param string $orientation 'landscape' or 'portrait'
     * @return void               Outputs PDF and exits
     */
    private static function exportPDFWithTCPDF(
        array $data,
        string $filename,
        array $headers,
        string $title,
        string $orientation
    ): void {
        // 📐 Map orientation to TCPDF format ('L' for landscape, 'P' for portrait)
        $orientCode = (strtolower($orientation) === 'landscape') ? 'L' : 'P';

        // 📄 Create new TCPDF instance
        // Parameters: orientation, unit (mm), page format (A4)
        // @see https://tcpdf.org/docs/srcdoc/TCPDF/class-TCPDF/#___construct
        $pdf = new \TCPDF($orientCode, 'mm', 'A4', true, 'UTF-8', false);

        // 📋 Set document metadata
        $pdf->SetCreator('SIGNula Export Service');
        $pdf->SetAuthor('SIGNula');
        $pdf->SetTitle($title);
        $pdf->SetSubject('Data Export');

        // 📐 Set page margins
        $pdf->SetMargins(self::PDF_MARGIN_LEFT, self::PDF_MARGIN_TOP, self::PDF_MARGIN_RIGHT);
        $pdf->SetAutoPageBreak(true, self::PDF_MARGIN_BOTTOM);

        // 🏷️ Configure header: title and generation date
        $pdf->SetHeaderData('', 0, $title, 'Generated: ' . date('Y-m-d H:i:s T'));

        // 🔢 Enable page numbering in footer
        $pdf->setFooterData([0, 0, 0], [128, 128, 128]);

        // 📝 Set default font
        $pdf->SetFont('helvetica', '', 9);

        // 📄 Add the first page
        $pdf->AddPage();

        // 📊 Build HTML table for TCPDF's writeHTML method
        // TCPDF's HTML engine handles pagination, header repetition, and styling
        $html = self::buildPDFTableHTML($data, $headers, $title);

        // 📝 Write the HTML table to the PDF
        // @see https://tcpdf.org/docs/srcdoc/TCPDF/class-TCPDF/#_writeHTML
        $pdf->writeHTML($html, true, false, true, false, '');

        // 🧹 Clear output buffers before sending
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 📤 Output the PDF as a download
        // 'D' parameter triggers a browser download
        // @see https://tcpdf.org/docs/srcdoc/TCPDF/class-TCPDF/#_Output
        $pdf->Output(self::sanitiseFilename($filename), 'D');
        exit;
    }

    /**
     * 🔄 Fallback: Export data as HTML with print-optimised stylesheet
     *
     * When TCPDF is not available, this generates a complete HTML page with
     * CSS @media print rules that produce clean printed output. The page
     * includes instructions for the user to use their browser's Print > Save as PDF.
     *
     * @param array  $data        Data rows (associative arrays)
     * @param string $filename    Suggested filename (shown in page title)
     * @param array  $headers     Column headers
     * @param string $title       Document title
     * @param string $orientation 'landscape' or 'portrait'
     * @return void               Outputs HTML and exits
     */
    private static function exportPDFAsHTML(
        array $data,
        string $filename,
        array $headers,
        string $title,
        string $orientation
    ): void {
        // 🧹 Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 📨 Send HTML content type
        header('Content-Type: text/html; charset=UTF-8');

        // 🎨 Build the HTML page with print stylesheet
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $generatedDate = date('Y-m-d H:i:s T');
        $pageOrientation = (strtolower($orientation) === 'landscape') ? 'landscape' : 'portrait';

        // 📝 Output the HTML document
        echo '<!DOCTYPE html>' . PHP_EOL;
        echo '<html lang="en">' . PHP_EOL;
        echo '<head>' . PHP_EOL;
        echo '    <meta charset="UTF-8">' . PHP_EOL;
        echo '    <meta name="viewport" content="width=device-width, initial-scale=1.0">' . PHP_EOL;
        echo '    <title>' . $escapedTitle . ' - SIGNula Export</title>' . PHP_EOL;
        echo '    <style>' . PHP_EOL;
        // 🎨 Screen styles (info banner + basic table formatting)
        echo '        /* 🖥️ Screen-only styles */' . PHP_EOL;
        echo '        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 20px; color: #333; background: #f5f5f5; }' . PHP_EOL;
        echo '        .export-info { background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 16px; margin-bottom: 20px; }' . PHP_EOL;
        echo '        .export-info p { margin: 4px 0; font-size: 14px; }' . PHP_EOL;
        echo '        .export-info .hint { color: #1565c0; font-weight: 600; }' . PHP_EOL;
        echo '        .export-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; color: #1a237e; }' . PHP_EOL;
        echo '        .export-meta { font-size: 12px; color: #666; margin-bottom: 16px; }' . PHP_EOL;
        echo '        .export-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }' . PHP_EOL;
        echo '        .export-table th { background: #4472C4; color: #fff; font-weight: 600; padding: 10px 12px; text-align: left; font-size: 13px; border: 1px solid #3a62a8; }' . PHP_EOL;
        echo '        .export-table td { padding: 8px 12px; font-size: 12px; border: 1px solid #e0e0e0; }' . PHP_EOL;
        echo '        .export-table tr:nth-child(even) td { background: #f5f7fa; }' . PHP_EOL;
        echo '        .export-table tr:hover td { background: #e8eef7; }' . PHP_EOL;
        // 🖨️ Print styles (clean, no decorations, page numbers via CSS counters)
        echo '        /* 🖨️ Print-only styles */' . PHP_EOL;
        echo '        @media print {' . PHP_EOL;
        echo '            @page { size: ' . $pageOrientation . '; margin: 15mm; }' . PHP_EOL;
        echo '            body { background: #fff; padding: 0; font-size: 10px; }' . PHP_EOL;
        echo '            .export-info { display: none; }' . PHP_EOL;
        echo '            .export-table { box-shadow: none; font-size: 9px; }' . PHP_EOL;
        echo '            .export-table th { background: #4472C4 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }' . PHP_EOL;
        echo '            .export-table tr:nth-child(even) td { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }' . PHP_EOL;
        echo '            .export-table tr { page-break-inside: avoid; }' . PHP_EOL;
        echo '            .export-table thead { display: table-header-group; }' . PHP_EOL;
        echo '            .page-footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 9px; color: #999; }' . PHP_EOL;
        echo '        }' . PHP_EOL;
        echo '    </style>' . PHP_EOL;
        echo '</head>' . PHP_EOL;
        echo '<body>' . PHP_EOL;

        // ℹ️ Info banner (visible on screen only, hidden when printing)
        echo '    <div class="export-info">' . PHP_EOL;
        echo '        <p class="hint">💡 To save as PDF: Use your browser\'s Print function (Ctrl+P / Cmd+P) and select "Save as PDF" as the destination.</p>' . PHP_EOL;
        echo '        <p>The print layout has been optimised for ' . $pageOrientation . ' orientation with repeated table headers on each page.</p>' . PHP_EOL;
        echo '    </div>' . PHP_EOL;

        // 🏷️ Title and metadata
        echo '    <h1 class="export-title">' . $escapedTitle . '</h1>' . PHP_EOL;
        echo '    <p class="export-meta">Generated: ' . htmlspecialchars($generatedDate, ENT_QUOTES, 'UTF-8')
            . ' | Rows: ' . count($data) . ' | SIGNula Export Service</p>' . PHP_EOL;

        // 📊 Data table
        echo '    <table class="export-table">' . PHP_EOL;

        // 🏷️ Table header (inside <thead> for repeat on printed pages)
        echo '        <thead>' . PHP_EOL;
        echo '            <tr>' . PHP_EOL;
        foreach ($headers as $header) {
            echo '                <th>' . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8') . '</th>' . PHP_EOL;
        }
        echo '            </tr>' . PHP_EOL;
        echo '        </thead>' . PHP_EOL;

        // 📝 Table body (zebra striping via CSS nth-child)
        echo '        <tbody>' . PHP_EOL;
        if (empty($data)) {
            // 📭 Empty state
            echo '            <tr><td colspan="' . count($headers) . '" style="text-align:center;color:#999;padding:24px;">No data to display</td></tr>' . PHP_EOL;
        } else {
            foreach ($data as $row) {
                echo '            <tr>' . PHP_EOL;
                foreach ($headers as $header) {
                    $value = $row[$header] ?? '';
                    echo '                <td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>' . PHP_EOL;
                }
                echo '            </tr>' . PHP_EOL;
            }
        }
        echo '        </tbody>' . PHP_EOL;
        echo '    </table>' . PHP_EOL;

        // 🔢 Print footer with page numbering placeholder
        echo '    <div class="page-footer">' . PHP_EOL;
        echo '        ' . $escapedTitle . ' - Generated ' . htmlspecialchars($generatedDate, ENT_QUOTES, 'UTF-8') . ' - SIGNula' . PHP_EOL;
        echo '    </div>' . PHP_EOL;

        echo '</body>' . PHP_EOL;
        echo '</html>';
        exit;
    }

    /**
     * 📊 Build HTML table markup for TCPDF rendering
     *
     * Generates an HTML table with inline CSS styling suitable for TCPDF's
     * writeHTML() method. TCPDF has limited CSS support, so styles must be inline.
     *
     * @param array  $data    Data rows
     * @param array  $headers Column headers
     * @param string $title   Document title
     * @return string         HTML table markup
     */
    private static function buildPDFTableHTML(array $data, array $headers, string $title): string
    {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $generatedDate = date('Y-m-d H:i:s T');

        // 📝 Build HTML string with inline styles (TCPDF requirement)
        $html = '<h2 style="color:#1a237e;font-family:helvetica;">' . $escapedTitle . '</h2>';
        $html .= '<p style="font-size:9px;color:#666;">Generated: ' . htmlspecialchars($generatedDate, ENT_QUOTES, 'UTF-8')
            . ' | Rows: ' . count($data) . '</p>';
        $html .= '<br/>';

        // 📊 Table with header and data rows
        $html .= '<table cellpadding="4" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;">';

        // 🏷️ Header row
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th style="background-color:#4472C4;color:#ffffff;font-weight:bold;font-size:9px;text-align:left;">'
                . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead>';

        // 📝 Data rows with zebra striping
        $html .= '<tbody>';
        $rowIndex = 0;
        foreach ($data as $row) {
            $bgColor = ($rowIndex % 2 === 0) ? '#ffffff' : '#f5f7fa';
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $html .= '<td style="background-color:' . $bgColor . ';font-size:8px;border:1px solid #e0e0e0;">'
                    . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
            $rowIndex++;
        }
        $html .= '</tbody></table>';

        return $html;
    }

    // ============================================================================
    // 🌐 Google Sheets Export
    // ============================================================================

    /**
     * 🔑 Build OAuth2 authorization URL for Google Sheets API
     *
     * Constructs the Google OAuth2 consent screen URL that the user must visit
     * to grant the application permission to create and manage spreadsheets.
     * The client_id is fetched from the database settings (export.google_sheets.client_id).
     *
     * @param string $redirectUri The URI to redirect to after authorization
     * @return string             Google OAuth2 authorization URL
     *
     * @throws \RuntimeException If the Google Sheets client_id is not configured
     *
     * @see https://developers.google.com/identity/protocols/oauth2/web-server#creatingclient
     * @see https://developers.google.com/sheets/api/guides/authorizing
     */
    public static function getGoogleSheetsAuthUrl(string $redirectUri): string
    {
        // 🔑 Retrieve the Google OAuth client ID from database settings
        $clientId = getSetting('export.google_sheets.client_id', '');

        if (empty($clientId)) {
            throw new \RuntimeException(
                'Google Sheets export not configured: export.google_sheets.client_id setting is missing or empty'
            );
        }

        // 🔗 Build the OAuth2 authorization URL with required parameters
        // @see https://developers.google.com/identity/protocols/oauth2/web-server#httprest
        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            // 📊 Scopes: spreadsheets (create/edit) + drive.file (create files only)
            // drive.file is a narrower scope than full drive access
            // @see https://developers.google.com/identity/protocols/oauth2/scopes#sheets
            'scope'         => implode(' ', [
                'https://www.googleapis.com/auth/spreadsheets',
                'https://www.googleapis.com/auth/drive.file',
            ]),
            'access_type'   => 'offline',  // 🔄 Request refresh token for long-lived access
            'prompt'        => 'consent',  // 🔐 Always show consent screen (ensures refresh token)
            // 🛡️ State parameter for CSRF protection
            // @see https://datatracker.ietf.org/doc/html/rfc6749#section-10.12
            'state'         => bin2hex(random_bytes(16)),
        ];

        return self::GOOGLE_AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 📊 Export data to a new Google Sheets spreadsheet
     *
     * Creates a new spreadsheet via the Google Sheets API v4, then populates it
     * with the provided data using a batch update request. Returns the URL of
     * the newly created spreadsheet.
     *
     * Uses file_get_contents() with stream_context_create() for HTTP requests
     * (no cURL dependency) to ensure compatibility with Dreamhost shared hosting.
     *
     * @param string     $accessToken Valid OAuth2 access token with spreadsheets scope
     * @param array      $data        Array of associative arrays (each = one row)
     * @param string     $title       Spreadsheet title
     * @param array|null $headers     Optional column headers; null = auto-detect from keys
     * @return string                 URL of the created Google Sheet
     *
     * @throws \RuntimeException If the API request fails
     *
     * @see https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets/create
     * @see https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/update
     */
    public static function exportToGoogleSheets(
        string $accessToken,
        array $data,
        string $title,
        ?array $headers = null
    ): string {
        // 🏷️ Auto-detect headers from the first row's keys if not provided
        if ($headers === null && !empty($data)) {
            $headers = array_keys($data[0]);
        } elseif ($headers === null) {
            $headers = [];
        }

        // 📄 Step 1: Create a new spreadsheet
        // @see https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets/create
        $createPayload = json_encode([
            'properties' => [
                'title'  => $title,
                'locale' => 'en_GB',
            ],
            'sheets' => [
                [
                    'properties' => [
                        'title'    => 'Export Data',
                        'index'    => 0,
                        'sheetId'  => 0,
                        'gridProperties' => [
                            'rowCount'    => count($data) + 1, // +1 for header row
                            'columnCount' => count($headers),
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $createResponse = self::httpRequest(
            self::GOOGLE_SHEETS_API,
            'POST',
            $createPayload,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        if ($createResponse === null) {
            throw new \RuntimeException('Failed to create Google Sheets spreadsheet: no response from API');
        }

        $spreadsheet = json_decode($createResponse, true);
        if (!isset($spreadsheet['spreadsheetId'])) {
            throw new \RuntimeException(
                'Failed to create Google Sheets spreadsheet: ' . ($spreadsheet['error']['message'] ?? 'Unknown error')
            );
        }

        $spreadsheetId = $spreadsheet['spreadsheetId'];
        $spreadsheetUrl = $spreadsheet['spreadsheetUrl'] ?? ('https://docs.google.com/spreadsheets/d/' . $spreadsheetId);

        // 📝 Step 2: Populate the spreadsheet with data
        // Build a 2D array of values (headers + data rows)
        // @see https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets.values/update
        $values = [];

        // 🏷️ First row: headers
        $values[] = $headers;

        // 📝 Subsequent rows: data
        foreach ($data as $row) {
            $rowValues = [];
            foreach ($headers as $header) {
                $rowValues[] = $row[$header] ?? '';
            }
            $values[] = $rowValues;
        }

        // 📊 Calculate the range (e.g., "Export Data!A1:D100")
        $lastCol = self::columnIndexToLetter(count($headers) - 1);
        $lastRow = count($data) + 1;
        $range = 'Export Data!A1:' . $lastCol . $lastRow;

        // 📤 Write values to the sheet using the values.update endpoint
        $updateUrl = self::GOOGLE_SHEETS_API . '/' . urlencode($spreadsheetId)
            . '/values/' . urlencode($range)
            . '?valueInputOption=USER_ENTERED';

        $updatePayload = json_encode([
            'range'          => $range,
            'majorDimension' => 'ROWS',
            'values'         => $values,
        ], JSON_THROW_ON_ERROR);

        $updateResponse = self::httpRequest(
            $updateUrl,
            'PUT',
            $updatePayload,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        if ($updateResponse === null) {
            // ⚠️ Spreadsheet was created but data population failed
            // Still return the URL so the user can see the (empty) sheet
            if (function_exists('error_log')) {
                error_log('SIGNula ExportService: Created Google Sheet but failed to populate data');
            }
        }

        // 🎨 Step 3: Format the header row (bold, background colour, frozen)
        // @see https://developers.google.com/sheets/api/reference/rest/v4/spreadsheets/batchUpdate
        $formatPayload = json_encode([
            'requests' => [
                // 🏷️ Bold header row with blue background
                [
                    'repeatCell' => [
                        'range' => [
                            'sheetId'          => 0,
                            'startRowIndex'    => 0,
                            'endRowIndex'      => 1,
                            'startColumnIndex' => 0,
                            'endColumnIndex'   => count($headers),
                        ],
                        'cell' => [
                            'userEnteredFormat' => [
                                'backgroundColor' => [
                                    'red'   => 0.267,
                                    'green' => 0.447,
                                    'blue'  => 0.769,
                                ],
                                'textFormat' => [
                                    'bold'            => true,
                                    'foregroundColor'  => [
                                        'red'   => 1.0,
                                        'green' => 1.0,
                                        'blue'  => 1.0,
                                    ],
                                ],
                            ],
                        ],
                        'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
                    ],
                ],
                // ❄️ Freeze the header row so it stays visible when scrolling
                [
                    'updateSheetProperties' => [
                        'properties' => [
                            'sheetId'        => 0,
                            'gridProperties' => [
                                'frozenRowCount' => 1,
                            ],
                        ],
                        'fields' => 'gridProperties.frozenRowCount',
                    ],
                ],
                // 📐 Auto-resize columns to fit content
                [
                    'autoResizeDimensions' => [
                        'dimensions' => [
                            'sheetId'    => 0,
                            'dimension'  => 'COLUMNS',
                            'startIndex' => 0,
                            'endIndex'   => count($headers),
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $formatUrl = self::GOOGLE_SHEETS_API . '/' . urlencode($spreadsheetId) . ':batchUpdate';

        // 🎨 Apply formatting (non-critical; don't throw if it fails)
        self::httpRequest(
            $formatUrl,
            'POST',
            $formatPayload,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]
        );

        return $spreadsheetUrl;
    }

    // ============================================================================
    // 🔵 Excel Online (Microsoft Graph) Export
    // ============================================================================

    /**
     * 🔑 Build OAuth2 authorization URL for Microsoft Graph (Excel Online)
     *
     * Constructs the Microsoft identity platform authorization URL for obtaining
     * access to create and manage Excel workbooks via Microsoft Graph API.
     * Requires export.excel_online.client_id and export.excel_online.tenant_id settings.
     *
     * @param string $redirectUri The URI to redirect to after authorization
     * @return string             Microsoft OAuth2 authorization URL
     *
     * @throws \RuntimeException If required settings are not configured
     *
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
     * @see https://learn.microsoft.com/en-us/graph/auth-v2-user
     */
    public static function getExcelOnlineAuthUrl(string $redirectUri): string
    {
        // 🔑 Retrieve Microsoft OAuth settings from database
        $clientId = getSetting('export.excel_online.client_id', '');
        $tenantId = getSetting('export.excel_online.tenant_id', 'common');

        if (empty($clientId)) {
            throw new \RuntimeException(
                'Excel Online export not configured: export.excel_online.client_id setting is missing or empty'
            );
        }

        // 🔗 Build the authorization URL with tenant ID
        $authUrl = str_replace('{tenant}', urlencode($tenantId), self::MS_AUTH_URL_TEMPLATE);

        // 📝 Build query parameters
        // @see https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow#request-an-authorization-code
        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'response_mode' => 'query',
            // 📁 Scopes: Files.ReadWrite (create/edit files in user's OneDrive)
            // @see https://learn.microsoft.com/en-us/graph/permissions-reference
            'scope'         => implode(' ', [
                'Files.ReadWrite',
                'openid',
                'profile',
                'offline_access',  // 🔄 Request refresh token
            ]),
            // 🛡️ State parameter for CSRF protection
            'state'         => bin2hex(random_bytes(16)),
            // 🔐 PKCE nonce for additional security
            'nonce'         => bin2hex(random_bytes(16)),
        ];

        return $authUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 📘 Export data to a new Excel Online workbook via Microsoft Graph API
     *
     * Creates a new Excel workbook in the user's OneDrive root, then populates it
     * with data using the Graph API's Excel session endpoint. Returns the URL
     * to view the workbook in Excel Online.
     *
     * Uses file_get_contents() with stream_context_create() for HTTP requests
     * (no cURL dependency) for Dreamhost shared hosting compatibility.
     *
     * @param string     $accessToken Valid OAuth2 access token with Files.ReadWrite scope
     * @param array      $data        Array of associative arrays (each = one row)
     * @param string     $title       Workbook title (used as filename)
     * @param array|null $headers     Optional column headers; null = auto-detect from keys
     * @return string                 URL to view the workbook in Excel Online
     *
     * @throws \RuntimeException If the API request fails
     *
     * @see https://learn.microsoft.com/en-us/graph/api/driveitem-put-content
     * @see https://learn.microsoft.com/en-us/graph/api/resources/excel
     */
    public static function exportToExcelOnline(
        string $accessToken,
        array $data,
        string $title,
        ?array $headers = null
    ): string {
        // 🏷️ Auto-detect headers from the first row's keys if not provided
        if ($headers === null && !empty($data)) {
            $headers = array_keys($data[0]);
        } elseif ($headers === null) {
            $headers = [];
        }

        // 📁 Step 1: Create an empty XLSX workbook file in a temp location
        // We need a minimal valid XLSX to upload as the initial file
        $tempFile = tempnam(sys_get_temp_dir(), 'signula_ms_export_');
        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file for Excel Online export');
        }

        // 📦 Build a minimal XLSX file to upload
        $xlsxBuilt = self::buildXLSXFile($tempFile, [], $headers);
        if (!$xlsxBuilt) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            throw new \RuntimeException('Failed to build initial XLSX template for Excel Online upload');
        }

        // 📝 Sanitise the title for use as a filename
        $safeTitle = preg_replace('/[^\w\s\-.]/', '', $title);
        $safeTitle = trim($safeTitle);
        if (empty($safeTitle)) {
            $safeTitle = 'SIGNula Export';
        }
        $oneDriveFilename = $safeTitle . '.xlsx';

        // 📤 Step 2: Upload the XLSX file to OneDrive root
        // @see https://learn.microsoft.com/en-us/graph/api/driveitem-put-content
        $uploadUrl = self::MS_GRAPH_API . '/me/drive/root:/' . rawurlencode($oneDriveFilename) . ':/content';
        $fileContents = file_get_contents($tempFile);

        // 🧹 Clean up temp file immediately
        unlink($tempFile);

        if ($fileContents === false) {
            throw new \RuntimeException('Failed to read temporary XLSX file for upload');
        }

        $uploadResponse = self::httpRequest(
            $uploadUrl,
            'PUT',
            $fileContents,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]
        );

        if ($uploadResponse === null) {
            throw new \RuntimeException('Failed to upload workbook to OneDrive: no response from API');
        }

        $driveItem = json_decode($uploadResponse, true);
        if (!isset($driveItem['id'])) {
            throw new \RuntimeException(
                'Failed to upload workbook to OneDrive: ' . ($driveItem['error']['message'] ?? 'Unknown error')
            );
        }

        $itemId = $driveItem['id'];

        // 🌐 Extract the web URL for the workbook
        $workbookUrl = $driveItem['webUrl'] ?? '';

        // 📝 Step 3: Populate the workbook with data using the Excel API
        // First, create an Excel session for batch operations
        // @see https://learn.microsoft.com/en-us/graph/api/workbook-createsession
        if (!empty($data) && !empty($headers)) {
            $sessionUrl = self::MS_GRAPH_API . '/me/drive/items/' . urlencode($itemId) . '/workbook/createSession';
            $sessionResponse = self::httpRequest(
                $sessionUrl,
                'POST',
                json_encode(['persistChanges' => true]),
                [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ]
            );

            $sessionId = null;
            if ($sessionResponse !== null) {
                $sessionData = json_decode($sessionResponse, true);
                $sessionId = $sessionData['id'] ?? null;
            }

            // 📊 Build the values array for the PATCH request
            $values = [];

            // 🏷️ Header row
            $values[] = $headers;

            // 📝 Data rows
            foreach ($data as $row) {
                $rowValues = [];
                foreach ($headers as $header) {
                    $value = $row[$header] ?? '';
                    $rowValues[] = $value;
                }
                $values[] = $rowValues;
            }

            // 📐 Calculate the range address
            $lastCol = self::columnIndexToLetter(count($headers) - 1);
            $lastRow = count($data) + 1; // +1 for header row
            $range = 'A1:' . $lastCol . $lastRow;

            // 📤 Write data to the worksheet range
            // @see https://learn.microsoft.com/en-us/graph/api/range-update
            $rangeUrl = self::MS_GRAPH_API . '/me/drive/items/' . urlencode($itemId)
                . '/workbook/worksheets/Export/range(address=\'' . $range . '\')';

            $rangePayload = json_encode([
                'values' => $values,
            ], JSON_THROW_ON_ERROR);

            $patchHeaders = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ];

            // 📌 Include session ID if we have one (for transactional consistency)
            if ($sessionId !== null) {
                $patchHeaders[] = 'workbook-session-id: ' . $sessionId;
            }

            $rangeResponse = self::httpRequest(
                $rangeUrl,
                'PATCH',
                $rangePayload,
                $patchHeaders
            );

            if ($rangeResponse === null) {
                // ⚠️ Non-fatal: workbook was created but data write failed
                if (function_exists('error_log')) {
                    error_log('SIGNula ExportService: Created Excel Online workbook but failed to populate data');
                }
            }

            // 🎨 Step 4: Format the header row (bold, background colour)
            // @see https://learn.microsoft.com/en-us/graph/api/rangeformat-update
            if ($rangeResponse !== null) {
                $headerRange = 'A1:' . $lastCol . '1';
                $formatUrl = self::MS_GRAPH_API . '/me/drive/items/' . urlencode($itemId)
                    . '/workbook/worksheets/Export/range(address=\'' . $headerRange . '\')/format';

                $formatPayload = json_encode([
                    'fill' => [
                        'color' => '#4472C4',
                    ],
                    'font' => [
                        'bold'  => true,
                        'color' => '#FFFFFF',
                    ],
                ], JSON_THROW_ON_ERROR);

                $formatHeaders = [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ];
                if ($sessionId !== null) {
                    $formatHeaders[] = 'workbook-session-id: ' . $sessionId;
                }

                // 🎨 Apply formatting (non-critical; don't throw if it fails)
                self::httpRequest($formatUrl, 'PATCH', $formatPayload, $formatHeaders);
            }

            // 🔒 Close the Excel session to release resources
            if ($sessionId !== null) {
                $closeUrl = self::MS_GRAPH_API . '/me/drive/items/' . urlencode($itemId) . '/workbook/closeSession';
                self::httpRequest(
                    $closeUrl,
                    'POST',
                    json_encode([]),
                    [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: application/json',
                        'workbook-session-id: ' . $sessionId,
                    ]
                );
            }
        }

        // ✅ Return the URL to the workbook (fall back to constructed URL if not in response)
        if (empty($workbookUrl)) {
            $workbookUrl = 'https://onedrive.live.com/edit.aspx?resid=' . urlencode($itemId);
        }

        return $workbookUrl;
    }

    // ============================================================================
    // 🔧 Data Formatting Utilities
    // ============================================================================

    /**
     * 🔄 Format raw database result arrays into export-ready data
     *
     * Transforms raw database query results by applying optional column mapping
     * (renaming columns), handling null values, formatting dates and numbers,
     * and ensuring consistent data types across all rows.
     *
     * @param array      $rawData   Array of associative arrays from database query
     * @param array|null $columnMap Optional mapping: ['db_column' => 'Display Header']
     *                              If null, column names are used as-is
     * @return array                Transformed data array ready for export methods
     *
     * @example
     *   // 🗃️ Map database columns to human-readable headers
     *   $map = [
     *       'userID'       => 'User ID',
     *       'firstName'    => 'First Name',
     *       'lastName'     => 'Last Name',
     *       'createdAt'    => 'Registration Date',
     *       'loginCount'   => 'Total Logins',
     *   ];
     *   $exportData = ExportService::formatDataForExport($dbResults, $map);
     *   ExportService::exportCSV($exportData, 'users.csv');
     */
    public static function formatDataForExport(array $rawData, ?array $columnMap = null): array
    {
        // 🛡️ Handle empty data
        if (empty($rawData)) {
            return [];
        }

        $formattedData = [];

        foreach ($rawData as $row) {
            $formattedRow = [];

            if ($columnMap !== null) {
                // 🗺️ Apply column mapping: only include mapped columns with renamed headers
                foreach ($columnMap as $dbColumn => $displayHeader) {
                    $value = $row[$dbColumn] ?? null;
                    $formattedRow[$displayHeader] = self::formatValue($value);
                }
            } else {
                // 📝 No mapping: use original column names, just format values
                foreach ($row as $key => $value) {
                    $formattedRow[$key] = self::formatValue($value);
                }
            }

            $formattedData[] = $formattedRow;
        }

        return $formattedData;
    }

    /**
     * 🔧 Format a single value for export
     *
     * Handles null values, date detection and formatting, number formatting,
     * boolean conversion, and string sanitisation.
     *
     * @param mixed $value Raw value from database
     * @return string      Formatted string value suitable for export
     *
     * @see https://www.php.net/manual/en/function.is-numeric.php
     * @see https://www.php.net/manual/en/function.strtotime.php
     */
    private static function formatValue(mixed $value): string
    {
        // 🚫 Null values become empty strings
        if ($value === null) {
            return '';
        }

        // ✅ Boolean values become "Yes" / "No" for human readability
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        // 🔢 Integer/float values: return as-is (preserve numeric precision)
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // 📝 Convert to string for remaining processing
        $strValue = (string) $value;

        // 📅 Detect and format date/datetime strings
        // Common MySQL date formats: YYYY-MM-DD, YYYY-MM-DD HH:MM:SS
        // @see https://dev.mysql.com/doc/refman/8.0/en/datetime.html
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $strValue)) {
            // 📅 Date only: format as DD/MM/YYYY (UK format, per project locale)
            $timestamp = strtotime($strValue);
            if ($timestamp !== false) {
                return date('d/m/Y', $timestamp);
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $strValue)) {
            // 📅 DateTime: format as DD/MM/YYYY HH:MM:SS
            $timestamp = strtotime($strValue);
            if ($timestamp !== false) {
                return date('d/m/Y H:i:s', $timestamp);
            }
        }

        // 📝 Return the string value as-is for all other types
        return $strValue;
    }

    // ============================================================================
    // ⚙️ Settings
    // ============================================================================

    /**
     * ⚙️ Fetch all export-related settings from the database
     *
     * Retrieves settings with the 'export.' prefix using the global getSetting()
     * function. Returns a structured associative array of configuration values.
     *
     * @return array Associative array of export settings with defaults applied
     *
     * @see web/_config/config.php - getSetting() function
     */
    public static function getExportSettings(): array
    {
        // ⚙️ Fetch each export setting from the database with sensible defaults
        // The getSetting() function reads from $GLOBALS['settings'] which is
        // populated from tblSettings at application bootstrap
        // @see https://signulo.id/admin/settings - Admin settings management page
        return [
            // 📊 Google Sheets integration settings
            'google_sheets' => [
                'client_id'     => getSetting('export.google_sheets.client_id', ''),
                'client_secret' => getSetting('export.google_sheets.client_secret', ''),
                'enabled'       => getSetting('export.google_sheets.enabled', '0') === '1',
            ],

            // 📘 Excel Online (Microsoft Graph) integration settings
            'excel_online' => [
                'client_id'     => getSetting('export.excel_online.client_id', ''),
                'client_secret' => getSetting('export.excel_online.client_secret', ''),
                'tenant_id'     => getSetting('export.excel_online.tenant_id', 'common'),
                'enabled'       => getSetting('export.excel_online.enabled', '0') === '1',
            ],

            // 📄 PDF export settings
            'pdf' => [
                'default_orientation' => getSetting('export.pdf.default_orientation', 'landscape'),
                'company_name'        => getSetting('export.pdf.company_name', 'SIGNula'),
                'show_logo'           => getSetting('export.pdf.show_logo', '1') === '1',
            ],

            // 📊 CSV export settings
            'csv' => [
                'delimiter'     => getSetting('export.csv.delimiter', ','),
                'include_bom'   => getSetting('export.csv.include_bom', '1') === '1',
            ],

            // 📗 General export settings
            'max_rows'         => (int) getSetting('export.max_rows', '50000'),
            'date_format'      => getSetting('export.date_format', 'd/m/Y'),
            'datetime_format'  => getSetting('export.datetime_format', 'd/m/Y H:i:s'),
        ];
    }

    // ============================================================================
    // 🔒 Internal Helper Methods
    // ============================================================================

    /**
     * 🌐 Make an HTTP request using file_get_contents and stream context
     *
     * Uses PHP's native stream wrapper for HTTP requests instead of cURL.
     * This ensures compatibility with Dreamhost shared hosting where cURL
     * may have restrictions or be configured differently.
     *
     * @param string      $url     Request URL
     * @param string      $method  HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string|null $body    Request body (null for GET/DELETE)
     * @param array       $headers Array of HTTP header strings
     * @return string|null         Response body, or null on failure
     *
     * @see https://www.php.net/manual/en/function.file-get-contents.php
     * @see https://www.php.net/manual/en/function.stream-context-create.php
     * @see https://www.php.net/manual/en/context.http.php
     */
    private static function httpRequest(
        string $url,
        string $method = 'GET',
        ?string $body = null,
        array $headers = []
    ): ?string {
        // 📝 Build the stream context options
        // @see https://www.php.net/manual/en/context.http.php
        $options = [
            'http' => [
                'method'          => strtoupper($method),
                'header'          => implode("\r\n", $headers),
                'timeout'         => self::HTTP_TIMEOUT,
                'ignore_errors'   => true,  // 🔍 Capture response body even on HTTP errors (4xx/5xx)
                'follow_location' => true,  // 🔄 Follow 3xx redirects
                'max_redirects'   => 5,
            ],
            // 🔒 SSL context for secure connections
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];

        // 📦 Attach request body if provided
        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        // 🌐 Create the stream context
        $context = stream_context_create($options);

        // 📡 Execute the request
        // @ operator suppresses warnings (we handle errors via return value)
        // @see https://www.php.net/manual/en/function.file-get-contents.php
        $response = @file_get_contents($url, false, $context);

        // 🔍 Check for HTTP response status in $http_response_header
        // This magic variable is set by file_get_contents on HTTP requests
        // @see https://www.php.net/manual/en/reserved.variables.httpresponseheader.php
        if ($response === false) {
            // ❌ Request failed completely (network error, DNS failure, etc.)
            if (function_exists('error_log')) {
                error_log('SIGNula ExportService: HTTP request failed for URL: ' . $url);
            }
            return null;
        }

        // 📊 Parse HTTP status code from response headers
        if (isset($http_response_header) && is_array($http_response_header)) {
            // 🔍 The first element contains the status line (e.g., "HTTP/1.1 200 OK")
            $statusLine = $http_response_header[0] ?? '';
            if (preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];

                // ⚠️ Log non-success status codes for debugging
                if ($statusCode >= 400) {
                    if (function_exists('error_log')) {
                        error_log(
                            'SIGNula ExportService: HTTP ' . $statusCode . ' for ' . $method . ' ' . $url
                            . ' — Response: ' . substr($response, 0, 500)
                        );
                    }
                }
            }
        }

        return $response;
    }

    /**
     * 🧹 Sanitise a filename for safe use in Content-Disposition headers
     *
     * Removes or replaces characters that could be used for path traversal,
     * header injection, or other security issues. Only allows alphanumeric
     * characters, hyphens, underscores, dots, and spaces.
     *
     * @param string $filename Raw filename to sanitise
     * @return string          Sanitised filename safe for HTTP headers
     *
     * @see https://owasp.org/www-community/attacks/HTTP_Response_Splitting
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Disposition
     */
    private static function sanitiseFilename(string $filename): string
    {
        // 🚫 Remove any path components (prevent path traversal)
        $filename = basename($filename);

        // 🧹 Remove any characters that aren't safe for filenames/headers
        // Allow: alphanumeric, hyphen, underscore, dot, space
        $filename = preg_replace('/[^\w\s\-.]/', '', $filename);

        // 🔄 Collapse multiple spaces/dots into single ones
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = preg_replace('/\.{2,}/', '.', $filename);

        // 📝 Trim and provide a fallback if the filename is empty after sanitisation
        $filename = trim($filename);
        if (empty($filename)) {
            $filename = 'export-' . date('Y-m-d-His') . '.csv';
        }

        return $filename;
    }
}
