"""Build an ALTO-independent reproducer for CoreText glyph-name rasterization."""

import argparse
import hashlib
import json
from pathlib import Path
import shutil
import struct
import tempfile

import ots
from fontTools.ttLib import TTFont
from fontTools.ttLib.sfnt import SFNTWriter


def generate(destination):
    fixture_directory = Path(__file__).parent.parent / "Fixtures" / "Fonts"
    source = fixture_directory / "AltoCorpusVariable.ttf"
    names = ["source.ttf", "post3.ttf", "index.html", "LICENSE-RECURSIVE.txt", "manifest.json"]
    destination.mkdir(parents=True, exist_ok=True)
    if any((destination / name).exists() for name in names):
        raise FileExistsError("Use an empty output directory for this reproducer")

    with TTFont(source) as font:
        tables = {tag: font.reader[tag] for tag in font.reader.keys()}
        if struct.unpack(">I", tables["post"][:4])[0] != 0x00020000:
            raise ValueError("The source must retain post format 2 glyph names")
        tables["post"] = struct.pack(">I", 0x00030000) + tables["post"][4:32]
        with (destination / "post3.ttf").open("wb") as stream:
            writer = SFNTWriter(stream, len(tables), sfntVersion=font.sfntVersion)
            for tag in sorted(tables):
                writer[tag] = tables[tag]
            writer.close()

    shutil.copyfile(source, destination / "source.ttf")
    shutil.copyfile(fixture_directory / "LICENSE-RECURSIVE.txt", destination / "LICENSE-RECURSIVE.txt")
    shutil.copyfile(Path(__file__).with_suffix(".html"), destination / "index.html")
    with TTFont(source) as before, TTFont(destination / "post3.ttf") as after:
        if set(before.reader.keys()) != set(after.reader.keys()):
            raise AssertionError("The reproducer changed the table inventory")
        for tag in before.reader.keys():
            left, right = before.reader[tag], after.reader[tag]
            if tag == "head":
                # SFNT checksum adjustment changes when post is replaced.
                left, right = left[:8] + left[12:], right[:8] + right[12:]
            if tag != "post" and left != right:
                raise AssertionError(f"Unexpected change to {tag}")

    with tempfile.TemporaryDirectory(prefix="alto-recursive-ots-") as temporary:
        for name in ("source.ttf", "post3.ttf"):
            result = ots.sanitize(str(destination / name), str(Path(temporary) / name))
            if result.returncode:
                raise RuntimeError(f"OTS rejected {name}")

    report = {name: hashlib.sha256((destination / name).read_bytes()).hexdigest()
              for name in ("source.ttf", "post3.ttf")}
    (destination / "manifest.json").write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("destination", type=Path)
    generate(parser.parse_args().destination)
