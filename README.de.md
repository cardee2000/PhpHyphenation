## PhpHyphenation

[Русский](README.md) · [English](README.en.md)

Die Bibliothek setzt weiche Trennstellen nach dem Liang-Knuth-Algorithmus. Eine Zeile darf nur an den markierten Stellen umbrochen werden, und das Trennzeichen bleibt beim Kopieren gewöhnlich zurück. Die Wörterbücher stammen aus TeX.

## Installation

```bash
composer require mifesta/hyphenation-php:~8.2.1
```

| Release | PHP |
|---|---|
| `~7.4.1` | 7.4 |
| `~8.0.1` | 8.0 |
| `~8.1.1` | 8.1 |
| `~8.2.1` | 8.2 |
| `~8.3.1` | 8.3 |
| `~8.4.1` | 8.4 |
| `~8.5.1` | 8.5 |

Erforderlich sind die Erweiterungen `iconv` und `mbstring`.

## Sprachen

| Code | Regeln |
|---|---|
| `ru_RU` | Russisch, mindestens 4 Buchstaben links und 4 rechts |
| `en_US` | Amerikanisches Englisch, mindestens 2 und 2 |
| `en_GB` | Britisches Englisch, mindestens 2 und 2 |
| `de-1996` | Deutsch nach der Rechtschreibreform von 1996, mindestens 2 und 2 |
| `de_DE` | dieselben Regeln und dasselbe Wörterbuch wie `de-1996` |

Ein Wort, das kürzer ist als die Summe der beiden Ränder, wird nicht getrennt: 8 Buchstaben für Russisch, 4 für Englisch und Deutsch. Ein unbekannter Sprachcode wirft `InvalidArgumentException`.

## Verwendung

```php
use Hyphenation\Hyphenation;

$hy = new Hyphenation('ru_RU');
echo $hy->hyphenate('Автоматическая расстановка переносов в узкой колонке.', 'UTF-8');

$de = new Hyphenation('de_DE');
echo $de->hyphenate('Donaudampfschifffahrt', 'UTF-8');
```

`hyphenate($text, $encoding, $shy, $preserveTags)`:

- `$encoding` ist die Kodierung des Textes. Ein leerer Wert bedeutet die Kodierung des Wörterbuchs, hier UTF-8. Andere `iconv`-Namen funktionieren ebenfalls, zum Beispiel `CP1251`.
- `$shy` ist die Trennungsmarkierung in UTF-8. Voreingestellt ist das Zeichen U+00AD. `&shy;` ist zulässig, und die gewählte Markierung bleibt am Objekt gespeichert.
- `$preserveTags` ist voreingestellt `true`. Wörter innerhalb von `<...>` bleiben unangetastet. Text zwischen Tags wird getrennt.

Ein Wort nach `\` bleibt ganz. Ein Wort aus lauter Großbuchstaben wird übersprungen. Großbuchstaben in der Wortmitte ebenfalls, bis `$hy->proceed_uppercase = true` gesetzt ist.

`set_limits($left, $right, $length, $rightLast, $leftUc)` erhöht die Ränder. Unter das Minimum der Sprache lassen sie sich nicht setzen. `$rightLast` gilt für das letzte Wort des Textes, `$leftUc` für ein Wort mit großem Anfangsbuchstaben. Voreingestellt entsprechen beide den gewöhnlichen Rändern.

Der erste Aufruf baut das Wörterbuch und schreibt es nach `src/completed`. Ist das Paketverzeichnis nicht beschreibbar, liegt der Cache im temporären Verzeichnis des Systems. Weitere Aufrufe lesen das fertige Wörterbuch.

## Test

```bash
composer test
```

## Lizenz

GNU Lesser General Public License v3.0 oder neuer. Siehe [LICENSE](LICENSE).

Die Rechtshinweise der Musterdateien stehen in `src/patterns`.
