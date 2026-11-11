<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require dirname(__DIR__) . '/src/Hyphenation.php';

use Hyphenation\Hyphenation;

$root = dirname(__DIR__);
$shy = "\u{00AD}";

foreach (array($root . '/src/completed/*', rtrim(sys_get_temp_dir(), '\\/') . '/hyphenation-*.compiled') as $pattern) {
    foreach (glob($pattern) ?: array() as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

function assert_same($expected, $actual, $label)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $label\n expected: $expected\n actual:   $actual\n");
        exit(1);
    }
    echo "ok $label\n";
}

function assert_true($cond, $label)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL $label\n");
        exit(1);
    }
    echo "ok $label\n";
}

function assert_hyphenated($original, $actual, $label)
{
    $shy = "\u{00AD}";
    assert_true(strpos($actual, $shy) !== false, $label . ' has a soft hyphen');
    assert_same($original, str_replace($shy, '', $actual), $label . ' letters stay intact');
    assert_true(strpos($actual, $shy) !== 0, $label . ' does not start with a hyphen');
    assert_true(substr($actual, -strlen($shy)) !== $shy, $label . ' does not end with a hyphen');
    assert_true(strpos($actual, $shy . $shy) === false, $label . ' has no doubled hyphens');
}

$ru = new Hyphenation('ru_RU');
$en = new Hyphenation('en_US');
$gb = new Hyphenation('en_GB');
$de = new Hyphenation('de-1996');

$plain = 'Автоматическая расстановка переносов нужна для узкой колонки.';
$plainHy = 'Авто' . $shy . 'ма' . $shy . 'ти' . $shy . 'че' . $shy . 'ская расста' . $shy . 'новка пере' . $shy . 'носов нужна для узкой колонки.';
$out = $ru->hyphenate($plain, 'UTF-8');
assert_same($plainHy, $out, 'russian sentence breaks on syllables');
assert_hyphenated($plain, $out, 'russian sentence');

assert_same('Он и я.', $ru->hyphenate('Он и я.', 'UTF-8'), 'short russian words stay intact');
assert_same('перенос', $ru->hyphenate('перенос', 'UTF-8'), 'seven-letter russian word stays intact');

$title = 'Автоматическая';
$titleOut = $ru->hyphenate($title, 'UTF-8');
assert_same('Авто' . $shy . 'ма' . $shy . 'ти' . $shy . 'че' . $shy . 'ская', $titleOut, 'title-case russian breaks');
assert_hyphenated($title, $titleOut, 'title-case russian word');

$upper = 'АВТОМАТИЧЕСКАЯ';
assert_same($upper, $ru->hyphenate($upper, 'UTF-8'), 'all-caps russian word is skipped');

$escaped = 'Не трогать \автоматическая конец переносов здесь.';
$escapedOut = $ru->hyphenate($escaped, 'UTF-8');
assert_true(strpos($escapedOut, '\автоматическая') !== false, 'escaped russian word stays whole');
assert_same($escaped, str_replace($shy, '', $escapedOut), 'escape does not eat characters');

$yo = 'Съёмка ёжика в подворотне.';
$yoOut = $ru->hyphenate($yo, 'UTF-8');
assert_same('Съёмка ёжика в подво' . $shy . 'ротне.', $yoOut, 'yo letters survive and the long word breaks');
assert_same($yo, str_replace($shy, '', $yoOut), 'yo letters survive');
assert_true(strpos($yoOut, $shy) !== false, 'long word around yo is hyphenated');

$cached = new Hyphenation('ru_RU');
assert_same($out, $cached->hyphenate($plain, 'UTF-8'), 'compiled dictionary matches a fresh compile');

$forced = new Hyphenation('ru_RU', Hyphenation::ALWAYS_RECOMPILE);
assert_same($out, $forced->hyphenate($plain, 'UTF-8'), 'forced recompile matches the cache');

file_put_contents($root . '/src/completed/ru_RU.compiled', 'not-a-dictionary');
$repaired = new Hyphenation('ru_RU');
assert_same($out, $repaired->hyphenate($plain, 'UTF-8'), 'broken cache is compiled again');

$mixed = 'АвтоматичесКая';
assert_same($mixed, (new Hyphenation('ru_RU'))->hyphenate($mixed, 'UTF-8'), 'inner capital skips the word');
$mixedHy = new Hyphenation('ru_RU');
$mixedHy->proceed_uppercase = true;
$mixedOut = $mixedHy->hyphenate($mixed, 'UTF-8');
assert_same('Авто' . $shy . 'ма' . $shy . 'ти' . $shy . 'че' . $shy . 'сКая', $mixedOut, 'inner capital is kept');
assert_hyphenated($mixed, $mixedOut, 'inner capital is hyphenated when allowed');

$html = '<a href="автоматическая">текст</a> автоматическая';
$htmlOut = $ru->hyphenate($html, 'UTF-8');
assert_true(strpos($htmlOut, 'href="автоматическая"') !== false, 'attribute value is not hyphenated');
assert_true(strpos($htmlOut, $shy) !== false, 'text outside the tag is hyphenated');
assert_same($html, str_replace($shy, '', $htmlOut), 'html letters stay intact');

