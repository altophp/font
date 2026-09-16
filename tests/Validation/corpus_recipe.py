"""Reproduce bounded, renamed OFL fixtures from checksum-pinned local originals."""

import argparse
import hashlib
from pathlib import Path

import ots
from fontTools import subset
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont


REVISION = "1ac2012c34919f5fa2675aacf723fa98edb30b5f"
SOURCES = {
    "cjk": (
        "NotoSansJP.ttf",
        "c2f3b4d463500a2ddcd3849cded1fceeb9fd6d1c32e6cbecd568453ba50fc68f",
        "AltoCorpusCJK.ttf",
        "Alto Corpus CJK",
        "日本語の組版、「縦書き」。漢字かなカナ がぎぐげごぱぴぷぺぽか\u3099 ABCXYZ0123",
    ),
    "recursive": (
        "Recursive.ttf",
        "653221ca467f4732fe6856ac493f6c409e9f56a7674abe36b2364acc89796f7c",
        "AltoCorpusVariable.ttf",
        "Alto Corpus Variable",
        "AVATAR To WA office ffi fi fl 0123456789 agijlrs A\u0301 Á e\u0308 ë o\u0302 ô XYZxyz",
    ),
}


def derive(scenario, source_directory, destination_directory):
    source_name, checksum, filename, family, text = SOURCES[scenario]
    source = source_directory / source_name
    if hashlib.sha256(source.read_bytes()).hexdigest() != checksum:
        raise ValueError(f"Unexpected source checksum: {source}")
    # Validate the unmodified source before producing the bounded fixture.
    import tempfile
    with tempfile.TemporaryDirectory(prefix="alto-corpus-source-") as temporary:
        result = ots.sanitize(str(source), str(Path(temporary) / "sanitized.ttf"))
        if result.returncode:
            raise RuntimeError(f"OTS rejected source: {source}")
    font = TTFont(source, recalcTimestamp=False)
    options = subset.Options()
    options.layout_features = ["*"]
    options.name_IDs = ["*"]
    options.name_legacy = True
    options.name_languages = ["*"]
    options.notdef_outline = True
    options.glyph_names = True
    if scenario == "cjk":
        # BASE compact rewriting is unsupported and not part of this fixture.
        options.drop_tables += ["BASE"]
    selector = subset.Subsetter(options=options)
    selector.populate(text=text)
    selector.subset(font)
    if scenario == "cjk":
        font = instantiateVariableFont(font, {"wght": 400}, inplace=True)
    names = {1: family, 3: family + " fixture 1", 4: family,
             6: family.replace(" ", ""), 16: family, 21: family,
             25: family.replace(" ", "")}
    for record in font["name"].names:
        if record.nameID in names:
            record.string = names[record.nameID].encode(record.getEncoding())
    font["head"].created = font["head"].modified = 3406620153
    destination_directory.mkdir(parents=True, exist_ok=True)
    destination = destination_directory / filename
    font.save(destination)
    print(filename, len(font.getGlyphOrder()), destination.stat().st_size,
          hashlib.sha256(destination.read_bytes()).hexdigest())


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source_directory", type=Path)
    parser.add_argument("destination_directory", type=Path)
    arguments = parser.parse_args()
    for scenario in SOURCES:
        derive(scenario, arguments.source_directory, arguments.destination_directory)
