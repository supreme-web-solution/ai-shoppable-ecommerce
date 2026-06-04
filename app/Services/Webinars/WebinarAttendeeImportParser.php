<?php

namespace App\Services\Webinars;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class WebinarAttendeeImportParser
{
    /**
     * @return list<array{full_name: string, email: string}>
     */
    public function parse(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');

        $rows = match ($extension) {
            'csv', 'txt' => $this->parseCsv($file->getRealPath() ?: $file->path()),
            'xlsx' => $this->parseXlsx($file->getRealPath() ?: $file->path()),
            default => throw ValidationException::withMessages([
                'file' => 'Upload a CSV or Excel (.xlsx) file.',
            ]),
        };

        return $this->normalizeRows($rows);
    }

    /**
     * @return list<list<string|null>>
     */
    protected function parseCsv(string $path): array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw ValidationException::withMessages([
                'file' => 'Could not read the uploaded file.',
            ]);
        }

        $content = $this->stripBom($content);
        $delimiter = $this->detectDelimiter($content);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'Could not read the uploaded file.',
            ]);
        }

        $rows = [];
        $isFirstRow = true;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $mapped = array_map(
                fn (mixed $cell): ?string => $cell === null ? null : trim((string) $cell),
                $row,
            );

            if ($isFirstRow && isset($mapped[0])) {
                $mapped[0] = $this->stripBom((string) $mapped[0]);
                $isFirstRow = false;
            }

            if ($this->rowHasContent($mapped)) {
                $rows[] = $mapped;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string|null>>
     */
    protected function parseXlsx(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'file' => 'Could not read the Excel file.',
            ]);
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            $zip->close();

            throw ValidationException::withMessages([
                'file' => 'The Excel file does not contain a readable worksheet.',
            ]);
        }

        $sheet = simplexml_load_string($sheetXml);

        if ($sheet === false) {
            $zip->close();

            throw ValidationException::withMessages([
                'file' => 'Could not parse the Excel worksheet.',
            ]);
        }

        $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];

        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $cells = [];
            $columnIndex = 0;

            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $targetIndex = $this->columnIndexFromCellReference($ref) ?? $columnIndex;

                while ($columnIndex < $targetIndex) {
                    $cells[$columnIndex] = null;
                    $columnIndex++;
                }

                $type = (string) ($cell['t'] ?? '');
                $value = isset($cell->v) ? (string) $cell->v : '';

                if ($type === 's' && $value !== '' && isset($sharedStrings[(int) $value])) {
                    $value = $sharedStrings[(int) $value];
                }

                $cells[$columnIndex] = trim($value) !== '' ? trim($value) : null;
                $columnIndex++;
            }

            if ($this->rowHasContent($cells)) {
                $rows[] = array_values($cells);
            }
        }

        $zip->close();

        return $rows;
    }

    /**
     * @return list<string>
     */
    protected function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = simplexml_load_string($xml);

        if ($document === false) {
            return [];
        }

        $document->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];

        foreach ($document->xpath('//m:si') ?: [] as $item) {
            $text = '';

            foreach ($item->t as $part) {
                $text .= (string) $part;
            }

            foreach ($item->r as $run) {
                foreach ($run->t as $part) {
                    $text .= (string) $part;
                }
            }

            $strings[] = trim($text);
        }

        return $strings;
    }

    protected function columnIndexFromCellReference(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/', strtoupper($reference), $matches)) {
            return null;
        }

        $letters = $matches[1];
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    protected function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\r\n") ?: '';

        if ($firstLine === '') {
            return ',';
        }

        $commaCount = substr_count($firstLine, ',');
        $semicolonCount = substr_count($firstLine, ';');

        return $semicolonCount > $commaCount ? ';' : ',';
    }

    protected function stripBom(string $value): string
    {
        return ltrim($value, "\xEF\xBB\xBF\xFE\xFF");
    }

    /**
     * @param  list<string|null>  $row
     */
    protected function rowHasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<list<string|null>>  $rows
     * @return list<array{full_name: string, email: string}>
     */
    protected function normalizeRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $headerMap = $this->headerIndexes($rows[0]);
        $start = 0;

        if ($headerMap !== null && $this->rowLooksLikeHeader($rows[0], $headerMap)) {
            $start = 1;
        }

        $normalized = [];

        for ($i = $start, $count = count($rows); $i < $count; $i++) {
            $attendee = $this->extractAttendeeFromRow($rows[$i], $headerMap);

            if ($attendee !== null) {
                $normalized[] = $attendee;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array{name: int|null, email: int|null}|null  $headerMap
     * @return array{full_name: string, email: string}|null
     */
    protected function extractAttendeeFromRow(array $row, ?array $headerMap): ?array
    {
        $email = null;
        $fullName = null;

        if ($headerMap !== null && $headerMap['email'] !== null) {
            $email = $this->normalizeEmail($row[$headerMap['email']] ?? null);

            if ($headerMap['name'] !== null) {
                $fullName = trim((string) ($row[$headerMap['name']] ?? ''));
            }
        }

        if ($email === null) {
            foreach ($row as $cell) {
                $candidate = $this->normalizeEmail($cell);

                if ($candidate !== null) {
                    $email = $candidate;

                    break;
                }
            }
        }

        if ($email === null) {
            return null;
        }

        if ($fullName === null || $fullName === '') {
            foreach ($row as $cell) {
                $value = trim((string) ($cell ?? ''));

                if ($value === '' || $this->normalizeEmail($value) !== null) {
                    continue;
                }

                $fullName = $value;

                break;
            }
        }

        if ($fullName === null || $fullName === '') {
            $fullName = $this->deriveNameFromEmail($email);
        }

        return [
            'full_name' => $fullName,
            'email' => $email,
        ];
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim((string) $value));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    protected function deriveNameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');
        $local = str_replace(['.', '_', '-'], ' ', $local);
        $name = Str::title(trim($local));

        return $name !== '' ? $name : 'Attendee';
    }

    /**
     * @param  list<string|null>  $headerRow
     * @param  array{name: int|null, email: int|null}  $headerMap
     */
    protected function rowLooksLikeHeader(array $headerRow, array $headerMap): bool
    {
        if ($this->rowContainsEmail($headerRow)) {
            return false;
        }

        if ($headerMap['email'] !== null) {
            $label = Str::lower(trim((string) ($headerRow[$headerMap['email']] ?? '')));

            if (in_array($label, ['email', 'e-mail', 'mail'], true)) {
                return true;
            }
        }

        if ($headerMap['name'] !== null) {
            $label = Str::lower(trim((string) ($headerRow[$headerMap['name']] ?? '')));

            if (in_array($label, ['name', 'full name', 'full_name', 'fullname', 'attendee', 'attendee name'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string|null>  $row
     */
    protected function rowContainsEmail(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->normalizeEmail($cell) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array{name: int|null, email: int|null}|null
     */
    protected function headerIndexes(array $headerRow): ?array
    {
        $nameIndex = null;
        $emailIndex = null;

        foreach ($headerRow as $index => $cell) {
            $label = Str::lower(trim((string) $cell));

            if ($label === '') {
                continue;
            }

            if (in_array($label, ['email', 'e-mail', 'mail', 'email address', 'email_address'], true)) {
                $emailIndex = (int) $index;
            }

            if (in_array($label, ['name', 'full name', 'full_name', 'fullname', 'attendee', 'attendee name'], true)) {
                $nameIndex = (int) $index;
            }
        }

        if ($nameIndex === null && $emailIndex === null) {
            return null;
        }

        return [
            'name' => $nameIndex,
            'email' => $emailIndex ?? ($nameIndex === 0 ? 1 : 0),
        ];
    }
}