$body = '<p>автоматическая</p>';
$bodyOut = $ru->hyphenate($body, 'UTF-8');
assert_hyphenated('автоматическая', str_replace(array('<p>', '</p>'), '', $bodyOut), 'text between tags is hyphenated');

$entity = $ru->hyphenate($title, 'UTF-8', '&shy;');
assert_true(strpos($entity, '&shy;') !== false, 'explicit shy marker is used');
assert_same($title, str_replace('&shy;', '', $entity), 'explicit marker does not stick to letters');
assert_true(strpos($ru->hyphenate($title, 'UTF-8'), '&shy;') !== false, 'marker is remembered');

$fresh = new Hyphenation('ru_RU');
$cpText = 'Автоматическая расстановка переносов в колонке.';
$cp = iconv('UTF-8', 'CP1251', $cpText);
assert_true($cp !== false, 'CP1251 conversion is available');
$cpOut = $fresh->hyphenate($cp, 'CP1251');
$cpShy = iconv('UTF-8', 'CP1251', $shy);
assert_true(strpos($cpOut, $cpShy) !== false, 'CP1251 text gets a soft hyphen');
assert_same($cpText, iconv('CP1251', 'UTF-8', str_replace($cpShy, '', $cpOut)), 'CP1251 roundtrip keeps letters');

$limits = new Hyphenation('ru_RU');
$limits->set_limits(20, 20);
assert_same($plain, $limits->hyphenate($plain, 'UTF-8'), 'raised limits suppress short breaks');

$enWord = 'hyphenation';
$enOut = $en->hyphenate($enWord, 'UTF-8');
assert_same('hy' . $shy . 'phen' . $shy . 'ation', $enOut, 'english hyphenation points');
assert_hyphenated($enWord, $enOut, 'english word');
assert_same('HYPHENATION', $en->hyphenate('HYPHENATION', 'UTF-8'), 'all-caps english word is skipped');
assert_hyphenated('Hyphenation', $en->hyphenate('Hyphenation', 'UTF-8'), 'title-case english word');

$gbOut = $gb->hyphenate($enWord, 'UTF-8');
assert_same('hy' . $shy . 'phen' . $shy . 'ation', $gbOut, 'british hyphenation points');
assert_hyphenated($enWord, $gbOut, 'british english word');

$deWord = 'Donaudampfschifffahrt';
$deOut = $de->hyphenate($deWord, 'UTF-8');
assert_same('Do' . $shy . 'nau' . $shy . 'dampf' . $shy . 'schiff' . $shy . 'fahrt', $deOut, 'german compound points');
assert_hyphenated($deWord, $deOut, 'german compound');

$deTitle = 'Überraschung';
$deTitleOut = $de->hyphenate($deTitle, 'UTF-8');
assert_same('Über' . $shy . 'ra' . $shy . 'schung', $deTitleOut, 'umlaut word points');
assert_hyphenated($deTitle, $deTitleOut, 'german word with umlaut');
assert_same('Stra' . $shy . 'ße', $de->hyphenate('Straße', 'UTF-8'), 'eszett stays one letter and breaks before it');
assert_same('und', $de->hyphenate('und', 'UTF-8'), 'three-letter german word stays intact');
assert_same('STRASSE', $de->hyphenate('STRASSE', 'UTF-8'), 'all-caps german word is skipped');

$method = new ReflectionMethod(Hyphenation::class, 'parse_config_str');
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}
$parsed = $method->invoke($fresh, "name = 'hello world'\nflag\n");
assert_same('hello world', $parsed['name'][0], 'quoted config value is unscreened');
assert_same(true, $parsed['flag'][0], 'bare config flag is true');
$parsedYo = $method->invoke($fresh, "alphabet = 'ёжик'\n", true);
assert_same('ёжик', $parsedYo['alphabet'][0], 'unicode quoted config value survives');

$pair = new ReflectionMethod(Hyphenation::class, 'pattern_to_pair');
if (PHP_VERSION_ID < 80100) {
    $pair->setAccessible(true);
}
$wordPair = $pair->invoke($fresh, 'сло-во');
assert_same('.слово.', $wordPair[0], 'dictionary word key keeps letters');
assert_same('00889800', $wordPair[1], 'dictionary hyphen is the odd digit between syllables');

$patternPair = $pair->invoke($fresh, '.аб1р');
assert_same('.абр', $patternPair[0], 'utf-8 pattern key is characters, not bytes');
assert_same('00010', $patternPair[1], 'utf-8 pattern mask matches characters');

try {
    new Hyphenation('no_such_lang');
    fwrite(STDERR, "FAIL missing language must throw\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    echo "ok missing language throws\n";
}

try {
    new Hyphenation('../ru_RU');
    fwrite(STDERR, "FAIL language id with a path must throw\n");
    exit(1);
} catch (InvalidArgumentException $e) {
    echo "ok language id with a path throws\n";
}

echo "all passed\n";
