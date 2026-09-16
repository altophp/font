"""Compare source shaping with independently decoded compact font containers."""

import argparse
import hashlib
import json
import os
import subprocess
from functools import lru_cache

from fontTools.pens.recordingPen import DecomposingRecordingPen
from fontTools.ttLib import TTFont


@lru_cache(maxsize=None)
def variation_glyph_set(font, variations):
    location = dict((tag, float(value)) for tag, value in
                    (item.split("=") for item in variations.split(",") if item))
    return font.getGlyphSet(location=location)


@lru_cache(maxsize=None)
def geometry_signature(font, name, variations):
    glyph_set = variation_glyph_set(font, variations)
    pen = DecomposingRecordingPen(glyph_set)
    glyph_set[name].draw(pen)
    geometry = pen.value
    return hashlib.sha256(repr(geometry).encode()).hexdigest()


def direct_outline_signature(font, name):
    coordinates, endpoints, flags = font["glyf"][name].getCoordinates(font["glyf"])
    return hashlib.sha256(repr((list(coordinates), list(endpoints), list(flags))).encode()).hexdigest()


def compare_direct_outlines(source_font, outputs):
    # OTS's WOFF2 decoder drops OVERLAP_SIMPLE although ALTO preserves it.
    # Check raw outlines and flags independently before that normalization.
    source_signatures = {direct_outline_signature(source_font, name)
                         for name in source_font.getGlyphOrder()}
    source_cmap = source_font.getBestCmap()
    for output in outputs:
        with TTFont(output) as font:
            for name in font.getGlyphOrder():
                if direct_outline_signature(font, name) not in source_signatures:
                    raise AssertionError(f"Direct outline or flags changed: {output} / {name}")
            for codepoint, name in font.getBestCmap().items():
                if direct_outline_signature(font, name) != direct_outline_signature(
                    source_font, source_cmap[codepoint]
                ):
                    raise AssertionError(f"Direct cmap outline or flags changed: {output} / U+{codepoint:04X}")


def shape(path, text, ppem, features, direction="ltr", variations="", font=None):
    output = subprocess.check_output(
        [
            os.environ.get("HB_SHAPE", "hb-shape"),
            str(path),
            text,
            "--output-format=json",
            "--no-glyph-names",
            f"--font-ppem={ppem}",
            f"--features={features}",
            f"--direction={direction}",
            f"--variations={variations}",
        ],
        text=True,
    )
    glyphs = json.loads(output)
    if font is not None:
        # Compact glyph IDs differ. Compare actual geometry instead of names
        # or IDs; instructions and serialization details are not geometry.
        for glyph in glyphs:
            name = font.getGlyphName(glyph["g"])
            glyph["g"] = geometry_signature(font, name, variations)
    return glyphs


