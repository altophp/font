"""Compare split PairSets against HarfBuzz, including fallback and overlap."""

import argparse
import json
import os
import subprocess


def shape(path, text, ppem, features):
    return json.loads(subprocess.check_output([
        os.environ.get("HB_SHAPE", "hb-shape"), path, text, "--output-format=json", "--no-glyph-names",
        f"--font-ppem={ppem}", f"--features={features}",
    ], text=True))


def compare(scenario, source, outputs):
    first = chr(0xF0001)
    texts = [first + chr(0xF0000 + glyph) for glyph in (
        1, 2, 99, 100, 101, 8189, 8190, 8191, 8192, 11999, 12000
    )]
    # Value2 is nonzero; include longer runs to catch incorrect iterator advances.
    texts += [texts[0] + texts[3] + texts[7], texts[7] + texts[0]]
    cases = [(text, ppem, kern) for text in texts for ppem in (0, 12, 13)
             for kern in ("kern=1", "kern=0")]
    expected = {case: shape(source, *case) for case in cases}
    enabled = expected[texts[0], 0, "kern=1"]
    disabled = expected[texts[0], 0, "kern=0"]
    assert enabled[0]["ax"] == disabled[0]["ax"] - 20, "First-match kerning is inactive"
    assert enabled[1]["dx"] == disabled[1]["dx"] + 30, "Value2 is inactive"
    fallback = expected[texts[3], 0, "kern=1"]
    assert fallback[0]["ax"] == disabled[0]["ax"] - 90, "Fallback subtable is inactive"
    if scenario == "device":
        assert expected[texts[0], 12, "kern=1"] != expected[texts[0], 13, "kern=1"], "Device adjustment is inactive"
    for output in outputs:
        for case, result in expected.items():
            actual = shape(output, *case)
            if actual != result:
                raise AssertionError(f"{output}: {case!r}: {actual!r} != {result!r}")
    print(f"{len(cases) * len(outputs)} oversized PairSet shaping comparisons passed")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("scenario", choices=("values", "device"))
    parser.add_argument("source")
    parser.add_argument("outputs", nargs="+")
    args = parser.parse_args()
    compare(args.scenario, args.source, args.outputs)
