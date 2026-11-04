# Vazirmatn for the catalogue PDF (ADR-052)

Vazirmatn 33.0.3 by the Vazirmatn Project Authors, SIL Open Font License 1.1
(`OFL.txt`; no Reserved Font Name). Source: the `vazirmatn@33.0.3` npm package,
`fonts/ttf/Vazirmatn-Regular.ttf` and `Vazirmatn-Bold.ttf`:

- Regular: sha256 `b69fd4c680b8f3f225feabcc655a2c585d97627b8f5f5c0f9985e894069f3a56`
- Bold: sha256 `f635fdbea28f265de395ba83b4b1570dcf2f58d13c65469e61903b1c2d2ae723`

The `vazirmatn*.php`, `.z` and `.ctg.z` files are TCPDF font definitions made
from those files with `TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 96, $dir)`
(tecnickcom/tcpdf 6.11). They are generated, not edited; regenerate them with
the same call after a font or TCPDF upgrade.