def compare(scenario, source, outputs, original_outputs=None):
    if scenario == "inter":
        texts = [
            "AVATAR To WA",
            "office ffi fi fl 0123456789",
            "A\u0301 e\u0308 o\u0302",
        ]
    elif scenario == "arabic":
        texts = ["سلام العربية", "لَا مُحَمَّد", "بسم الله"]
    elif scenario == "devanagari":
        texts = ["नमस्ते हिन्दी", "क्षि त्रि श्र ज्ञ", "कि क्र र्क"]
    elif scenario == "cjk":
        texts = ["日本語の組版", "「縦書き」。漢字かなカナ", "がぎぐげごぱぴぷぺぽか\u3099"]
    elif scenario == "recursive":
        texts = ["AVATAR To WA", "office ffi fi fl 0123456789", "agijlrs", "A\u0301 e\u0308 o\u0302"]
    elif scenario == "layout":
        texts = ["ab", "ac", "cad", "fi\u0301", "a\u0301\u0301", "d", "a", "b"]
    elif scenario == "vertical":
        texts = ["ab", "cd", "abcd"]
    elif scenario in ("carets", "variable-carets"):
        texts = ["fi", "fifi"]
    else:
        # Test both classes, unaffected first glyphs, and both sides of the
        # format 1 record split. Include the last glyph and class-0 behavior.
        texts = [
            chr(0xF0001) + chr(0xF0000 + second)
            + chr(0xF0002) + chr(0xF0000 + second)
            for second in (1, 2, 500, 10918, 10919, 10920, 11999, 12000)
        ]

    source_font = TTFont(source) if scenario not in ("rows", "fallback") else None
    if source_font is not None and original_outputs:
        compare_direct_outlines(source_font, original_outputs)
    feature_sets = ("kern=1", "kern=0")
    if scenario in ("carets", "variable-carets"):
        feature_sets = ("liga=1",)
    if scenario == "layout":
        baseline = "liga=0,salt=0,calt=0,kern=0,mark=0,mkmk=0,curs=0,dist=0"
        feature_sets = (baseline,) + tuple(
            baseline + f",{feature}=1"
            for feature in ("liga", "salt", "calt", "kern", "mark", "mkmk", "curs", "dist")
        ) + ("liga=1,salt=0,calt=1,kern=1,mark=1,mkmk=1,curs=1,dist=1",)

    cases = [
        (text, ppem, features, "rtl" if scenario == "arabic" else "ltr", "")
        for text in texts
        for ppem in (0, 12, 13)
        for features in feature_sets
    ]
    if scenario in ("arabic", "devanagari"):
        # Require real script substitutions, not merely cmap mapping. A missing
        # glyph or inactive source layout must not yield a passing comparison.
        cmap = source_font.getBestCmap()
        substituted = False
        for text in texts:
            glyphs = shape(source, text, 0, "", "rtl" if scenario == "arabic" else "ltr")
            if any(glyph["g"] == 0 for glyph in glyphs):
                raise AssertionError("Corpus text contains a missing source glyph")
            mapped_ids = {source_font.getGlyphID(cmap[ord(char)]) for char in text}
            substituted |= any(glyph["g"] not in mapped_ids for glyph in glyphs)
        if not substituted:
            raise AssertionError("Corpus fixture does not apply script substitutions")
    elif scenario in ("vertical", "variable-carets"):
        cases = [
            (text, 0, "", "ttb" if scenario == "vertical" else "ltr", f"wght={weight}")
            for text in texts
            for weight in (100, 400, 650, 900)
        ]
    elif scenario == "cjk":
        cases = [(text, ppem, "", direction, "") for text in texts
                 for ppem in (0, 12, 24) for direction in ("ltr", "ttb")]
        if shape(source, texts[1], 0, "vert=1,vrt2=1", "ttb") == shape(
            source, texts[1], 0, "vert=0,vrt2=0", "ttb"
        ):
            raise AssertionError("Source fixture does not apply vertical substitutions")
    elif scenario == "recursive":
        axes = source_font["fvar"].axes
        defaults = {axis.axisTag: axis.defaultValue for axis in axes}
        locations = [defaults, {axis.axisTag: axis.minValue for axis in axes},
                     {axis.axisTag: axis.maxValue for axis in axes},
                     {axis.axisTag: (axis.minValue + axis.maxValue) / 2 for axis in axes}]
        # Exercise every axis independently, alongside all-axis corners.
        locations += [{**defaults, axis.axisTag: value} for axis in axes
                      for value in (axis.minValue, axis.maxValue)]
        variations = list(dict.fromkeys(
            ",".join(f"{tag}={value}" for tag, value in sorted(location.items()))
            for location in locations
        ))
        cases = [(text, ppem, features, "ltr", location) for text in texts
                 for ppem in (0, 16) for features in ("", "liga=0,kern=0")
                 for location in variations]
        if source_font["GSUB"].table.FeatureVariations is None:
            raise AssertionError("Production variable fixture lost feature variations")
        probe = "agijlrs"
        with_substitutions = shape(source, probe, 0, "", variations="MONO=0,CASL=1,slnt=-15,CRSV=1,wght=700")
        without_substitutions = shape(source, probe, 0, "", variations="MONO=0,CASL=1,slnt=-15,CRSV=0,wght=700")
        if [g["g"] for g in with_substitutions] == [g["g"] for g in without_substitutions]:
            raise AssertionError("Production source does not apply conditional substitutions")
        # Removing gvar must be caught even while HarfBuzz still reads the
        # unchanged source metrics and substitutions. This checks the oracle.
        missing_gvar = TTFont(source)
        del missing_gvar["gvar"]
        location = variations[2]
        if shape(source, probe, 0, "", variations=location, font=source_font) == shape(
            source, probe, 0, "", variations=location, font=missing_gvar
        ):
            raise AssertionError("Geometry oracle failed to detect missing gvar")
    expected = [
        shape(source, *case, font=source_font) for case in cases
    ]

    # A broken source fixture with an inactive lookup would otherwise let a
    # broken output pass. Prove that kerning and the device actually apply.
    if scenario == "vertical":
        if shape(source, texts[0], 0, "", "ttb", "wght=400") == shape(
            source, texts[0], 0, "", "ttb", "wght=900"
        ):
            raise AssertionError("Source fixture does not apply vertical variation")
    elif scenario == "layout":
        probes = {
            "liga": ["fi"],
            "salt": ["a"],
            "calt": ["b", "ac", "cad"],
            "kern": ["ab", "cd"],
            "mark": ["a\u0301", "fi\u0301"],
            "mkmk": ["a\u0301\u0301"],
            "curs": ["ac"],
            "dist": ["d", "ac"],
        }
        for feature, probe_texts in probes.items():
            disabled = baseline + (",liga=1" if feature == "mark" else "")
            for text in probe_texts:
                if shape(source, text, 0, disabled + f",{feature}=1") == shape(
                    source, text, 0, disabled
                ):
                    raise AssertionError(f"Source fixture does not apply {feature} to {text!r}")
    elif scenario in ("rows", "fallback", "inter"):
        if shape(source, texts[0], 0, "kern=1") == shape(source, texts[0], 0, "kern=0"):
            raise AssertionError("Source fixture does not apply kerning")
    if scenario == "fallback":
        if shape(source, texts[0], 12, "kern=1") == shape(source, texts[0], 13, "kern=1"):
            raise AssertionError("Source fixture does not apply the 12 ppem device")

    for output in outputs:
        font = TTFont(output) if source_font is not None else None
        for case, expected_glyphs in zip(cases, expected):
            actual = shape(output, *case, font=font)
            if actual != expected_glyphs:
                raise AssertionError(
                    f"Shaping differs for {output}: {case!r}\n"
                    f"Expected: {expected_glyphs}\nActual: {actual}"
                )
    print(f"{scenario}: {len(cases) * len(outputs)} shaping comparisons passed")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("rows", "fallback", "inter", "arabic", "devanagari", "cjk", "recursive", "layout", "vertical", "carets", "variable-carets"))
    parser.add_argument("source")
    parser.add_argument("outputs", nargs="+")
    parser.add_argument("--original-outputs", nargs="+")
    arguments = parser.parse_args()
    compare(arguments.scenario, arguments.source, arguments.outputs, arguments.original_outputs)
