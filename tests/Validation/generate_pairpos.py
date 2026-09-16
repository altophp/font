"""Generate deterministic, independently built fonts that force GPOS splitting."""

import argparse
import struct

from fontTools.fontBuilder import FontBuilder
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.ttLib.tables.DefaultTable import DefaultTable


GLYPH_COUNT = 12001


def u16(*values):
    return struct.pack(">" + "H" * len(values), *(value & 0xFFFF for value in values))


def pair_positioning(scenario):
    if scenario == "rows":
        coverage = u16(2, 1, 1, GLYPH_COUNT - 1, 0)
        # The source fits using ClassDef format 1; alternating classes expand
        # when the compactor writes range records and require separate rows.
        class_one = u16(1, 1, GLYPH_COUNT - 1) + u16(
            *(1 + glyph % 2 for glyph in range(1, GLYPH_COUNT))
        )
        class_two = u16(2, 0)
        matrix = u16(0, -10, -20)
        coverage_offset = 16 + len(matrix)
        class_two_offset = coverage_offset + len(coverage)
        class_one_offset = class_two_offset + len(class_two)
        header = u16(
            2, coverage_offset, 4, 0, class_one_offset, class_two_offset, 3, 1
        )
        return header + matrix + coverage + class_two + class_one

    coverage = u16(1, 1, 1)
    class_one = u16(2, 0)
    class_two = u16(1, 0, GLYPH_COUNT) + u16(
        *(1 + glyph % 2 for glyph in range(GLYPH_COUNT))
    )
    # A +1 pixel advance adjustment at 12 ppem forces device relocation.
    # Expanded ClassDef2 ranges push the device beyond the 16-bit limit.
    # Format 1 then needs multiple subtables for its 12,001 pair records.
    device = u16(12, 12, 1, 0x4000)
    matrix = u16(-30, 28, -10, 28, -20, 28)
    coverage_offset = 16 + len(matrix) + len(device)
    class_one_offset = coverage_offset + len(coverage)
    class_two_offset = class_one_offset + len(class_one)
    header = u16(
        2, coverage_offset, 0x44, 0, class_one_offset, class_two_offset, 1, 3
    )
    return header + matrix + device + coverage + class_one + class_two


def generate(scenario, destination):
    names = [".notdef"] + [f"g{index}" for index in range(1, GLYPH_COUNT)]
    builder = FontBuilder(1000, isTTF=True)
    builder.setupGlyphOrder(names)
    builder.setupCharacterMap(
        {0xF0000 + index: names[index] for index in range(1, GLYPH_COUNT)}
    )
    glyphs = {}
    for index, name in enumerate(names):
        pen = TTGlyphPen(None)
        pen.moveTo((50, 0))
        pen.lineTo((250, 500 + index // 251))
        pen.lineTo((400 + index % 251, 0))
        pen.closePath()
        glyphs[name] = pen.glyph()
    builder.setupGlyf(glyphs)
    builder.setupHorizontalMetrics({name: (500, 0) for name in names})
    builder.setupHorizontalHeader(ascent=800, descent=-200)
    builder.setupNameTable(
        {
            "familyName": "Alto overflow validation",
            "styleName": "Regular",
            "uniqueFontIdentifier": "AltoOverflow",
            "fullName": "AltoOverflow",
            "psName": "AltoOverflow",
        }
    )
    builder.setupOS2(
        sTypoAscender=800, sTypoDescender=-200, usWinAscent=800, usWinDescent=200
    )
    builder.setupPost()
    builder.font.recalcTimestamp = False
    builder.font["head"].created = 3406620153
    builder.font["head"].modified = 3406620153

    script = u16(1) + b"DFLT" + u16(8, 4, 0, 0, 0xFFFF, 1, 0)
    feature = u16(1) + b"kern" + u16(8, 0, 1, 0)
    lookup = u16(1, 4, 2, 0, 1, 8) + pair_positioning(scenario)
    gpos = DefaultTable("GPOS")
    # Keep the deliberate source layout; FontTools must not optimize it away.
    gpos.data = (
        u16(1, 0, 10, 10 + len(script), 10 + len(script) + len(feature))
        + script
        + feature
        + lookup
    )
    builder.font["GPOS"] = gpos
    builder.save(destination)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("rows", "fallback"))
    parser.add_argument("destination")
    arguments = parser.parse_args()
    generate(arguments.scenario, arguments.destination)
