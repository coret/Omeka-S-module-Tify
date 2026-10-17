# Translations

Shipped catalogues:

| File | Locale | Notes |
|---|---|---|
| `template.pot` | — | Extracted source strings (54). |
| `en.po` / `en.mo` | `en` | Identity catalogue. English is the source language, but an explicit `en` locale then resolves instead of falling through to the raw msgid. |
| `nl_NL.po` / `nl_NL.mo` | `nl_NL` | Dutch. Matches the filename Omeka core uses (`application/language/nl_NL.po`). |
| `nl.po` / `nl.mo` | `nl` | Same Dutch content, so a plain `nl` locale resolves too. |

The strings are the module name and description from `config/module.ini`
(Omeka runs these through the translator in the module list), the labels, help
texts and validation messages of the settings and the site page block, and the
admin tab label.

These catalogues translate the Omeka side of the module only. The interface
of the viewer itself is translated by TIFY, from
`asset/vendor/tify/translations/`.

## Regenerating after changing a string

Translatable strings are marked `// @translate` in PHP and passed through
`translate()` in templates. After adding or changing one:

1. Add the string and its Dutch translation to every `.po` file (and the
   `.pot`), keeping the `#:` source reference current.
2. Compile: `for l in nl nl_NL en; do msgfmt -c -o language/$l.mo language/$l.po; done`
3. Check: `msgfmt --statistics language/nl.po -o /dev/null` should report every
   message translated.
