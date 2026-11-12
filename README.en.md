## PhpHyphenation

[Русский](README.md) · [Deutsch](README.de.md)

The library inserts soft hyphens using the Liang–Knuth algorithm. A line may break only at the marked points, and the hyphen character is usually left behind when the text is copied. The dictionaries come from TeX.

## Install

```bash
composer require mifesta/hyphenation-php:~8.5.1
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

The `iconv` and `mbstring` extensions are required.

## Languages

| Code | Rules |
|---|---|
| `ru_RU` | Russian, at least 4 letters on the left and 4 on the right |
| `en_US` | American English, at least 2 and 2 |
| `en_GB` | British English, at least 2 and 2 |
| `de-1996` | German after the 1996 spelling reform, at least 2 and 2 |
| `de_DE` | the same rules and the same dictionary as `de-1996` |

A word shorter than the sum of the two margins is not hyphenated: 8 letters for Russian, 4 for English and German. An unknown language code throws `InvalidArgumentException`.

## Usage

```php
use Hyphenation\Hyphenation;

$hy = new Hyphenation('ru_RU');
echo $hy->hyphenate('Автоматическая расстановка переносов в узкой колонке.', 'UTF-8');

$de = new Hyphenation('de_DE');
echo $de->hyphenate('Donaudampfschifffahrt', 'UTF-8');
```

`hyphenate($text, $encoding, $shy, $preserveTags)`:

- `$encoding` is the encoding of the text. An empty string means the dictionary encoding, which is UTF-8 for these languages. Other `iconv` names work as well, for example `CP1251`.
- `$shy` is the hyphen marker in UTF-8. The default is U+00AD. `&shy;` is accepted, and the chosen marker stays on the object.
- `$preserveTags` defaults to `true`. Words inside `<...>` are left untouched. Text between tags is hyphenated.

A word preceded by `\` stays whole. A word in all capitals is skipped. Capitals in the middle of a word are skipped too, until `$hy->proceed_uppercase = true`.

`set_limits($left, $right, $length, $rightLast, $leftUc)` raises the margins. They cannot be set below the language minimum. `$rightLast` applies to the last word of the text, `$leftUc` to a word whose first letter is capital. By default both match the ordinary margins.

The first call compiles the dictionary and writes it to `src/completed`. If the package directory is not writable, the cache goes to the system temporary directory. Later calls read the compiled dictionary.

## Testing

```bash
composer test
```

## License

GNU Lesser General Public License v3.0 or later. See [LICENSE](LICENSE).

Copyright notices for the pattern files are in `src/patterns`.
