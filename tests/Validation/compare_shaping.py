"""Compare source shaping with independently decoded compact font containers."""

import argparse
import hashlib
import json
import os
import subprocess

from fontTools.ttLib import TTFont


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
            coordinates, endpoints, flags = font["glyf"][name].getCoordinates(
                font["glyf"]
            )
            geometry = (list(coordinates), list(endpoints), list(flags))
            glyph["g"] = hashlib.sha256(repr(geometry).encode()).hexdigest()
    return glyphs


def compare(scenario, source, outputs):
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
    parser.add_argument("scenario", choices=("rows", "fallback", "inter", "arabic", "devanagari", "layout", "vertical", "carets", "variable-carets"))
    parser.add_argument("source")
    parser.add_argument("outputs", nargs="+")
    arguments = parser.parse_args()
    compare(arguments.scenario, arguments.source, arguments.outputs)
