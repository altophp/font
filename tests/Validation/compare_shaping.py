"""Compare source shaping with independently decoded compact font containers."""

import argparse
import hashlib
import json
import os
import subprocess

from fontTools.ttLib import TTFont


def shape(path, text, ppem, features, font=None):
    output = subprocess.check_output(
        [
            os.environ.get("HB_SHAPE", "hb-shape"),
            str(path),
            text,
            "--output-format=json",
            "--no-glyph-names",
            f"--font-ppem={ppem}",
            f"--features={features}",
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
        source_font = TTFont(source)
    else:
        # Test both classes, unaffected first glyphs, and both sides of the
        # format 1 record split. Include the last glyph and class-0 behavior.
        texts = [
            chr(0xF0001) + chr(0xF0000 + second)
            + chr(0xF0002) + chr(0xF0000 + second)
            for second in (1, 2, 500, 10918, 10919, 10920, 11999, 12000)
        ]
        source_font = None

    cases = [
        (text, ppem, features)
        for text in texts
        for ppem in (0, 12, 13)
        for features in ("kern=1", "kern=0")
    ]
    expected = [
        shape(source, text, ppem, features, source_font)
        for text, ppem, features in cases
    ]

    # A broken source fixture with an inactive lookup would otherwise let a
    # broken output pass. Prove that kerning and the device actually apply.
    if shape(source, texts[0], 0, "kern=1") == shape(source, texts[0], 0, "kern=0"):
        raise AssertionError("Source fixture does not apply kerning")
    if scenario == "fallback":
        if shape(source, texts[0], 12, "kern=1") == shape(source, texts[0], 13, "kern=1"):
            raise AssertionError("Source fixture does not apply the 12 ppem device")

    for output in outputs:
        font = TTFont(output) if scenario == "inter" else None
        for (text, ppem, features), expected_glyphs in zip(cases, expected):
            actual = shape(output, text, ppem, features, font)
            if actual != expected_glyphs:
                raise AssertionError(
                    f"Shaping differs for {output}: {text!r}, {ppem} ppem, {features}\n"
                    f"Expected: {expected_glyphs}\nActual: {actual}"
                )
    print(f"{scenario}: {len(cases) * len(outputs)} shaping comparisons passed")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("rows", "fallback", "inter"))
    parser.add_argument("source")
    parser.add_argument("outputs", nargs="+")
    arguments = parser.parse_args()
    compare(arguments.scenario, arguments.source, arguments.outputs)
