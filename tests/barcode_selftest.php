<?php
// Self-test for includes/barcode.php. No database or web server needed. Run it from the project folder:
//   php tests/barcode_selftest.php        (XAMPP: C:\xampp\php\php.exe tests\barcode_selftest.php)
// Safe to delete; the website never loads this file.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/barcode.php';

$pass = 0; $fail = 0;
function t($name, $ok) { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL; }

// real-world codes
t('EAN-13 4006381333931 valid',  barcode_validate('4006381333931', 'EAN_13')['ok']);
t('EAN-13 5901234123457 valid',  barcode_validate('5901234123457', 'EAN_13')['ok']);
t('UPC-A 036000291452 valid',    barcode_validate('036000291452', 'UPC_A')['ok']);
t('EAN-8 96385074 valid',        barcode_validate('96385074', 'EAN_8')['ok']);
t('UPC-E 04252614 valid',        barcode_validate('04252614', 'UPC_E')['ok']);
t('UPC-E expands to UPC-A',      barcode_upce_to_upca('04252614') === '042100005264');

// invalid input
t('wrong check digit rejected',  !barcode_validate('4006381333932', 'EAN_13')['ok']);
t('12 digits is not EAN-13',     !barcode_validate('400638133393', 'EAN_13')['ok']);
t('UPC-E number system 2 rejected', !barcode_validate('24252614', 'UPC_E')['ok']);
t('empty rejected',              !barcode_validate('   ', 'EAN_13')['ok']);
t('Code 128 accepted',           barcode_validate('ABC-12345', 'CODE_128')['ok']);
t('Code 128 is upper-cased like SKUs', barcode_validate('abc-123', 'CODE_128')['code'] === 'ABC-123');
t('quote rejected',              !barcode_validate("AB'C1", 'CODE_128')['ok']);
t('too short rejected',          !barcode_validate('A1', 'CODE_128')['ok']);
t('Code 39 accepted',            barcode_validate('PROD-0042', 'CODE_39')['ok']);
t('Code 39 with # rejected',     !barcode_validate('PROD#42', 'CODE_39')['ok']);
t('SQL text rejected',           !barcode_validate("1'; DROP TABLE products;--", 'CODE_128')['ok']);
t('HTML text rejected',          !barcode_validate('<script>alert(1)</script>', 'CODE_128')['ok']);
t('51 characters rejected',      !barcode_validate(str_repeat('A', 51), 'CODE_128')['ok']);

// format given by the scanner in any spelling, or missing
t('format auto-detected (EAN-13)', barcode_validate('4006381333931', '')['format'] === 'EAN_13');
t('format auto-detected (UPC-A)',  barcode_validate('036000291452', '')['format'] === 'UPC_A');
t('format auto-detected (EAN-8)',  barcode_validate('96385074', '')['format'] === 'EAN_8');
t('format auto-detected (UPC-E)',  barcode_validate('04252614', '')['format'] === 'UPC_E');
t('format spellings',              barcode_format_key('ean_13') === 'EAN_13' && barcode_format_key('EAN-13') === 'EAN_13' && barcode_format_key('Code 128') === 'CODE_128');
t('unfamiliar format name falls back to the digits', barcode_validate('4006381333931', 'SOMETHING_ELSE')['format'] === 'EAN_13');

// the same product written two ways must count as one
$v = barcode_variants('036000291452', 'UPC_A');
t('UPC-A <-> EAN-13 are the same product', in_array('036000291452', $v, true) && in_array('0036000291452', $v, true));
t('EAN-13 with leading 0 also matches UPC-A', in_array('036000291452', barcode_variants('0036000291452', 'EAN_13'), true));
$v = barcode_variants('04252614', 'UPC_E');
t('UPC-E also matches its UPC-A and EAN-13 forms', in_array('042100005264', $v, true) && in_array('0042100005264', $v, true));
t('in-house Code 128 only matches itself', barcode_variants('ABC-123', 'CODE_128') === ['ABC-123']);

echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
