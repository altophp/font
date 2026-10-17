# Formats

Check here whether a file can be read, and what the package can do with it
once loaded. The container is detected from the first four bytes of the file,
never from its extension: a file renamed to `.ttf` is still what its
signature says.

| Format | Signature | Reported as | Support |
| --- | --- | --- | --- |
| [TrueType](formats/truetype.md) | `0x00010000` or `true` | `FontFormat::TrueType` | Read, write, subset |
| [OpenType](formats/opentype.md) with CFF outlines | `OTTO` | `FontFormat::OpenType` | Rejected: CFF and CFF2 outlines are not supported |
| [Collections](formats/collections.md) | `ttcf` | `FontFormat::TrueTypeCollection` | Read one face, write it standalone |
| [WOFF](formats/woff.md) | `wOFF` | `FontFormat::Woff` | Read, write; requires the Zlib extension |
| [WOFF2](formats/woff2.md) | `wOF2` | `FontFormat::Woff2` | Read, write; requires Brotli |

Within those containers, the outlines decide what the package can do. It
works on TrueType `glyf` outlines, compound glyphs included, and on variable
`glyf` fonts with their `fvar`, `avar`, `gvar`, and `HVAR` tables; compact
subsets also remap `VVAR` with `vhea` and `vmtx`. [OpenType](formats/opentype.md)
explains what to do with a CFF font, and [Tables](tables.md) lists what a
compact subset rewrites.

## Detection

`metadata()->format` and `face()->format` report the detected container.
`FontFormat::fromPath()` maps an extension to the format it usually holds,
which is useful for filtering candidates before loading, but loading always
trusts the signature. An `.otf` file whose signature is `0x00010000`
therefore loads and reports `TrueType`.

Loading failures raise the exceptions described in [Errors](errors.md).
