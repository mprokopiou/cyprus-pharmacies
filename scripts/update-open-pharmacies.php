<?php

declare(strict_types=1);

const API_BASE = 'https://www.data.gov.cy/api/action/datastore/search.json';
const DATASET_URL = 'https://www.data.gov.cy/el/dataset/katalogos-diimereyonton-farmakeion';
const TIMEZONE = 'Europe/Nicosia';
const PAGE_LIMIT = 500;

$resources = [
    [
        'district' => 'Λευκωσία',
        'slug' => 'nicosia',
        'resource_id' => '468b39e8-811d-4586-8e5e-37533d801575',
    ],
    [
        'district' => 'Λεμεσός',
        'slug' => 'limassol',
        'resource_id' => '97282b19-bc01-48e4-983e-5ff65a1fb135',
    ],
    [
        'district' => 'Λάρνακα',
        'slug' => 'larnaca',
        'resource_id' => '84ff41ba-65b8-4ec7-9f31-8130fbe2d1b1',
    ],
    [
        'district' => 'Αμμόχωστος',
        'slug' => 'famagusta',
        'resource_id' => 'cdf7ff43-1928-4e6a-a3b9-ff4228cefbfe',
    ],
    [
        'district' => 'Πάφος',
        'slug' => 'paphos',
        'resource_id' => '802df2db-2b28-4bb3-b355-e437acdf728d',
    ],
];

$options = getopt('', ['date:']);

$timezone = new DateTimeZone(TIMEZONE);
$targetDate = isset($options['date'])
    ? new DateTimeImmutable($options['date'], $timezone)
    : new DateTimeImmutable('now', $timezone);

$outputPath = __DIR__ . '/../data/open-pharmacies.json';
$coordinatesPath = __DIR__ . '/../data/pharmacy-coordinates.json';

$coordinates = loadJsonMap($coordinatesPath);

$pharmacies = [];
$errors = [];

foreach ($resources as $resource) {
    try {
        $records = fetchAllRecords($resource['resource_id']);
    } catch (Throwable $exception) {
        $errors[] = sprintf(
            '%s failed: %s',
            $resource['district'],
            $exception->getMessage()
        );

        continue;
    }

    foreach ($records as $record) {
        $recordDateRaw = recordValue($record, ['Date', 'Ημερομηνία']);
        $recordDate = parseRecordDate($recordDateRaw, $timezone);

        if ($recordDate === null || $recordDate->format('Y-m-d') !== $targetDate->format('Y-m-d')) {
            continue;
        }

        $regNo = recordValue($record, ['Reg. No.', 'Reg No.', 'AM', 'ΑΜ', 'AM (Reg. No.)']);
        $surname = recordValue($record, ['Surmame', 'Surname', 'Επίθετο']);
        $name = recordValue($record, ['Name', 'Ονομα', 'Όνομα']);
        $address = recordValue($record, ['Address', 'Διεύθυνση']);
        $additionalInfo = recordValue($record, ['Additional Address Info', 'Συμπληρωματική Διεύθυνση']);
        $area = recordValue($record, ['Muniuciplity / Community', 'Municipality / Community', 'Municipality/Community', 'Δήμος / Κοινότητα']);
        $phone = recordValue($record, ['Pharmacy Tel. No.', 'Pharmacy Tel No', 'Τηλέφωνο Φαρμακείου']);
        $housePhone = recordValue($record, ['House Tel. No.', 'House Tel No', 'Τηλέφωνο Οικίας']);
        $day = recordValue($record, ['Day', 'Ημέρα']);

        $coords = findCoordinates(
            coordinates: $coordinates,
            districtSlug: $resource['slug'],
            regNo: $regNo,
            phone: $phone
        );

        $fullName = trim(sprintf('%s %s', $surname, $name));

        $pharmacies[] = [
            'id' => sprintf('%s-%s-%s', $resource['slug'], safeId($regNo), $targetDate->format('Ymd')),
            'regNo' => $regNo,
            'name' => $fullName !== '' ? $fullName : 'Pharmacy',
            'district' => $resource['district'],
            'area' => $area,
            'address' => $address,
            'additionalInfo' => $additionalInfo,
            'phone' => cleanPhone($phone),
            'housePhone' => cleanPhone($housePhone),
            'date' => $targetDate->format('Y-m-d'),
            'day' => $day,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
        ];
    }
}

usort(
    $pharmacies,
    static fn (array $a, array $b): int =>
        [$a['district'], $a['area'], $a['name']] <=> [$b['district'], $b['area'], $b['name']]
);

$missingCoordinates = count(array_filter(
    $pharmacies,
    static fn (array $pharmacy): bool => $pharmacy['lat'] === null || $pharmacy['lng'] === null
));

