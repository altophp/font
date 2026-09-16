"""Build small fonts for contextual layout and variable vertical metrics checks."""

import argparse
import struct

from fontTools.feaLib.builder import addOpenTypeFeaturesFromString
from fontTools.fontBuilder import FontBuilder
from fontTools.pens.ttGlyphPen import TTGlyphPen
from fontTools.ttLib import newTable
from fontTools.ttLib.tables import otTables
from fontTools.ttLib.tables.DefaultTable import DefaultTable
from fontTools.varLib.builder import (
    buildVarData,
    buildVarIdxMap,
    buildVarRegionList,
    buildVarStore,
)


def generate(scenario, destination):
    names = [
        ".notdef", "unused", "a", "b", "c", "d", "f", "i", "fi",
        "a.alt", "a.alt2", "b.alt", "acutecomb",
    ]
    builder = FontBuilder(1000, isTTF=True)
    builder.setupGlyphOrder(names)
    builder.setupCharacterMap(
        {**{ord(name): name for name in "abcdfi"}, 0x0301: "acutecomb"}
    )
    glyphs = {}
    for index, name in enumerate(names):
        pen = TTGlyphPen(None)
        pen.moveTo((50, 0))
        pen.lineTo((250, 500 + index * 10))
        pen.lineTo((400 + index, 0))
        pen.closePath()
        glyphs[name] = pen.glyph()
    builder.setupGlyf(glyphs)
    builder.setupHorizontalMetrics({name: (500, 50) for name in names})
    builder.setupHorizontalHeader(ascent=800, descent=-200)
    builder.setupNameTable(
        {
            "familyName": "Alto layout validation",
            "styleName": "Regular",
            "uniqueFontIdentifier": "AltoLayout",
            "fullName": "AltoLayout",
            "psName": "AltoLayout",
        }
    )
    builder.setupOS2(
        sTypoAscender=800, sTypoDescender=-200, usWinAscent=800, usWinDescent=200
    )
    builder.setupPost()
    builder.font.recalcTimestamp = False
    builder.font["head"].created = 3406620153
    builder.font["head"].modified = 3406620153

    if scenario == "layout":
        addOpenTypeFeaturesFromString(builder.font, """
            languagesystem DFLT dflt;
            markClass acutecomb <anchor 0 0> @TOP;
            feature liga { sub f i by fi; } liga;
            feature salt { sub a from [a.alt a.alt2]; } salt;
            feature calt {
                sub b by b.alt c;
                sub a' c by a.alt;
                rsub c a' d by a.alt2;
            } calt;
            feature kern {
                pos a b -50;
                pos [a c] [b d] -20;
            } kern;
            feature mark {
                pos base [a b] <anchor 200 700> mark @TOP;
                pos ligature fi <anchor 100 700> mark @TOP
                    ligComponent <anchor 300 700> mark @TOP;
            } mark;
            feature mkmk { pos mark acutecomb <anchor 50 200> mark @TOP; } mkmk;
            feature curs {
                pos cursive a <anchor 0 0> <anchor 400 0>;
                pos cursive c <anchor 20 10> <anchor 420 10>;
            } curs;
            feature dist {
                pos d <10 20 30 40>;
                pos a' c -10;
            } dist;
        """)
    elif scenario in ("carets", "variable-carets"):
        addOpenTypeFeaturesFromString(builder.font, """
            languagesystem DFLT dflt;
            feature liga { sub f i by fi; } liga;
            table GDEF { LigatureCaretByPos fi 200 350; } GDEF;
        """)
        gdef = builder.font["GDEF"].table
        device = otTables.Device()
        if scenario == "variable-carets":
            builder.setupFvar([("wght", 100, 400, 900, "Weight")], [])
            builder.setupGvar({name: [] for name in names})
            regions = buildVarRegionList([{"wght": (0, 1, 1)}], ["wght"])
            gdef.Version = 0x00010003
            gdef.VarStore = buildVarStore(regions, [buildVarData([0], [[80]])])
            device.StartSize, device.EndSize, device.DeltaFormat = 0, 0, 0x8000
            hvar = otTables.HVAR()
            hvar.Version = 0x00010000
            hvar.VarStore = buildVarStore(
                regions, [buildVarData([0], [[index * 10] for index in range(len(names))])]
            )
            hvar.AdvWidthMap = buildVarIdxMap(list(range(len(names))), names)
            hvar.LsbMap = hvar.RsbMap = None
            builder.font["HVAR"] = newTable("HVAR")
            builder.font["HVAR"].table = hvar
        else:
            device.StartSize, device.EndSize, device.DeltaFormat = 12, 13, 1
            device.DeltaValue = [1, -1]
        for caret in gdef.LigCaretList.LigGlyph[0].CaretValue:
            caret.Format = 3
            caret.DeviceTable = device

        if scenario == "variable-carets":
            # Deliberately put the GDEF store first and the HVAR mapping
            # before its store. Relative offsets inside each piece survive.
            raw = builder.font.getTableData("GDEF")
            store_offset = struct.unpack_from(">I", raw, 14)[0]
            store = raw[store_offset:]
            header = bytearray(raw[:18])
            for field in (4, 6, 8, 10, 12):
                offset = struct.unpack_from(">H", header, field)[0]
                if offset:
                    struct.pack_into(">H", header, field, offset + len(store))
            struct.pack_into(">I", header, 14, 18)
            table = DefaultTable("GDEF")
            table.data = bytes(header) + store + raw[18:store_offset]
            builder.font["GDEF"] = table

            raw = builder.font.getTableData("HVAR")
            store_offset, map_offset = struct.unpack_from(">II", raw, 4)
            mapping = raw[map_offset:]
            table = DefaultTable("HVAR")
            table.data = struct.pack(">HHIIII", 1, 0, 20 + len(mapping), 20, 0, 0)
            table.data += mapping + raw[store_offset:map_offset]
            builder.font["HVAR"] = table
    else:
        builder.setupVerticalMetrics(
            {name: (1000 + index * 10, 100) for index, name in enumerate(names)}
        )
        builder.setupVerticalHeader(ascent=800, descent=-200)
        builder.setupFvar([("wght", 100, 400, 900, "Weight")], [])
        builder.setupGvar({name: [] for name in names})
        regions = buildVarRegionList([{"wght": (0, 1, 1)}], ["wght"])
        data = buildVarData([0], [[index * 20] for index in range(len(names))])
        table = otTables.VVAR()
        table.Version = 0x00010000
        # FontTools deduplicates both repeated data offsets and these maps.
        table.VarStore = buildVarStore(regions, [data, data])
        mapping = buildVarIdxMap(
            [(index % 2) * 0x10000 + index for index in range(len(names))], names
        )
        table.AdvHeightMap = mapping
        table.TsbMap = mapping
        table.BsbMap = None
        table.VOrgMap = None
        builder.font["VVAR"] = newTable("VVAR")
        builder.font["VVAR"].table = table

    builder.save(destination)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("layout", "vertical", "carets", "variable-carets"))
    parser.add_argument("destination")
    arguments = parser.parse_args()
    generate(arguments.scenario, arguments.destination)
