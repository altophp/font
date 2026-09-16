"""Generate valid large PairPos format 1 inputs, preserving deliberate offsets."""

import argparse
import struct

from fontTools.ttLib import TTFont
from fontTools.ttLib.tables.DefaultTable import DefaultTable

from generate_pairpos import generate, u16


def positioning(scenario):
    count = 12000 if scenario == "values" else 8191
    glyphs = [glyph for glyph in range(1, count + 1) if glyph != 100]
    device_offset = 2 + len(glyphs) * 8
    device = u16(12, 12, 1, 0x4000) if scenario == "device" else b""
    records = b"".join(
        u16(glyph, -20, device_offset, 30)
        if device else u16(glyph, -20, 30)
        for glyph in glyphs
    )
    coverage = u16(1, 1, 1)
    pair = (
        u16(1, 12, 0x44 if device else 4, 1, 1, 18)
        + coverage + u16(len(glyphs)) + records + device
    )
    # Glyph 1 overlaps: the first subtable must win. Glyph 100 only occurs
    # here: preceding split subtables must allow a later matching subtable.
    fallback = u16(1, 12, 4, 1, 1, 18) + coverage + u16(2, 1, -90, 13, 100, -90, 13)
    lookup = (
        u16(1, 4, 9, 0, 2, 10, 18)
        + u16(1, 2) + struct.pack(">I", 16)
        + u16(1, 2) + struct.pack(">I", 8 + len(pair))
        + pair + fallback
    )
    script = u16(1) + b"DFLT" + u16(8, 4, 0, 0, 0xFFFF, 1, 0)
    feature = u16(1) + b"kern" + u16(8, 0, 1, 0)
    return (
        u16(1, 0, 10, 10 + len(script), 10 + len(script) + len(feature))
        + script + feature + lookup
    )


def main(scenario, destination):
    generate("rows", destination)
    font = TTFont(destination)
    table = DefaultTable("GPOS")
    table.data = positioning(scenario)
    font["GPOS"] = table
    font.save(destination)
    font.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("values", "device"))
    parser.add_argument("destination")
    args = parser.parse_args()
    main(args.scenario, args.destination)