$payload = [
    'updatedAt' => (new DateTimeImmutable('now', $timezone))->format(DateTimeInterface::ATOM),
    'date' => $targetDate->format('Y-m-d'),
    'source' => 'Pharmaceutical Services / data.gov.cy - Κατάλογος Διημερευόντων Φαρμακείων',
    'sourceUrl' => DATASET_URL,
    'license' => 'Creative Commons Attribution 4.0 International',
    'total' => count($pharmacies),
    'missingCoordinates' => $missingCoordinates,
    'pharmacies' => $pharmacies,
];

writeJsonFile($outputPath, $payload);

echo sprintf(
    "Generated %s with %d pharmacies for %s. Missing coordinates: %d\n",
    $outputPath,
    count($pharmacies),
    $targetDate->format('Y-m-d'),
    $missingCoordinates
);

if ($errors !== []) {
    fwrite(STDERR, "Importer completed with errors:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }

    exit(2);
}

function fetchAllRecords(string $resourceId): array
{
    $records = [];
    $offset = 0;
    $total = null;

    do {
        $url = API_BASE . '?' . http_build_query([
            'resource_id' => $resourceId,
            'limit' => PAGE_LIMIT,
            'offset' => $offset,
        ]);

        $response = httpGetJson($url);

        if (!isset($response['success']) || $response['success'] !== true) {
            throw new RuntimeException('API returned success=false');
        }

        $pageRecords = $response['result']['records'] ?? [];
        $total = (int) ($response['result']['total'] ?? count($pageRecords));

        $records = array_merge($records, $pageRecords);
        $offset += PAGE_LIMIT;
    } while ($offset < $total);

    return $records;
}

function httpGetJson(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header' => "User-Agent: cyprus-pharmacies-map/1.0\r\n",
        ],
    ]);

    $body = file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException("Could not fetch {$url}");
    }

    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from {$url}");
    }

    return $decoded;
}

function recordValue(array $record, array $candidateKeys): string
{
    foreach ($candidateKeys as $key) {
        if (array_key_exists($key, $record)) {
            return cleanValue($record[$key]);
        }
    }

    $normalizedRecord = [];

    foreach ($record as $key => $value) {
        $normalizedRecord[normalizeKey((string) $key)] = $value;
    }

    foreach ($candidateKeys as $key) {
        $normalizedKey = normalizeKey($key);

        if (array_key_exists($normalizedKey, $normalizedRecord)) {
            return cleanValue($normalizedRecord[$normalizedKey]);
        }
    }

    return '';
}

function cleanValue(mixed $value): string
{
    $text = trim((string) $value);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return $text === '0' ? '' : $text;
}

function normalizeKey(string $key): string
{
    $key = strtolower($key);
    $key = preg_replace('/[^a-z0-9α-ωάέήίόύώϊϋΐΰ]+/u', '', $key);

    return $key ?? '';
}

function parseRecordDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $variants = [
        $value,
        str_replace('-', '/', $value),
    ];

    $formats = [
        '!d/m/y',
        '!d/m/Y',
        '!Y-m-d',
        '!Y/m/d',
    ];

    foreach ($variants as $variant) {
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $variant, $timezone);
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $date instanceof DateTimeImmutable
                && (
                    $errors === false
                    || ((int) $errors['warning_count'] === 0 && (int) $errors['error_count'] === 0)
                )
            ) {
                return $date;
            }
        }
    }

    return null;
}

function cleanPhone(string $phone): string
{
    return preg_replace('/[^0-9+]/', '', $phone) ?? '';
}

function safeId(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return bin2hex(random_bytes(4));
    }

    return preg_replace('/[^a-zA-Z0-9_-]+/', '-', $value) ?? $value;
}

function findCoordinates(array $coordinates, string $districtSlug, string $regNo, string $phone): array
{
    $phone = cleanPhone($phone);

    $candidateKeys = array_filter([
        $regNo !== '' ? sprintf('district:%s|reg:%s', $districtSlug, $regNo) : null,
        $regNo !== '' ? sprintf('reg:%s', $regNo) : null,
        $regNo !== '' ? $regNo : null,
        $phone !== '' ? sprintf('phone:%s', $phone) : null,
        $phone !== '' ? $phone : null,
    ]);

    foreach ($candidateKeys as $key) {
        if (
            isset($coordinates[$key]['lat'], $coordinates[$key]['lng'])
            && is_numeric($coordinates[$key]['lat'])
            && is_numeric($coordinates[$key]['lng'])
        ) {
            return [
                'lat' => (float) $coordinates[$key]['lat'],
                'lng' => (float) $coordinates[$key]['lng'],
            ];
        }
    }

    return [
        'lat' => null,
        'lng' => null,
    ];
}

function loadJsonMap(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $contents = file_get_contents($path);

    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

function writeJsonFile(string $path, array $payload): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Could not create directory {$directory}");
    }

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    );

    file_put_contents($path, $json . PHP_EOL);
}
